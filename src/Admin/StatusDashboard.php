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

use Sentinel\Admin\SettingsPage;

/**
 * Class StatusDashboard
 *
 * Registers a WordPress admin dashboard widget that displays the
 * version and activation status of monitored plugins.
 *
 * Includes an AJAX endpoint so the widget can refresh itself
 * without a full page reload.
 */
class StatusDashboard
{
    private const WIDGET_ID   = 'sentinel_plugin_status';
    private const AJAX_ACTION = 'sentinel_refresh_status';

    /**
     * Plugin keys (resolved against whatever plugins are being monitored)
     * whose functionality depends on Unity having booted. When UNITY_KILL
     * is engaged, Unity short-circuits before firing the `unity/loaded`
     * action, so these plugins cannot function — regardless of their own
     * active state.
     *
     * Concordance is intentionally not in this list: it does not depend
     * on Unity.
     *
     * @var list<string>
     */
    private const UNITY_DEPENDENTS = [
        'tsml-for-unity',
        'scrutiny',
        'amber',
        'integrity',
        'reconcile',
        'reach',
        'stalwart',
        'steward',
        'trusted',
        'trumpet',
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
        $mandatoryDefs = SettingsPage::getMandatoryPlugins();
        $optionalDefs  = SettingsPage::getOptionalPlugins();

        // Track which keys are mandatory vs optional so the stability
        // indicator and help text only consider mandatory entries.
        $mandatoryKeys = [];
        $optionalKeys  = [];

        $plugins = [];

        // Mandatory plugins are always shown, regardless of install state.
        foreach ($mandatoryDefs as $key => $definition) {
            $plugins[$key]   = self::getPluginStatus($definition);
            $mandatoryKeys[] = $key;
        }

        // Optional plugins only appear in the table when actually installed.
        // A key already claimed by the mandatory list is ignored here so we
        // don't double-count it.
        foreach ($optionalDefs as $key => $definition) {
            if (isset($plugins[$key])) {
                continue;
            }
            $status = self::getPluginStatus($definition);
            if (!$status['installed']) {
                continue;
            }
            $plugins[$key]  = $status;
            $optionalKeys[] = $key;
        }

        // Unity's UNITY_KILL kill switch short-circuits its boot, so even
        // though WordPress still reports it as active, none of its hooks fire
        // and every dependent plugin stands down. Flag it on the Unity row.
        $unityKilled = defined('UNITY_KILL') && UNITY_KILL === true;
        if (isset($plugins['unity'])) {
            $plugins['unity']['killSwitch'] = $unityKilled && $plugins['unity']['installed'];
        }

        // When the kill switch is engaged, mark every Unity-dependent plugin
        // we're actually monitoring as unavailable so the row reflects what's
        // actually happening on the site rather than WordPress's view of the
        // active_plugins list.
        if ($unityKilled) {
            foreach (self::UNITY_DEPENDENTS as $dependentKey) {
                if (isset($plugins[$dependentKey]) && $plugins[$dependentKey]['installed']) {
                    $plugins[$dependentKey]['unavailable'] = true;
                }
            }
        }

        // Overall health is determined ONLY by mandatory plugins.
        // Optional plugins never affect the stability indicator.
        //   error   – Unity kill switch is set (and Unity is mandatory),
        //             or any mandatory plugin is not installed
        //   warn    – all mandatory plugins installed but some inactive
        //   healthy – every mandatory plugin installed and active
        $anyMissing  = false;
        $anyInactive = false;
        foreach ($mandatoryKeys as $key) {
            $info = $plugins[$key];
            if (!$info['installed']) {
                $anyMissing = true;
            } elseif (!$info['active']) {
                $anyInactive = true;
            }
        }

        $unityMandatory = in_array('unity', $mandatoryKeys, true);

        if ($unityMandatory && !empty($plugins['unity']['killSwitch'])) {
            // Take precedence: with Unity dormant, dependent plugins won't
            // function regardless of their own active/inactive state.
            $overallHealth = 'error';
            $overallLabel  = __('Unity Disabled — Kill Switch Active', 'sentinel');
        } elseif ($anyMissing) {
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
                        <th><?php esc_html_e('Status', 'sentinel'); ?></th>
                        <th><?php esc_html_e('Version', 'sentinel'); ?></th>
                        <th><?php esc_html_e('Build Date', 'sentinel'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $info): ?>
                        <tr>
                            <td class="sentinel-plugin-name"><?php echo esc_html($info['label']); ?></td>
                            <td>
                                <?php if (!empty($info['killSwitch'])): ?>
                                    <span class="sentinel-badge sentinel-badge--killed" title="<?php echo esc_attr__('UNITY_KILL is defined as true in wp-config.php', 'sentinel'); ?>">
                                        <?php esc_html_e('Disabled (Kill Switch)', 'sentinel'); ?>
                                    </span>
                                <?php elseif (!empty($info['unavailable'])): ?>
                                    <span class="sentinel-badge sentinel-badge--warn" title="<?php echo esc_attr__('Unity is disabled, so this plugin cannot function.', 'sentinel'); ?>">
                                        <?php esc_html_e('Unavailable', 'sentinel'); ?>
                                    </span>
                                <?php elseif ($info['active']): ?>
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
                            <td>
                                <?php if ($info['version']): ?>
                                    <code><?php echo esc_html($info['version']); ?></code>
                                <?php else: ?>
                                    <span class="sentinel-na"><?php esc_html_e('N/A', 'sentinel'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($info['buildDate']): ?>
                                    <span class="sentinel-build-date"><?php echo esc_html($info['buildDate']); ?></span>
                                <?php else: ?>
                                    <span class="sentinel-na"><?php esc_html_e('N/A', 'sentinel'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // When the kill switch is on, dependents shown as "Unavailable"
            // shouldn't also appear in the "X is installed but not active"
            // help block — that advice (activate from the Plugins page)
            // doesn't apply while Unity is dead.
            //
            // The help blocks only consider MANDATORY plugins: optional ones
            // are user-acknowledged "nice to have" entries and shouldn't
            // prompt the admin to act.
            $mandatoryPlugins = array_intersect_key($plugins, array_flip($mandatoryKeys));
            $inactive = array_filter(
                $mandatoryPlugins,
                fn($p) => $p['installed'] && !$p['active'] && empty($p['unavailable'])
            );
            $missing  = array_filter($mandatoryPlugins, fn($p) => !$p['installed']);
            ?>

            <?php if ($unityMandatory && !empty($plugins['unity']['killSwitch'])): ?>
                <p class="sentinel-help sentinel-help--alert">
                    <strong><?php esc_html_e('Unity is disabled.', 'sentinel'); ?></strong>
                    <?php
                    printf(
                        esc_html__(
                            '%1$s is defined as %2$s in %3$s, which prevents Unity from booting. Dependent plugins (TSML for Unity, Scrutiny, Amber, Integrity, Reconcile, Reach, Stalwart, Steward, Trusted, Trumpet) will not function until this is cleared.',
                            'sentinel'
                        ),
                        '<code>UNITY_KILL</code>',
                        '<code>true</code>',
                        '<code>wp-config.php</code>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($inactive)): ?>
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
     * Two optional keys are added by render() and are not populated here:
     *   - `killSwitch`  — true when Unity's UNITY_KILL is engaged (Unity row only)
     *   - `unavailable` — true for Unity-dependent plugins when UNITY_KILL is engaged
     *
     * @param array{file: string, label: string} $definition
     * @return array{installed: bool, active: bool, version: string, buildDate: string, label: string}
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
            $version = $data['Version'];
        }

        // Determine a "build date" for the plugin. Each monitored plugin is
        // expected to declare a "Build date:" line in the header block of its
        // readme.txt; we parse it from there. This works whether the plugin
        // is active or not, mirroring how the version is read above.
        $buildDate = '';
        if ($installed) {
            $buildDate = self::readBuildDateFromReadme(dirname($fullPath));
        }

        return [
            'installed' => $installed,
            'active'    => $active,
            'version'   => $version,
            'buildDate' => $buildDate,
            'label'     => $definition['label'],
        ];
    }

    /**
     * Read the "Build date" value from a plugin's readme.txt header.
     *
     * Looks for a line such as "Build date: 2026-05-31" within the header
     * block of the plugin's readme. Only the start of the file is read,
     * since the value lives in the header alongside Stable tag, etc.
     *
     * Returns an empty string when no readme is present or no build date
     * line is found, in which case the widget shows "N/A".
     *
     * @param string $pluginDir Absolute path to the plugin's directory.
     * @return string
     */
    private static function readBuildDateFromReadme(string $pluginDir): string
    {
        foreach (['readme.txt', 'README.txt'] as $name) {
            $readme = $pluginDir . '/' . $name;
            if (!is_readable($readme)) {
                continue;
            }

            // The build date lives in the header block at the top of the
            // file; read only the first chunk to avoid loading large readmes.
            $contents = file_get_contents($readme, false, null, 0, 8192);
            if ($contents === false) {
                continue;
            }

            if (preg_match('/^[ \t]*Build date[ \t]*:[ \t]*(.+?)[ \t]*$/mi', $contents, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }
}
