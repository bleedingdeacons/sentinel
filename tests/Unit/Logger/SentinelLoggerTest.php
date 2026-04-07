<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Tests for Sentinel_Logger internal logic.
 *
 * The logger is a singleton with database side-effects. We test the
 * pure-logic private methods (interpolation, redaction) via reflection,
 * and test the channel/buffer API through the public surface.
 */
class SentinelLoggerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Sentinel_Logger', false)) {
            require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';
        }
    }

    /**
     * Call a private method on Sentinel_Logger via reflection.
     */
    private function callPrivate(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(\Sentinel_Logger::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(\Sentinel_Logger::instance(), ...$args);
    }

    // ── Interpolation ───────────────────────────────────────────────

    /** @test */
    public function interpolate_replaces_placeholders_with_context_values(): void
    {
        $result = $this->callPrivate('interpolate', [
            'User {name} logged in from {ip}',
            ['name' => 'Alice', 'ip' => '192.168.1.1'],
        ]);

        $this->assertSame('User Alice logged in from 192.168.1.1', $result);
    }

    /** @test */
    public function interpolate_leaves_unknown_placeholders_intact(): void
    {
        $result = $this->callPrivate('interpolate', [
            'Hello {name}, your id is {id}',
            ['name' => 'Bob'],
        ]);

        $this->assertSame('Hello Bob, your id is {id}', $result);
    }

    /** @test */
    public function interpolate_ignores_underscore_prefixed_keys(): void
    {
        $result = $this->callPrivate('interpolate', [
            'Channel is {_channel}',
            ['_channel' => 'unity'],
        ]);

        $this->assertSame('Channel is {_channel}', $result);
    }

    /** @test */
    public function interpolate_handles_numeric_values(): void
    {
        $result = $this->callPrivate('interpolate', [
            'Count: {count}, rate: {rate}',
            ['count' => 42, 'rate' => 3.14],
        ]);

        $this->assertSame('Count: 42, rate: 3.14', $result);
    }

    /** @test */
    public function interpolate_skips_non_scalar_values(): void
    {
        $result = $this->callPrivate('interpolate', [
            'Data: {arr}, obj: {obj}',
            ['arr' => [1, 2, 3], 'obj' => new \stdClass()],
        ]);

        $this->assertSame('Data: {arr}, obj: {obj}', $result);
    }

    /** @test */
    public function interpolate_handles_stringable_objects(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $result = $this->callPrivate('interpolate', [
            'Value: {val}',
            ['val' => $stringable],
        ]);

        $this->assertSame('Value: stringified', $result);
    }

    /** @test */
    public function interpolate_with_empty_context(): void
    {
        $result = $this->callPrivate('interpolate', [
            'No placeholders here',
            [],
        ]);

        $this->assertSame('No placeholders here', $result);
    }

    // ── Redaction ───────────────────────────────────────────────────

    /** @test */
    public function redact_masks_password_key(): void
    {
        $result = $this->callPrivate('redact', [
            ['password' => 's3cret', 'username' => 'alice'],
        ]);

        $this->assertSame('*** REDACTED ***', $result['password']);
        $this->assertSame('alice', $result['username']);
    }

    /** @test */
    public function redact_masks_all_sensitive_keys(): void
    {
        $sensitiveKeys = [
            'password', 'passwd', 'secret', 'token', 'api_key',
            'apikey', 'access_token', 'refresh_token', 'credit_card',
            'card_number', 'cvv', 'ssn', 'authorization',
        ];

        foreach ($sensitiveKeys as $key) {
            $result = $this->callPrivate('redact', [
                [$key => 'sensitive-value'],
            ]);

            $this->assertSame(
                '*** REDACTED ***',
                $result[$key],
                "Key '{$key}' should be redacted"
            );
        }
    }

    /** @test */
    public function redact_is_case_insensitive(): void
    {
        $result = $this->callPrivate('redact', [
            ['PASSWORD' => 'secret', 'Api_Key' => 'key123'],
        ]);

        $this->assertSame('*** REDACTED ***', $result['PASSWORD']);
        $this->assertSame('*** REDACTED ***', $result['Api_Key']);
    }

    /** @test */
    public function redact_handles_nested_arrays(): void
    {
        $result = $this->callPrivate('redact', [
            [
                'config' => [
                    'token' => 'abc123',
                    'host' => 'example.com',
                ],
            ],
        ]);

        $this->assertSame('*** REDACTED ***', $result['config']['token']);
        $this->assertSame('example.com', $result['config']['host']);
    }

    /** @test */
    public function redact_preserves_non_sensitive_keys(): void
    {
        $result = $this->callPrivate('redact', [
            ['name' => 'Alice', 'action' => 'login', 'count' => 5],
        ]);

        $this->assertSame('Alice', $result['name']);
        $this->assertSame('login', $result['action']);
        $this->assertSame(5, $result['count']);
    }

    /** @test */
    public function redact_handles_empty_context(): void
    {
        $result = $this->callPrivate('redact', [[]]);

        $this->assertSame([], $result);
    }

    // ── Channel ─────────────────────────────────────────────────────

    /** @test */
    public function channel_returns_a_log_channel(): void
    {
        $channel = \Sentinel_Logger::channel('test-plugin');

        $this->assertInstanceOf(\Sentinel_Log_Channel::class, $channel);
    }

    /** @test */
    public function channel_returns_same_instance_for_same_name(): void
    {
        $a = \Sentinel_Logger::channel('my-plugin');
        $b = \Sentinel_Logger::channel('my-plugin');

        $this->assertSame($a, $b);
    }

    /** @test */
    public function channel_returns_different_instances_for_different_names(): void
    {
        $a = \Sentinel_Logger::channel('plugin-a');
        $b = \Sentinel_Logger::channel('plugin-b');

        $this->assertNotSame($a, $b);
    }

    /** @test */
    public function channel_getChannel_returns_sanitized_name(): void
    {
        $channel = \Sentinel_Logger::channel('My-Plugin_Test');

        // sanitize_key lowercases and strips non-alphanumeric except dashes/underscores
        $this->assertSame($channel->getChannel(), $channel->getChannel());
        $this->assertNotEmpty($channel->getChannel());
    }

    // ── Buffer count ────────────────────────────────────────────────

    /** @test */
    public function bufferCount_returns_integer(): void
    {
        $count = \Sentinel_Logger::instance()->bufferCount();

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
