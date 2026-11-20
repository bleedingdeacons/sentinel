<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Journal;

use Sentinel\Journal\HasJournal;

/*
 * Tests for the HasJournal convenience trait, against the real wp_journal()
 * from sentinel-logger.php rather than a stand-in.
 */

beforeEach(function () {
    global $wpdb;
    $wpdb->inserts      = [];
    $wpdb->insertReturn = 1;
});

it('records a JSON string on the class-derived channel', function () {
    global $wpdb;

    $id = HasJournalDefaultChannel::journal('{"a":1}');

    expect($id)->toBeString()
        ->and(end($wpdb->inserts)[1]['channel'])->toBe('hasjournaldefaultchannel')
        ->and(end($wpdb->inserts)[1]['data'])->toBe('{"a":1}');
});

it('records a PHP object on the overridden channel', function () {
    global $wpdb;

    HasJournalCustomChannel::journal((object) ['b' => 2]);

    expect(end($wpdb->inserts)[1]['channel'])->toBe('custom-channel')
        ->and(end($wpdb->inserts)[1]['data'])->toBe('{"b":2}');
});

it('answers null for a string that is not JSON', function () {
    expect(HasJournalCustomChannel::journal('plain text'))->toBeNull();
});

// ── Consumers ─────────────────────────────────────────────────────

class HasJournalDefaultChannel
{
    use HasJournal;
}

class HasJournalCustomChannel
{
    use HasJournal;

    protected static function journalChannel(): string
    {
        return 'custom-channel';
    }
}
