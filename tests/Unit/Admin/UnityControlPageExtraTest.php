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
 * Covers the UnityControlPage branches the main suite leaves out: the
 * runtime-killed and development-mode render paths (which need the real
 * constants defined, so they run in isolated processes), and the
 * marker-present-insert / no-op writer branches.
 */
#[CoversClass(\Sentinel\Admin\UnityControlPage::class)]
final class UnityControlPageExtraTest extends AdminTestCase
{
    private const KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';
    private const PROD_MARKER = '/* Environment Flag (managed by Sentinel) */';

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

    private function submit(string $action, array $extra = []): void
    {
        $_POST = array_merge([
            '_sentinel_unity_nonce' => 'n',
            'sentinel_unity_action' => $action,
        ], $extra);
        UnityControlPage::handleSave();
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

    // ── writer marker-insert branches (in-process) ───────────────────────
    #[Test]
    public function disable_inserts_under_an_existing_marker_without_a_define(): void
    {
        // Marker present but no define line → the "insert under marker" branch.
        $this->writeWpConfig("<?php\n" . self::KILL_MARKER . "\n\$table_prefix = 'wp_';\n");

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'UNITY_KILL', true );", $config);
        $this->assertSame(1, substr_count($config, self::KILL_MARKER));
    }

    #[Test]
    public function production_off_inserts_under_an_existing_marker_without_a_define(): void
    {
        $this->writeWpConfig("<?php\n" . self::PROD_MARKER . "\n\$table_prefix = 'wp_';\n");

        $this->submit('production_off');

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'PRODUCTION', false );", $config);
        $this->assertSame(1, substr_count($config, self::PROD_MARKER));
    }

    // ── writer no-op branches (value already correct) ────────────────────
    #[Test]
    public function disabling_when_already_true_is_a_noop_success(): void
    {
        $this->writeWpConfig("<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n");
        $before = $this->readWpConfig();

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $this->assertSame($before, $this->readWpConfig());
    }

    #[Test]
    public function production_off_when_already_false_is_a_noop_success(): void
    {
        $this->writeWpConfig("<?php\n" . self::PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n");
        $before = $this->readWpConfig();

        $this->submit('production_off');

        $this->assertSame($before, $this->readWpConfig());
    }
}
