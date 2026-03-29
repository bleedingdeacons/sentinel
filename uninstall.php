<?php

declare(strict_types=1);

/**
 * Sentinel Uninstall Handler
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Always removes the shared logger mu-plugin.
 * Only drops the log database table if the user opted in via
 * Settings → Sentinel → "Drop log table on uninstall".
 * Removes Sentinel logger constants from wp-config.php.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define SENTINEL_PLUGIN_DIR since the main plugin file hasn't loaded
if (!defined('SENTINEL_PLUGIN_DIR')) {
    define('SENTINEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Load the LoggerManager class (autoloader won't be registered during uninstall)
$logger_manager = __DIR__ . '/src/Logger/LoggerManager.php';
if (file_exists($logger_manager)) {
    require_once $logger_manager;

    // Always remove the mu-plugin file
    \Sentinel\Logger\LoggerManager::remove();

    // Only drop the log table if the admin explicitly opted in.
    // The option defaults to '' (false / preserve data).
    $drop = get_option('sentinel_drop_table_on_uninstall', '');

    if ($drop === '1') {
        \Sentinel\Logger\LoggerManager::cleanLogs();
    }

    // Clean up our own options regardless
    delete_option('sentinel_drop_table_on_uninstall');
}

// Load the SettingsPage class to remove wp-config.php constants
$settings_page = __DIR__ . '/src/Admin/SettingsPage.php';
if (file_exists($settings_page)) {
    // The SettingsPage class requires the Plugin class for the MENU_SLUG constant.
    $plugin_class = __DIR__ . '/src/Plugin.php';
    if (file_exists($plugin_class)) {
        require_once $plugin_class;
    }

    require_once $settings_page;

    \Sentinel\Admin\SettingsPage::removeAllWpConfigConstants();
}
