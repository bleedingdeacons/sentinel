<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Logger\HasLogger;
use Sentinel\Monitor\Alerts\WpdbAlertContactRepository;
use Sentinel\Monitor\Alerts\WpdbAlertReplyRepository;
use Sentinel\Monitor\Alerts\WpdbAlertRepository;
use Sentinel\Monitor\Devices\WpdbDeviceRepository;
use wpdb;

/**
 * Owns every table the Monitor module creates, and makes sure they exist.
 *
 * <b>Why this is not just the activation hook.</b> Reach learned this the
 * hard way: a site that *updates* a plugin never runs
 * `register_activation_hook`, and neither does a `GitHub Plugin URI`
 * auto-update. A release adding a table shipped code expecting a table
 * nothing had created, enrolment appeared to succeed because
 * `$wpdb->insert()` reports a missing table by returning false, and the
 * handset 401'd on its next request. So the schema version lives in an
 * option, is compared on load, and dbDelta runs when it has moved.
 *
 * Its own option, separate from the logger's `sentinel_logger_db_version`:
 * the two install at different times (the logger's lazily, from the
 * mu-plugin, on every site; these only where Unity is active) and must not
 * be able to mark each other current.
 *
 * <b>Bump {@see VERSION} whenever a table is added or a column
 * changes.</b> Nothing detects that for you; an unbumped version means
 * the change reaches new installs and silently skips every existing one.
 */
final class Schema
{
    use HasLogger;

    /**
     * Schema version. Bump on any change to a CREATE TABLE.
     *
     * 1 — the tables moved from Reach (its schema version 9: devices,
     *     alerts, acknowledgements, contacts and replies), under new names,
     *     plus devices.error_alerts, the opt-in for alerts raised when an
     *     error is logged.
     */
    public const VERSION = 1;

    public const OPTION = 'sentinel_monitor_schema_version';

    protected static function logChannel(): string
    {
        return 'monitor';
    }

    /**
     * Create or upgrade every table if the stored version is behind.
     *
     * Cheap on the common path: one option read and nothing else.
     */
    public static function ensureInstalled(): void
    {
        $installed = (int) get_option(self::OPTION, 0);
        if ($installed >= self::VERSION) {
            return;
        }

        global $wpdb;

        // Guarded rather than assumed: a TypeError here would take the
        // whole site down, which is far worse than an install that waits
        // for the next request.
        if (!$wpdb instanceof wpdb) {
            self::logWarning('Schema install skipped: $wpdb is not available yet');
            return;
        }

        self::install($wpdb);

        update_option(self::OPTION, self::VERSION, true);

        self::logInfo('Schema installed or upgraded', [
            'from' => $installed,
            'to'   => self::VERSION,
        ]);
    }

    /**
     * Record the schema as current without running the installers, for a
     * caller that has just called {@see install()} itself.
     */
    public static function markInstalled(): void
    {
        update_option(self::OPTION, self::VERSION, true);
    }

    /**
     * Run every table's installer. Each is an idempotent dbDelta.
     */
    public static function install(wpdb $wpdb): void
    {
        WpdbDeviceRepository::install($wpdb);
        WpdbAlertRepository::install($wpdb);
        WpdbAlertContactRepository::install($wpdb);
        WpdbAlertReplyRepository::install($wpdb);
    }

    /**
     * Drop every table and the version option. For uninstall only.
     */
    public static function uninstall(wpdb $wpdb): void
    {
        foreach (
            [
                WpdbAlertReplyRepository::TABLE_SUFFIX,
                WpdbAlertContactRepository::TABLE_SUFFIX,
                WpdbAlertRepository::ACKS_TABLE_SUFFIX,
                WpdbAlertRepository::TABLE_SUFFIX,
                WpdbDeviceRepository::TABLE_SUFFIX,
            ] as $suffix
        ) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $suffix . '`');
        }

        delete_option(self::OPTION);
    }
}
