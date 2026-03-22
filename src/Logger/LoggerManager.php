<?php

declare(strict_types=1);

namespace Sentinel\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the lifecycle of the shared logger mu-plugin.
 *
 * - activate()   → copies bd-shared-logger.php into mu-plugins/
 * - deactivate() → (no-op — logger stays for other plugins)
 * - uninstall()  → removes logger from mu-plugins/ and optionally cleans logs
 */
class LoggerManager
{
    /**
     * Filename of the mu-plugin.
     */
    private const MU_FILENAME = 'bd-shared-logger.php';

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
     * Remove log files created by the logger.
     *
     * Optional — call from uninstall.php for a full cleanup.
     */
    public static function cleanLogs(): void
    {
        $logDir = defined('BD_LOG_DIR')
            ? untrailingslashit(BD_LOG_DIR)
            : untrailingslashit(WP_CONTENT_DIR) . '/logs';

        if (!is_dir($logDir)) {
            return;
        }

        // Only remove files created by our logger
        $pattern = $logDir . '/bd-shared.log*';
        foreach (glob($pattern) as $file) {
            @unlink($file);
        }

        // Remove .htaccess and index.php if the directory is now empty
        $remaining = array_diff(scandir($logDir), ['.', '..', '.htaccess', 'index.php']);
        if (empty($remaining)) {
            @unlink($logDir . '/.htaccess');
            @unlink($logDir . '/index.php');
            @rmdir($logDir);
        }
    }
}
