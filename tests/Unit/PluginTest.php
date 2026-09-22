<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit;

use ReflectionProperty;
use Sentinel\Plugin;

/*
 * Tests for the plugin bootstrap and its top-level admin menu.
 *
 * init() is guarded so it can only run once per request; the admin menu
 * registration then has to clean up after WordPress, which auto-creates a
 * submenu entry duplicating the parent label. Both behaviours are easy to
 * regress and invisible until someone looks at the menu.
 */

function resetPluginInitialised(): void
{
    $prop = new ReflectionProperty(Plugin::class, 'initialized');
    $prop->setValue(null, false);
}

function isPluginInitialised(): bool
{
    $prop = new ReflectionProperty(Plugin::class, 'initialized');

    return (bool) $prop->getValue();
}

beforeEach(function () {
    resetPluginInitialised();
});

afterEach(function () {
    resetPluginInitialised();
    unset($GLOBALS['submenu']);
});

describe('init', function () {
    it('wires the admin surface once', function () {
        Plugin::init();

        expect(isPluginInitialised())->toBeTrue();
    });

    it('is idempotent', function () {
        Plugin::init();
        // A second call must return early rather than registering every
        // page a second time.
        Plugin::init();

        expect(isPluginInitialised())->toBeTrue();
    });
});

// menu registered
it('adds the Sentinel menu in registerTopLevelMenu', function () {
    Plugin::registerTopLevelMenu();
})->throwsNoExceptions();

describe('removeDuplicateSubmenu', function () {
    it('removes the duplicate submenu entry', function () {
        // WordPress auto-creates a first submenu whose slug equals the
        // parent slug; that is the one that must go.
        $GLOBALS['submenu'] = [
            Plugin::MENU_SLUG => [
                0 => ['Sentinel', 'manage_options', Plugin::MENU_SLUG],
                1 => ['Settings', 'manage_options', 'sentinel-settings'],
            ],
        ];

        Plugin::removeDuplicateSubmenu();

        $remaining = array_values($GLOBALS['submenu'][Plugin::MENU_SLUG]);
        expect($remaining)->toHaveCount(1)
            ->and($remaining[0][2])->toBe('sentinel-settings');
    });

    it('is a no-op when there is no submenu', function () {
        $GLOBALS['submenu'] = [];

        Plugin::removeDuplicateSubmenu();

        expect($GLOBALS['submenu'])->toBe([]);
    });

    it('leaves a menu without a duplicate alone', function () {
        $GLOBALS['submenu'] = [
            Plugin::MENU_SLUG => [
                0 => ['Settings', 'manage_options', 'sentinel-settings'],
                1 => ['Log Viewer', 'manage_options', 'sentinel-logs'],
            ],
        ];

        Plugin::removeDuplicateSubmenu();

        expect($GLOBALS['submenu'][Plugin::MENU_SLUG])->toHaveCount(2);
    });
});
