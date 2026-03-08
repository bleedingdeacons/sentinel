<?php

declare(strict_types=1);

namespace Sentinel\Admin;

use function add_action;
use function current_user_can;
use function esc_html;
use function esc_html__;
use function is_plugin_active;
use function wp_enqueue_style;

/**
 * Class DashboardWidget
 *
 * Registers a WordPress admin dashboard widget that displays the
 * version and activation status of monitored plugins.
 */
class DashboardWidget
{
    private const WIDGET_ID = 'sentinel_plugin_status';

    /**
     * Plugin registry - each entry defines a monitored plugin.
     *
     * @var array<string, array{file: string, constant: string, label: string}>
     */
    private const PLUGINS = [
        'unity' => [
            'file'     => 'unity/Unity.php',
            'constant' => 'UNITY_VERSION',
            'label'    => 'Unity',
        ],
        'tsml-for-unity' => [
            'file'     => 'tsml-for-unity/tsml-for-unity.php',
            'constant' => 'TSML_FOR_UNITY_VERSION',
            'label'    => 'TSML for Unity',
        ],
        'scrutiny' => [
            'file'     => 'scrutiny/Scrutiny.php',
            'constant' => 'SCRUTINY_VERSION',
            'label'    => 'Scrutiny',
        ],
        'integrity' => [
            'file'     => 'integrity/integrity.php',
            'constant' => 'INTEGRITY_VERSION',
            'label'    => 'Integrity',
        ],
        'reconcile' => [
            'file'     => 'reconcile/reconcile.php',
            'constant' => 'RECONCILE_VERSION',
            'label'    => 'Reconcile',
        ],
        'concordance' => [
            'file'     => 'concordance/Concordance.php',
            'constant' => 'CONCORDANCE_VERSION',
            'label'    => 'Concordance',
        ],
        'amber' => [
            'file'     => 'amber/Amber.php',
            'constant' => 'AMBER_VERSION',
            'label'    => 'Amber',
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
    }

    /**
     * Enqueue widget-specific styles on the dashboard page only.
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
            esc_html__('Intergroup Plugin Status', 'sentinel'),
            [self::class, 'render']
        );
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

        // Overall health: healthy only if every plugin is active
        $allActive    = true;
        $anyInstalled = false;
        foreach ($plugins as $info) {
            if (!$info['active']) {
                $allActive = false;
            }
            if ($info['installed']) {
                $anyInstalled = true;
            }
        }

        if ($allActive) {
            $overallHealth = 'healthy';
            $overallLabel  = __('All Systems Operational', 'sentinel');
        } elseif ($anyInstalled) {
            $overallHealth = 'warn';
            $overallLabel  = __('Attention Required', 'sentinel');
        } else {
            $overallHealth = 'error';
            $overallLabel  = __('Plugins Missing', 'sentinel');
        }

        ?>
        <div class="sentinel-widget">
            <!-- Overall Status Header -->
            <div class="sentinel-status-header sentinel-status--<?php echo esc_attr($overallHealth); ?>">
                <span class="sentinel-status-indicator"></span>
                <span class="sentinel-status-label">
                    <?php echo esc_html($overallLabel); ?>
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
                                <?php if ($info['installed']): ?>
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
            // Show help text for any plugins that need attention
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
        </div>
        <?php
    }

    /**
     * Gather status information for a single plugin.
     *
     * @param array{file: string, constant: string, label: string} $definition
     * @return array{installed: bool, active: bool, version: string, label: string}
     */
    private static function getPluginStatus(array $definition): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = file_exists(WP_PLUGIN_DIR . '/' . $definition['file']);
        $active    = is_plugin_active($definition['file']);
        $version   = defined($definition['constant']) ? constant($definition['constant']) : '';

        return [
            'installed' => $installed,
            'active'    => $active,
            'version'   => $version,
            'label'     => $definition['label'],
        ];
    }
}
