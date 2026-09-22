<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use Sentinel\Admin\StatusDashboard;
use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;

/*
 * Tests for the dashboard status widget.
 *
 * The widget's job is to tell an operator, at a glance, whether the suite
 * is healthy. The interesting behaviour is therefore in how it classifies
 * each monitored plugin — installed vs missing, active vs inactive, and
 * what version and build date it reports — and in the rule that optional
 * plugins are hidden when absent and never drag the overall indicator down.
 *
 * Fixture plugins are written to a temp WP_PLUGIN_DIR so the installed /
 * version / build-date reads run against real files.
 */

beforeEach(function () {
    // Point the widget at a small, predictable plugin set.
    $this->monitor = function (string $mandatory, string $optional = ''): void {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, $mandatory);
        $this->setOption(SettingsPage::OPTION_OPTIONAL_PLUGINS, $optional);
    };
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    // hooks registered
    it('registers the widget hooks on init', function () {
        StatusDashboard::init();
    })->throwsNoExceptions();

    // enqueue guarded by hook
    it('loads assets only on the dashboard screen', function () {
        StatusDashboard::enqueueAssets('edit.php');
        StatusDashboard::enqueueAssets('index.php');
    })->throwsNoExceptions();

    // widget registered
    it('registers the widget for a permitted user', function () {
        StatusDashboard::register();
    })->throwsNoExceptions();

    // registration skipped
    it('does not register the widget without the capability', function () {
        $this->denyCapability();

        StatusDashboard::register();
    })->throwsNoExceptions();
});

// ── rendering ─────────────────────────────────────────────────────
describe('render', function () {
    it('reports an installed and active plugin with its version', function () {
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->activePlugins = ['unity/unity.php'];
        $this->pluginVersion = '1.18.9';
        ($this->monitor)('unity/unity.php|Unity');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Unity')
            ->toContain('1.18.9')
            ->toContain('2026-07-23');
    });

    it('distinguishes an installed but inactive plugin from an active one', function () {
        $this->makePlugin('scrutiny/scrutiny.php', '2026-07-22');
        $this->activePlugins = []; // installed, not activated
        ($this->monitor)('scrutiny/scrutiny.php|Scrutiny');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Scrutiny');
    });

    // Mandatory plugins are always shown so a missing one is visible.
    it('still lists a mandatory plugin that is not installed', function () {
        // Nothing written to disk for this one.
        ($this->monitor)('missing/missing.php|Missing Plugin');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Missing Plugin');
    });

    it('hides an optional plugin when not installed', function () {
        ($this->monitor)('unity/unity.php|Unity', 'ghost/ghost.php|Ghost Plugin');
        $this->makePlugin('unity/unity.php', '2026-07-23');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Unity')
            ->not->toContain('Ghost Plugin'); // Optional plugins only appear once installed.
    });

    it('shows an optional plugin once installed', function () {
        ($this->monitor)('unity/unity.php|Unity', 'reach/reach.php|Reach');
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->makePlugin('reach/reach.php', '2026-07-21');
        $this->activePlugins = ['unity/unity.php', 'reach/reach.php'];

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Reach');
    });

    it('does not duplicate a key claimed by the mandatory list from the optional one', function () {
        ($this->monitor)('unity/unity.php|Unity Mandatory', 'unity/unity.php|Unity Optional');
        $this->makePlugin('unity/unity.php', '2026-07-23');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Unity Mandatory')
            ->not->toContain('Unity Optional');
    });

    it('reports no build date for a plugin without a readme', function () {
        $this->makePlugin('nodate/nodate.php', null); // no readme written
        ($this->monitor)('nodate/nodate.php|No Date');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('No Date');
    });

    it('also reads an uppercase readme for the build date', function () {
        $this->makePlugin('upper/upper.php', '2026-01-09', 'README.txt');
        ($this->monitor)('upper/upper.php|Upper');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('2026-01-09');
    });

    it('tolerates a readme without a build date line', function () {
        $this->makePlugin('plain/plain.php', null);
        file_put_contents(WP_PLUGIN_DIR . '/plain/readme.txt', "=== Plain ===\nStable tag: 1.0.0\n");
        ($this->monitor)('plain/plain.php|Plain');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect($html)->toContain('Plain');
    });

    it('copes with no monitored plugins at all', function () {
        ($this->monitor)('', '');

        $html = $this->capture([StatusDashboard::class, 'render']);

        expect(trim($html))->not->toBe('', 'The widget still renders its shell.');
    });
});

// ── ajax ──────────────────────────────────────────────────────────
describe('ajaxRefresh', function () {
    // wp_send_json_success short-circuits with a JsonResponseException.
    it('returns the widget html', function () {
        $this->makePlugin('unity/unity.php', '2026-07-23');
        ($this->monitor)('unity/unity.php|Unity');

        expect(fn () => StatusDashboard::ajaxRefresh())
            ->toThrow(function (JsonResponseException $e) {
                expect($e->success)->toBeTrue()
                    ->and($e->data)->toBeArray()
                    ->and($e->data['html'])->toContain('Unity');
            });
    });

    // wp_send_json_error short-circuits with a JsonResponseException.
    it('is refused without the capability', function () {
        $this->denyCapability();

        expect(fn () => StatusDashboard::ajaxRefresh())
            ->toThrow(function (JsonResponseException $e) {
                expect($e->success)->toBeFalse();
            });
    });
});
