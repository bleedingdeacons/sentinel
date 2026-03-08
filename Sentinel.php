<?php

declare(strict_types=1);

/**
 * Plugin Name: Sentinel
 * Description: Dashboard displaying the Intergroup plugin(s) status.
 * Version: 1.0.2
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
        error_log('Sentinel Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Sentinel Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Initialize after Integrity has loaded
add_action('integrity_loaded', function (): void {
    try {
        \Sentinel\Plugin::init();

        do_action('sentinel/loaded');

    } catch (\Exception $e) {
        error_log('Sentinel Plugin Initialization Error: ' . $e->getMessage());
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
        error_log('Sentinel Plugin Fatal Error: ' . $e->getMessage());
        error_log('Sentinel Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Sentinel Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
});

// Show error if Integrity is not active (check after plugins_loaded)
add_action('plugins_loaded', function (): void {
    if (!defined('INTEGRITY_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>Sentinel Plugin Error:</strong> ';
            echo esc_html__('Sentinel requires the Integrity plugin to be installed and activated.', 'sentinel');
            echo '</p></div>';
        });
    }
}, 20);

// Activation hook
register_activation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});
