<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/*
 * Tests for the Sentinel_Log_Level and Sentinel_Log_Channel classes.
 *
 * These classes are defined in sentinel-logger.php (the mu-plugin).
 * We test the pure-logic portions: level thresholds, channel naming,
 * and the dispatch contract. Database interaction is mocked or skipped.
 */

// This file runs on plain PHPUnit rather than wp-mocks' TestCase, so any
// Mockery expectation added here is only verified with the trait in place.
uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    // Ensure the logger classes are loaded
    if (!class_exists('Sentinel_Log_Level', false)) {
        // Load just the class definitions from the mu-plugin source
        require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
    }
});

// ── Sentinel_Log_Level::meetsThreshold ──────────────────────────
it('lets emergency meet every threshold', function () {
    expect(\Sentinel_Log_Level::meetsThreshold('emergency', 'emergency'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('emergency', 'debug'))->toBeTrue();
});

it('lets debug meet only the debug threshold', function () {
    expect(\Sentinel_Log_Level::meetsThreshold('debug', 'debug'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('debug', 'info'))->toBeFalse()
        ->and(\Sentinel_Log_Level::meetsThreshold('debug', 'error'))->toBeFalse();
});

it('lets warning meet warning and below', function () {
    expect(\Sentinel_Log_Level::meetsThreshold('warning', 'warning'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('warning', 'notice'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('warning', 'debug'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('warning', 'error'))->toBeFalse();
});

it('does not let error meet critical', function () {
    expect(\Sentinel_Log_Level::meetsThreshold('error', 'critical'))->toBeFalse();
});

it('defines every priority level', function () {
    $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    foreach ($levels as $level) {
        expect(\Sentinel_Log_Level::PRIORITY)->toHaveKey($level);
    }
});

it('orders priorities from emergency lowest to debug highest', function () {
    expect(\Sentinel_Log_Level::PRIORITY['emergency'])
        ->toBeLessThan(\Sentinel_Log_Level::PRIORITY['debug'])
        ->and(\Sentinel_Log_Level::PRIORITY['error'])
        ->toBeLessThan(\Sentinel_Log_Level::PRIORITY['info']);
});

it('gives an unknown level the debug priority', function () {
    // Unknown level should be treated as lowest priority (7 = debug)
    expect(\Sentinel_Log_Level::meetsThreshold('unknown', 'debug'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('unknown', 'info'))->toBeFalse();
});

it('treats an unknown threshold as debug', function () {
    // Unknown threshold = debug (7), so everything meets it
    expect(\Sentinel_Log_Level::meetsThreshold('emergency', 'unknown'))->toBeTrue()
        ->and(\Sentinel_Log_Level::meetsThreshold('debug', 'unknown'))->toBeTrue();
});
