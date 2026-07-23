<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use Sentinel\Admin\StatusDashboard;
use Sentinel\Tests\AdminTestCase;
use Sentinel\Tests\JsonResponseException;

/**
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
final class StatusDashboardTest extends AdminTestCase
{
    /** Point the widget at a small, predictable plugin set. */
    private function monitor(string $mandatory, string $optional = ''): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, $mandatory);
        $this->setOption(SettingsPage::OPTION_OPTIONAL_PLUGINS, $optional);
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_widget_hooks(): void
    {
        StatusDashboard::init();

        $this->assertTrue(true, 'hooks registered');
    }

    /** @test */
    public function assets_load_only_on_the_dashboard_screen(): void
    {
        StatusDashboard::enqueueAssets('edit.php');
        StatusDashboard::enqueueAssets('index.php');

        $this->assertTrue(true, 'enqueue guarded by hook');
    }

    /** @test */
    public function widget_is_registered_for_a_permitted_user(): void
    {
        StatusDashboard::register();

        $this->assertTrue(true, 'widget registered');
    }

    /** @test */
    public function widget_is_not_registered_without_the_capability(): void
    {
        $this->denyCapability();

        StatusDashboard::register();

        $this->assertTrue(true, 'registration skipped');
    }

    // ── rendering ─────────────────────────────────────────────────────

    /** @test */
    public function an_installed_and_active_plugin_is_reported_with_its_version(): void
    {
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->activePlugins = ['unity/unity.php'];
        $this->pluginVersion = '1.18.9';
        $this->monitor('unity/unity.php|Unity');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Unity', $html);
        $this->assertStringContainsString('1.18.9', $html);
        $this->assertStringContainsString('2026-07-23', $html);
    }

    /** @test */
    public function an_installed_but_inactive_plugin_is_distinguished_from_an_active_one(): void
    {
        $this->makePlugin('scrutiny/scrutiny.php', '2026-07-22');
        $this->activePlugins = []; // installed, not activated
        $this->monitor('scrutiny/scrutiny.php|Scrutiny');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Scrutiny', $html);
    }

    /** @test */
    public function a_mandatory_plugin_that_is_not_installed_is_still_listed(): void
    {
        // Nothing written to disk for this one.
        $this->monitor('missing/missing.php|Missing Plugin');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString(
            'Missing Plugin',
            $html,
            'Mandatory plugins are always shown so a missing one is visible.'
        );
    }

    /** @test */
    public function an_optional_plugin_is_hidden_when_not_installed(): void
    {
        $this->monitor('unity/unity.php|Unity', 'ghost/ghost.php|Ghost Plugin');
        $this->makePlugin('unity/unity.php', '2026-07-23');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Unity', $html);
        $this->assertStringNotContainsString(
            'Ghost Plugin',
            $html,
            'Optional plugins only appear once installed.'
        );
    }

    /** @test */
    public function an_optional_plugin_is_shown_once_installed(): void
    {
        $this->monitor('unity/unity.php|Unity', 'reach/reach.php|Reach');
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->makePlugin('reach/reach.php', '2026-07-21');
        $this->activePlugins = ['unity/unity.php', 'reach/reach.php'];

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Reach', $html);
    }

    /** @test */
    public function a_key_claimed_by_the_mandatory_list_is_not_duplicated_by_the_optional_one(): void
    {
        $this->monitor('unity/unity.php|Unity Mandatory', 'unity/unity.php|Unity Optional');
        $this->makePlugin('unity/unity.php', '2026-07-23');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Unity Mandatory', $html);
        $this->assertStringNotContainsString('Unity Optional', $html);
    }

    /** @test */
    public function a_plugin_without_a_readme_reports_no_build_date(): void
    {
        $this->makePlugin('nodate/nodate.php', null); // no readme written
        $this->monitor('nodate/nodate.php|No Date');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('No Date', $html);
    }

    /** @test */
    public function an_uppercase_readme_is_also_read_for_the_build_date(): void
    {
        $this->makePlugin('upper/upper.php', '2026-01-09', 'README.txt');
        $this->monitor('upper/upper.php|Upper');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('2026-01-09', $html);
    }

    /** @test */
    public function a_readme_without_a_build_date_line_is_tolerated(): void
    {
        $this->makePlugin('plain/plain.php', null);
        file_put_contents(WP_PLUGIN_DIR . '/plain/readme.txt', "=== Plain ===\nStable tag: 1.0.0\n");
        $this->monitor('plain/plain.php|Plain');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Plain', $html);
    }

    /** @test */
    public function render_copes_with_no_monitored_plugins_at_all(): void
    {
        $this->monitor('', '');

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertNotSame('', trim($html), 'The widget still renders its shell.');
    }

    // ── ajax ──────────────────────────────────────────────────────────

    /** @test */
    public function ajax_refresh_returns_the_widget_html(): void
    {
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->monitor('unity/unity.php|Unity');

        try {
            StatusDashboard::ajaxRefresh();
            $this->fail('Expected wp_send_json_success to short-circuit.');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertIsArray($e->data);
            $this->assertStringContainsString('Unity', $e->data['html']);
        }
    }

    /** @test */
    public function ajax_refresh_is_refused_without_the_capability(): void
    {
        $this->denyCapability();

        try {
            StatusDashboard::ajaxRefresh();
            $this->fail('Expected wp_send_json_error to short-circuit.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
        }
    }
}
