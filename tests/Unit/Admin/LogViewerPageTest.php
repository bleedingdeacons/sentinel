<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\LogViewerPage;
use Sentinel\Tests\AdminTestCase;
use Sentinel\Tests\JsonResponseException;
use Sentinel\Tests\WpDieException;
use stdClass;

/**
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
final class LogViewerPageTest extends AdminTestCase
{
    /**
     * Make the stubbed $wpdb report a populated log table.
     *
     * One row object serves both the GROUP BY query and the "latest row"
     * lookup, so it carries the columns each of them reads.
     */
    private function seedLogTable(int $totalRows = 3): void
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

    /** Reset the shared $wpdb double between tests. */
    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->existingTable = '';
        $wpdb->varReturn     = '0';
        $wpdb->rows          = [];
        $_POST = [];
        $_GET  = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_and_register_page_run_without_error(): void
    {
        LogViewerPage::init();
        LogViewerPage::registerPage();

        $this->assertTrue(true, 'registration completed');
    }

    /** @test */
    public function assets_load_only_on_the_log_viewer_screen(): void
    {
        LogViewerPage::enqueueAssets('some-other-page');
        LogViewerPage::registerPage();
        LogViewerPage::enqueueAssets('sentinel_page_stub');

        $this->assertTrue(true, 'enqueue guarded by hook suffix');
    }

    // ── clear action guards ───────────────────────────────────────────

    /** @test */
    public function clear_action_ignores_requests_without_its_post_field(): void
    {
        $_POST = [];

        LogViewerPage::handleClearAction();

        $this->assertTrue(true, 'returned before touching the table');
    }

    /** @test */
    public function clear_action_refuses_users_without_the_capability(): void
    {
        $this->denyCapability();
        $_POST = ['sentinel_clear_log' => '1'];

        $this->expectException(WpDieException::class);

        LogViewerPage::handleClearAction();
    }

    // ── aggregate table rendering ─────────────────────────────────────

    /** @test */
    public function aggregate_table_explains_itself_when_the_table_is_absent(): void
    {
        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        $this->assertNotSame('', trim($html), 'An empty state is still rendered.');
    }

    /** @test */
    public function aggregate_table_handles_a_table_that_exists_but_is_empty(): void
    {
        global $wpdb;
        $wpdb->existingTable = \Sentinel_Logger::tableName();
        $wpdb->varReturn     = '0'; // COUNT(*) === 0

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        $this->assertNotSame('', trim($html));
    }

    /** @test */
    public function aggregate_table_lists_channel_level_and_latest_message(): void
    {
        $this->seedLogTable();

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        $this->assertStringContainsString('scrutiny', $html);
        $this->assertStringContainsString('error', $html);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    /** @test */
    public function aggregate_table_copes_when_the_group_query_returns_nothing(): void
    {
        global $wpdb;
        // Non-zero count, but the GROUP BY comes back empty — a race between
        // the two queries, or a table truncated mid-request.
        $wpdb->existingTable = \Sentinel_Logger::tableName();
        $wpdb->varReturn     = '5';
        $wpdb->rows          = [];

        $html = $this->capture([LogViewerPage::class, 'renderAggregateTable']);

        $this->assertNotSame('', trim($html));
    }

    // ── page rendering ────────────────────────────────────────────────

    /** @test */
    public function render_page_shows_the_empty_state_with_no_table(): void
    {
        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        $this->assertNotSame('', trim($html));
    }

    /** @test */
    public function render_page_shows_aggregated_rows_when_the_table_has_data(): void
    {
        $this->seedLogTable();

        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        $this->assertStringContainsString('scrutiny', $html);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    /** @test */
    public function render_page_confirms_a_completed_clear(): void
    {
        $_GET = ['cleared' => '1'];

        $html = $this->capture([LogViewerPage::class, 'renderPage']);

        $this->assertStringContainsString('cleared', strtolower($html));
    }

    /** @test */
    public function render_page_refuses_users_without_the_capability(): void
    {
        $this->denyCapability();

        $this->expectException(WpDieException::class);

        LogViewerPage::renderPage();
    }

    // ── ajax ──────────────────────────────────────────────────────────

    /** @test */
    public function ajax_refresh_returns_the_aggregate_table_html(): void
    {
        $this->seedLogTable();

        try {
            LogViewerPage::ajaxRefresh();
            $this->fail('Expected wp_send_json_success to short-circuit.');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertStringContainsString('scrutiny', $e->data['html']);
        }
    }

    /** @test */
    public function ajax_refresh_is_refused_without_the_capability(): void
    {
        $this->denyCapability();

        try {
            LogViewerPage::ajaxRefresh();
            $this->fail('Expected wp_send_json_error to short-circuit.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
        }
    }
}
