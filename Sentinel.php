<?php

declare(strict_types=1);

/**
 * Plugin Name: Sentinel
 * Description: Dashboard displaying the Intergroup plugin(s) status.
 * Version: 1.0.5
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
$sentinel_plugin_dir = plugin_dir_path(__FILE__);
$sentinel_plugin_url = plugin_dir_url(__FILE__);

if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

if (function_exists('get_plugin_data')) {
    $sentinel_plugin_data = get_plugin_data(__FILE__, false, false);
    define('SENTINEL_VERSION', $sentinel_plugin_data['Version']);
} else {
    define('SENTINEL_VERSION', '1.0.0');
}
define('SENTINEL_PLUGIN_DIR', $sentinel_plugin_dir);
define('SENTINEL_PLUGIN_URL', $sentinel_plugin_url);

// Autoloader for Sentinel namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Sentinel\\';
        $base_dir = SENTINEL_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Initialize Sentinel on plugins_loaded - no dependency on any single plugin
add_action('plugins_loaded', function (): void {
    try {
        \Sentinel\Plugin::init();

        do_action('sentinel/loaded');

    } catch (\Exception $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Plugin Initialization Error: ' . $e->getMessage());
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                $message = sprintf(
                    '<strong>Sentinel Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

    } catch (\Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Plugin Fatal Error: ' . $e->getMessage());
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Sentinel Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Sentinel Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
}, 5); // Priority 5: load early so hooks below can fire

// Listen for each monitored plugin's loaded action to refresh status
$sentinel_plugin_hooks = [
    'unity/loaded',
    'scrutiny_loaded',
    'integrity_loaded',
    'concordance/loaded',
    'amber/loaded',
];

foreach ($sentinel_plugin_hooks as $hook) {
    add_action($hook, function () use ($hook): void {
        do_action('sentinel/plugin_status_changed', $hook);
    });
}

// Listen for plugin activation/deactivation to catch status changes immediately
add_action('activated_plugin', function (string $plugin): void {
    do_action('sentinel/plugin_status_changed', $plugin);
});

add_action('deactivated_plugin', function (string $plugin): void {
    do_action('sentinel/plugin_status_changed', $plugin);
});

// Activation hook
register_activation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});
