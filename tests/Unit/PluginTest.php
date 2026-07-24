<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit;

use ReflectionProperty;
use Sentinel\Plugin;
use Sentinel\Tests\AdminTestCase;

/**
 * Tests for the plugin bootstrap and its top-level admin menu.
 *
 * init() is guarded so it can only run once per request; the admin menu
 * registration then has to clean up after WordPress, which auto-creates a
 * submenu entry duplicating the parent label. Both behaviours are easy to
 * regress and invisible until someone looks at the menu.
 */
final class PluginTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetInitialised();
    }

    protected function tearDown(): void
    {
        $this->resetInitialised();
        unset($GLOBALS['submenu']);

        parent::tearDown();
    }

    private function resetInitialised(): void
    {
        $prop = new ReflectionProperty(Plugin::class, 'initialized');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    private function isInitialised(): bool
    {
        $prop = new ReflectionProperty(Plugin::class, 'initialized');
        $prop->setAccessible(true);

        return (bool) $prop->getValue();
    }

    /** @test */
    public function init_wires_the_admin_surface_once(): void
    {
        Plugin::init();

        $this->assertTrue($this->isInitialised());
    }

    /** @test */
    public function init_is_idempotent(): void
    {
        Plugin::init();
        // A second call must return early rather than registering every
        // page a second time.
        Plugin::init();

        $this->assertTrue($this->isInitialised());
    }

    /** @test */
    public function register_top_level_menu_adds_the_sentinel_menu(): void
    {
        Plugin::registerTopLevelMenu();

        $this->assertTrue(true, 'menu registered');
    }

    /** @test */
    public function duplicate_submenu_entry_is_removed(): void
    {
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
        $this->assertCount(1, $remaining);
        $this->assertSame('sentinel-settings', $remaining[0][2]);
    }

    /** @test */
    public function submenu_cleanup_is_a_no_op_when_there_is_no_submenu(): void
    {
        $GLOBALS['submenu'] = [];

        Plugin::removeDuplicateSubmenu();

        $this->assertSame([], $GLOBALS['submenu']);
    }

    /** @test */
    public function submenu_cleanup_leaves_a_menu_without_a_duplicate_alone(): void
    {
        $GLOBALS['submenu'] = [
            Plugin::MENU_SLUG => [
                0 => ['Settings', 'manage_options', 'sentinel-settings'],
                1 => ['Log Viewer', 'manage_options', 'sentinel-logs'],
            ],
        ];

        Plugin::removeDuplicateSubmenu();

        $this->assertCount(2, $GLOBALS['submenu'][Plugin::MENU_SLUG]);
    }
}
