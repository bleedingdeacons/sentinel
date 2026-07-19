<?php

declare(strict_types=1);

namespace Sentinel\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WP_Mock;

/**
 * Base TestCase for Sentinel plugin tests
 *
 * Provides setup and teardown for WP_Mock and Mockery integration, matching
 * Unity, tsml-for-unity and Integrity: PHPUnit's TestCase with WP_Mock driven
 * by hand, rather than extending WP_Mock\Tools\TestCase.
 *
 * Sentinel_Logger is a singleton whose constructor reads configuration and
 * ensures its table exists, so merely calling instance() reaches into
 * WordPress. The stubs below cover that path; without them every test that
 * touches the logger dies on an undefined function before asserting anything.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // The logger lives in a mu-plugin-style file rather than the PSR-4
        // tree, so it is required rather than autoloaded. Loading it here (not
        // just in the test that needs it) lets the stubs below reference its
        // constants. Safe because tests/bootstrap.php defines
        // SENTINEL_CAPTURE_ERRORS as false, which suppresses the global
        // handler registration at the foot of the file.
        if (!class_exists('Sentinel_Logger', false)) {
            require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
        }

        $this->stubLoggerBootstrap();
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Stub the WordPress calls made while constructing Sentinel_Logger.
     */
    private function stubLoggerBootstrap(): void
    {
        // resolveConfig() falls through to a filter for each setting; return
        // the supplied default so tests see the documented defaults.
        WP_Mock::userFunction('apply_filters')
            ->andReturnUsing(static fn (string $filter, mixed $default = null): mixed => $default);

        // maybeCreateTable() short-circuits when the stored schema version
        // already matches, which keeps dbDelta and $wpdb out of unit tests.
        WP_Mock::userFunction('get_option')
            ->with('sentinel_logger_db_version', '')
            ->andReturn(\Sentinel_Logger::DB_VERSION);

        // Any other option read falls back to its default.
        WP_Mock::userFunction('get_option')
            ->andReturnUsing(static fn (string $name, mixed $default = false): mixed => $default);

        WP_Mock::userFunction('update_option')->andReturn(true);

        // Channel names are keys; sanitize_key's real behaviour is what the
        // channel-naming assertions are checking, so mirror it rather than
        // passing the value through untouched.
        WP_Mock::userFunction('sanitize_key')
            ->andReturnUsing(static fn (string $key): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '');

        // Not stubbed: register_shutdown_function, which the constructor calls
        // to flush the buffer at end of request. It is an internal PHP
        // function and WP_Mock refuses to override those. Harmless here — the
        // registered flush is a no-op while the buffer is empty, and these
        // tests never fill it.
    }
}
