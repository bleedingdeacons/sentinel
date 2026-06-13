<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Plugin;

use function add_action;
use function add_settings_error;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function settings_errors;
use function wp_die;
use function wp_json_encode;
use function wp_nonce_field;

/**
 * Class UnityControlPage
 *
 * Sentinel submenu page that controls the Unity plugin's UNITY_KILL kill
 * switch by writing a define() line to wp-config.php.
 *
 * Two actions are exposed:
 *   - Disable Unity:  write `define( 'UNITY_KILL', true );` to wp-config.php
 *   - Enable Unity:   remove the UNITY_KILL define from wp-config.php
 *
 * When UNITY_KILL is true Unity short-circuits during boot — no constants,
 * no autoloader, no hooks — and every dependent plugin (TSML for Unity,
 * Scrutiny, Amber, Integrity, Reconcile) stands down because the
 * `unity/loaded` action never fires.
 *
 * Changes take effect on the next request, since wp-config.php is included
 * before plugins on the request that performs the write.
 */
class UnityControlPage
{
    private const PAGE_SLUG  = 'sentinel-unity-control';
    private const MENU_TITLE = 'Unity Control';
    private const PAGE_TITLE = 'Unity Control';
    private const CAPABILITY = 'manage_options';

    private const KILL_SWITCH_CONSTANT = 'UNITY_KILL';

    /**
     * Environment flag constant. When defined as false in wp-config.php,
     * developer-only UI is exposed across plugins (e.g. Amber's Developer
     * submenu). When undefined or true, the site is treated as production
     * and that UI is hidden. The toggle below removes the define when
     * switching to production, so wp-config.php only carries the line when
     * the site is in the non-default (development) state.
     */
    private const PRODUCTION_CONSTANT = 'PRODUCTION';

    /**
     * Marker comment placed above the UNITY_KILL define when it is written
     * to wp-config.php. Distinct from Sentinel's logger-config marker so
     * that the two groups stay visually separate in wp-config.php.
     */
    private const WP_CONFIG_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';

    /**
     * Marker comment for the PRODUCTION define. Kept distinct from the
     * Unity kill-switch marker so the two groups stay visually separate.
     */
    private const PRODUCTION_MARKER = '/* Environment Flag (managed by Sentinel) */';

    private const NONCE_ACTION = 'sentinel_unity_control';
    private const NONCE_FIELD  = '_sentinel_unity_nonce';

    /**
     * Plugins that depend on Unity and will stop working when the kill
     * switch is engaged. Used in the warning copy on the page.
     *
     * Concordance is intentionally not in this list: it does not depend
     * on Unity.
     *
     * @var list<string>
     */
    private const DEPENDENT_PLUGINS = [
        'TSML for Unity',
        'Scrutiny',
        'Amber',
        'Integrity',
        'Reconcile',
        'Reach',
        'Stalwart',
        'Steward',
        'Trusted',
        'Trumpet',
    ];

    /** @var string The hook suffix returned by add_submenu_page(). */
    private static string $hookSuffix = '';

    /**
     * Set to true after a successful enable/disable in handleSave(), so
     * renderPage() can emit a small script that reloads the page (and
     * thereby picks up the new runtime state) after briefly showing the
     * success notice.
     */
    private static bool $justChanged = false;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_init', [self::class, 'handleSave']);
    }

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

    // ── Save Handler ───────────────────────────────────────────────────────────

    /**
     * Handle form submission from the Unity Control page.
     *
     * Two actions are accepted via the `sentinel_unity_action` POST field:
     *   - 'disable' — write UNITY_KILL=true to wp-config.php (requires the
     *                 'sentinel_unity_confirm' checkbox)
     *   - 'enable'  — remove the UNITY_KILL define from wp-config.php
     */
    public static function handleSave(): void
    {
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $action = isset($_POST['sentinel_unity_action'])
            ? (string) $_POST['sentinel_unity_action']
            : '';

        if (!in_array($action, ['disable', 'enable', 'production_on', 'production_off'], true)) {
            return;
        }

        if (!SettingsPage::isWpConfigWritable()) {
            add_settings_error(
                'sentinel_unity_control',
                'wp_config_not_writable',
                __('wp-config.php is not writable. The kill switch could not be changed from this page. See the manual instructions below.', 'sentinel'),
                'error'
            );
            return;
        }

        if ($action === 'disable') {
            // Require explicit confirmation, since this disables six
            // dependent plugins.
            if (empty($_POST['sentinel_unity_confirm'])) {
                add_settings_error(
                    'sentinel_unity_control',
                    'confirm_required',
                    __('You must tick the confirmation checkbox before disabling Unity.', 'sentinel'),
                    'error'
                );
                return;
            }

            if (self::writeKillSwitch(true)) {
                self::$justChanged = true;
                add_settings_error(
                    'sentinel_unity_control',
                    'kill_switch_enabled',
                    __('Unity has been disabled. The change takes effect on the next page load. Dependent plugins (TSML for Unity, Scrutiny, Amber, Integrity, Reconcile, Reach, Stalwart, Steward, Trusted, Trumpet) will stand down.', 'sentinel'),
                    'updated'
                );
            } else {
                add_settings_error(
                    'sentinel_unity_control',
                    'write_failed',
                    __('Failed to write the kill switch to wp-config.php. The file may have become unwritable.', 'sentinel'),
                    'error'
                );
            }
            return;
        }

        if ($action === 'enable') {
            if (self::removeKillSwitch()) {
                self::$justChanged = true;
                add_settings_error(
                    'sentinel_unity_control',
                    'kill_switch_removed',
                    __('Unity has been re-enabled. The change takes effect on the next page load.', 'sentinel'),
                    'updated'
                );
            } else {
                add_settings_error(
                    'sentinel_unity_control',
                    'remove_failed',
                    __('Failed to remove the kill switch from wp-config.php. The file may have become unwritable.', 'sentinel'),
                    'error'
                );
            }
            return;
        }

        // ── PRODUCTION toggle ──
        // 'production_off' writes define( 'PRODUCTION', false ); to expose
        // developer UI. 'production_on' removes the define so the runtime
        // default (true) takes over, keeping wp-config.php clean when in
        // the default production state.

        if ($action === 'production_off') {
            if (self::writeProduction(false)) {
                self::$justChanged = true;
                add_settings_error(
                    'sentinel_unity_control',
                    'production_off',
                    __('Production mode disabled. Developer-only UI will be exposed on the next page load.', 'sentinel'),
                    'updated'
                );
            } else {
                add_settings_error(
                    'sentinel_unity_control',
                    'production_write_failed',
                    __('Failed to write the PRODUCTION constant to wp-config.php. The file may have become unwritable.', 'sentinel'),
                    'error'
                );
            }
            return;
        }

        // action === 'production_on'
        if (self::removeProduction()) {
            self::$justChanged = true;
            add_settings_error(
                'sentinel_unity_control',
                'production_on',
                __('Production mode enabled. Developer-only UI will be hidden on the next page load.', 'sentinel'),
                'updated'
            );
        } else {
            add_settings_error(
                'sentinel_unity_control',
                'production_remove_failed',
                __('Failed to remove the PRODUCTION constant from wp-config.php. The file may have become unwritable.', 'sentinel'),
                'error'
            );
        }
    }

    // ── wp-config.php Writers ──────────────────────────────────────────────────

    /**
     * Write `define( 'UNITY_KILL', <bool> );` to wp-config.php under the
     * Unity-specific marker comment.
     *
     * Replaces the existing line if present; inserts a new marker + define
     * after the opening <?php tag otherwise. Built on top of the atomic
     * write primitive shared with SettingsPage.
     */
    private static function writeKillSwitch(bool $value): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $defineLine = "define( '" . self::KILL_SWITCH_CONSTANT . "', " . ($value ? 'true' : 'false') . " );";

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::KILL_SWITCH_CONSTANT, '/')
            . '[\'"]\s*,\s*.+?\)\s*;/m';

        if (preg_match($pattern, $contents)) {
            // Replace existing line in-place.
            $updated = preg_replace($pattern, $defineLine, $contents, 1);
        } elseif (str_contains($contents, self::WP_CONFIG_MARKER)) {
            // Marker is present from a previous run — insert under it.
            $updated = preg_replace(
                '/' . preg_quote(self::WP_CONFIG_MARKER, '/') . '/m',
                self::WP_CONFIG_MARKER . "\n" . $defineLine,
                $contents,
                1
            );
        } else {
            // Fresh insert after the opening <?php tag.
            $insertBlock = "\n" . self::WP_CONFIG_MARKER . "\n" . $defineLine . "\n";
            $pos = strpos($contents, '<?php');
            if ($pos !== false) {
                $eol = strpos($contents, "\n", $pos);
                if ($eol !== false) {
                    $updated = substr_replace($contents, $insertBlock, $eol + 1, 0);
                } else {
                    $updated = $contents . $insertBlock;
                }
            } else {
                // Defensive fallback — wp-config.php should always start
                // with <?php.
                $updated = "<?php\n" . $insertBlock . $contents;
            }
        }

        if ($updated === null || $updated === $contents) {
            return $updated !== null; // No-op success vs. preg failure.
        }

        return self::atomicWrite($path, $updated);
    }

    /**
     * Remove `define( 'UNITY_KILL', ... );` and its marker comment from
     * wp-config.php. Idempotent — returns true if there was nothing to
     * remove.
     */
    private static function removeKillSwitch(): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::KILL_SWITCH_CONSTANT, '/')
            . '[\'"]\s*,\s*.+?\)\s*;\s*\n?/m';
        $updated = preg_replace($pattern, '', $contents);

        if ($updated === null) {
            return false;
        }

        // Remove the marker comment if it now stands alone.
        $updated = str_replace(self::WP_CONFIG_MARKER . "\n", '', $updated);
        $updated = str_replace(self::WP_CONFIG_MARKER, '', $updated);

        // Tidy excessive blank lines left by the removal.
        $updated = preg_replace("/\n{3,}/", "\n\n", $updated);

        if ($updated === $contents) {
            return true; // Nothing was set; nothing to do.
        }

        return self::atomicWrite($path, $updated);
    }

    /**
     * Atomic write: write to a temp file in the same directory, preserve
     * the original permissions, then rename into place.
     */
    private static function atomicWrite(string $path, string $contents): bool
    {
        $tmpPath = $path . '.sentinel-unity-tmp';
        if (file_put_contents($tmpPath, $contents) === false) {
            return false;
        }

        $perms = fileperms($path);
        if ($perms !== false) {
            chmod($tmpPath, $perms);
        }

        return rename($tmpPath, $path);
    }

    // ── State Inspection ───────────────────────────────────────────────────────

    /**
     * Whether the kill switch is currently active at runtime.
     *
     * Mirrors the check in unity/unity.php exactly — only the literal
     * boolean `true` engages the kill switch.
     */
    private static function isKilledAtRuntime(): bool
    {
        return defined(self::KILL_SWITCH_CONSTANT) && constant(self::KILL_SWITCH_CONSTANT) === true;
    }

    /**
     * Whether wp-config.php currently contains a UNITY_KILL define on
     * disk, irrespective of its value. Used to advise the operator whether
     * the "Enable" action will actually do anything.
     */
    private static function isDefinedInFile(): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::KILL_SWITCH_CONSTANT, '/')
            . '[\'"]\s*,/m';

        return preg_match($pattern, $contents) === 1;
    }

    // ── PRODUCTION wp-config.php Writers ───────────────────────────────────────

    /**
     * Write `define( 'PRODUCTION', <bool> );` to wp-config.php under the
     * PRODUCTION-specific marker comment. Mirrors writeKillSwitch().
     */
    private static function writeProduction(bool $value): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $defineLine = "define( '" . self::PRODUCTION_CONSTANT . "', " . ($value ? 'true' : 'false') . " );";

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::PRODUCTION_CONSTANT, '/')
            . '[\'"]\s*,\s*.+?\)\s*;/m';

        if (preg_match($pattern, $contents)) {
            $updated = preg_replace($pattern, $defineLine, $contents, 1);
        } elseif (str_contains($contents, self::PRODUCTION_MARKER)) {
            $updated = preg_replace(
                '/' . preg_quote(self::PRODUCTION_MARKER, '/') . '/m',
                self::PRODUCTION_MARKER . "\n" . $defineLine,
                $contents,
                1
            );
        } else {
            $insertBlock = "\n" . self::PRODUCTION_MARKER . "\n" . $defineLine . "\n";
            $pos = strpos($contents, '<?php');
            if ($pos !== false) {
                $eol = strpos($contents, "\n", $pos);
                if ($eol !== false) {
                    $updated = substr_replace($contents, $insertBlock, $eol + 1, 0);
                } else {
                    $updated = $contents . $insertBlock;
                }
            } else {
                $updated = "<?php\n" . $insertBlock . $contents;
            }
        }

        if ($updated === null || $updated === $contents) {
            return $updated !== null;
        }

        return self::atomicWrite($path, $updated);
    }

    /**
     * Remove the PRODUCTION define and its marker from wp-config.php.
     * Idempotent. Mirrors removeKillSwitch().
     */
    private static function removeProduction(): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::PRODUCTION_CONSTANT, '/')
            . '[\'"]\s*,\s*.+?\)\s*;\s*\n?/m';
        $updated = preg_replace($pattern, '', $contents);

        if ($updated === null) {
            return false;
        }

        $updated = str_replace(self::PRODUCTION_MARKER . "\n", '', $updated);
        $updated = str_replace(self::PRODUCTION_MARKER, '', $updated);

        $updated = preg_replace("/\n{3,}/", "\n\n", $updated);

        if ($updated === $contents) {
            return true;
        }

        return self::atomicWrite($path, $updated);
    }

    /**
     * Whether the site is currently in production mode at runtime.
     *
     * PRODUCTION defaults to true when not defined, so undefined ≡ production.
     */
    private static function isProductionAtRuntime(): bool
    {
        return !defined(self::PRODUCTION_CONSTANT) || constant(self::PRODUCTION_CONSTANT) === true;
    }

    /**
     * Whether wp-config.php currently contains a PRODUCTION define on disk,
     * irrespective of its value.
     */
    private static function isProductionDefinedInFile(): bool
    {
        $path = SettingsPage::wpConfigPath();
        if ($path === null || !is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $pattern = '/^\s*define\s*\(\s*[\'"]'
            . preg_quote(self::PRODUCTION_CONSTANT, '/')
            . '[\'"]\s*,/m';

        return preg_match($pattern, $contents) === 1;
    }

    // ── Page Rendering ─────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        $killed         = self::isKilledAtRuntime();
        $inFile         = self::isDefinedInFile();
        $isProduction   = self::isProductionAtRuntime();
        $prodInFile     = self::isProductionDefinedInFile();
        $writable       = SettingsPage::isWpConfigWritable();
        $configPath     = SettingsPage::wpConfigPath();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php settings_errors('sentinel_unity_control'); ?>

            <?php if (self::$justChanged): ?>
                <?php
                // After a successful enable/disable we briefly show the
                // success notice and then reload. The reload is essential:
                // the UNITY_KILL define is read from wp-config.php, which
                // is included before plugins, so the page rendered by the
                // same request that wrote it still reflects the *old*
                // runtime state. A GET reload picks up the new state and
                // avoids the browser's POST-resubmit prompt.
                $reloadUrl = esc_url(
                    admin_url('admin.php?page=' . self::PAGE_SLUG)
                );
                ?>
                <script>
                (function () {
                    setTimeout(function () {
                        window.location.replace(<?php echo wp_json_encode($reloadUrl); ?>);
                    }, 1500);
                })();
                </script>
            <?php endif; ?>

            <p>
                <?php
                printf(
                    esc_html__('Unity supports a %s kill switch defined in %s. When set to %s, Unity short-circuits during boot — no constants, no autoloader, no hooks — and every dependent plugin stands down.', 'sentinel'),
                    '<code>UNITY_KILL</code>',
                    '<code>wp-config.php</code>',
                    '<code>true</code>'
                );
                ?>
            </p>

            <!-- Current State -->
            <h2><?php esc_html_e('Current State', 'sentinel'); ?></h2>
            <table class="widefat striped" style="max-width:720px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width:40%;">
                            <?php esc_html_e('Runtime status', 'sentinel'); ?>
                        </th>
                        <td>
                            <?php if ($killed): ?>
                                <strong style="color:#d63638;">
                                    <?php esc_html_e('Disabled (kill switch engaged)', 'sentinel'); ?>
                                </strong>
                            <?php else: ?>
                                <strong style="color:#00a32a;">
                                    <?php esc_html_e('Enabled', 'sentinel'); ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php
                            printf(
                                esc_html__('%s in wp-config.php', 'sentinel'),
                                '<code>UNITY_KILL</code>'
                            );
                            ?>
                        </th>
                        <td>
                            <?php if ($inFile): ?>
                                <?php esc_html_e('Defined', 'sentinel'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Not defined', 'sentinel'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('wp-config.php location', 'sentinel'); ?></th>
                        <td>
                            <?php if ($configPath !== null): ?>
                                <code><?php echo esc_html($configPath); ?></code>
                            <?php else: ?>
                                <em><?php esc_html_e('Not found', 'sentinel'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Writable by web server', 'sentinel'); ?></th>
                        <td>
                            <?php if ($writable): ?>
                                <?php esc_html_e('Yes', 'sentinel'); ?>
                            <?php else: ?>
                                <strong><?php esc_html_e('No', 'sentinel'); ?></strong>
                                — <?php esc_html_e('toggle controls below are unavailable; use the manual instructions.', 'sentinel'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($killed && !$inFile): ?>
                <div class="notice notice-warning inline" style="margin-top:1em;">
                    <p>
                        <?php
                        printf(
                            esc_html__('%s is currently true at runtime but no matching define was found in %s. The constant is being set somewhere else (a must-use plugin, a server-level config, or another file). The controls below cannot manage it.', 'sentinel'),
                            '<code>UNITY_KILL</code>',
                            '<code>wp-config.php</code>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Disable / Enable controls -->
            <h2><?php esc_html_e('Toggle Unity', 'sentinel'); ?></h2>

            <?php if ($killed): ?>
                <p>
                    <?php esc_html_e('Unity is currently disabled. Re-enabling will remove the kill switch from wp-config.php and Unity will boot normally on the next page load.', 'sentinel'); ?>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <input type="hidden" name="sentinel_unity_action" value="enable">
                    <p>
                        <button type="submit" class="button button-primary" <?php echo $writable ? '' : 'disabled'; ?>>
                            <?php esc_html_e('Enable Unity', 'sentinel'); ?>
                        </button>
                    </p>
                </form>
            <?php else: ?>
                <div class="notice notice-error inline" style="margin:1em 0;">
                    <p>
                        <strong><?php esc_html_e('Disabling Unity will also disable:', 'sentinel'); ?></strong>
                        <?php echo esc_html(implode(', ', self::DEPENDENT_PLUGINS)); ?>.
                        <?php esc_html_e('These plugins depend on Unity having booted, and will stand down until the kill switch is cleared.', 'sentinel'); ?>
                    </p>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <input type="hidden" name="sentinel_unity_action" value="disable">
                    <p>
                        <label>
                            <input type="checkbox" name="sentinel_unity_confirm" value="1">
                            <?php esc_html_e('I understand this will disable Unity and every plugin that depends on it.', 'sentinel'); ?>
                        </label>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary" <?php echo $writable ? '' : 'disabled'; ?>>
                            <?php esc_html_e('Disable Unity', 'sentinel'); ?>
                        </button>
                    </p>
                </form>
            <?php endif; ?>

            <!-- Production Mode toggle -->
            <h2><?php esc_html_e('Production Mode', 'sentinel'); ?></h2>
            <p>
                <?php
                printf(
                    esc_html__('The %s constant controls whether developer-only UI (such as Amber\'s Developer submenu) is exposed. Defaults to %s when not defined.', 'sentinel'),
                    '<code>PRODUCTION</code>',
                    '<code>true</code>'
                );
                ?>
            </p>

            <table class="widefat striped" style="max-width:720px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width:40%;">
                            <?php esc_html_e('Current mode', 'sentinel'); ?>
                        </th>
                        <td>
                            <?php if ($isProduction): ?>
                                <strong style="color:#00a32a;">
                                    <?php esc_html_e('Production (developer UI hidden)', 'sentinel'); ?>
                                </strong>
                            <?php else: ?>
                                <strong style="color:#d63638;">
                                    <?php esc_html_e('Development (developer UI exposed)', 'sentinel'); ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php
                            printf(
                                esc_html__('%s in wp-config.php', 'sentinel'),
                                '<code>PRODUCTION</code>'
                            );
                            ?>
                        </th>
                        <td>
                            <?php if ($prodInFile): ?>
                                <?php esc_html_e('Defined', 'sentinel'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Not defined (using default: true)', 'sentinel'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$isProduction && !$prodInFile): ?>
                <div class="notice notice-warning inline" style="margin-top:1em;">
                    <p>
                        <?php
                        printf(
                            esc_html__('%s is currently false at runtime but no matching define was found in %s. The constant is being set somewhere else (a must-use plugin, a server-level config, or another file). The controls below cannot manage it.', 'sentinel'),
                            '<code>PRODUCTION</code>',
                            '<code>wp-config.php</code>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($isProduction): ?>
                <p>
                    <?php esc_html_e('Switching to development mode will write define( \'PRODUCTION\', false ); to wp-config.php and expose developer-only UI on the next page load.', 'sentinel'); ?>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <input type="hidden" name="sentinel_unity_action" value="production_off">
                    <p>
                        <button type="submit" class="button" <?php echo $writable ? '' : 'disabled'; ?>>
                            <?php esc_html_e('Switch to Development Mode', 'sentinel'); ?>
                        </button>
                    </p>
                </form>
            <?php else: ?>
                <p>
                    <?php esc_html_e('Switching back to production mode will remove the PRODUCTION define from wp-config.php (the runtime default is true). Developer-only UI will be hidden on the next page load.', 'sentinel'); ?>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <input type="hidden" name="sentinel_unity_action" value="production_on">
                    <p>
                        <button type="submit" class="button button-primary" <?php echo $writable ? '' : 'disabled'; ?>>
                            <?php esc_html_e('Switch to Production Mode', 'sentinel'); ?>
                        </button>
                    </p>
                </form>
            <?php endif; ?>

            <!-- Manual instructions, always shown for reference -->
            <h2><?php esc_html_e('Manual Configuration', 'sentinel'); ?></h2>
            <p>
                <?php
                printf(
                    esc_html__('You can also manage the kill switch by editing %s directly. Add or update one of these lines before the %s comment:', 'sentinel'),
                    '<code>wp-config.php</code>',
                    "<code>/* That's all, stop editing! */</code>"
                );
                ?>
            </p>
            <pre style="padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;max-width:720px;"><code><?php echo esc_html(self::WP_CONFIG_MARKER); ?>
define( 'UNITY_KILL', true );  // Disable Unity
// define( 'UNITY_KILL', false ); // Or remove the line entirely to re-enable

<?php echo esc_html(self::PRODUCTION_MARKER); ?>
define( 'PRODUCTION', false ); // Development mode (expose developer UI)
// Remove the line entirely to return to production mode (the default)</code></pre>
        </div>
        <?php
    }
}
