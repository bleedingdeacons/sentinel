<?php

declare(strict_types=1);

namespace Sentinel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Admin\DashboardWidget;
use Sentinel\Admin\LogViewerPage;
use Sentinel\Logger\HasLogger;

/**
 * Main Sentinel Plugin Class
 */
class Plugin
{
    use HasLogger;

    private static bool $initialized = false;

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

        // Initialize the dashboard widget and log viewer (admin only)
        if (is_admin()) {
            DashboardWidget::init();

            try {
                LogViewerPage::init();
            } catch (\Throwable $e) {
                self::logError('LogViewerPage failed to initialise: ' . $e->getMessage());
            }
        }

        self::logDebug('Sentinel initialised');
    }
}
