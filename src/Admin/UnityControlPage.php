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
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function settings_errors;
use function wp_die;
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
 * Scrutiny, Amber, Integrity, Reconcile, Concordance) stands down because
 * the `unity/loaded` action never fires.
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
     * Marker comment placed above the UNITY_KILL define when it is written
     * to wp-config.php. Distinct from Sentinel's logger-config marker so
     * that the two groups stay visually separate in wp-config.php.
     */
    private const WP_CONFIG_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';

    private const NONCE_ACTION = 'sentinel_unity_control';
    private const NONCE_FIELD  = '_sentinel_unity_nonce';

    /**
     * Plugins that depend on Unity and will stop working when the kill
     * switch is engaged. Used in the warning copy on the page.
     *
     * @var list<string>
     */
    private const DEPENDENT_PLUGINS = [
        'TSML for Unity',
        'Scrutiny',
        'Amber',
        'Integrity',
        'Reconcile',
        'Concordance',
    ];

    /** @var string The hook suffix returned by add_submenu_page(). */
    private static string $hookSuffix = '';

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

        if (!in_array($action, ['disable', 'enable'], true)) {
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
                add_settings_error(
                    'sentinel_unity_control',
                    'kill_switch_enabled',
                    __('Unity has been disabled. The change takes effect on the next page load. Dependent plugins (TSML for Unity, Scrutiny, Amber, Integrity, Reconcile, Concordance) will stand down.', 'sentinel'),
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

        // action === 'enable'
        if (self::removeKillSwitch()) {
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

    // ── Page Rendering ─────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        $killed     = self::isKilledAtRuntime();
        $inFile     = self::isDefinedInFile();
        $writable   = SettingsPage::isWpConfigWritable();
        $configPath = SettingsPage::wpConfigPath();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php settings_errors('sentinel_unity_control'); ?>

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
// define( 'UNITY_KILL', false ); // Or remove the line entirely to re-enable</code></pre>
        </div>
        <?php
    }
}
