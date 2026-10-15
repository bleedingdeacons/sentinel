<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use BleedingDeacons\WpMocks\WpState;
use Sentinel\Monitor\Core\Schema;
use Sentinel\Tests\Monitor\Fixtures\WpdbStub;

/**
 * Tables have to exist on sites that *update* Sentinel, not only on sites
 * that activate it.
 *
 * The activation hook was the only thing creating them, and WordPress
 * does not fire it when a plugin is updated in place - which is how these
 * sites take releases. A version that added a table therefore shipped
 * code expecting a table nobody had created, and the symptom was ugly:
 * enrolment appeared to succeed and handed back a token for a row that
 * did not exist.
 */

beforeEach(function () {
    WpState::$options = [];

    // ensureInstalled() reaches for the global $wpdb and runs each
    // installer. The SQL they emit is pinned by the repository tests;
    // what matters here is only whether they are reached at all.
    // dbDelta is already a no-op from the shared bootstrap stubs.
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new WpdbStub();
    $GLOBALS['sentinel_test_dbdelta'] = [];
});

afterEach(function () {
    // Put back the logger's stand-in rather than unsetting it: the logger
    // flushes into the global $wpdb at shutdown, after every test has run.
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

test('installs when no version has ever been recorded', function () {
    Schema::ensureInstalled();

    $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
});

test('installs when the stored version is behind', function () {
    // The case that matters: a site that has had Sentinel for a while and
    // has just taken a release which added a table.
    update_option(Schema::OPTION, Schema::VERSION - 1, true);

    Schema::ensureInstalled();

    $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
});

test('does nothing when already current', function () {
    update_option(Schema::OPTION, Schema::VERSION, true);

    Schema::ensureInstalled();

    $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
});

test('ahead is left alone', function () {
    // A downgrade must not drag the recorded version backwards: the
    // tables on disk are still the newer shape, and claiming otherwise
    // would make the next upgrade skip its dbDelta.
    update_option(Schema::OPTION, Schema::VERSION + 5, true);

    Schema::ensureInstalled();

    $this->assertSame(Schema::VERSION + 5, (int) get_option(Schema::OPTION, 0));
});

test('mark installed records the current version', function () {
    Schema::markInstalled();

    $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
});

test('installs every Monitor table under its own name', function () {
    Schema::install(new WpdbStub());

    $sql = implode("\n", array_map('strval', $GLOBALS['sentinel_test_dbdelta']));

    foreach (['devices', 'alerts', 'alert_acks', 'alert_contacts', 'alert_replies'] as $table) {
        $this->assertStringContainsString('CREATE TABLE wp_sentinel_monitor_' . $table . ' (', $sql);
    }
    $this->assertStringNotContainsString('reach_', $sql);
});

test('devices carry the error alert opt in, off by default', function () {
    Schema::install(new WpdbStub());

    $sql = implode("\n", array_map('strval', $GLOBALS['sentinel_test_dbdelta']));

    $this->assertStringContainsString('error_alerts TINYINT(1) NOT NULL DEFAULT 0', $sql);
});

test('uninstall drops every table and forgets the version', function () {
    update_option(Schema::OPTION, Schema::VERSION, true);
    $wpdb = new WpdbStub();

    Schema::uninstall($wpdb);

    $dropped = implode("\n", $wpdb->queries);
    foreach (['devices', 'alerts', 'alert_acks', 'alert_contacts', 'alert_replies'] as $table) {
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_sentinel_monitor_' . $table . '`', $dropped);
    }
    $this->assertSame(0, (int) get_option(Schema::OPTION, 0));
});
