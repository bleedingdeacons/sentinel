<?php

declare(strict_types=1);

namespace Sentinel\Tests;

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;
use BleedingDeacons\WpMocks\WpState;

/**
 * Base TestCase for Sentinel plugin tests.
 *
 * Brain Monkey's lifecycle, Mockery integration and the WordPress stubs all
 * come from bleedingdeacons/wp-mocks, shared across the plugin suite.
 *
 * The $optionStore this class used to carry is gone: wp-mocks' get_option() and
 * update_option() are real functions over WpState::$options, which is the same
 * idea generalised. Anything that used setOption() still works — it now seeds
 * that store instead.
 *
 * Sentinel_Logger is a singleton whose constructor reads configuration and
 * ensures its table exists, so merely calling instance() reaches into
 * WordPress. The setUp below covers that path.
 */
abstract class TestCase extends WpMocksTestCase
{
    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The logger lives in a mu-plugin-style file rather than the PSR-4
        // tree, so it is required rather than autoloaded. Loading it here (not
        // just in the test that needs it) lets the seeding below reference its
        // constants. Safe because tests/bootstrap.php defines
        // SENTINEL_CAPTURE_ERRORS as false, which suppresses the global
        // handler registration at the foot of the file.
        if (!class_exists('Sentinel_Logger', false)) {
            require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
        }

        // maybeCreateTable() short-circuits when the stored schema version
        // already matches, which keeps dbDelta and $wpdb out of unit tests.
        WpState::$options['sentinel_logger_db_version'] = \Sentinel_Logger::DB_VERSION;

        // Not stubbed: register_shutdown_function, which the constructor calls
        // to flush the buffer at end of request. It is an internal PHP function
        // and neither Patchwork nor Brain Monkey will override those. Harmless
        // here — the registered flush is a no-op while the buffer is empty, and
        // these tests never fill it.
        //
        // Also not stubbed: apply_filters(). resolveConfig() falls through to a
        // filter for each setting, and Brain Monkey's apply_filters already
        // returns the value it was handed, so the documented defaults survive.
    }

    /**
     * Seed an option value read back by get_option().
     */
    protected function setOption(string $name, mixed $value): void
    {
        WpState::$options[$name] = $value;
    }
}
