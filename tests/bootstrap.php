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

/**
 * Minimal $wpdb stand-in.
 *
 * Deliberately a plain class rather than a Mockery double: the logger
 * registers handleShutdown() as a shutdown function, which flushes whatever
 * is left in the buffer *after* PHPUnit has finished and Mockery has closed.
 * A mock would be dead by then and take the process down with it; this
 * survives, swallows the write, and lets the run exit cleanly.
 */
if (!class_exists('Sentinel_Test_Wpdb')) {
    class Sentinel_Test_Wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var array<int, string> Every statement passed to query(). */
        public array $queries = [];

        public function prepare(string $query, mixed ...$args): string
        {
            // Good enough for assertions about shape: substitute positionally
            // without the escaping WordPress would apply.
            foreach ($args as $arg) {
                $query = preg_replace('/%[sdf]/', (string) $arg, $query, 1) ?? $query;
            }

            return $query;
        }

        public function query(string $query): int
        {
            $this->queries[] = $query;

            return 1;
        }

        public function get_var(string $query): string
        {
            $this->queries[] = $query;

            return '0';
        }

        /** @return array<int, mixed> */
        public function get_results(string $query): array
        {
            $this->queries[] = $query;

            return [];
        }

        /** @var array<int, array{0: string, 1: array<string, mixed>}> Rows passed to insert(). */
        public array $inserts = [];

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            $this->inserts[] = [$table, $data];

            return 1;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

global $wpdb;
$wpdb = new Sentinel_Test_Wpdb();

/**
 * esc_sql() as a real function rather than a WP_Mock stub, for the same
 * reason as $wpdb above: flush() calls it, and the shutdown flush happens
 * after WP_Mock has torn its stubs down.
 */
if (!function_exists('esc_sql')) {
    function esc_sql(string $data): string
    {
        return addslashes($data);
    }
}
