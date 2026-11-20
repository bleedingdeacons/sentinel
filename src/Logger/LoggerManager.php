<?php

declare(strict_types=1);

namespace Sentinel\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the lifecycle of the shared logger mu-plugin.
 *
 * - deploy()    → copies sentinel-logger.php into mu-plugins/
 * - remove()    → removes logger from mu-plugins/
 * - cleanLogs() → drops the database table
 */
class LoggerManager
{
    /**
     * Filename of the mu-plugin.
     */
    private const MU_FILENAME = 'sentinel-logger.php';

    /**
     * Legacy filename(s) from earlier versions of the plugin.
     */
    private const LEGACY_MU_FILENAMES = [
        'bd-shared-logger.php',
    ];

    /**
     * Get the source path (bundled inside Sentinel).
     */
    public static function sourcePath(): string
    {
        return SENTINEL_PLUGIN_DIR . 'src/Logger/' . self::MU_FILENAME;
    }

    /**
     * Get the destination path in mu-plugins/.
     */
    public static function destinationPath(): string
    {
        return WPMU_PLUGIN_DIR . '/' . self::MU_FILENAME;
    }

    /**
     * Deploy (or update) the logger mu-plugin.
     *
     * Called on plugin activation and on every load to keep
     * the deployed copy in sync with the bundled version.
     *
     * @param bool $force Skip the hash comparison and always copy.
     */
    public static function deploy(bool $force = false): void
    {
        $source = self::sourcePath();
        $dest   = self::destinationPath();

        if (!file_exists($source)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Sentinel: Logger source file missing at ' . $source);
            return;
        }

        // Create mu-plugins directory if it doesn't exist
        if (!file_exists(WPMU_PLUGIN_DIR)) {
            wp_mkdir_p(WPMU_PLUGIN_DIR);
        }

        // Remove any legacy-named copies to prevent "Cannot redeclare" fatals
        self::removeLegacy();

        // Skip copy if the deployed version is identical (saves I/O)
        if (!$force && file_exists($dest) && md5_file($source) === md5_file($dest)) {
            return;
        }

        // Atomic deploy: write to temp file then rename
        $tmp = $dest . '.tmp';
        if (copy($source, $tmp)) {
            rename($tmp, $dest);
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Sentinel: Failed to deploy logger to ' . $dest);
        }

        // Ensure the database table exists after deploying the mu-plugin.
        // The mu-plugin creates it lazily, but we force it here so the
        // table is ready immediately after activation or update.
        if (class_exists('Sentinel_Logger')) {
            \Sentinel_Logger::createTable();
        }

        // Likewise the journal's. On an upgrade from a version without it the
        // class is not loaded yet (this request runs the old mu-plugin), and
        // Sentinel_Journal::record() creates the table on first use instead.
        if (class_exists('Sentinel_Journal')) {
            \Sentinel_Journal::maybeCreateTable();
        }
    }

    /**
     * Check if the logger is currently deployed.
     */
    public static function isDeployed(): bool
    {
        return file_exists(self::destinationPath());
    }

    /**
     * Check if the deployed version matches the bundled version.
     */
    public static function isCurrentVersion(): bool
    {
        $source = self::sourcePath();
        $dest   = self::destinationPath();

        if (!file_exists($source) || !file_exists($dest)) {
            return false;
        }

        return md5_file($source) === md5_file($dest);
    }

    /**
     * Remove legacy-named mu-plugin files from earlier versions.
     *
     * Prevents "Cannot redeclare" fatal errors when the plugin
     * filename has changed between releases.
     */
    public static function removeLegacy(): void
    {
        foreach (self::LEGACY_MU_FILENAMES as $legacy) {
            $path = WPMU_PLUGIN_DIR . '/' . $legacy;
            if (file_exists($path) && !unlink($path)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('Sentinel: Failed to remove legacy mu-plugin: ' . $path);
            }
        }
    }

    /**
     * Remove the logger mu-plugin.
     *
     * Called from uninstall.php when Sentinel is deleted.
     */
    public static function remove(): void
    {
        $dest = self::destinationPath();
        if (file_exists($dest) && !unlink($dest)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Sentinel: Failed to remove logger mu-plugin: ' . $dest);
        }
    }

    /**
     * Remove the data created by the logger: the log and the journal.
     *
     * Drops both database tables and removes their schema version options.
     * Called from uninstall.php for a full cleanup.
     */
    public static function cleanLogs(): void
    {
        // When the mu-plugin has already been removed its classes are not
        // available, so each table is dropped directly by name instead.
        if (class_exists('Sentinel_Logger')) {
            \Sentinel_Logger::dropTable();
        } else {
            self::dropTable('sentinel_log_entries', 'sentinel_logger_db_version');
        }

        if (class_exists('Sentinel_Journal')) {
            \Sentinel_Journal::dropTable();
        } else {
            self::dropTable('sentinel_journal', 'sentinel_journal_db_version');
        }
    }

    /**
     * Drop one of the logger's tables by its unprefixed name.
     */
    private static function dropTable(string $name, string $versionOption): void
    {
        global $wpdb;
        $table = $wpdb->prefix . $name;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
        delete_option($versionOption);
    }
}
