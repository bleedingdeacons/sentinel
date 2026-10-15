<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

use Closure;
use Sentinel\Logger\HasLogger;
use Sentinel\Monitor\Core\Schema;
use Sentinel\Monitor\MonitorServices;
use Throwable;

/**
 * Collects the errors logged during a request and pages the opted-in
 * handsets about them once the request is over.
 *
 * <b>Never inline.</b> Logging an error must not make the request that logged
 * it slower or more fragile, so the hooks below only append to a list in
 * memory. Storing and pushing happen at shutdown, after the response has been
 * sent where the server allows it, and nothing they do can throw back into
 * the code that logged.
 *
 * <b>Registered from sentinel.php itself</b>, not from the Monitor boot, so
 * it hears errors logged before `plugins_loaded` and on requests where Unity
 * never loads — the second being when an alert matters most.
 *
 * <b>Delivered on WordPress's `shutdown` action</b>, at the latest priority,
 * with a plain shutdown function behind it for anything logged later still.
 * Not from a shutdown function registered at the end of the queue, which was
 * the first version: on the SiteGround test bed something exits from an
 * earlier shutdown function, and an exit there cancels every one after it, so
 * the alerts never went. The action runs from WordPress's own shutdown
 * function, which is registered before any plugin's. Delivery clears the
 * queue, so the fallback finding it empty is the ordinary case.
 *
 * <b>A fatal is sent at once.</b> WordPress's fatal error handler ends in
 * wp_die(), which exits and cancels every shutdown function after it, this
 * one's included. The logger announces the fatal just before that exit, so
 * {@see onFatal()} delivers there and then rather than waiting for a shutdown
 * that will not come, and leaves the response alone so WordPress's error page
 * still renders.
 *
 * Stands down with the rest of the module under SENTINEL_MONITOR_KILL, and
 * stays silent until the Monitor tables exist.
 */
final class ErrorAlertListener
{
    use HasLogger;

    /** Errors kept per request. A loop that logs thousands needs one alert. */
    private const QUEUE_MAX = 50;

    /** @var array<int, LoggedError> */
    private static array $queue = [];

    private static int $overflow = 0;

    private static bool $registered = false;

    private static bool $delivering = false;

    /** @var (Closure(): ErrorAlertSettings)|null */
    private static ?Closure $settingsFactory = null;

    /** @var (Closure(): ErrorAlertSender)|null */
    private static ?Closure $senderFactory = null;

    private static ?ErrorAlertSettings $settings = null;

    protected static function logChannel(): string
    {
        return 'monitor';
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('sentinel_logger_after_write', [self::class, 'onLog'], 10, 3);
        add_action('sentinel_logger_fatal', [self::class, 'onFatal'], 10, 3);

        add_action('shutdown', [self::class, 'onShutdown'], PHP_INT_MAX, 0);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** Send what the request logged, from the shutdown action or the fallback. */
    public static function onShutdown(): void
    {
        self::send(time(), true);
    }

    /**
     * Hear one log entry. Cheap on purpose: it runs for every entry at or
     * above the logger's own level, on every request.
     *
     * @param mixed $level
     * @param mixed $message
     * @param mixed $context
     */
    public static function onLog($level, $message, $context = []): void
    {
        if (self::$delivering || !is_string($level) || !is_string($message)) {
            return;
        }

        if (defined('SENTINEL_MONITOR_KILL') && SENTINEL_MONITOR_KILL) {
            return;
        }

        $settings = self::settings();
        if (!$settings->isEnabled() || !\Sentinel_Log_Level::meetsThreshold(strtolower($level), $settings->threshold())) {
            return;
        }

        $error = LoggedError::fromLog($level, $message, is_array($context) ? $context : []);
        if (in_array($error->channel, $settings->excludedChannels(), true)) {
            return;
        }

        if (count(self::$queue) >= self::QUEUE_MAX) {
            self::$overflow++;
            return;
        }

        self::$queue[] = $error;
    }

    /**
     * Hear the fatal the request died on, and send everything queued now.
     * See the class docblock on why this cannot wait for shutdown.
     *
     * @param mixed $level
     * @param mixed $message
     * @param mixed $context
     */
    public static function onFatal($level, $message, $context = []): void
    {
        self::onLog($level, $message, $context);
        self::send(time(), false);
    }

    /**
     * Send what the request logged. Runs at shutdown; public only so the
     * shutdown queue and the tests can call it. A test passes $now.
     */
    public static function deliver(?int $now = null): void
    {
        self::send($now ?? time(), $now === null);
    }

    /**
     * @param bool $finishRequest Whether the response may be sent first.
     *     Never on a fatal: WordPress has its error page still to print.
     */
    private static function send(int $now, bool $finishRequest): void
    {
        if (self::$queue === [] || self::$delivering) {
            return;
        }

        self::$delivering = true;
        $queue = self::$queue;
        $overflow = self::$overflow;
        self::$queue = [];
        self::$overflow = 0;

        try {
            if ((int) get_option(Schema::OPTION, 0) < Schema::VERSION) {
                return;
            }

            // Send the response before doing any network work, where the
            // server allows it. Nothing after this can change what the
            // visitor sees.
            if ($finishRequest && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $sender = self::sender();
            $throttle = new ErrorAlertThrottle(self::settings());

            foreach (self::grouped($queue, $overflow) as [$error, $repeats]) {
                $suppressed = $throttle->admit($error->fingerprint(), $now, $repeats);
                if ($suppressed === null) {
                    continue;
                }

                $sender->send($error, $suppressed, $now);
            }
        } catch (Throwable $e) {
            self::logError('Error alerts could not be delivered: ' . $e->getMessage());
        } finally {
            self::$delivering = false;

            // The logger flushed its buffer in its own shutdown handler,
            // which has already run, so anything logged while delivering —
            // a refused push, say — would otherwise never be written.
            if (function_exists('wp_log_flush')) {
                wp_log_flush();
            }
        }
    }

    /**
     * Swap the collaborators, for tests. Null restores the defaults.
     *
     * @param (Closure(): ErrorAlertSettings)|null $settings
     * @param (Closure(): ErrorAlertSender)|null $sender
     */
    public static function useFactories(?Closure $settings, ?Closure $sender): void
    {
        self::$settingsFactory = $settings;
        self::$senderFactory = $sender;
        self::$settings = null;
    }

    /** Forget the queue, the registration and anything cached, for tests. */
    public static function reset(): void
    {
        self::$registered = false;
        self::$queue = [];
        self::$overflow = 0;
        self::$delivering = false;
        self::$settings = null;
    }

    /** @return array<int, LoggedError> */
    public static function queued(): array
    {
        return self::$queue;
    }

    /**
     * Fold repeats of the same error within the request into the first.
     * Errors past the queue limit are counted against the first error.
     *
     * @param array<int, LoggedError> $queue
     * @return array<int, array{0: LoggedError, 1: int}>
     */
    private static function grouped(array $queue, int $overflow): array
    {
        $groups = [];
        foreach ($queue as $error) {
            $fingerprint = $error->fingerprint();
            if (isset($groups[$fingerprint])) {
                $groups[$fingerprint][1]++;
            } else {
                $groups[$fingerprint] = [$error, 0];
            }
        }

        $groups = array_values($groups);
        if ($overflow > 0 && $groups !== []) {
            $groups[0][1] += $overflow;
        }

        return $groups;
    }

    private static function settings(): ErrorAlertSettings
    {
        return self::$settings ??= (self::$settingsFactory !== null
            ? (self::$settingsFactory)()
            : MonitorServices::errorAlertSettings());
    }

    private static function sender(): ErrorAlertSender
    {
        return self::$senderFactory !== null
            ? (self::$senderFactory)()
            : MonitorServices::errorAlertSender();
    }
}
