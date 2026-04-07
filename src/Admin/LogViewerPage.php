<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Plugin;

use function add_action;
use function admin_url;
use function add_submenu_page;
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
 * Registers a WordPress admin page under the Sentinel top-level menu
 * that displays aggregated log entries from the database table. Entries
 * are grouped by channel + level with counts, and the page provides a
 * "Clear Log" action.
 *
 * Also displays the current logging configuration (defines/filters)
 * and explains how to control logging output.
 */
class LogViewerPage
{
    private const PAGE_SLUG    = 'sentinel-logs';
    private const MENU_TITLE   = 'Logs';
    private const PAGE_TITLE   = 'Sentinel Log Viewer';
    private const CAPABILITY   = 'manage_options';
    private const CLEAR_ACTION = 'sentinel_clear_log';
    private const AJAX_ACTION  = 'sentinel_log_viewer_refresh';

    /** @var string The hook suffix returned by add_submenu_page(). */
    private static string $hookSuffix = '';

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
     * Register the admin page as a submenu under Sentinel.
     */
    public static function registerPage(): void
    {
        self::$hookSuffix = (string) add_submenu_page(
            Plugin::MENU_SLUG,
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
        if (self::$hookSuffix === '' || $hook !== self::$hookSuffix) {
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

        if (class_exists('Sentinel_Logger')) {
            \Sentinel_Logger::truncateTable();
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => self::PAGE_SLUG, 'cleared' => '1'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * AJAX handler for refreshing log data.
     * Flushes any buffered entries before querying so the view is up to date.
     */
    public static function ajaxRefresh(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error('Unauthorized', 403);
        }

        // Flush any buffered entries so the view reflects the latest data.
        if (function_exists('wp_log_flush')) {
            wp_log_flush();
        }

        ob_start();
        self::renderAggregateTable();
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Check whether the log table exists and has rows.
     */
    private static function tableExists(): bool
    {
        global $wpdb;
        if (!class_exists('Sentinel_Logger')) {
            return false;
        }
        $table = \Sentinel_Logger::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    /**
     * Query the database and return aggregate data.
     *
     * @return array{
     *     aggregates: list<array{channel: string, level: string, count: int, last_seen: string, last_message: string, first_seen: string}>,
     *     total_rows: int,
     *     table_name: string|null
     * }
     */
    private static function getAggregateData(): array
    {
        global $wpdb;

        $result = [
                'aggregates' => [],
                'total_rows' => 0,
                'table_name' => null,
        ];

        if (!self::tableExists()) {
            return $result;
        }

        $table = $wpdb->prefix . 'sentinel_logs';
        $result['table_name'] = $table;
        $escapedTable = '`' . esc_sql($table) . '`';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $result['total_rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$escapedTable}");

        if ($result['total_rows'] === 0) {
            return $result;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $sql = "
        SELECT
            channel,
            level,
            COUNT(*)       AS cnt,
            MIN(logged_at) AS first_seen,
            MAX(logged_at) AS last_seen
        FROM {$escapedTable}
        GROUP BY channel, level
        ORDER BY last_seen DESC
    ";

        $rows = $wpdb->get_results($sql);
        if (!$rows) {
            return $result;
        }

        foreach ($rows as $row) {

            $lastMessage = $wpdb->get_var(
                    $wpdb->prepare(
                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
                            "SELECT message
                 FROM {$escapedTable}
                 WHERE channel = %s AND level = %s
                 ORDER BY id DESC
                 LIMIT 1",
                            $row->channel,
                            $row->level
                    )
            );

            $result['aggregates'][] = [
                    'channel'      => $row->channel,
                    'level'        => $row->level,
                    'count'        => (int) $row->cnt,
                    'first_seen'   => $row->first_seen,
                    'last_seen'    => $row->last_seen,
                    'last_message' => $lastMessage ?: '',
            ];
        }

        return $result;
    }
    /**
     * Render the aggregate table HTML (used for both full page and AJAX).
     */
    public static function renderAggregateTable(): void
    {
        $data       = self::getAggregateData();
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
                <strong><?php echo esc_html(number_format($data['total_rows'])); ?></strong>
                <?php esc_html_e('total entries', 'sentinel'); ?>
            </span>
            <span class="sentinel-log-stat">
                <strong><?php echo esc_html((string) count($aggregates)); ?></strong>
                <?php esc_html_e('unique groups', 'sentinel'); ?>
            </span>
        </div>

        <table class="sentinel-log-table widefat striped">
            <thead>
                <tr>
                    <th class="sentinel-log-col-level"><?php esc_html_e('Level', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-channel"><?php esc_html_e('Channel', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-count"><?php esc_html_e('Count', 'sentinel'); ?></th>
                    <th class="sentinel-log-col-time"><?php esc_html_e('Last Seen', 'sentinel'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aggregates as $entry): ?>
                    <tbody class="sentinel-log-group"
                        data-level="<?php echo esc_attr(strtoupper($entry['level'])); ?>"
                        data-channel="<?php echo esc_attr($entry['channel']); ?>"
                        data-count="<?php echo esc_attr(number_format($entry['count'])); ?>"
                        data-last-seen="<?php echo esc_attr($entry['last_seen']); ?>"
                        data-message="<?php echo esc_attr($entry['last_message']); ?>">
                    <tr class="sentinel-log-row--<?php echo esc_attr($entry['level']); ?> sentinel-log-row-header">
                        <td class="sentinel-log-col-level">
                            <span class="sentinel-badge sentinel-badge--<?php echo esc_attr($entry['level']); ?>">
                                <?php echo esc_html(strtoupper($entry['level'])); ?>
                            </span>
                        </td>
                        <td class="sentinel-log-col-channel">
                            <code><?php echo esc_html($entry['channel']); ?></code>
                        </td>
                        <td class="sentinel-log-col-count">
                            <span class="sentinel-log-count"><?php echo esc_html(number_format($entry['count'])); ?></span>
                        </td>
                        <td class="sentinel-log-col-time">
                            <span class="sentinel-log-time"><?php echo esc_html($entry['last_seen']); ?></span>
                        </td>
                    </tr>
                    <tr class="sentinel-log-row--<?php echo esc_attr($entry['level']); ?> sentinel-log-row-message">
                        <td colspan="4" class="sentinel-log-col-message">
                            <span class="sentinel-log-message"><?php echo esc_html($entry['last_message']); ?></span>
                        </td>
                    </tr>
                    </tbody>
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

        // Flush any buffered entries so the page always shows the latest data.
        if (function_exists('wp_log_flush')) {
            wp_log_flush();
        }

        $cleared = isset($_GET['cleared']) && $_GET['cleared'] === '1';
        $loggerDeployed = class_exists('Sentinel_Logger');
        $tableExists    = self::tableExists();
        $data           = self::getAggregateData();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Log table cleared successfully.', 'sentinel'); ?></p>
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
                    <h2><?php esc_html_e('Summary', 'sentinel'); ?></h2>
                    <div class="sentinel-log-actions">
                        <label class="sentinel-auto-refresh-toggle" for="sentinel-auto-refresh">
                            <span class="sentinel-toggle-label"><?php esc_html_e('Auto-refresh', 'sentinel'); ?></span>
                            <span class="sentinel-toggle-switch">
                                <input type="checkbox" id="sentinel-auto-refresh" />
                                <span class="sentinel-toggle-slider"></span>
                            </span>
                        </label>
                        <button type="button" class="button" id="sentinel-refresh-log">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php esc_html_e('Refresh', 'sentinel'); ?>
                        </button>
                        <?php if ($tableExists && $data['total_rows'] > 0): ?>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field(self::CLEAR_ACTION, '_sentinel_nonce'); ?>
                                <button type="submit"
                                        name="sentinel_clear_log"
                                        value="1"
                                        class="button button-secondary sentinel-clear-btn"
                                        onclick="return confirm('<?php echo esc_attr__('Are you sure you want to clear all log entries? This cannot be undone.', 'sentinel'); ?>');">
                                    <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-top: -2px;"></span>
                                    <?php esc_html_e('Clear Log', 'sentinel'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="sentinel-log-aggregate-container">
                    <?php self::renderAggregateTable(); ?>
                </div>
                <br>
                <?php if ($data['table_name']): ?>
                    <p class="description sentinel-table-name">
                        <?php esc_html_e('Database table:', 'sentinel'); ?>
                        <code><?php echo esc_html($data['table_name']); ?></code>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
