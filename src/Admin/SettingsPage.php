<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function add_options_page;
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
 * Registers a Sentinel settings page under Settings → Sentinel.
 * Currently exposes:
 *   - Whether to drop the log database table on plugin uninstall.
 */
class SettingsPage
{
    private const PAGE_SLUG  = 'sentinel-settings';
    private const MENU_TITLE = 'Sentinel';
    private const PAGE_TITLE = 'Sentinel Settings';
    private const CAPABILITY = 'manage_options';

    /**
     * Option key: whether to drop the log table on uninstall.
     * Stored as '1' (yes, drop) or '' (no, preserve — the default).
     */
    public const OPTION_DROP_TABLE = 'sentinel_drop_table_on_uninstall';

    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * Register the settings page under Settings.
     */
    public static function registerPage(): void
    {
        add_options_page(
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
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
                'When unchecked (the default), the log table is preserved so other Bleeding Deacons plugins can continue to use it.',
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
            <form method="post" action="options.php">
                <?php
                settings_fields('sentinel_settings_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
