<?php

declare(strict_types=1);

namespace Sentinel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Admin\DashboardWidget;
use Sentinel\Admin\LogViewerPage;
use Sentinel\Admin\SettingsPage;
use Sentinel\Logger\HasLogger;

/**
 * Main Sentinel Plugin Class
 */
class Plugin
{
    use HasLogger;

    private static bool $initialized = false;

    /**
     * Top-level admin menu slug – shared with sub-pages.
     */
    public const MENU_SLUG  = 'sentinel';
    private const CAPABILITY = 'manage_options';

    protected static function logChannel(): string
    {
        return 'sentinel';
    }

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        self::logInfo('Sentinel initialising', ['version' => SENTINEL_VERSION]);

        // Initialize the dashboard widget, log viewer and settings (admin only)
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerTopLevelMenu']);

            DashboardWidget::init();
            SettingsPage::init();

            try {
                LogViewerPage::init();
            } catch (\Throwable $e) {
                self::logError('LogViewerPage failed to initialise: ' . $e->getMessage());
            }
        }

        self::logDebug('Sentinel initialised');
    }

    /**
     * Register the top-level Sentinel admin menu.
     *
     * The first submenu page (Logs) is registered by LogViewerPage and
     * replaces the auto-generated duplicate entry.
     */
    public static function registerTopLevelMenu(): void
    {
        add_menu_page(
            __('Sentinel', 'sentinel'),      // page title
            __('Sentinel', 'sentinel'),      // menu title
            self::CAPABILITY,
            self::MENU_SLUG,                 // slug — LogViewerPage will match this for the first submenu
            '__return_null',                 // placeholder callback, overridden by the first submenu
            'dashicons-shield',              // icon
            80                               // position
        );
    }
}
