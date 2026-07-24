<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Sentinel\Logger\HasLogger;
use Sentinel\Tests\TestCase;

/**
 * Tests for the HasLogger convenience trait.
 *
 * wp_log() is a real function defined by sentinel-logger.php rather than a
 * stub, so these tests run against the genuine logger: the trait resolves a
 * real channel and the shorthands buffer real entries. That is the more
 * useful test anyway — it proves the trait is wired to the logger the
 * plugin actually ships, not to a mock that agrees with it.
 */
final class HasLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasLoggerDefaultChannel::resetChannel();
        HasLoggerCustomChannel::resetChannel();
    }

    /** @test */
    public function channel_name_defaults_to_the_short_class_name(): void
    {
        // sanitize_key() is stubbed in TestCase to mirror the real
        // lowercasing, so the derived channel is the class's short name.
        $this->assertSame('hasloggerdefaultchannel', HasLoggerDefaultChannel::channel());
    }

    /** @test */
    public function channel_name_can_be_overridden_by_the_consuming_class(): void
    {
        $this->assertSame('custom-channel', HasLoggerCustomChannel::channel());
    }

    /** @test */
    public function log_resolves_a_channel_named_after_the_consuming_class(): void
    {
        $channel = HasLoggerCustomChannel::log();

        $this->assertInstanceOf(\Sentinel_Log_Channel::class, $channel);
        $this->assertSame('custom-channel', $channel->getChannel());
    }

    /** @test */
    public function the_resolved_channel_is_cached(): void
    {
        $first  = HasLoggerCustomChannel::log();
        $second = HasLoggerCustomChannel::log();

        $this->assertSame($first, $second, 'The channel is resolved once and reused.');
    }

    /** @test */
    public function two_consumers_get_their_own_channels(): void
    {
        $custom  = HasLoggerCustomChannel::log();
        $default = HasLoggerDefaultChannel::log();

        $this->assertSame('custom-channel', $custom->getChannel());
        $this->assertSame('hasloggerdefaultchannel', $default->getChannel());
    }

    /** @test */
    public function every_shorthand_buffers_an_entry(): void
    {
        $logger = \Sentinel_Logger::instance();
        $before = $logger->bufferCount();

        HasLoggerCustomChannel::logEmergency('msg', ['k' => 'v']);
        HasLoggerCustomChannel::logAlert('msg');
        HasLoggerCustomChannel::logCritical('msg');
        HasLoggerCustomChannel::logError('msg');
        HasLoggerCustomChannel::logWarning('msg');
        HasLoggerCustomChannel::logNotice('msg');
        HasLoggerCustomChannel::logInfo('msg');
        HasLoggerCustomChannel::logDebug('msg');

        $this->assertSame(
            $before + 8,
            \Sentinel_Logger::instance()->bufferCount(),
            'Each shorthand should reach the logger exactly once.'
        );
    }

    /** @test */
    public function shorthands_are_safe_to_call_before_the_channel_is_resolved(): void
    {
        // Nothing has called log() yet on this consumer; the shorthand must
        // resolve the channel itself rather than dereferencing null.
        HasLoggerDefaultChannel::resetChannel();

        HasLoggerDefaultChannel::logError('resolves lazily');

        $this->assertInstanceOf(\Sentinel_Log_Channel::class, HasLoggerDefaultChannel::log());
    }
}

/** Consumer that keeps the trait's derived channel name. */
final class HasLoggerDefaultChannel
{
    use HasLogger;

    public static function channel(): string
    {
        return static::logChannel();
    }

    /** The trait caches its channel in a static; clear it between tests. */
    public static function resetChannel(): void
    {
        self::$loggerChannel = null;
    }
}

/** Consumer that names its own channel. */
final class HasLoggerCustomChannel
{
    use HasLogger;

    protected static function logChannel(): string
    {
        return 'custom-channel';
    }

    public static function channel(): string
    {
        return static::logChannel();
    }

    public static function resetChannel(): void
    {
        self::$loggerChannel = null;
    }
}
