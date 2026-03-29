<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Plugin;

use function add_action;
use function add_submenu_page;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function get_option;
use function update_option;
use function wp_nonce_field;

/**
 * Class SettingsPage
 *
 * Registers a Sentinel settings page under the Sentinel top-level menu.
 * Exposes:
 *   - Logger configuration constants (written to wp-config.php):
 *     SENTINEL_LOG_ENABLED, SENTINEL_LOG_LEVEL,
 *     SENTINEL_LOG_MAX_ROWS, SENTINEL_LOG_BUFFER_SIZE.
 *   - Whether to drop the log database table on plugin uninstall.
 */
class SettingsPage
{
    private const PAGE_SLUG  = 'sentinel-settings';
    private const MENU_TITLE = 'Settings';
    private const PAGE_TITLE = 'Sentinel Settings';
    private const CAPABILITY = 'manage_options';

    /**
     * Option key: whether to drop the log table on uninstall.
     * Stored as '1' (yes, drop) or '' (no, preserve — the default).
     */
    public const OPTION_DROP_TABLE = 'sentinel_drop_table_on_uninstall';

    /**
     * Nonce action for saving logger configuration.
     */
    private const NONCE_ACTION = 'sentinel_logger_config';
    private const NONCE_FIELD  = '_sentinel_logger_nonce';

    /**
     * Marker comment inserted into wp-config.php to group Sentinel constants.
     */
    private const WP_CONFIG_MARKER = '/* Sentinel Logger Configuration */';

    /**
     * Valid log levels accepted by the logger (PSR-3 / RFC 5424).
     */
    private const VALID_LOG_LEVELS = [
        'debug', 'info', 'notice', 'warning',
        'error', 'critical', 'alert', 'emergency',
    ];

    /**
     * Human-readable labels for log level options.
     */
    private const LOG_LEVEL_LABELS = [
        'debug'     => 'Debug (all messages)',
        'info'      => 'Info',
        'notice'    => 'Notice',
        'warning'   => 'Warning',
        'error'     => 'Error',
        'critical'  => 'Critical',
        'alert'     => 'Alert',
        'emergency' => 'Emergency (most severe only)',
    ];

    /**
     * Default values for each logger constant.
     * Must stay in sync with Sentinel_Logger::__construct().
     */
    private const DEFAULTS = [
        'SENTINEL_LOG_ENABLED'     => true,
        'SENTINEL_LOG_LEVEL'       => 'debug',
        'SENTINEL_LOG_MAX_ROWS'    => 10000,
        'SENTINEL_LOG_BUFFER_SIZE' => 50,
    ];

    /** @var string The hook suffix returned by add_submenu_page(). */
    private static string $hookSuffix = '';

    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_init', [self::class, 'handleLoggerConfigSave']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Register the settings page as a submenu under Sentinel.
     */
    public static function registerPage(): void
    {
        self::$hookSuffix = (string) add_submenu_page(
            Plugin::MENU_SLUG,       // parent slug
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Enqueue styles on the settings page for the logging configuration section.
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
    }

    /**
     * Register settings, sections and fields with the Settings API.
     */
    public static function registerSettings(): void
    {
        register_setting('sentinel_settings_group', self::OPTION_DROP_TABLE, [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return $value ? '1' : '';
            },
            'default'           => '',
        ]);

        add_settings_section(
            'sentinel_uninstall_section',
            __('Uninstall Behaviour', 'sentinel'),
            [self::class, 'renderUninstallSectionDescription'],
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_DROP_TABLE,
            __('Drop log table on uninstall', 'sentinel'),
            [self::class, 'renderDropTableField'],
            self::PAGE_SLUG,
            'sentinel_uninstall_section'
        );
    }

    /**
     * Section description callback.
     */
    public static function renderUninstallSectionDescription(): void
    {
        echo '<p class="description">';
        esc_html_e(
            'Control what happens to log data when Sentinel is deleted from the Plugins page.',
            'sentinel'
        );
        echo '</p>';
    }

    /**
     * Render the checkbox field.
     */
    public static function renderDropTableField(): void
    {
        $value = get_option(self::OPTION_DROP_TABLE, '');
        ?>
        <label for="<?php echo esc_attr(self::OPTION_DROP_TABLE); ?>">
            <input
                type="checkbox"
                id="<?php echo esc_attr(self::OPTION_DROP_TABLE); ?>"
                name="<?php echo esc_attr(self::OPTION_DROP_TABLE); ?>"
                value="1"
                <?php checked($value, '1'); ?>
            />
            <?php esc_html_e(
                'Remove the log database table and all stored entries when Sentinel is uninstalled.',
                'sentinel'
            ); ?>
        </label>
        <p class="description">
            <?php esc_html_e(
                'When unchecked (the default), the log table is preserved.',
                'sentinel'
            ); ?>
        </p>
        <?php
    }

    /**
     * Helper: check if the user has opted in to dropping the table.
     */
    public static function shouldDropTable(): bool
    {
        return get_option(self::OPTION_DROP_TABLE, '') === '1';
    }

    // ── Logging Configuration Info ─────────────────────────────────────────────

    /**
     * Get the current logging configuration for display.
     *
     * @return array<string, array{value: mixed, source: string, description: string}>
     */
    private static function getLoggingConfig(): array
    {
        $config = [];

        // SENTINEL_LOG_ENABLED
        $config['SENTINEL_LOG_ENABLED'] = [
            'value'       => defined('SENTINEL_LOG_ENABLED') ? (SENTINEL_LOG_ENABLED ? 'true' : 'false') : '(not set — default: true)',
            'source'      => defined('SENTINEL_LOG_ENABLED') ? 'wp-config.php' : 'default',
            'description' => 'Master switch. Set to false to disable all logging.',
            'example'     => "define( 'SENTINEL_LOG_ENABLED', false );",
        ];

        // SENTINEL_LOG_LEVEL
        $config['SENTINEL_LOG_LEVEL'] = [
            'value'       => defined('SENTINEL_LOG_LEVEL') ? SENTINEL_LOG_LEVEL : '(not set — default: debug)',
            'source'      => defined('SENTINEL_LOG_LEVEL') ? 'wp-config.php' : 'default',
            'description' => 'Minimum severity to record. Messages below this level are discarded.',
            'example'     => "define( 'SENTINEL_LOG_LEVEL', 'warning' );",
            'options'     => 'emergency, alert, critical, error, warning, notice, info, debug',
        ];

        // SENTINEL_LOG_MAX_ROWS
        $config['SENTINEL_LOG_MAX_ROWS'] = [
            'value'       => defined('SENTINEL_LOG_MAX_ROWS') ? number_format(SENTINEL_LOG_MAX_ROWS) : '(not set — default: 10,000)',
            'source'      => defined('SENTINEL_LOG_MAX_ROWS') ? 'wp-config.php' : 'default',
            'description' => 'Maximum number of rows kept in the log table. Oldest entries are pruned automatically.',
            'example'     => "define( 'SENTINEL_LOG_MAX_ROWS', 25000 );",
        ];

        // SENTINEL_LOG_BUFFER_SIZE
        $config['SENTINEL_LOG_BUFFER_SIZE'] = [
            'value'       => defined('SENTINEL_LOG_BUFFER_SIZE') ? number_format(SENTINEL_LOG_BUFFER_SIZE) : '(not set — default: 50)',
            'source'      => defined('SENTINEL_LOG_BUFFER_SIZE') ? 'wp-config.php' : 'default',
            'description' => 'Number of entries held in the in-memory buffer before auto-flushing to the database. Lower values mean more frequent writes; higher values reduce DB round-trips.',
            'example'     => "define( 'SENTINEL_LOG_BUFFER_SIZE', 100 );",
        ];

        return $config;
    }

    // ── wp-config.php Constant Management ──────────────────────────────────────

    /**
     * Read the current value of a Sentinel constant.
     *
     * If the constant is defined at runtime (present in wp-config.php),
     * return its value. Otherwise return the default.
     */
    private static function getConstantValue(string $constant): mixed
    {
        if (defined($constant)) {
            return constant($constant);
        }

        return self::DEFAULTS[$constant] ?? null;
    }

    /**
     * Return the path to wp-config.php.
     *
     * WordPress allows wp-config.php to live either in ABSPATH or one
     * directory above it.
     */
    public static function wpConfigPath(): ?string
    {
        $path = ABSPATH . 'wp-config.php';
        if (file_exists($path)) {
            return $path;
        }

        $path = dirname(ABSPATH) . '/wp-config.php';
        if (file_exists($path)) {
            return $path;
        }

        return null;
    }

    /**
     * Check whether wp-config.php is writable.
     */
    public static function isWpConfigWritable(): bool
    {
        $path = self::wpConfigPath();
        return $path !== null && is_writable($path);
    }

    /**
     * Update a single constant in wp-config.php.
     *
     * If the constant already exists its value is replaced in-place.
     * If it does not exist a new define() line is inserted below the
     * Sentinel marker comment (which is itself created if absent).
     *
     * @param string $constant The constant name.
     * @param mixed  $value    The value to set.
     * @return bool True on success.
     */
    public static function setWpConfigConstant(string $constant, mixed $value): bool
    {
        $path = self::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $phpValue = self::formatPhpValue($value);
        $defineLine = "define( '" . $constant . "', " . $phpValue . " );";

        // Pattern matches:  define( 'CONSTANT_NAME', <any value> );
        $pattern = '/^\s*define\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*,\s*.+?\)\s*;/m';

        if (preg_match($pattern, $contents)) {
            // Replace the existing define() line.
            $contents = preg_replace($pattern, $defineLine, $contents, 1);
        } else {
            // Insert a new define(). Place it after the marker comment,
            // creating the marker if it doesn't exist yet.
            if (str_contains($contents, self::WP_CONFIG_MARKER)) {
                $contents = preg_replace(
                    '/' . preg_quote(self::WP_CONFIG_MARKER, '/') . '/m',
                    self::WP_CONFIG_MARKER . "\n" . $defineLine,
                    $contents,
                    1
                );
            } else {
                // Insert marker + define after the opening <?php tag.
                $insertBlock = "\n" . self::WP_CONFIG_MARKER . "\n" . $defineLine . "\n";
                $pos = strpos($contents, '<?php');
                if ($pos !== false) {
                    // Find end of the <?php line.
                    $eol = strpos($contents, "\n", $pos);
                    if ($eol !== false) {
                        $contents = substr_replace($contents, $insertBlock, $eol + 1, 0);
                    } else {
                        $contents .= $insertBlock;
                    }
                } else {
                    // Fallback — shouldn't happen in a valid wp-config.php.
                    $contents = "<?php\n" . $insertBlock . $contents;
                }
            }
        }

        return self::atomicWrite($path, $contents);
    }

    /**
     * Remove a single Sentinel constant from wp-config.php.
     */
    public static function removeWpConfigConstant(string $constant): bool
    {
        $path = self::wpConfigPath();
        if ($path === null || !is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        // Remove the define() line.
        $pattern = '/^\s*define\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*,\s*.+?\)\s*;\s*\n?/m';
        $updated = preg_replace($pattern, '', $contents);

        if ($updated === $contents) {
            return true; // Nothing to remove.
        }

        // Remove the marker comment if no Sentinel constants remain.
        $hasSentinelConstants = false;
        foreach (array_keys(self::DEFAULTS) as $name) {
            if ($name !== $constant && preg_match('/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]/', $updated)) {
                $hasSentinelConstants = true;
                break;
            }
        }
        if (!$hasSentinelConstants) {
            $updated = str_replace(self::WP_CONFIG_MARKER . "\n", '', $updated);
            $updated = str_replace(self::WP_CONFIG_MARKER, '', $updated);
        }

        // Clean up excessive blank lines.
        $updated = preg_replace("/\n{3,}/", "\n\n", $updated);

        return self::atomicWrite($path, $updated);
    }

    /**
     * Remove all Sentinel logger constants from wp-config.php.
     */
    public static function removeAllWpConfigConstants(): bool
    {
        $success = true;
        foreach (array_keys(self::DEFAULTS) as $constant) {
            if (!self::removeWpConfigConstant($constant)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Format a PHP value for embedding in a define() statement.
     */
    private static function formatPhpValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return "'" . addcslashes((string) $value, "'\\") . "'";
    }

    /**
     * Write contents to a file atomically (write temp → rename).
     * Preserves the original file permissions.
     */
    private static function atomicWrite(string $path, string $contents): bool
    {
        $tmpPath = $path . '.sentinel-tmp';
        if (file_put_contents($tmpPath, $contents) === false) {
            return false;
        }

        $perms = fileperms($path);
        if ($perms !== false) {
            chmod($tmpPath, $perms);
        }

        return rename($tmpPath, $path);
    }

    // ── Logger Config Save Handler ─────────────────────────────────────────────

    /**
     * Handle the custom form submission for logger configuration.
     *
     * This is handled manually (not via the Settings API) because the
     * values are written to wp-config.php as PHP constants, not stored
     * in the options table.
     */
    public static function handleLoggerConfigSave(): void
    {
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        if (!self::isWpConfigWritable()) {
            add_settings_error(
                'sentinel_logger_config',
                'wp_config_not_writable',
                __('wp-config.php is not writable. Logger settings could not be saved.', 'sentinel'),
                'error'
            );
            return;
        }

        $errors = false;

        // SENTINEL_LOG_ENABLED (checkbox → bool)
        $enabled = !empty($_POST['sentinel_log_enabled']);
        if (!self::setWpConfigConstant('SENTINEL_LOG_ENABLED', $enabled)) {
            $errors = true;
        }

        // SENTINEL_LOG_LEVEL (select → string)
        $level = sanitize_text_field($_POST['sentinel_log_level'] ?? 'debug');
        if (!in_array($level, self::VALID_LOG_LEVELS, true)) {
            $level = 'debug';
        }
        if (!self::setWpConfigConstant('SENTINEL_LOG_LEVEL', $level)) {
            $errors = true;
        }

        // SENTINEL_LOG_MAX_ROWS (int, clamped to 100–1,000,000)
        $maxRows = (int) ($_POST['sentinel_log_max_rows'] ?? 10000);
        $maxRows = max(100, min(1000000, $maxRows));
        if (!self::setWpConfigConstant('SENTINEL_LOG_MAX_ROWS', $maxRows)) {
            $errors = true;
        }

        // SENTINEL_LOG_BUFFER_SIZE (int, clamped to 1–500)
        $bufferSize = (int) ($_POST['sentinel_log_buffer_size'] ?? 50);
        $bufferSize = max(1, min(500, $bufferSize));
        if (!self::setWpConfigConstant('SENTINEL_LOG_BUFFER_SIZE', $bufferSize)) {
            $errors = true;
        }

        if ($errors) {
            add_settings_error(
                'sentinel_logger_config',
                'wp_config_write_error',
                __('Some logger settings could not be written to wp-config.php.', 'sentinel'),
                'error'
            );
        } else {
            add_settings_error(
                'sentinel_logger_config',
                'settings_updated',
                __('Logger settings saved to wp-config.php. Changes take effect on the next page load.', 'sentinel'),
                'updated'
            );
        }
    }

    // ── Page Rendering ─────────────────────────────────────────────────────────

    /**
     * Render the full settings page.
     */
    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php settings_errors('sentinel_logger_config'); ?>

            <!-- Logger Configuration (writes to wp-config.php) -->
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <h2><?php esc_html_e('Logger Configuration', 'sentinel'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'These settings are stored as PHP constants in wp-config.php so they are available before any plugins load.',
                        'sentinel'
                    ); ?>
                </p>

                <?php if (!self::isWpConfigWritable()): ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <?php esc_html_e(
                                'wp-config.php is not writable. Logger settings cannot be saved from this page. You can define the constants manually in wp-config.php instead.',
                                'sentinel'
                            ); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <!-- SENTINEL_LOG_ENABLED -->
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Enable logging', 'sentinel'); ?>
                        </th>
                        <td>
                            <?php $enabledValue = self::getConstantValue('SENTINEL_LOG_ENABLED'); ?>
                            <label for="sentinel_log_enabled">
                                <input
                                    type="checkbox"
                                    id="sentinel_log_enabled"
                                    name="sentinel_log_enabled"
                                    value="1"
                                    <?php checked($enabledValue); ?>
                                    <?php disabled(!self::isWpConfigWritable()); ?>
                                />
                                <?php esc_html_e('When disabled, no log entries are recorded.', 'sentinel'); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- SENTINEL_LOG_LEVEL -->
                    <tr>
                        <th scope="row">
                            <label for="sentinel_log_level">
                                <?php esc_html_e('Minimum log level', 'sentinel'); ?>
                            </label>
                        </th>
                        <td>
                            <?php $levelValue = (string) self::getConstantValue('SENTINEL_LOG_LEVEL'); ?>
                            <select
                                id="sentinel_log_level"
                                name="sentinel_log_level"
                                <?php disabled(!self::isWpConfigWritable()); ?>
                            >
                                <?php foreach (self::LOG_LEVEL_LABELS as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($levelValue, $key); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Only entries at or above this severity are recorded.', 'sentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- SENTINEL_LOG_MAX_ROWS -->
                    <tr>
                        <th scope="row">
                            <label for="sentinel_log_max_rows">
                                <?php esc_html_e('Maximum log rows', 'sentinel'); ?>
                            </label>
                        </th>
                        <td>
                            <?php $maxRowsValue = (int) self::getConstantValue('SENTINEL_LOG_MAX_ROWS'); ?>
                            <input
                                type="number"
                                id="sentinel_log_max_rows"
                                name="sentinel_log_max_rows"
                                value="<?php echo esc_attr((string) $maxRowsValue); ?>"
                                min="100"
                                max="1000000"
                                step="100"
                                class="small-text"
                                <?php disabled(!self::isWpConfigWritable()); ?>
                            />
                            <p class="description">
                                <?php esc_html_e('Oldest entries are pruned when the table exceeds this limit.', 'sentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- SENTINEL_LOG_BUFFER_SIZE -->
                    <tr>
                        <th scope="row">
                            <label for="sentinel_log_buffer_size">
                                <?php esc_html_e('Write buffer size', 'sentinel'); ?>
                            </label>
                        </th>
                        <td>
                            <?php $bufferValue = (int) self::getConstantValue('SENTINEL_LOG_BUFFER_SIZE'); ?>
                            <input
                                type="number"
                                id="sentinel_log_buffer_size"
                                name="sentinel_log_buffer_size"
                                value="<?php echo esc_attr((string) $bufferValue); ?>"
                                min="1"
                                max="500"
                                step="1"
                                class="small-text"
                                <?php disabled(!self::isWpConfigWritable()); ?>
                            />
                            <p class="description">
                                <?php esc_html_e('Number of entries buffered in memory before flushing to the database.', 'sentinel'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php if (self::isWpConfigWritable()): ?>
                    <?php submit_button(__('Save Logger Settings', 'sentinel')); ?>
                <?php endif; ?>
            </form>

            <hr />

            <!-- Uninstall Behaviour (stored as WP option via Settings API) -->
            <form method="post" action="options.php">
                <?php
                settings_fields('sentinel_settings_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>

            <hr />

            <!-- Logging Configuration Reference -->
            <?php $config = self::getLoggingConfig(); ?>
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
add_filter( 'sentinel_logger_level', function () {
    return 'warning';
});

// Disable logging via filter
add_filter( 'sentinel_logger_enabled', '__return_false' );

// Set max rows via filter
add_filter( 'sentinel_logger_max_rows', function () {
    return 25000;
});

// Set in-memory buffer size via filter
add_filter( 'sentinel_logger_buffer_size', function () {
    return 100;
});

// Suppress or modify individual entries
add_filter( 'sentinel_logger_entry', function ( \$entry, \$channel ) {
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
