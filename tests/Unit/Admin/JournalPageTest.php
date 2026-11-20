<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use Sentinel\Admin\JournalPage;
use stdClass;

/*
 * Tests for the journal admin page.
 *
 * The bootstrap's $wpdb double answers every get_results() with the same
 * rows, so one seeded row serves both the channel list and the page of
 * entries; it carries the columns each reads.
 */

function seedJournalTable(int $total = 1): void
{
    global $wpdb;

    $wpdb->existingTable = \Sentinel_Journal::tableName();
    $wpdb->varReturn     = (string) $total;

    $row = new stdClass();
    $row->id          = '3f2c1d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
    $row->recorded_at = '2026-11-20 14:03:07.123456';
    $row->channel     = 'unity';
    $row->data        = '{"event":"shift_swapped","shift":"SHIFT-2026-08-15-N"}';

    $wpdb->rows = [$row];
}

afterEach(function () {
    global $wpdb;
    $wpdb->existingTable = '';
    $wpdb->varReturn     = '0';
    $wpdb->rows          = [];
    $_GET = [];
});

describe('registration', function () {
    it('runs init and registerPage without error', function () {
        JournalPage::init();
        JournalPage::registerPage();
    })->throwsNoExceptions();

    it('loads assets only on the journal screen', function () {
        JournalPage::enqueueAssets('some-other-page');
        JournalPage::registerPage();
        JournalPage::enqueueAssets($this->submenuHook(JournalPage::PAGE_SLUG));
    })->throwsNoExceptions();
});

describe('page', function () {
    it('refuses users without the capability', function () {
        $this->denyCapability();

        JournalPage::renderPage();
    })->throws(WpDieException::class);

    it('shows an empty state when there is no table yet', function () {
        $html = $this->capture([JournalPage::class, 'renderPage']);

        expect($html)->toContain('No journal entries yet.');
    });

    it('lists entries with their channel, id and pretty-printed JSON', function () {
        seedJournalTable();

        $html = $this->capture([JournalPage::class, 'renderPage']);

        expect($html)->toContain('unity')
            ->toContain('3f2c1d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f')
            ->toContain('2026-11-20 14:03:07')
            // esc_html() is htmlspecialchars() under wp-mocks, so the quotes arrive escaped.
            ->toContain('&quot;event&quot;: &quot;shift_swapped&quot;')
            ->toContain('wp_sentinel_journal');
    });

    it('filters by the requested channel', function () {
        global $wpdb;
        seedJournalTable();
        $_GET = ['channel' => 'unity'];

        $this->capture([JournalPage::class, 'renderPage']);

        $filtered = array_filter($wpdb->queries, fn (string $q): bool => str_contains($q, "WHERE channel = unity"));
        expect($filtered)->not->toBeEmpty();
    });

    it('pages, newest first, and clamps a page past the end', function () {
        global $wpdb;
        seedJournalTable(JournalPage::PER_PAGE * 2 + 1);

        $data = JournalPage::getPageData('', 99);

        expect($data['pages'])->toBe(3)
            ->and($data['page'])->toBe(3);

        $select = array_values(array_filter($wpdb->queries, fn (string $q): bool => str_contains($q, 'ORDER BY recorded_at DESC')));
        expect(end($select))->toContain('LIMIT ' . JournalPage::PER_PAGE . ' OFFSET ' . JournalPage::PER_PAGE * 2);
    });
});

describe('prettyJson', function () {
    it('falls back to the stored text when it does not decode', function () {
        expect(JournalPage::prettyJson('not json'))->toBe('not json');
    });

    it('keeps an empty value empty', function () {
        expect(JournalPage::prettyJson(''))->toBe('');
    });
});
