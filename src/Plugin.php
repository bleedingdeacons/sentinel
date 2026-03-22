<?php

declare(strict_types=1);

namespace Sentinel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Admin\DashboardWidget;
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

        // Initialize the dashboard widget (admin only)
        if (is_admin()) {
            DashboardWidget::init();
        }

        self::logDebug('Sentinel initialised');
    }
}
