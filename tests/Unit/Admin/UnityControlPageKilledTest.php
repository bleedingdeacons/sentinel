<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;
use Sentinel\Tests\AdminTestCase;

/**
 * Covers the UnityControlPage branches the main suite leaves out that need
 * the real constants defined: the runtime-killed and development-mode render
 * paths, which therefore run in isolated processes.
 *
 * Split out of UnityControlPageExtraTest when that file moved to Pest, which
 * refuses process isolation outright; this one stays a PHPUnit class so the
 * isolation survives, and Pest still runs it. The in-process writer branches
 * that shared the original class are in UnityControlPageExtraTest.php.
 */
#[CoversClass(\Sentinel\Admin\UnityControlPage::class)]
final class UnityControlPageKilledTest extends AdminTestCase
{
    private const KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetJustChanged();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->resetJustChanged();
        parent::tearDown();
    }

    private function resetJustChanged(): void
    {
        $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
        $prop->setValue(null, false);
    }

    // ── runtime-killed render (isolated: defines UNITY_KILL) ──────────────
    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
    public function render_reflects_the_kill_switch_engaged_at_runtime(): void
    {
        define('UNITY_KILL', true);
        $this->writeWpConfig(
            "<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n"
        );

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('kill switch engaged', $html);
        $this->assertStringContainsString('Enable Unity', $html);
    }

    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
    public function render_warns_when_killed_at_runtime_but_not_in_the_file(): void
    {
        define('UNITY_KILL', true);
        // No UNITY_KILL define written → killed at runtime, absent from file.
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('being set somewhere else', $html);
    }

    // ── development-mode render (isolated: defines PRODUCTION false) ──────
    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
    public function render_reflects_development_mode_at_runtime(): void
    {
        define('PRODUCTION', false);
        // PRODUCTION not written to file → dev at runtime, absent from file.
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('developer UI exposed', $html);
        $this->assertStringContainsString('Switch to Production Mode', $html);
        $this->assertStringContainsString('being set somewhere else', $html);
    }
}
