<?php

declare(strict_types=1);

namespace Sentinel\Monitor;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use RuntimeException;
use Sentinel\Logger\HasLogger;
use Sentinel\Monitor\Admin\DevicesPage;
use Sentinel\Monitor\Admin\SendMessagePage;
use Sentinel\Monitor\Admin\SettingsPage;
use Sentinel\Monitor\Alerts\AlertApi;
use Sentinel\Monitor\Alerts\AlertContactRepository;
use Sentinel\Monitor\Alerts\AlertReplyRepository;
use Sentinel\Monitor\Alerts\AlertRepository;
use Sentinel\Monitor\Core\Capabilities;
use Sentinel\Monitor\Core\MonitorServiceProvider;
use Sentinel\Monitor\Core\Schema;
use Sentinel\Monitor\Devices\DeviceRepository;
use Sentinel\Monitor\Devices\MemberLookupUnavailable;
use Sentinel\Monitor\Rest\AlertController;
use Sentinel\Monitor\Rest\DeviceAuthController;
use Sentinel\Monitor\Rest\DirectoryController;
use Sentinel\Monitor\Rest\OAuthCallbackController;
use Unity\Core\Interfaces\Container;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function add_action;
use function add_filter;
use function is_admin;

/**
 * The Monitor module: the server side of the Monitor app.
 *
 * Moved from Reach, where it was the server side of the Hand app. It signs
 * handsets in, keeps their push registrations, and delivers alerts and
 * messages to them, all under `sentinel/v1`.
 *
 * <b>Optional, and on Unity's schedule.</b> The rest of Sentinel — the
 * logger, the dashboard, Unity Control — needs nothing from any other
 * plugin and must keep working when they are missing or broken. This module
 * needs Unity (members, committees, groups, the password store) and
 * Scrutiny (audit logging), so it boots on `unity/loaded` and stands down
 * with an admin notice when Scrutiny is absent. Define
 * `SENTINEL_MONITOR_KILL` to stand it down on purpose.
 *
 * Fires `sentinel/monitor/loaded` once it is up.
 */
final class Plugin
{
    use HasLogger;

    /** Daily sweep of expired alerts and what hangs off them. */
    public const ALERT_PURGE_CRON_HOOK = 'sentinel_monitor_purge_alerts';

    /**
     * How far past expiry an alert is kept before the sweep removes it.
     * A day's grace leaves the admin list something to show about last
     * night without keeping the table large.
     */
    private const ALERT_RETENTION_SECONDS = 86400;

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    protected static function logChannel(): string
    {
        return 'monitor';
    }

    /**
     * Why the module is not running, or null when it may boot.
     */
    public static function standDownReason(): ?string
    {
        if (defined('SENTINEL_MONITOR_KILL') && SENTINEL_MONITOR_KILL) {
            return 'SENTINEL_MONITOR_KILL is set.';
        }
        if (!function_exists('scrutiny')) {
            return 'Scrutiny is not active, and Monitor needs it for GDPR audit logging.';
        }
        if (!class_exists(\Guardian\ProviderRegistry::class)) {
            return 'The Guardian library is missing from vendor/. Reinstall Sentinel from a release zip.';
        }
        return null;
    }

    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        // Before anything can query the tables. An update over an active
        // plugin never fires the activation hook; see Schema.
        Schema::ensureInstalled();
        Capabilities::ensureAssigned();

        (new MonitorServiceProvider())->register($unityContainer);

        self::$initialized = true;

        $unityContainer->get(OAuthCallbackController::class)->register();
        $unityContainer->get(DeviceAuthController::class)->register();
        $unityContainer->get(AlertController::class)->register();
        $unityContainer->get(DirectoryController::class)->register();

        // The action form of the alerting API. The function form,
        // sentinel_monitor_send_alert(), is declared in sentinel.php.
        $unityContainer->get(AlertApi::class)->register();

        self::scheduleAlertPurge();

        add_filter('rest_post_dispatch', [self::class, 'noStore'], 10, 3);
        add_filter('rest_dispatch_request', [self::class, 'dispatchGuarded'], 10, 4);

        // A deleted member's handsets are revoked. CurrentDevice would
        // refuse them at the next request anyway — it re-runs the gate on
        // every call — but that leaves live rows the dispatcher still counts
        // as targets in the meantime. Their password credential is not
        // Monitor's to delete; Reach and Unity handle that.
        $devices = $unityContainer->get(DeviceRepository::class);
        add_action('unity/member_deleted', function ($postId, $member = null) use ($devices) {
            if ($member === null) {
                return;
            }
            $email = strtolower(trim((string) $member->getPersonalEmail()));
            if ($email !== '') {
                $devices->revokeAllForMember($email, time());
            }
        }, 10, 2);

        if (is_admin()) {
            $unityContainer->get(DevicesPage::class)->register();
            $unityContainer->get(SendMessagePage::class)->register();
            $unityContainer->get(SettingsPage::class)->register();
        }

        self::logDebug('Monitor initialised', ['version' => defined('SENTINEL_VERSION') ? SENTINEL_VERSION : 'unknown']);
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Sentinel Monitor not initialized');
        }
        return self::$container;
    }

    /**
     * Everything under sentinel/v1 is per-device and authorised by a bearer
     * token, which shared caches do not recognise, and WordPress only sends
     * REST no-cache headers for logged-in WP users. Force no-store across the
     * namespace.
     *
     * @param mixed $response
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function noStore($response, $server, $request)
    {
        if (
            $response instanceof WP_REST_Response
            && $request instanceof WP_REST_Request
            && self::isMonitorRoute((string) $request->get_route())
        ) {
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, private');
        }

        return $response;
    }

    /**
     * Run a Monitor route's callback, answering 503 rather than letting a
     * {@see MemberLookupUnavailable} escape as a fatal.
     *
     * A 401 would tell the app to drop its credentials and sign in again;
     * a 503 is a failure it waits out. See MemberLookupUnavailable.
     *
     * @param mixed $result
     * @param mixed $request
     * @param mixed $route
     * @param mixed $handler
     * @return mixed
     */
    public static function dispatchGuarded($result, $request, $route, $handler)
    {
        if (
            $result !== null
            || !$request instanceof WP_REST_Request
            || !is_string($route)
            || !self::isMonitorRoute($route)
            || !is_array($handler)
            || !isset($handler['callback'])
            || !is_callable($handler['callback'])
        ) {
            return $result;
        }

        try {
            return call_user_func($handler['callback'], $request);
        } catch (MemberLookupUnavailable $e) {
            self::logError('Member lookup unavailable; request refused without revoking', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return new WP_Error(
                'sentinel_unavailable',
                'Monitor cannot check your membership right now. Please try again shortly.',
                ['status' => 503],
            );
        }
    }

    private static function isMonitorRoute(string $route): bool
    {
        return str_starts_with(ltrim($route, '/'), OAuthCallbackController::NAMESPACE . '/');
    }

    private static function scheduleAlertPurge(): void
    {
        if (!wp_next_scheduled(self::ALERT_PURGE_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::ALERT_PURGE_CRON_HOOK);
        }

        add_action(self::ALERT_PURGE_CRON_HOOK, function (): void {
            $container = self::$container;
            if ($container === null) {
                return;
            }

            $before = time() - self::ALERT_RETENTION_SECONDS;

            // Contacts first: they are joined to the alerts to find them,
            // so deleting the alerts first would strand every contact row —
            // orphaned personal data. Replies likewise, for tidiness.
            $container->get(AlertContactRepository::class)->purgeForExpiredAlertsBefore($before);
            $container->get(AlertReplyRepository::class)->purgeForAlertsExpiredBefore($before);
            $container->get(AlertRepository::class)->purgeExpiredBefore($before);
        });
    }
}
