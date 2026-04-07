<?php

declare(strict_types=1);

namespace Sentinel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Admin\StatusDashboard;
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

        // Initialize the dashboard widget, log viewer and settings (admin only)
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerTopLevelMenu']);

            StatusDashboard::init();

            try {
                LogViewerPage::init();
            } catch (\Throwable $e) {
                self::logError('LogViewerPage failed to initialise: ' . $e->getMessage());
            }

            SettingsPage::init();
        }
        self::logDebug('Initialised', ['version' => defined('SENTINEL_VERSION') ? SENTINEL_VERSION : 'unknown']);
    }

    /**
     * Register the top-level Sentinel admin menu and schedule
     * removal of the auto-generated duplicate first submenu entry.
     */
    public static function registerTopLevelMenu(): void
    {
        add_menu_page(
            __('Sentinel', 'sentinel'),
            __('Sentinel', 'sentinel'),
            self::CAPABILITY,
            self::MENU_SLUG,
            '__return_null',
            'dashicons-shield',
            80
        );

        // WordPress auto-creates a submenu item that duplicates the
        // parent label. Remove it after all admin_menu callbacks have
        // finished registering their pages.
        add_action('admin_menu', [self::class, 'removeDuplicateSubmenu'], 999);
    }

    /**
     * Remove the auto-generated first submenu that duplicates the
     * top-level "Sentinel" entry.
     */
    public static function removeDuplicateSubmenu(): void
    {
        global $submenu;

        if (empty($submenu[self::MENU_SLUG])) {
            return;
        }

        foreach ($submenu[self::MENU_SLUG] as $index => $item) {
            // The auto-generated entry uses the parent slug as both
            // the menu slug (index 2) and shares the parent capability.
            if (isset($item[2]) && $item[2] === self::MENU_SLUG) {
                unset($submenu[self::MENU_SLUG][$index]);
                break;
            }
        }
    }
}
