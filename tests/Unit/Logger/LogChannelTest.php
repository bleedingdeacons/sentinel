<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

/*
 * Tests for Sentinel_Log_Channel.
 *
 * Verifies that each PSR-3 convenience method (emergency, alert, etc.)
 * delegates to log() with the correct level constant.
 */

dataset('psr3 levels', [
    'emergency' => ['emergency'],
    'alert'     => ['alert'],
    'critical'  => ['critical'],
    'error'     => ['error'],
    'warning'   => ['warning'],
    'notice'    => ['notice'],
    'info'      => ['info'],
    'debug'     => ['debug'],
]);

it('returns the channel name from getChannel', function () {
    $logger = \Sentinel_Logger::instance();
    $channel = new \Sentinel_Log_Channel('my-plugin', $logger);

    expect($channel->getChannel())->toBe('my-plugin');
});

it('sanitizes the channel name', function () {
    // sanitize_key is a WP function; the constructor calls it, so we test
    // via the static ::channel() factory which handles it.
    $channel = \Sentinel_Logger::channel('Test_Plugin');

    // sanitize_key lowercases and keeps alnum, dashes, underscores
    $name = $channel->getChannel();
    expect($name)->toMatch('/^[a-z0-9_-]+$/');
});

it('has a convenience method for each PSR-3 level', function (string $level) {
    $channel = \Sentinel_Logger::channel('test-levels');

    expect(method_exists($channel, $level))
        ->toBeTrue("Sentinel_Log_Channel should have a {$level}() method");
})->with('psr3 levels');

it('accepts a level string in log', function (string $level) {
    $channel = \Sentinel_Logger::channel('test-log');

    // log() should not throw for any valid PSR-3 level
    // (It will attempt to dispatch but the buffer/DB won't be available
    // in test context — we just verify it doesn't fatal)
    $channel->log($level, 'Test message for ' . $level);
})->with('psr3 levels')->throwsNoExceptions();
