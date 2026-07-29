<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use ReflectionMethod;
use ReflectionProperty;
use Sentinel\Tests\TestCase;
use WP_Mock;

/**
 * Exercises the Sentinel_Logger engine — buffered dispatch/flush, table
 * management, config resolution and request-type detection — against the
 * bootstrap's $wpdb stand-in, complementing SentinelLoggerTest (pure logic).
 *
 * @covers \Sentinel_Logger
 * @covers \Sentinel_Log_Channel
 * @covers \Sentinel_Log_Level
 */
class SentinelLoggerEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::userFunction('do_action')->andReturn(null);
        WP_Mock::userFunction('delete_option')->andReturn(true);
        $this->setBuffer([]);
        $GLOBALS['wpdb']->queries = [];
    }

    /** @param list<mixed> $rows */
    private function setBuffer(array $rows): void
    {
        $p = new ReflectionProperty(\Sentinel_Logger::class, 'buffer');
        $p->setAccessible(true);
        $p->setValue(\Sentinel_Logger::instance(), $rows);
    }

    private function setPrivate(string $name, mixed $value): void
    {
        $p = new ReflectionProperty(\Sentinel_Logger::class, $name);
        $p->setAccessible(true);
        $p->setValue(\Sentinel_Logger::instance(), $value);
    }

    private function callPrivate(string $method, array $args = []): mixed
    {
        $m = new ReflectionMethod(\Sentinel_Logger::class, $method);
        $m->setAccessible(true);
        return $m->invoke(\Sentinel_Logger::instance(), ...$args);
    }

    // ── dispatch → enqueue → buffer → flush ──────────────────────────────

    public function testDispatchBuffersAndFlushWritesRows(): void
    {
        $channel = \Sentinel_Logger::channel('enginetest');
        $channel->info('User {name} did a thing', ['name' => 'Alice', 'password' => 'secret']);
        $channel->error('Something broke');

        $this->assertSame(2, \Sentinel_Logger::instance()->bufferCount());

        $written = \Sentinel_Logger::instance()->flush();
        $this->assertSame(2, $written);
        $this->assertSame(0, \Sentinel_Logger::instance()->bufferCount());

        // A bulk INSERT was issued.
        $insert = implode(' ', $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('INSERT INTO', $insert);
    }

    public function testFlushOnEmptyBufferIsANoop(): void
    {
        $this->setBuffer([]);
        $this->assertSame(0, \Sentinel_Logger::instance()->flush());
    }

    public function testDispatchRespectsTheEnabledFlag(): void
    {
        $this->setPrivate('enabled', false);
        try {
            \Sentinel_Logger::channel('enginetest')->info('ignored');
            $this->assertSame(0, \Sentinel_Logger::instance()->bufferCount());
        } finally {
            $this->setPrivate('enabled', true);
        }
    }

    public function testDispatchRespectsTheLevelThreshold(): void
    {
        $this->setPrivate('minLevel', 'error');
        try {
            \Sentinel_Logger::channel('enginetest')->debug('too chatty');
            $this->assertSame(0, \Sentinel_Logger::instance()->bufferCount());
        } finally {
            $this->setPrivate('minLevel', 'debug');
        }
    }

    // ── table management ─────────────────────────────────────────────────

    public function testCreateTableRunsDbDelta(): void
    {
        $GLOBALS['sentinel_test_dbdelta'] = [];
        \Sentinel_Logger::createTable();
        $this->assertNotEmpty($GLOBALS['sentinel_test_dbdelta']);
    }

    public function testTruncateTableIssuesTruncate(): void
    {
        \Sentinel_Logger::truncateTable();
        $this->assertStringContainsString('TRUNCATE TABLE', implode(' ', $GLOBALS['wpdb']->queries));
    }

    public function testDropTableIssuesDrop(): void
    {
        \Sentinel_Logger::dropTable();
        $this->assertStringContainsString('DROP TABLE', implode(' ', $GLOBALS['wpdb']->queries));
    }

    // ── config resolution & request type ─────────────────────────────────

    public function testResolveConfigPrefersADefinedConstant(): void
    {
        // ABSPATH is defined by the bootstrap, so this hits the constant branch.
        $this->assertSame(ABSPATH, $this->callPrivate('resolveConfig', ['ABSPATH', 'unused_filter', 'fallback']));
    }

    public function testResolveConfigFallsBackToFilterDefault(): void
    {
        $this->assertSame('fallback', $this->callPrivate('resolveConfig', ['SENTINEL_UNDEFINED_CONST', 'some_filter', 'fallback']));
    }

    public function testGetRequestTypeDefaultsToFront(): void
    {
        // No CLI/CRON/AJAX/REST/admin markers in a plain unit run.
        $this->assertSame('FRONT', $this->callPrivate('getRequestType'));
    }

    public function testHandleShutdownFlushesWithoutError(): void
    {
        $this->setBuffer([]);
        $this->callPrivate('handleShutdown');
        $this->assertTrue(true);
    }
}
