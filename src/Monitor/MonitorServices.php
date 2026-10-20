<?php

declare(strict_types=1);

namespace Sentinel\Monitor;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Monitor\Alerts\AlertContactRepository;
use Sentinel\Monitor\Alerts\AlertReplyRepository;
use Sentinel\Monitor\Alerts\AlertRepository;
use Sentinel\Monitor\Alerts\Fcm\FcmClient;
use Sentinel\Monitor\Alerts\Transport\FcmTransport;
use Sentinel\Monitor\Alerts\WpdbAlertContactRepository;
use Sentinel\Monitor\Alerts\WpdbAlertReplyRepository;
use Sentinel\Monitor\Alerts\WpdbAlertRepository;
use Sentinel\Monitor\Core\Settings;
use Sentinel\Monitor\Devices\DeviceRepository;
use Sentinel\Monitor\Devices\WpdbDeviceRepository;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSender;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSettings;
use wpdb;

/**
 * The Monitor services that need nothing from Unity, built lazily and
 * shared.
 *
 * Settings, the repositories over Monitor's own tables, the FCM transport
 * and the error-alert sender depend only on WordPress. They are built here rather than only
 * inside Unity's container so that something which has to work when Unity
 * does not — an alert about Unity itself failing — can still reach them.
 * {@see MonitorServiceProvider} registers these same instances into Unity's
 * container, so code resolving from there and code calling here share one
 * of each.
 *
 * Anything that does need Unity (the member gate, the controllers, the
 * directory) lives only in the container.
 */
final class MonitorServices
{
    private static ?Settings $settings = null;
    private static ?DeviceRepository $devices = null;
    private static ?AlertRepository $alerts = null;
    private static ?AlertContactRepository $alertContacts = null;
    private static ?AlertReplyRepository $alertReplies = null;
    private static ?FcmClient $fcmClient = null;
    private static ?FcmTransport $fcmTransport = null;
    private static ?ErrorAlertSettings $errorAlertSettings = null;

    public static function settings(): Settings
    {
        return self::$settings ??= new Settings();
    }

    public static function devices(): DeviceRepository
    {
        return self::$devices ??= new WpdbDeviceRepository(self::wpdb());
    }

    public static function alerts(): AlertRepository
    {
        return self::$alerts ??= new WpdbAlertRepository(self::wpdb());
    }

    public static function alertContacts(): AlertContactRepository
    {
        return self::$alertContacts ??= new WpdbAlertContactRepository(self::wpdb());
    }

    public static function alertReplies(): AlertReplyRepository
    {
        return self::$alertReplies ??= new WpdbAlertReplyRepository(self::wpdb());
    }

    public static function fcmClient(): FcmClient
    {
        return self::$fcmClient ??= new FcmClient();
    }

    public static function fcmTransport(): FcmTransport
    {
        return self::$fcmTransport ??= new FcmTransport(
            self::fcmClient(),
            self::settings(),
            self::devices(),
        );
    }

    public static function errorAlertSettings(): ErrorAlertSettings
    {
        return self::$errorAlertSettings ??= new ErrorAlertSettings();
    }

    public static function errorAlertSender(): ErrorAlertSender
    {
        return new ErrorAlertSender(
            self::devices(),
            self::alerts(),
            self::fcmTransport(),
        );
    }

    /**
     * Forget every built service. For tests, which swap $wpdb between
     * cases.
     */
    public static function reset(): void
    {
        self::$settings = null;
        self::$devices = null;
        self::$alerts = null;
        self::$alertContacts = null;
        self::$alertReplies = null;
        self::$fcmClient = null;
        self::$fcmTransport = null;
        self::$errorAlertSettings = null;
    }

    private static function wpdb(): wpdb
    {
        global $wpdb;
        return $wpdb;
    }
}
