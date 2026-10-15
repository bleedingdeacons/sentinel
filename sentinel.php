<?php

/**
 * Plugin Name: Sentinel
 * Description: Dashboard displaying the Intergroup plugin(s) status, the suite's shared logger, and the server side of the Monitor app (optional; needs Unity and Scrutiny).
 * Version: 3.1.0
 * Build date: 2026/10/15 19:26:30
 * Requires at least: 6.0
 * Requires PHP: 8.4
 * GitHub Plugin URI: https://github.com/bleedingdeacons/sentinel
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/sentinel
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

declare(strict_types=1);

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

// Composer's autoloader, when present. It supplies the Guardian library, which
// the Monitor module signs handsets in with; the production build ships it in
// vendor/. Nothing outside the Monitor module needs it.
$sentinel_autoloader = SENTINEL_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($sentinel_autoloader)) {
    require_once $sentinel_autoloader;
}

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

// Error alerts: page opted-in Monitor handsets when an error is logged.
// Registered here rather than from the Monitor boot below, so it hears errors
// logged before plugins_loaded and on requests where Unity never loads — when
// an alert matters most. It needs nothing from Unity. See ErrorAlertListener.
\Sentinel\Monitor\ErrorAlerts\ErrorAlertListener::register();

// The Monitor module: the server side of the Monitor app. Optional — it boots
// only when Unity loads, and stands down without Scrutiny or the Guardian
// library — so nothing above depends on it. See Sentinel\Monitor\Plugin.
add_action('unity/loaded', function ($container): void {
    try {
        $reason = \Sentinel\Monitor\Plugin::standDownReason();
        if ($reason !== null) {
            if (is_admin() && !(defined('SENTINEL_MONITOR_KILL') && SENTINEL_MONITOR_KILL)) {
                add_action('admin_notices', function () use ($reason): void {
                    echo '<div class="notice notice-warning"><p><strong>Sentinel Monitor is not running:</strong> '
                        . esc_html($reason) . '</p></div>';
                });
            }
            return;
        }

        \Sentinel\Monitor\Plugin::init($container);

        do_action('sentinel/monitor/loaded', \Sentinel\Monitor\Plugin::getContainer());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('monitor')->error('Sentinel Monitor initialisation error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Sentinel Monitor initialisation error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e): void {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Sentinel Monitor error:</strong> '
                    . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}, 10);

/**
 * Raise an alert on the Monitor app's handsets.
 *
 * The Monitor module's public alerting API, moved from Reach's
 * reach_send_alert():
 *
 *     $id = sentinel_monitor_send_alert([
 *         'kind'      => 'shift_uncovered',
 *         'source'    => 'trusted',
 *         'title'     => 'Helpline shift uncovered',
 *         'body'      => 'Tonight 22:00-08:00 has nobody signed up.',
 *         'reference' => 'SHIFT-2026-08-15-N',
 *         'priority'  => 'urgent',
 *     ]);
 *
 * Only `kind` and `title` are required. See
 * {@see \Sentinel\Monitor\Alerts\AlertApi} for the full argument list.
 *
 * <b>Never put personal data in an alert.</b> The text passes through
 * Google's push infrastructure and onto a lock screen.
 *
 * Guarded so a caller can use function_exists() to degrade gracefully.
 *
 * @param array<string, mixed> $alert
 * @return int|\WP_Error The stored alert's id, or why it was refused.
 */
if (!function_exists('sentinel_monitor_send_alert')) {
    function sentinel_monitor_send_alert(array $alert): int|\WP_Error
    {
        try {
            return \Sentinel\Monitor\Plugin::getContainer()
                ->get(\Sentinel\Monitor\Alerts\AlertApi::class)
                ->send($alert);
        } catch (\Throwable $e) {
            // An alert that could not be raised must never take the calling
            // plugin down with it.
            return new \WP_Error(
                'sentinel_unavailable',
                'Sentinel Monitor is not ready to accept alerts: ' . $e->getMessage(),
                ['status' => 503],
            );
        }
    }
}

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

    // Stop the Monitor alert sweep. Enrolled handsets are deliberately left
    // alone: deactivating is routinely how a site is upgraded, and making
    // every handset sign in again is far too destructive an answer to a few
    // seconds of downtime. Nothing authenticates while Sentinel is inactive
    // anyway, because none of its routes are registered.
    wp_clear_scheduled_hook(\Sentinel\Monitor\Plugin::ALERT_PURGE_CRON_HOOK);
});
