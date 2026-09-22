<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Sentinel\Logger\HasLogger;

/*
 * Tests for the HasLogger convenience trait.
 *
 * wp_log() is a real function defined by sentinel-logger.php rather than a
 * stub, so these tests run against the genuine logger: the trait resolves a
 * real channel and the shorthands buffer real entries. That is the more
 * useful test anyway — it proves the trait is wired to the logger the
 * plugin actually ships, not to a mock that agrees with it.
 */

beforeEach(function () {
    HasLoggerDefaultChannel::resetChannel();
    HasLoggerCustomChannel::resetChannel();
});

it('defaults the channel name to the short class name', function () {
    // sanitize_key() is stubbed in TestCase to mirror the real
    // lowercasing, so the derived channel is the class's short name.
    expect(HasLoggerDefaultChannel::channel())->toBe('hasloggerdefaultchannel');
});

it('lets the consuming class override the channel name', function () {
    expect(HasLoggerCustomChannel::channel())->toBe('custom-channel');
});

it('resolves a channel named after the consuming class', function () {
    $channel = HasLoggerCustomChannel::log();

    expect($channel)->toBeInstanceOf(\Sentinel_Log_Channel::class)
        ->and($channel->getChannel())->toBe('custom-channel');
});

it('caches the resolved channel', function () {
    $first  = HasLoggerCustomChannel::log();
    $second = HasLoggerCustomChannel::log();

    expect($second)->toBe($first, 'The channel is resolved once and reused.');
});

it('gives two consumers their own channels', function () {
    $custom  = HasLoggerCustomChannel::log();
    $default = HasLoggerDefaultChannel::log();

    expect($custom->getChannel())->toBe('custom-channel')
        ->and($default->getChannel())->toBe('hasloggerdefaultchannel');
});

it('buffers an entry for every shorthand', function () {
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

    expect(\Sentinel_Logger::instance()->bufferCount())
        ->toBe($before + 8, 'Each shorthand should reach the logger exactly once.');
});

it('makes the shorthands safe to call before the channel is resolved', function () {
    // Nothing has called log() yet on this consumer; the shorthand must
    // resolve the channel itself rather than dereferencing null.
    HasLoggerDefaultChannel::resetChannel();

    HasLoggerDefaultChannel::logError('resolves lazily');

    expect(HasLoggerDefaultChannel::log())->toBeInstanceOf(\Sentinel_Log_Channel::class);
});

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
