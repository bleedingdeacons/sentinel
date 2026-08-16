<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use Sentinel\Admin\StatusDashboard;
use Sentinel\Tests\AdminTestCase;

/**
 * Covers the StatusDashboard's UNITY_KILL-engaged render branches, which need
 * the real constant defined and therefore run in an isolated process.
 *
 * @covers \Sentinel\Admin\StatusDashboard
 */
final class StatusDashboardKilledTest extends AdminTestCase
{
    private function monitor(string $mandatory, string $optional = ''): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, $mandatory);
        $this->setOption(SettingsPage::OPTION_OPTIONAL_PLUGINS, $optional);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function render_flags_the_kill_switch_and_stands_down_dependents(): void
    {
        define('UNITY_KILL', true);

        // Unity plus a dependent, both installed and "active" per WordPress.
        $this->makePlugin('unity/unity.php', '2026-07-23');
        $this->makePlugin('scrutiny/scrutiny.php', '2026-07-22');
        $this->activePlugins = ['unity/unity.php', 'scrutiny/scrutiny.php'];
        $this->monitor("unity/unity.php|Unity\nscrutiny/scrutiny.php|Scrutiny");

        $html = $this->capture([StatusDashboard::class, 'render']);

        // Overall health reflects the kill switch.
        $this->assertStringContainsString('Kill Switch', $html);
        // The dependent is shown as unavailable rather than active.
        $this->assertStringContainsString('Unavailable', $html);
        // The alert help block explains the situation.
        $this->assertStringContainsString('Unity is disabled', $html);
    }

    /**
     * Promises reads every one of its tools' data through Unity and boots from
     * `unity/loaded`, so a kill switch leaves it installed, active and unable
     * to answer a single request. It has to be stood down with the rest.
     *
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function render_stands_promises_down_with_the_other_unity_dependents(): void
    {
        define('UNITY_KILL', true);

        $this->makePlugin('unity/unity.php', '2026-08-16');
        $this->makePlugin('promises/promises.php', '2026-08-16');
        $this->activePlugins = ['unity/unity.php', 'promises/promises.php'];
        $this->monitor("unity/unity.php|Unity", "promises/promises.php|Promises");

        $html = $this->capture([StatusDashboard::class, 'render']);

        $this->assertStringContainsString('Promises', $html);
        $this->assertStringContainsString('Unavailable', $html);
    }
}
