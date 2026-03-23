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
     * Remove the logger mu-plugin.
     *
     * Called from uninstall.php when Sentinel is deleted.
     */
    public static function remove(): void
    {
        $dest = self::destinationPath();
        if (file_exists($dest)) {
            @unlink($dest);
        }
    }

    /**
     * Remove log data created by the logger.
     *
     * Drops the database table and removes the schema version option.
     * Called from uninstall.php for a full cleanup.
     */
    public static function cleanLogs(): void
    {
        if (class_exists('Sentinel_Logger')) {
            \Sentinel_Logger::dropTable();
            return;
        }

        // Fallback: if the mu-plugin has already been removed and the class
        // isn't available, drop the table directly.
        global $wpdb;
        $table = $wpdb->prefix . 'sentinel_log_entries';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option('sentinel_logger_db_version');
    }
}
