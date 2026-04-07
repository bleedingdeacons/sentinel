<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Tests for the Sentinel_Log_Level and Sentinel_Log_Channel classes.
 *
 * These classes are defined in sentinel-logger.php (the mu-plugin).
 * We test the pure-logic portions: level thresholds, channel naming,
 * and the dispatch contract. Database interaction is mocked or skipped.
 */
class LogLevelTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the logger classes are loaded
        if (!class_exists('Sentinel_Log_Level', false)) {
            // Load just the class definitions from the mu-plugin source
            require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
        }
    }

    // ── Sentinel_Log_Level::meetsThreshold ──────────────────────────

    /** @test */
    public function emergency_meets_every_threshold(): void
    {
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('emergency', 'emergency'));
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('emergency', 'debug'));
    }

    /** @test */
    public function debug_only_meets_debug_threshold(): void
    {
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('debug', 'debug'));
        $this->assertFalse(\Sentinel_Log_Level::meetsThreshold('debug', 'info'));
        $this->assertFalse(\Sentinel_Log_Level::meetsThreshold('debug', 'error'));
    }

    /** @test */
    public function warning_meets_warning_and_below(): void
    {
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('warning', 'warning'));
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('warning', 'notice'));
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('warning', 'debug'));
        $this->assertFalse(\Sentinel_Log_Level::meetsThreshold('warning', 'error'));
    }

    /** @test */
    public function error_does_not_meet_critical(): void
    {
        $this->assertFalse(\Sentinel_Log_Level::meetsThreshold('error', 'critical'));
    }

    /** @test */
    public function all_priority_levels_are_defined(): void
    {
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        foreach ($levels as $level) {
            $this->assertArrayHasKey($level, \Sentinel_Log_Level::PRIORITY);
        }
    }

    /** @test */
    public function priorities_are_ordered_emergency_lowest_debug_highest(): void
    {
        $this->assertLessThan(
            \Sentinel_Log_Level::PRIORITY['debug'],
            \Sentinel_Log_Level::PRIORITY['emergency']
        );
        $this->assertLessThan(
            \Sentinel_Log_Level::PRIORITY['info'],
            \Sentinel_Log_Level::PRIORITY['error']
        );
    }

    /** @test */
    public function unknown_level_defaults_to_debug_priority(): void
    {
        // Unknown level should be treated as lowest priority (7 = debug)
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('unknown', 'debug'));
        $this->assertFalse(\Sentinel_Log_Level::meetsThreshold('unknown', 'info'));
    }

    /** @test */
    public function unknown_threshold_defaults_to_debug(): void
    {
        // Unknown threshold = debug (7), so everything meets it
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('emergency', 'unknown'));
        $this->assertTrue(\Sentinel_Log_Level::meetsThreshold('debug', 'unknown'));
    }
}
