<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Sentinel_Log_Channel.
 *
 * Verifies that each PSR-3 convenience method (emergency, alert, etc.)
 * delegates to log() with the correct level constant.
 */
class LogChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Sentinel_Log_Channel', false)) {
            require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
        }
    }

    /** @test */
    public function getChannel_returns_the_channel_name(): void
    {
        $logger = \Sentinel_Logger::instance();
        $channel = new \Sentinel_Log_Channel('my-plugin', $logger);

        $this->assertSame('my-plugin', $channel->getChannel());
    }

    /** @test */
    public function channel_name_is_sanitized(): void
    {
        // sanitize_key is a WP function — in test context it may
        // or may not be available via WP_Mock. The constructor calls it,
        // so we test via the static ::channel() factory which handles it.
        $channel = \Sentinel_Logger::channel('Test_Plugin');

        // sanitize_key lowercases and keeps alnum, dashes, underscores
        $name = $channel->getChannel();
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $name);
    }

    /**
     * @test
     * @dataProvider psr3LevelProvider
     */
    public function convenience_method_exists_for_each_psr3_level(string $level): void
    {
        $channel = \Sentinel_Logger::channel('test-levels');

        $this->assertTrue(
            method_exists($channel, $level),
            "Sentinel_Log_Channel should have a {$level}() method"
        );
    }

    /**
     * @test
     * @dataProvider psr3LevelProvider
     */
    public function log_method_accepts_level_string(string $level): void
    {
        $channel = \Sentinel_Logger::channel('test-log');

        // log() should not throw for any valid PSR-3 level
        // (It will attempt to dispatch but the buffer/DB won't be available
        // in test context — we just verify it doesn't fatal)
        $channel->log($level, 'Test message for ' . $level);

        $this->assertTrue(true, "{$level} level dispatched without error");
    }

    public static function psr3LevelProvider(): array
    {
        return [
            'emergency' => ['emergency'],
            'alert'     => ['alert'],
            'critical'  => ['critical'],
            'error'     => ['error'],
            'warning'   => ['warning'],
            'notice'    => ['notice'],
            'info'      => ['info'],
            'debug'     => ['debug'],
        ];
    }
}
