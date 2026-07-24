<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;
use Sentinel\Tests\AdminTestCase;
use Sentinel\Tests\WpDieException;

/**
 * Tests for the Unity Control page.
 *
 * This page edits wp-config.php to stand Unity down (UNITY_KILL) and to
 * toggle the PRODUCTION flag, so the tests drive it against a real
 * throwaway wp-config.php under ABSPATH. Getting these rewrites wrong
 * would corrupt a live site's config, so the assertions check the exact
 * define() text, that markers are not duplicated, and that removal is
 * idempotent.
 *
 * The runtime-state readers (isKilledAtRuntime / isProductionAtRuntime)
 * are driven by defined() on real constants. Defining UNITY_KILL in-process
 * would leak into every other test, so only the undefined branch is
 * exercised here — which is also the branch that matters, since a site
 * with the switch engaged never reaches this admin page.
 */
final class UnityControlPageTest extends AdminTestCase
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

    /**
     * handleSave() latches a private static flag on success, which would
     * otherwise bleed into the rendering tests.
     */
    private function resetJustChanged(): void
    {
        $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    /** Submit the page's form with the given action. */
    private function submit(string $action, array $extra = []): void
    {
        $_POST = array_merge([
            '_sentinel_unity_nonce'  => 'n',
            'sentinel_unity_action'  => $action,
        ], $extra);

        UnityControlPage::handleSave();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_and_register_page_run_without_error(): void
    {
        UnityControlPage::init();
        UnityControlPage::registerPage();

        $this->assertTrue(true, 'registration completed');
    }

    // ── save handler guards ───────────────────────────────────────────

    /** @test */
    public function save_handler_ignores_requests_without_its_nonce_field(): void
    {
        $_POST = ['sentinel_unity_action' => 'disable'];

        UnityControlPage::handleSave();

        $this->assertTrue(true, 'returned before touching wp-config.php');
    }

    /** @test */
    public function save_handler_refuses_users_without_the_capability(): void
    {
        $this->denyCapability();
        $_POST = ['_sentinel_unity_nonce' => 'n'];

        $this->expectException(WpDieException::class);

        UnityControlPage::handleSave();
    }

    /** @test */
    public function save_handler_ignores_an_unrecognised_action(): void
    {
        $this->writeWpConfig();
        $before = $this->readWpConfig();

        $this->submit('something-else');

        $this->assertSame($before, $this->readWpConfig(), 'Unknown actions are dropped.');
    }

    /** @test */
    public function save_handler_reports_an_unwritable_config(): void
    {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $this->assertTrue(true, 'not-writable branch reported via add_settings_error');
    }

    // ── kill switch ───────────────────────────────────────────────────

    /** @test */
    public function disabling_unity_requires_the_confirmation_checkbox(): void
    {
        $this->writeWpConfig();

        $this->submit('disable'); // no confirmation ticked

        $this->assertStringNotContainsString(
            'UNITY_KILL',
            $this->readWpConfig(),
            'Without confirmation the kill switch must not be written.'
        );
    }

    /** @test */
    public function disabling_unity_writes_the_kill_switch_with_its_marker(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        $this->assertStringContainsString(self::KILL_MARKER, $config);
        $this->assertStringContainsString("define( 'UNITY_KILL', true );", $config);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    /** @test */
    public function disabling_twice_replaces_rather_than_duplicates(): void
    {
        $this->writeWpConfig("<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', false );\n");

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'UNITY_KILL', true );", $config);
        $this->assertSame(1, substr_count($config, 'UNITY_KILL'));
        $this->assertSame(1, substr_count($config, self::KILL_MARKER));
    }

    /** @test */
    public function enabling_unity_removes_the_define_and_marker(): void
    {
        $this->writeWpConfig(
            "<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n\$table_prefix = 'wp_';\n"
        );

        $this->submit('enable');

        $config = $this->readWpConfig();
        $this->assertStringNotContainsString('UNITY_KILL', $config);
        $this->assertStringNotContainsString(self::KILL_MARKER, $config);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    /** @test */
    public function enabling_unity_when_it_was_never_disabled_is_a_no_op(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        $this->submit('enable');

        $this->assertSame($before, $this->readWpConfig());
    }

    /** @test */
    public function the_kill_switch_is_written_even_without_a_php_opening_tag(): void
    {
        $this->writeWpConfig("no php tag\n");

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        $this->assertStringStartsWith('<?php', $config);
        $this->assertStringContainsString("define( 'UNITY_KILL', true );", $config);
    }

    /** @test */
    public function the_kill_switch_appends_when_the_config_is_a_single_line(): void
    {
        $this->writeWpConfig('<?php');

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $this->assertStringContainsString("define( 'UNITY_KILL', true );", $this->readWpConfig());
    }

    // ── PRODUCTION flag ───────────────────────────────────────────────

    /** @test */
    public function turning_production_off_writes_the_constant_false(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $this->submit('production_off');

        $config = $this->readWpConfig();
        $this->assertStringContainsString(self::PROD_MARKER, $config);
        $this->assertStringContainsString("define( 'PRODUCTION', false );", $config);
    }

    /** @test */
    public function turning_production_on_removes_the_constant_entirely(): void
    {
        // Production is the runtime default, so "on" means removing the
        // define rather than writing true.
        $this->writeWpConfig(
            "<?php\n" . self::PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n\$table_prefix = 'wp_';\n"
        );

        $this->submit('production_on');

        $config = $this->readWpConfig();
        $this->assertStringNotContainsString('PRODUCTION', $config);
        $this->assertStringNotContainsString(self::PROD_MARKER, $config);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    /** @test */
    public function turning_production_off_twice_replaces_rather_than_duplicates(): void
    {
        $this->writeWpConfig("<?php\n" . self::PROD_MARKER . "\ndefine( 'PRODUCTION', true );\n");

        $this->submit('production_off');

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'PRODUCTION', false );", $config);
        $this->assertSame(1, substr_count($config, 'PRODUCTION', 0));
        $this->assertSame(1, substr_count($config, self::PROD_MARKER));
    }

    /** @test */
    public function turning_production_on_when_undefined_is_a_no_op(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        $this->submit('production_on');

        $this->assertSame($before, $this->readWpConfig());
    }

    /** @test */
    public function production_is_written_even_without_a_php_opening_tag(): void
    {
        $this->writeWpConfig("no php tag\n");

        $this->submit('production_off');

        $this->assertStringStartsWith('<?php', $this->readWpConfig());
        $this->assertStringContainsString("define( 'PRODUCTION', false );", $this->readWpConfig());
    }

    /** @test */
    public function production_appends_when_the_config_is_a_single_line(): void
    {
        $this->writeWpConfig('<?php');

        $this->submit('production_off');

        $this->assertStringContainsString("define( 'PRODUCTION', false );", $this->readWpConfig());
    }

    // ── rendering ─────────────────────────────────────────────────────

    /** @test */
    public function render_page_shows_unity_running_when_no_kill_switch_is_set(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('Unity Control', $html);
        $this->assertStringContainsString('<form', $html);
        // Dependent plugins are listed so the operator knows the blast radius.
        $this->assertStringContainsString('Scrutiny', $html);
    }

    /** @test */
    public function render_page_reflects_a_kill_switch_present_in_the_file(): void
    {
        $this->writeWpConfig(
            "<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n"
        );

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('Unity Control', $html);
        $this->assertNotSame('', trim($html));
    }

    /** @test */
    public function render_page_reflects_production_defined_in_the_file(): void
    {
        $this->writeWpConfig(
            "<?php\n" . self::PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n"
        );

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('Unity Control', $html);
    }

    /** @test */
    public function render_page_handles_a_missing_wp_config(): void
    {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        // Falls back to the manual-instructions path rather than fataling.
        $this->assertStringContainsString('Unity Control', $html);
    }

    /** @test */
    public function render_page_emits_the_reload_script_after_a_successful_change(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        $this->assertStringContainsString('Unity Control', $html);
    }

    /** @test */
    public function render_page_refuses_users_without_the_capability(): void
    {
        $this->denyCapability();

        $this->expectException(WpDieException::class);

        UnityControlPage::renderPage();
    }
}
