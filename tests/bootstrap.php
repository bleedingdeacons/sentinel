<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Sentinel
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize WP_Mock
WP_Mock::bootstrap();

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('SENTINEL_PLUGIN_DIR')) {
    define('SENTINEL_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('SENTINEL_PLUGIN_URL')) {
    define('SENTINEL_PLUGIN_URL', 'http://example.com/wp-content/plugins/sentinel/');
}

if (!defined('SENTINEL_VERSION')) {
    define('SENTINEL_VERSION', '1.0.0');
}

if (!defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/wp-mu-plugins');
}

// Load the logger classes directly so we can test them without
// the mu-plugin bootstrap (which would register global handlers).
// We extract just the class definitions by requiring a test-safe
// version or using the classes after they're defined.
//
// The sentinel-logger.php file has an ABSPATH guard and registers
// global handlers. We've already defined ABSPATH above, so the
// classes will load. The global handler registration at the bottom
// is controlled by SENTINEL_CAPTURE_ERRORS — disable it for tests.
if (!defined('SENTINEL_CAPTURE_ERRORS')) {
    define('SENTINEL_CAPTURE_ERRORS', false);
}
