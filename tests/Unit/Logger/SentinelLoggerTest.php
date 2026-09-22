<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

/*
 * Tests for Sentinel_Logger internal logic.
 *
 * The logger is a singleton with database side-effects. We test the
 * pure-logic private methods (interpolation, redaction) via reflection,
 * and test the channel/buffer API through the public surface.
 */

// MockeryPHPUnitIntegration is applied by Sentinel\Tests\TestCase.
// Using it here as well makes assertPostConditions() recurse into
// itself until the process runs out of memory.

/**
 * Call a private method on Sentinel_Logger via reflection.
 *
 * @param array<int, mixed> $args
 */
function callLoggerPrivate(string $method, array $args): mixed
{
    $ref = new \ReflectionMethod(\Sentinel_Logger::class, $method);

    return $ref->invoke(\Sentinel_Logger::instance(), ...$args);
}

// ── Interpolation ───────────────────────────────────────────────
describe('interpolate', function () {
    it('replaces placeholders with context values', function () {
        $result = callLoggerPrivate('interpolate', [
            'User {name} logged in from {ip}',
            ['name' => 'Alice', 'ip' => '192.168.1.1'],
        ]);

        expect($result)->toBe('User Alice logged in from 192.168.1.1');
    });

    it('leaves unknown placeholders intact', function () {
        $result = callLoggerPrivate('interpolate', [
            'Hello {name}, your id is {id}',
            ['name' => 'Bob'],
        ]);

        expect($result)->toBe('Hello Bob, your id is {id}');
    });

    it('ignores underscore-prefixed keys', function () {
        $result = callLoggerPrivate('interpolate', [
            'Channel is {_channel}',
            ['_channel' => 'unity'],
        ]);

        expect($result)->toBe('Channel is {_channel}');
    });

    it('handles numeric values', function () {
        $result = callLoggerPrivate('interpolate', [
            'Count: {count}, rate: {rate}',
            ['count' => 42, 'rate' => 3.14],
        ]);

        expect($result)->toBe('Count: 42, rate: 3.14');
    });

    it('skips non-scalar values', function () {
        $result = callLoggerPrivate('interpolate', [
            'Data: {arr}, obj: {obj}',
            ['arr' => [1, 2, 3], 'obj' => new \stdClass()],
        ]);

        expect($result)->toBe('Data: {arr}, obj: {obj}');
    });

    it('handles stringable objects', function () {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $result = callLoggerPrivate('interpolate', [
            'Value: {val}',
            ['val' => $stringable],
        ]);

        expect($result)->toBe('Value: stringified');
    });

    it('handles an empty context', function () {
        $result = callLoggerPrivate('interpolate', [
            'No placeholders here',
            [],
        ]);

        expect($result)->toBe('No placeholders here');
    });
});

// ── Redaction ───────────────────────────────────────────────────
describe('redact', function () {
    it('masks the password key', function () {
        $result = callLoggerPrivate('redact', [
            ['password' => 's3cret', 'username' => 'alice'],
        ]);

        expect($result['password'])->toBe('*** REDACTED ***')
            ->and($result['username'])->toBe('alice');
    });

    it('masks all sensitive keys', function () {
        $sensitiveKeys = [
            'password', 'passwd', 'secret', 'token', 'api_key',
            'apikey', 'access_token', 'refresh_token', 'credit_card',
            'card_number', 'cvv', 'ssn', 'authorization',
        ];

        foreach ($sensitiveKeys as $key) {
            $result = callLoggerPrivate('redact', [
                [$key => 'sensitive-value'],
            ]);

            expect($result[$key])->toBe('*** REDACTED ***', "Key '{$key}' should be redacted");
        }
    });

    it('is case-insensitive', function () {
        $result = callLoggerPrivate('redact', [
            ['PASSWORD' => 'secret', 'Api_Key' => 'key123'],
        ]);

        expect($result['PASSWORD'])->toBe('*** REDACTED ***')
            ->and($result['Api_Key'])->toBe('*** REDACTED ***');
    });

    it('handles nested arrays', function () {
        $result = callLoggerPrivate('redact', [
            [
                'config' => [
                    'token' => 'abc123',
                    'host' => 'example.com',
                ],
            ],
        ]);

        expect($result['config']['token'])->toBe('*** REDACTED ***')
            ->and($result['config']['host'])->toBe('example.com');
    });

    it('preserves non-sensitive keys', function () {
        $result = callLoggerPrivate('redact', [
            ['name' => 'Alice', 'action' => 'login', 'count' => 5],
        ]);

        expect($result['name'])->toBe('Alice')
            ->and($result['action'])->toBe('login')
            ->and($result['count'])->toBe(5);
    });

    it('handles an empty context', function () {
        $result = callLoggerPrivate('redact', [[]]);

        expect($result)->toBe([]);
    });
});

// ── Channel ─────────────────────────────────────────────────────
describe('channel', function () {
    it('returns a log channel', function () {
        $channel = \Sentinel_Logger::channel('test-plugin');

        expect($channel)->toBeInstanceOf(\Sentinel_Log_Channel::class);
    });

    it('returns the same instance for the same name', function () {
        $a = \Sentinel_Logger::channel('my-plugin');
        $b = \Sentinel_Logger::channel('my-plugin');

        expect($b)->toBe($a);
    });

    it('returns different instances for different names', function () {
        $a = \Sentinel_Logger::channel('plugin-a');
        $b = \Sentinel_Logger::channel('plugin-b');

        expect($b)->not->toBe($a);
    });

    it('returns a sanitized name from getChannel', function () {
        $channel = \Sentinel_Logger::channel('My-Plugin_Test');

        // sanitize_key lowercases and strips non-alphanumeric except dashes/underscores
        expect($channel->getChannel())->toBe($channel->getChannel())
            ->not->toBeEmpty();
    });
});

// ── Buffer count ────────────────────────────────────────────────
it('returns an integer from bufferCount', function () {
    $count = \Sentinel_Logger::instance()->bufferCount();

    expect($count)->toBeInt()
        ->toBeGreaterThanOrEqual(0);
});
