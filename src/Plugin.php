<?php

declare(strict_types=1);

namespace Sentinel;

use Sentinel\Admin\DashboardWidget;

/**
 * Main Sentinel Plugin Class
 */
class Plugin
{
    private static bool $initialized = false;

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

        // Initialize the dashboard widget (admin only)
        if (is_admin()) {
            DashboardWidget::init();
        }
    }
}
