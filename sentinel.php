<?php

declare(strict_types=1);

/**
 * Plugin Name: Sentinel
 * Description: Dashboard displaying the Intergroup plugin(s) status.
 * Version: 2.3.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/sentinel
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/sentinel
 * Contact: thebleedingdeacons@gmail.com
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

$sentinel_plugin_data = get_plugin_data(__FILE__, false, false);
define('SENTINEL_VERSION', $sentinel_plugin_data['Version']);
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
        function_exists('wp_log')
            ? wp_log('sentinel')->error('Sentinel Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Sentinel Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('sentinel')->critical('Sentinel Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Sentinel Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Initialize Sentinel on plugins_loaded - no dependency on any single plugin
add_action('plugins_loaded', function (): void {
    try {
        \Sentinel\Plugin::init();

        do_action('sentinel/loaded');

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('sentinel')->error('Sentinel Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Sentinel Plugin Initialization Error: ' . $e->getMessage());

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
        function_exists('wp_log')
            ? wp_log('sentinel')->critical('Sentinel Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Sentinel Plugin Fatal Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Sentinel Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
}, 5); // Priority 5: load early so hooks below can fire

// Keep the deployed logger in sync when Sentinel is updated.
// Runs on every request type (admin, CLI, cron, front) so WP-CLI picks up changes.
add_action('plugins_loaded', function (): void {
    if (!\Sentinel\Logger\LoggerManager::isCurrentVersion()) {
        \Sentinel\Logger\LoggerManager::deploy();
    }
}, 1);

// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('sentinel deploy-logger', function () {
        \Sentinel\Logger\LoggerManager::deploy(true);
        $dest = \Sentinel\Logger\LoggerManager::destinationPath();
        if (file_exists($dest)) {
            \WP_CLI::success('Logger deployed to ' . $dest);
        } else {
            \WP_CLI::error('Deploy failed — file not found at ' . $dest);
        }
    });

    \WP_CLI::add_command('log tail', function ($args, $assoc_args) {
        if (!function_exists('wp_log')) {
            \WP_CLI::error('Shared logger not deployed. Run: wp sentinel deploy-logger');
        }

        global $wpdb;
        $table   = \Sentinel_Logger::tableName();
        $limit   = (int) ($assoc_args['lines'] ?? 50);
        $channel = $assoc_args['channel'] ?? null;
        $level   = $assoc_args['level'] ?? null;

        $where = [];
        $values = [];
        if ($channel) {
            $where[]  = 'channel = %s';
            $values[] = $channel;
        }
        if ($level) {
            $where[]  = 'level = %s';
            $values[] = strtolower($level);
        }

        $sql = "SELECT * FROM {$table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY id DESC LIMIT %d";
        $values[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$values));

        if (!$rows) {
            \WP_CLI::log('(no matching log entries)');
            return;
        }

        // Display oldest-first
        $rows = array_reverse($rows);

        foreach ($rows as $row) {
            $meta = sprintf(
                '[%s] [%s] [%s] [%s] [req:%s] [mem:%s]',
                $row->logged_at,
                strtoupper($row->level),
                $row->channel,
                $row->request_type,
                $row->request_id,
                $row->memory
            );
            \WP_CLI::log($meta);
            $msg = $row->message;
            if (!empty($row->context)) {
                $msg .= ' ' . $row->context;
            }
            \WP_CLI::log($msg);
            \WP_CLI::log('');
        }
    });

    \WP_CLI::add_command('log clear', function () {
        if (!function_exists('wp_log')) {
            \WP_CLI::error('Shared logger not deployed. Run: wp sentinel deploy-logger');
        }
        \Sentinel_Logger::truncateTable();
        \WP_CLI::success('Log table cleared.');
    });

    \WP_CLI::add_command('log table', function () {
        if (!function_exists('wp_log')) {
            \WP_CLI::error('Shared logger not deployed. Run: wp sentinel deploy-logger');
        }
        \WP_CLI::log(\Sentinel_Logger::tableName());
    });

    \WP_CLI::add_command('log flush', function () {
        if (!function_exists('wp_log')) {
            \WP_CLI::error('Shared logger not deployed. Run: wp sentinel deploy-logger');
        }
        $count = \Sentinel_Logger::instance()->flush();
        if ($count > 0) {
            \WP_CLI::success(sprintf('Flushed %d buffered log entries to the database.', $count));
        } else {
            \WP_CLI::log('Buffer is empty — nothing to flush.');
        }
    });
}

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

// Activation hook — deploy shared logger to mu-plugins/
register_activation_hook(__FILE__, function (): void {
    \Sentinel\Logger\LoggerManager::deploy();
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});