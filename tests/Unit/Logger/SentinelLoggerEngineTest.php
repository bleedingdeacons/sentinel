<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use BleedingDeacons\WpMocks\WpState;
use ReflectionMethod;
use ReflectionProperty;

/*
 * Exercises the Sentinel_Logger engine — buffered dispatch/flush, table
 * management, config resolution and request-type detection — against the
 * bootstrap's $wpdb stand-in, complementing SentinelLoggerTest (pure logic).
 */

// The logger lives in a mu-plugin-style file outside the PSR-4 tree and is
// only loaded by TestCase::setUp(), so it does not exist yet when this file is
// collected. covers() resolves its targets there and then, and loading the
// file early to satisfy it would run its top-level code outside any test and
// drop those lines from coverage. coversClass() — chained on the beforeEach
// below — records the same #[CoversClass] attributes without resolving them,
// exactly as PHPUnit did.

/** @param list<mixed> $rows */
function setLoggerBuffer(array $rows): void
{
    $p = new ReflectionProperty(\Sentinel_Logger::class, 'buffer');
    $p->setValue(\Sentinel_Logger::instance(), $rows);
}

function setLoggerProperty(string $name, mixed $value): void
{
    $p = new ReflectionProperty(\Sentinel_Logger::class, $name);
    $p->setValue(\Sentinel_Logger::instance(), $value);
}

/**
 * @param array<int, mixed> $args
 */
function callLoggerEngine(string $method, array $args = []): mixed
{
    $m = new ReflectionMethod(\Sentinel_Logger::class, $method);
    return $m->invoke(\Sentinel_Logger::instance(), ...$args);
}

beforeEach(function () {
    // do_action() is Brain Monkey's, and delete_option() is a real stub
    // over WpState — neither needs standing in for here any more.
    setLoggerBuffer([]);
    $GLOBALS['wpdb']->queries = [];
})->coversClass(\Sentinel_Logger::class, \Sentinel_Log_Channel::class, \Sentinel_Log_Level::class);

// ── dispatch → enqueue → buffer → flush ──────────────────────────────
describe('dispatch and flush', function () {
    it('buffers dispatched entries and writes them as rows on flush', function () {
        $channel = \Sentinel_Logger::channel('enginetest');
        $channel->info('User {name} did a thing', ['name' => 'Alice', 'password' => 'secret']);
        $channel->error('Something broke');

        expect(\Sentinel_Logger::instance()->bufferCount())->toBe(2);

        $written = \Sentinel_Logger::instance()->flush();
        expect($written)->toBe(2)
            ->and(\Sentinel_Logger::instance()->bufferCount())->toBe(0);

        // A bulk INSERT was issued.
        $insert = implode(' ', $GLOBALS['wpdb']->queries);
        expect($insert)->toContain('INSERT INTO');
    });

    it('treats a flush of an empty buffer as a no-op', function () {
        setLoggerBuffer([]);
        expect(\Sentinel_Logger::instance()->flush())->toBe(0);
    });

    it('respects the enabled flag', function () {
        setLoggerProperty('enabled', false);
        try {
            \Sentinel_Logger::channel('enginetest')->info('ignored');
            expect(\Sentinel_Logger::instance()->bufferCount())->toBe(0);
        } finally {
            setLoggerProperty('enabled', true);
        }
    });

    it('respects the level threshold', function () {
        setLoggerProperty('minLevel', 'error');
        try {
            \Sentinel_Logger::channel('enginetest')->debug('too chatty');
            expect(\Sentinel_Logger::instance()->bufferCount())->toBe(0);
        } finally {
            setLoggerProperty('minLevel', 'debug');
        }
    });
});

// ── table management ─────────────────────────────────────────────────
describe('table management', function () {
    it('runs dbDelta to create the table', function () {
        $GLOBALS['sentinel_test_dbdelta'] = [];
        \Sentinel_Logger::createTable();
        expect($GLOBALS['sentinel_test_dbdelta'])->not->toBeEmpty();
    });

    it('issues a TRUNCATE to truncate the table', function () {
        \Sentinel_Logger::truncateTable();
        expect(implode(' ', $GLOBALS['wpdb']->queries))->toContain('TRUNCATE TABLE');
    });

    it('issues a DROP to drop the table', function () {
        \Sentinel_Logger::dropTable();
        expect(implode(' ', $GLOBALS['wpdb']->queries))->toContain('DROP TABLE');
    });
});

// ── config resolution & request type ─────────────────────────────────
describe('config resolution and request type', function () {
    it('prefers a defined constant when resolving config', function () {
        // ABSPATH is defined by the bootstrap, so this hits the constant branch.
        expect(callLoggerEngine('resolveConfig', ['ABSPATH', 'unused_filter', 'fallback']))->toBe(ABSPATH);
    });

    it('falls back to the filter default when resolving config', function () {
        expect(callLoggerEngine('resolveConfig', ['SENTINEL_UNDEFINED_CONST', 'some_filter', 'fallback']))
            ->toBe('fallback');
    });

    it('defaults the request type to FRONT', function () {
        // No CLI/CRON/AJAX/REST markers, and not an admin request either.
        // wp-mocks' is_admin() defaults to true, so say so explicitly rather
        // than relying on the function being undefined, which is what used to
        // send this down the FRONT branch.
        WpState::$isAdmin = false;

        expect(callLoggerEngine('getRequestType'))->toBe('FRONT');
    });

    it('reports admin requests as ADMIN', function () {
        WpState::$isAdmin = true;

        expect(callLoggerEngine('getRequestType'))->toBe('ADMIN');
    });

    it('flushes on shutdown without error', function () {
        setLoggerBuffer([]);
        callLoggerEngine('handleShutdown');
    })->throwsNoExceptions();

    it('ignores a non-fatal last error on shutdown', function () {
        // handleShutdown() only records the error PHP died on, so a warning
        // left lying around must not be written as a fatal. Which of the two
        // guard branches this lands on used to depend on whatever incidental
        // error the rest of the run had triggered; seeding one makes it
        // deterministic.
        //
        // The warning is raised under a pass-through handler that returns
        // false, so PHP's own handler still records it for error_get_last()
        // while PHPUnit's never sees it. Pest's printer reports even an
        // @-suppressed warning as a WARN on the test; plain PHPUnit ignored it.
        setLoggerBuffer([]);
        set_error_handler(static fn (): bool => false, E_USER_WARNING);
        try {
            @trigger_error('a warning, not a fatal', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
        $before = count($GLOBALS['wpdb']->inserts);

        callLoggerEngine('handleShutdown');

        expect($GLOBALS['wpdb']->inserts)->toHaveCount($before, 'No fatal row was written.');
    });
});
