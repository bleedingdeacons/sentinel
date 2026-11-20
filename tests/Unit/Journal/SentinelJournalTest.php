<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Journal;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Actions;

use function Brain\Monkey\Functions\when;

/*
 * Tests for the journal engine in sentinel-logger.php.
 *
 * Run against the real Sentinel_Journal over the bootstrap's $wpdb double,
 * which records every insert, so each test can say exactly what row would
 * have reached the table.
 */

const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

beforeEach(function () {
    global $wpdb;
    $wpdb->inserts      = [];
    $wpdb->insertReturn = 1;
});

/** @return array<string, mixed> The data of the last row inserted. */
function lastJournalRow(): array
{
    global $wpdb;
    $last = end($wpdb->inserts);
    expect($last)->not->toBeFalse('A row was inserted.');
    expect($last[0])->toBe('wp_sentinel_journal');

    return $last[1];
}

describe('recording', function () {
    it('stores a JSON string as given and returns its uuid', function () {
        $json = '{"event":"shift_swapped","shift":"SHIFT-2026-08-15-N"}';

        $id  = \Sentinel_Journal::record('unity', $json);
        $row = lastJournalRow();

        expect($id)->toMatch(UUID_V4)
            ->and($row['id'])->toBe($id)
            ->and($row['channel'])->toBe('unity')
            ->and($row['data'])->toBe($json);
    });

    it('encodes a PHP object', function () {
        \Sentinel_Journal::record('unity', (object) ['event' => 'swap', 'count' => 2, 'url' => 'a/b', 'name' => 'Zoë']);

        expect(lastJournalRow()['data'])->toBe('{"event":"swap","count":2,"url":"a/b","name":"Zoë"}');
    });

    it('lets a JsonSerializable decide its own shape', function () {
        $entry = new class implements \JsonSerializable {
            public string $hidden = 'not this';

            public function jsonSerialize(): mixed
            {
                return ['shape' => 'mine'];
            }
        };

        \Sentinel_Journal::record('unity', $entry);

        expect(lastJournalRow()['data'])->toBe('{"shape":"mine"}');
    });

    it('encodes an array', function () {
        \Sentinel_Journal::record('unity', ['a' => 1.0, 'b' => [1, 2]]);

        expect(lastJournalRow()['data'])->toBe('{"a":1.0,"b":[1,2]}');
    });

    it('sanitizes the channel like a log channel', function () {
        \Sentinel_Journal::record('My Plugin!', '{}');

        expect(lastJournalRow()['channel'])->toBe('myplugin');
    });

    it('gives every entry its own uuid', function () {
        $a = \Sentinel_Journal::record('unity', '{}');
        $b = \Sentinel_Journal::record('unity', '{}');

        expect($a)->not->toBe($b);
    });

    it('fires an action once the entry is stored', function () {
        Actions\expectDone('sentinel_journal_recorded')
            ->once()
            ->with(\Mockery::pattern(UUID_V4), 'unity', '{"x":1}');

        \Sentinel_Journal::record('unity', '{"x":1}');
    });

    it('is reachable through wp_journal()', function () {
        $id = wp_journal('scrutiny', ['ok' => true]);

        expect($id)->toMatch(UUID_V4)
            ->and(lastJournalRow()['channel'])->toBe('scrutiny');
    });
});

describe('time', function () {
    it('records site time from wp_timezone(), to the microsecond', function () {
        // Fourteen hours from UTC, so a UTC timestamp could not pass for it.
        $zone = new \DateTimeZone('Pacific/Kiritimati');
        when('wp_timezone')->justReturn($zone);

        \Sentinel_Journal::record('unity', '{}');
        $recordedAt = lastJournalRow()['recorded_at'];

        expect($recordedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/');

        $asSiteTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $recordedAt, $zone);
        expect(abs($asSiteTime->getTimestamp() - time()))->toBeLessThan(5);
    });
});

describe('refusals', function () {
    it('refuses a string that is not JSON, and writes nothing', function () {
        global $wpdb;

        expect(\Sentinel_Journal::record('unity', 'not json {'))->toBeNull()
            ->and($wpdb->inserts)->toBe([]);
    });

    it('refuses a value json_encode() cannot encode', function () {
        global $wpdb;

        expect(\Sentinel_Journal::record('unity', ['n' => NAN]))->toBeNull()
            ->and($wpdb->inserts)->toBe([]);
    });

    it('swallows whatever a JsonSerializable throws', function () {
        global $wpdb;
        $entry = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('not today');
            }
        };

        expect(\Sentinel_Journal::record('unity', $entry))->toBeNull()
            ->and($wpdb->inserts)->toBe([]);
    });

    it('logs the refusal on the caller\'s channel', function () {
        $before = \Sentinel_Logger::instance()->bufferCount();

        \Sentinel_Journal::record('unity', 'nope');

        expect(\Sentinel_Logger::instance()->bufferCount())->toBe($before + 1);
    });

    it('returns null when the insert fails', function () {
        global $wpdb;
        $wpdb->insertReturn = false;

        expect(\Sentinel_Journal::record('unity', '{}'))->toBeNull();
    });
});

describe('table', function () {
    it('creates the table once per schema version', function () {
        global $wpdb;
        unset(WpState::$options[\Sentinel_Journal::DB_VERSION_OPTION]);
        $GLOBALS['sentinel_test_dbdelta'] = [];
        $wpdb->existingTable = 'wp_sentinel_journal';

        try {
            \Sentinel_Journal::record('unity', '{}');
            \Sentinel_Journal::record('unity', '{}');
        } finally {
            $wpdb->existingTable = '';
        }

        expect($GLOBALS['sentinel_test_dbdelta'])->toHaveCount(1)
            ->and(WpState::$options[\Sentinel_Journal::DB_VERSION_OPTION])->toBe(\Sentinel_Journal::DB_VERSION);
    });

    it('keeps trying when the table could not be created', function () {
        // existingTable stays '', so the post-create check finds nothing:
        // dbDelta "ran" but the table is not there (no CREATE privilege, say).
        unset(WpState::$options[\Sentinel_Journal::DB_VERSION_OPTION]);
        $GLOBALS['sentinel_test_dbdelta'] = [];

        \Sentinel_Journal::record('unity', '{}');
        \Sentinel_Journal::record('unity', '{}');

        expect($GLOBALS['sentinel_test_dbdelta'])->toHaveCount(2, 'Each write retries the create.')
            ->and(WpState::$options)->not->toHaveKey(\Sentinel_Journal::DB_VERSION_OPTION);
    });

    it('keys on a uuid, with a site-time datetime and a JSON column', function () {
        $GLOBALS['sentinel_test_dbdelta'] = [];

        \Sentinel_Journal::createTable();
        $sql = (string) $GLOBALS['sentinel_test_dbdelta'][0];

        expect($sql)->toContain('CREATE TABLE wp_sentinel_journal')
            ->toContain('id char(36) NOT NULL')
            ->toContain('recorded_at datetime(6) NOT NULL')
            ->toContain('data json NOT NULL')
            ->toContain('PRIMARY KEY  (id)');
    });

    it('drops the table and forgets its version', function () {
        global $wpdb;

        \Sentinel_Journal::dropTable();

        expect(end($wpdb->queries))->toContain('DROP TABLE IF EXISTS `wp_sentinel_journal`')
            ->and(WpState::$options)->not->toHaveKey(\Sentinel_Journal::DB_VERSION_OPTION);
    });
});
