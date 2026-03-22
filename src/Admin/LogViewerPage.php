<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_redirect;
use function wp_safe_redirect;
use function wp_verify_nonce;

/**
 * Class LogViewerPage
 *
 * Registers a WordPress admin page under Settings that displays
 * aggregated log entries from the shared logger. Entries are grouped
 * by channel + level with counts, and the page provides a "Clear Log"
 * action.
 *
 * Also displays the current logging configuration (defines/filters)
 * and explains how to control logging output.
 */
class LogViewerPage
{
    private const PAGE_SLUG    = 'sentinel-logs';
    private const MENU_TITLE   = 'Sentinel Logs';
    private const PAGE_TITLE   = 'Sentinel Log Viewer';
    private const CAPABILITY   = 'manage_options';
    private const CLEAR_ACTION = 'sentinel_clear_log';
    private const AJAX_ACTION  = 'sentinel_log_viewer_refresh';

    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_init', [self::class, 'handleClearAction']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxRefresh']);
    }

    /**
     * Register the admin page under Settings.
     */
    public static function registerPage(): void
    {
        add_management_page(
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Enqueue CSS and JS only on our page.
     */
    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'sentinel-log-viewer',
            SENTINEL_PLUGIN_URL . 'assets/log-viewer.css',
            [],
            SENTINEL_VERSION
        );

        wp_enqueue_script(
            'sentinel-log-viewer',
            SENTINEL_PLUGIN_URL . 'assets/log-viewer.js',
            [],
            SENTINEL_VERSION,
            true
        );

        wp_localize_script('sentinel-log-viewer', 'sentinelLogViewer', [
            'url'    => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce'  => wp_create_nonce(self::AJAX_ACTION),
        ]);
    }

    /**
     * Handle the "Clear Log" POST action.
     */
    public static function handleClearAction(): void
    {
        if (!isset($_POST['sentinel_clear_log'])) {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Unauthorized', 'sentinel'));
        }

        check_admin_referer(self::CLEAR_ACTION, '_sentinel_nonce');

        $logFile = self::getLogFilePath();
        if ($logFile && file_exists($logFile)) {
            file_put_contents($logFile, '');
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => self::PAGE_SLUG, 'cleared' => '1'],
                admin_url('tools.php')
            )
        );
        exit;
    }

    /**
     * AJAX handler for refreshing log data.
     */
    public static function ajaxRefresh(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error('Unauthorized', 403);
        }

        ob_start();
        self::renderAggregateTable();
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Get the log file path from the shared logger.
     */
    private static function getLogFilePath(): ?string
    {
        if (!class_exists('BD_Shared_Logger')) {
            return null;
        }

        return \BD_Shared_Logger::instance()->getLogFile();
    }

    /**
     * Parse the log file and return aggregate data.
     *
     * @return array{
     *     aggregates: array<string, array{channel: string, level: string, count: int, last_seen: string, last_message: string}>,
     *     total_lines: int,
     *     file_size: int,
     *     file_path: string|null
     * }
     */
    private static function getAggregateData(): array
    {
        $logFile = self::getLogFilePath();

        $result = [
            'aggregates'  => [],
            'total_lines' => 0,
            'file_size'   => 0,
            'file_path'   => $logFile,
        ];

        if (!$logFile || !file_exists($logFile)) {
            return $result;
        }

        $result['file_size'] = (int) filesize($logFile);

        $fp = fopen($logFile, 'r');
        if (!$fp) {
            return $result;
        }

        // Pattern: [timestamp] [LEVEL] [channel] [type] [req:id] [mem:size] message {context}
        $pattern = '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+\[([^\]]+)\]\s+\[([^\]]+)\]\s+\[req:([^\]]+)\]\s+\[mem:([^\]]+)\]\s+(.*)$/';

        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line);
            if (empty($line)) {
                continue;
            }

            $result['total_lines']++;

            if (!preg_match($pattern, $line, $matches)) {
                continue;
            }

            $timestamp = $matches[1];
            $level     = strtolower($matches[2]);
            $channel   = $matches[3];
            $message   = $matches[7];

            // Strip JSON context from end of message for grouping
            $msgKey = preg_replace('/\s+\{.*\}$/', '', $message);
            // Normalise variable parts (request IDs, timestamps, etc.) for better grouping
            $msgKey = preg_replace('/[0-9a-f]{8,}/', '{id}', $msgKey);

            $groupKey = $channel . '|' . $level . '|' . $msgKey;

            if (!isset($result['aggregates'][$groupKey])) {
                $result['aggregates'][$groupKey] = [
                    'channel'      => $channel,
                    'level'        => $level,
                    'count'        => 0,
                    'last_seen'    => $timestamp,
                    'last_message' => $message,
                    'first_seen'   => $timestamp,
                ];
            }

            $result['aggregates'][$groupKey]['count']++;
            $result['aggregates'][$groupKey]['last_seen']    = $timestamp;
            $result['aggregates'][$groupKey]['last_message'] = $message;
        }

        fclose($fp);

        // Sort: highest count first, then by last_seen descending
        uasort($result['aggregates'], function ($a, $b) {
            $levelPriority = [
                'emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3,
                'warning' => 4, 'notice' => 5, 'info' => 6, 'debug' => 7,
            ];
            $aP = $levelPriority[$a['level']] ?? 7;
            $bP = $levelPriority[$b['level']] ?? 7;
            if ($aP !== $bP) {
                return $aP - $bP;
            }
            return $b['count'] - $a['count'];
        });

        return $result;
    }

    /**
     * Get the current logging configuration.
     *
     * @return array<string, array{value: mixed, source: string, description: string}>
     */
    private static function getLoggingConfig(): array
    {
        $config = [];

        // BD_LOG_ENABLED
        $config['BD_LOG_ENABLED'] = [
            'value'       => defined('BD_LOG_ENABLED') ? (BD_LOG_ENABLED ? 'true' : 'false') : '(not set — default: true)',
            'source'      => defined('BD_LOG_ENABLED') ? 'wp-config.php' : 'default',
            'description' => 'Master switch. Set to false to disable all logging.',
            'example'     => "define( 'BD_LOG_ENABLED', false );",
        ];

        // BD_LOG_LEVEL
        $config['BD_LOG_LEVEL'] = [
            'value'       => defined('BD_LOG_LEVEL') ? BD_LOG_LEVEL : '(not set — default: debug)',
            'source'      => defined('BD_LOG_LEVEL') ? 'wp-config.php' : 'default',
            'description' => 'Minimum severity to record. Messages below this level are discarded.',
            'example'     => "define( 'BD_LOG_LEVEL', 'warning' );",
            'options'     => 'emergency, alert, critical, error, warning, notice, info, debug',
        ];

        // BD_LOG_DIR
        $config['BD_LOG_DIR'] = [
            'value'       => defined('BD_LOG_DIR') ? BD_LOG_DIR : '(not set — default: wp-content/logs)',
            'source'      => defined('BD_LOG_DIR') ? 'wp-config.php' : 'default',
            'description' => 'Directory where log files are written.',
            'example'     => "define( 'BD_LOG_DIR', '/var/log/wordpress' );",
        ];

        // BD_LOG_MAX_SIZE
        $defaultSize = '5 MB';
        $config['BD_LOG_MAX_SIZE'] = [
            'value'       => defined('BD_LOG_MAX_SIZE') ? size_format(BD_LOG_MAX_SIZE) : '(not set — default: ' . $defaultSize . ')',
            'source'      => defined('BD_LOG_MAX_SIZE') ? 'wp-config.php' : 'default',
            'description' => 'Maximum size of a single log file before rotation (in bytes).',
            'example'     => "define( 'BD_LOG_MAX_SIZE', 10 * 1024 * 1024 ); // 10 MB",
        ];

        // BD_LOG_MAX_FILES
        $config['BD_LOG_MAX_FILES'] = [
            'value'       => defined('BD_LOG_MAX_FILES') ? BD_LOG_MAX_FILES : '(not set — default: 5)',
            'source'      => defined('BD_LOG_MAX_FILES') ? 'wp-config.php' : 'default',
            'description' => 'Number of rotated log files to keep.',
            'example'     => "define( 'BD_LOG_MAX_FILES', 3 );",
        ];

        return $config;
    }

    /**
     * Render the aggregate table HTML (used for both full page and AJAX).
     */
    public static function renderAggregateTable(): void
    {
        $data = self::getAggregateData();
        $aggregates = $data['aggregates'];

        if (empty($aggregates)) {
            echo '<div class="sentinel-log-empty">';
            echo '<span class="dashicons dashicons-yes-alt"></span>';
            echo '<p>' . esc_html__('No log entries found.', 'sentinel') . '</p>';
            echo '</div>';
            return;
        }

        ?>
        <div class="sentinel-log-summary">
            <span class="sentinel-log-stat">
                <strong><?php echo esc_html(number_format($data['total_lines'])); ?></strong>
                <?php esc_html_e('total entries', 'sentinel'); ?>
            </span>
            <span class="sentinel-log-stat">
                <strong><?php echo esc_html(count($aggregates)); ?></strong>
                <?php esc_html_e('unique messages', 'sentinel'); ?>
            </span>
            <span class="sentinel-log-stat">
                <strong><?php echo esc_html(size_format($data['file_size'])); ?></strong>
                <?php esc_html_e('file size', 'sentinel'); ?>
            </span>
        </div>

        <table class="sentinel-log-table widefat striped">
            <thead>
                <tr>
                    <th class="sentinel-log-col-count"><?php esc_html_e('Count', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-level"><?php esc_html_e('Level', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-channel"><?php esc_html_e('Channel', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-message"><?php esc_html_e('Last Message', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-time"><?php esc_html_e('Last Seen', 'sentinel'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aggregates as $entry): ?>
                    <tr class="sentinel-log-row--<?php echo esc_attr($entry['level']); ?>">
                        <td class="sentinel-log-col-count">
                            <span class="sentinel-log-count"><?php echo esc_html(number_format($entry['count'])); ?></span>
                        </td>
                        <td class="sentinel-log-col-level">
                            <span class="sentinel-badge sentinel-badge--<?php echo esc_attr($entry['level']); ?>">
                                <?php echo esc_html(strtoupper($entry['level'])); ?>
                            </span>
                        </td>
                        <td class="sentinel-log-col-channel">
                            <code><?php echo esc_html($entry['channel']); ?></code>
                        </td>
                        <td class="sentinel-log-col-message">
                            <span class="sentinel-log-message"><?php echo esc_html($entry['last_message']); ?></span>
                        </td>
                        <td class="sentinel-log-col-time">
                            <span class="sentinel-log-time"><?php echo esc_html($entry['last_seen']); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the full admin page.
     */
    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        $logFile = self::getLogFilePath();
        $config  = self::getLoggingConfig();
        $cleared = isset($_GET['cleared']) && $_GET['cleared'] === '1';
        $loggerDeployed = class_exists('BD_Shared_Logger');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Log file cleared successfully.', 'sentinel'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$loggerDeployed): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('The shared logger (mu-plugin) is not deployed. Run', 'sentinel'); ?>
                        <code>wp sentinel deploy-logger</code>
                        <?php esc_html_e('or deactivate and reactivate Sentinel.', 'sentinel'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Log Aggregate Section -->
            <div class="sentinel-log-section">
                <div class="sentinel-log-header">
                    <h2><?php esc_html_e('Log Aggregates', 'sentinel'); ?></h2>
                    <div class="sentinel-log-actions">
                        <button type="button" class="button" id="sentinel-refresh-log">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php esc_html_e('Refresh', 'sentinel'); ?>
                        </button>
                        <?php if ($logFile && file_exists($logFile) && filesize($logFile) > 0): ?>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field(self::CLEAR_ACTION, '_sentinel_nonce'); ?>
                                <button type="submit"
                                        name="sentinel_clear_log"
                                        value="1"
                                        class="button button-secondary sentinel-clear-btn"
                                        onclick="return confirm('<?php echo esc_attr__('Are you sure you want to clear the entire log file? This cannot be undone.', 'sentinel'); ?>');">
                                    <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-top: -2px;"></span>
                                    <?php esc_html_e('Clear Log', 'sentinel'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($logFile): ?>
                    <p class="description">
                        <?php esc_html_e('Log file:', 'sentinel'); ?>
                        <code><?php echo esc_html($logFile); ?></code>
                    </p>
                <?php endif; ?>

                <div id="sentinel-log-aggregate-container">
                    <?php self::renderAggregateTable(); ?>
                </div>
            </div>

            <!-- Logging Configuration Section -->
            <div class="sentinel-log-section sentinel-config-section">
                <h2><?php esc_html_e('Logging Configuration', 'sentinel'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Control logging output by adding these constants to your wp-config.php file (before the "That\'s all" comment). Changes take effect on the next page load.',
                        'sentinel'
                    ); ?>
                </p>

                <table class="sentinel-config-table widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Constant', 'sentinel'); ?></th>
                            <th><?php esc_html_e('Current Value', 'sentinel'); ?></th>
                            <th><?php esc_html_e('Source', 'sentinel'); ?></th>
                            <th><?php esc_html_e('Description', 'sentinel'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($config as $name => $def): ?>
                            <tr>
                                <td><code class="sentinel-config-name"><?php echo esc_html($name); ?></code></td>
                                <td>
                                    <span class="sentinel-config-value <?php echo $def['source'] === 'default' ? 'sentinel-config-default' : 'sentinel-config-set'; ?>">
                                        <?php echo esc_html((string) $def['value']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($def['source'] === 'wp-config.php'): ?>
                                        <span class="sentinel-badge sentinel-badge--active"><?php echo esc_html($def['source']); ?></span>
                                    <?php else: ?>
                                        <span class="sentinel-badge sentinel-badge--inactive"><?php echo esc_html($def['source']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($def['description']); ?>
                                    <?php if (!empty($def['options'])): ?>
                                        <br><small class="sentinel-config-options">
                                            <?php esc_html_e('Options:', 'sentinel'); ?>
                                            <?php echo esc_html($def['options']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="sentinel-config-examples">
                    <h3><?php esc_html_e('Example wp-config.php Snippets', 'sentinel'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Add any of these lines to wp-config.php to customise logging behaviour.', 'sentinel'); ?>
                    </p>
                    <pre class="sentinel-code-block"><?php
                        $examples = [];
                        foreach ($config as $name => $def) {
                            if (!empty($def['example'])) {
                                $examples[] = $def['example'];
                            }
                        }
                        echo esc_html(implode("\n", $examples));
                    ?></pre>

                    <h3><?php esc_html_e('Filter Hooks (Advanced)', 'sentinel'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('If you prefer not to use constants, each setting can also be controlled via a WordPress filter. Constants always take precedence.', 'sentinel'); ?>
                    </p>
                    <pre class="sentinel-code-block"><?php echo esc_html(
"// Set minimum log level via filter
add_filter( 'bd_logger_level', function () {
    return 'warning';
});

// Disable logging via filter
add_filter( 'bd_logger_enabled', '__return_false' );

// Suppress or modify individual entries
add_filter( 'bd_logger_entry', function ( \$entry, \$channel ) {
    // Suppress all debug entries from the 'unity' channel
    if ( \$channel === 'unity' && \$entry['level'] === 'debug' ) {
        return null;
    }
    return \$entry;
}, 10, 2 );"
                    ); ?></pre>
                </div>
            </div>
        </div>
        <?php
    }
}
