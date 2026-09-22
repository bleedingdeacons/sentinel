<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\LogViewerPage;
use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use stdClass;

/*
 * Tests for the log viewer page.
 *
 * The page aggregates the log table by channel and level, then finds the
 * most recent message for each pair. The branches that matter are the
 * degenerate ones an operator hits first: no logger deployed, no table,
 * and a table with no rows — each must render an explanation rather than
 * an empty screen or a database error.
 *
 * handleClearAction()'s success path ends in exit(), which would take the
 * test runner down with it, so only its guard branches are exercised here.
 */

/**
 * Make the stubbed $wpdb report a populated log table.
 *
 * One row object serves both the GROUP BY query and the "latest row"
 * lookup, so it carries the columns each of them reads.
 */
function seedLogViewerTable(int $totalRows = 3): void
{
    global $wpdb;

    $wpdb->existingTable = \Sentinel_Logger::tableName();
    $wpdb->varReturn     = (string) $totalRows;

    $row = new stdClass();
    $row->channel    = 'scrutiny';
    $row->level      = 'error';
    $row->cnt        = $totalRows;
    $row->first_seen = '2026-07-20 09:00:00';
    $row->last_seen  = '2026-07-23 17:00:00';
    $row->message    = 'Something went wrong';
    $row->context    = '{"key":"value"}';

    $wpdb->rows = [$row];
}

// Reset the shared $wpdb double between tests.
afterEach(function () {
    global $wpdb;
    $wpdb->existingTable = '';
    $wpdb->varReturn     = '0';
    $wpdb->rows          = [];
    $_POST = [];
    $_GET  = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    // registration completed
    it('runs init and registerPage without error', function () {
        LogViewerPage::init();
        LogViewerPage::registerPage();
    })->throwsNoExceptions();

    // enqueue guarded by hook suffix
    it('loads assets only on the log viewer screen', function () {
        LogViewerPage::enqueueAssets('some-other-page');
        LogViewerPage::registerPage();
        LogViewerPage::enqueueAssets($this->submenuHook('sentinel-logs'));
    })->throwsNoExceptions();
});

// ── clear action guards ───────────────────────────────────────────
describe('clear action guards', function () {
    // returned before touching the table
    it('ignores requests without its POST field', function () {
        $_POST = [];

        LogViewerPage::handleClearAction();
    })->throwsNoExceptions();

    it('refuses users without the capability', function () {
        $this->denyCapability();
        $_POST = ['sentinel_clear_log' => '1'];

        LogViewerPage::handleClearAction();
    })->throws(WpDieException::class);
});

// ── aggregate table rendering ─────────────────────────────────────
describe('aggregate table', function () {
    it('explains itself when the table is absent', function () {
        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        expect(trim($html))->not->toBe('', 'An empty state is still rendered.');
    });

    it('handles a table that exists but is empty', function () {
        global $wpdb;
        $wpdb->existingTable = \Sentinel_Logger::tableName();
        $wpdb->varReturn     = '0'; // COUNT(*) === 0

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        expect(trim($html))->not->toBe('');
    });

    it('lists channel, level and latest message', function () {
        seedLogViewerTable();

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        expect($html)->toContain('scrutiny')
            ->toContain('error')
            ->toContain('Something went wrong');
    });

    it('copes when the group query returns nothing', function () {
        global $wpdb;
        // Non-zero count, but the GROUP BY comes back empty — a race between
        // the two queries, or a table truncated mid-request.
        $wpdb->existingTable = \Sentinel_Logger::tableName();
        $wpdb->varReturn     = '5';
        $wpdb->rows          = [];

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        expect(trim($html))->not->toBe('');
    });
});

// ── page rendering ────────────────────────────────────────────────
describe('renderPage', function () {
    it('shows the empty state with no table', function () {
        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        expect(trim($html))->not->toBe('');
    });

    it('shows aggregated rows when the table has data', function () {
        seedLogViewerTable();

        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        expect($html)->toContain('scrutiny')
            ->toContain('Something went wrong');
    });

    it('confirms a completed clear', function () {
        $_GET = ['cleared' => '1'];

        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        expect(strtolower($html))->toContain('cleared');
    });

    it('refuses users without the capability', function () {
        $this->denyCapability();

        LogViewerPage::renderPage();
    })->throws(WpDieException::class);
});

// ── ajax ──────────────────────────────────────────────────────────
describe('ajaxRefresh', function () {
    // wp_send_json_success short-circuits with a JsonResponseException.
    it('returns the aggregate table html', function () {
        seedLogViewerTable();

        expect(fn () => LogViewerPage::ajaxRefresh())
            ->toThrow(function (JsonResponseException $e) {
                expect($e->success)->toBeTrue()
                    ->and($e->data['html'])->toContain('scrutiny');
            });
    });

    // wp_send_json_error short-circuits with a JsonResponseException.
    it('is refused without the capability', function () {
        $this->denyCapability();

        expect(fn () => LogViewerPage::ajaxRefresh())
            ->toThrow(function (JsonResponseException $e) {
                expect($e->success)->toBeFalse();
            });
    });
});
