<?php

declare(strict_types=1);

/**
 * Sentinel Uninstall Handler
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes the shared logger mu-plugin and optionally cleans up log files.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the LoggerManager class (autoloader won't be registered during uninstall)
$logger_manager = __DIR__ . '/src/Logger/LoggerManager.php';
if (file_exists($logger_manager)) {
    // Define SENTINEL_PLUGIN_DIR since the main plugin file hasn't loaded
    if (!defined('SENTINEL_PLUGIN_DIR')) {
        define('SENTINEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
    }

    require_once $logger_manager;

    // Remove the mu-plugin
    \Sentinel\Logger\LoggerManager::remove();

    // Clean up log files
    // Comment out the line below to preserve logs after uninstall
    \Sentinel\Logger\LoggerManager::cleanLogs();
}
