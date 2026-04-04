<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function current_user_can;
use function esc_html;
use function esc_html__;
use function get_plugin_data;
use function is_plugin_active;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;

/**
 * Class DashboardWidget
 *
 * Registers a WordPress admin dashboard widget that displays the
 * version and activation status of monitored plugins.
 *
 * Includes an AJAX endpoint so the widget can refresh itself
 * without a full page reload.
 */
class DashboardWidget
{
    private const WIDGET_ID   = 'sentinel_plugin_status';
    private const AJAX_ACTION = 'sentinel_refresh_status';

    /**
     * Plugin registry - each entry defines a monitored plugin.
     *
     * The 'file' path is relative to WP_PLUGIN_DIR and must match
     * the value WordPress stores in the active_plugins option.
     *
     * @var array<string, array{file: string, label: string}>
     */
    private const PLUGINS = [
        'unity' => [
            'file'  => 'unity/Unity.php',
            'label' => 'Unity',
        ],
        'tsml-for-unity' => [
            'file'  => 'tsml-for-unity/tsml-for-unity.php',
            'label' => 'TSML for Unity',
        ],
        'scrutiny' => [
            'file'  => 'scrutiny/Scrutiny.php',
            'label' => 'Scrutiny',
        ],
        'amber' => [
            'file'  => 'amber/Amber.php',
            'label' => 'Amber',
        ],
        'integrity' => [
            'file'  => 'integrity/integrity.php',
            'label' => 'Integrity',
        ],
        'reconcile' => [
            'file'  => 'reconcile/reconcile.php',
            'label' => 'Reconcile',
        ],
        'concordance' => [
            'file'  => 'concordance/Concordance.php',
            'label' => 'Concordance',
        ],
    ];

    /**
     * Initialize dashboard widget hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxRefresh']);
    }

    /**
     * Enqueue widget-specific styles and the refresh script on the dashboard.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'index.php') {
            return;
        }

        wp_enqueue_style(
            'sentinel-dashboard',
            SENTINEL_PLUGIN_URL . 'assets/dashboard.css',
            [],
            SENTINEL_VERSION
        );

        wp_enqueue_script(
            'sentinel-dashboard',
            SENTINEL_PLUGIN_URL . 'assets/dashboard.js',
            [],
            SENTINEL_VERSION,
            true
        );

        wp_localize_script('sentinel-dashboard', 'sentinelAjax', [
            'url'      => admin_url('admin-ajax.php'),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce(self::AJAX_ACTION),
            'interval' => 5, // seconds between polls
        ]);
    }

    /**
     * Register the dashboard widget with WordPress.
     *
     * @return void
     */
    public static function register(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            esc_html__('Sentinel Status', 'sentinel'),
            [self::class, 'render']
        );
    }

    /**
     * AJAX handler - returns the widget HTML fragment.
     *
     * @return void
     */
    public static function ajaxRefresh(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        ob_start();
        self::render();
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Render the dashboard widget content.
     *
     * @return void
     */
    public static function render(): void
    {
        $plugins = [];
        foreach (self::PLUGINS as $key => $definition) {
            $plugins[$key] = self::getPluginStatus($definition);
        }

        // Overall health:
        //   error   – any plugin is not installed
        //   warn    – all installed but some inactive
        //   healthy – every plugin installed and active
        $anyMissing  = false;
        $anyInactive = false;
        foreach ($plugins as $info) {
            if (!$info['installed']) {
                $anyMissing = true;
            } elseif (!$info['active']) {
                $anyInactive = true;
            }
        }

        if ($anyMissing) {
            $overallHealth = 'error';
            $overallLabel  = __('Plugins Missing', 'sentinel');
        } elseif ($anyInactive) {
            $overallHealth = 'warn';
            $overallLabel  = __('Attention Required', 'sentinel');
        } else {
            $overallHealth = 'healthy';
            $overallLabel  = __('All Systems Operational', 'sentinel');
        }

        ?>
        <div class="sentinel-widget">
            <!-- Overall Status Header -->
            <div class="sentinel-status-header sentinel-status--<?php echo esc_attr($overallHealth); ?>">
                <span class="sentinel-status-indicator"></span>
                <span class="sentinel-status-label">
                    <?php echo esc_html($overallLabel); ?>
                </span>
                <span class="sentinel-version">
                    Sentinel v<?php echo esc_html(SENTINEL_VERSION); ?>
                </span>
            </div>

            <!-- Plugin Status Table -->
            <table class="sentinel-info-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Plugin', 'sentinel'); ?></th>
                        <th><?php esc_html_e('Version', 'sentinel'); ?></th>
                        <th><?php esc_html_e('Status', 'sentinel'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $info): ?>
                        <tr>
                            <td class="sentinel-plugin-name"><?php echo esc_html($info['label']); ?></td>
                            <td>
                                <?php if ($info['version']): ?>
                                    <code><?php echo esc_html($info['version']); ?></code>
                                <?php else: ?>
                                    <span class="sentinel-na"><?php esc_html_e('N/A', 'sentinel'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($info['active']): ?>
                                    <span class="sentinel-badge sentinel-badge--active">
                                        <?php esc_html_e('Active', 'sentinel'); ?>
                                    </span>
                                <?php elseif ($info['installed']): ?>
                                    <span class="sentinel-badge sentinel-badge--inactive">
                                        <?php esc_html_e('Inactive', 'sentinel'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sentinel-badge sentinel-badge--missing">
                                        <?php esc_html_e('Not Installed', 'sentinel'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $inactive = array_filter($plugins, fn($p) => $p['installed'] && !$p['active']);
            $missing  = array_filter($plugins, fn($p) => !$p['installed']);

            if (!empty($inactive)): ?>
                <p class="sentinel-help">
                    <?php
                    $names = implode(', ', array_column($inactive, 'label'));
                    printf(
                        esc_html__('%s is installed but not active. Activate from the Plugins page.', 'sentinel'),
                        esc_html($names)
                    );
                    ?>
                </p>
                <div class="sentinel-links">
                    <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-primary button-small">
                        <?php esc_html_e('Go to Plugins', 'sentinel'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($missing)): ?>
                <p class="sentinel-help">
                    <?php
                    $names = implode(', ', array_column($missing, 'label'));
                    printf(
                        esc_html__('%s is not installed.', 'sentinel'),
                        esc_html($names)
                    );
                    ?>
                </p>
            <?php endif; ?>

            <!-- Log Summary link -->
            <div class="sentinel-widget-footer">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sentinel-logs')); ?>" class="button button-secondary button-small">
                    <span class="dashicons dashicons-list-view" style="vertical-align: middle; margin-top: -2px; font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php esc_html_e('View Log Summary', 'sentinel'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Gather status information for a single plugin.
     *
     * Reads the version from the plugin file header (via get_plugin_data)
     * so it is available even when the plugin is inactive.
     *
     * @param array{file: string, label: string} $definition
     * @return array{installed: bool, active: bool, version: string, label: string}
     */
    private static function getPluginStatus(array $definition): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $fullPath  = WP_PLUGIN_DIR . '/' . $definition['file'];
        $installed = file_exists($fullPath);
        $active    = is_plugin_active($definition['file']);
        $version   = '';

        // Read version from the file header - works whether active or not
        if ($installed && function_exists('get_plugin_data')) {
            $data    = get_plugin_data($fullPath, false, false);
            $version = $data['Version'] ?? '';
        }

        return [
            'installed' => $installed,
            'active'    => $active,
            'version'   => $version,
            'label'     => $definition['label'],
        ];
    }
}
