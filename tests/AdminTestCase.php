<?php

declare(strict_types=1);

namespace Sentinel\Tests;

use WP_Mock;

/**
 * Base case for the admin-page tests.
 *
 * The admin classes are thin over WordPress: they register menus, read
 * options, and emit large blocks of HTML. Testing them therefore means
 * standing up enough of WordPress that a render call runs end to end, so
 * this class stubs the whole surface they touch in one place rather than
 * repeating it per test.
 *
 * Two deliberate choices:
 *
 *   - Escaping and translation helpers pass their input straight through.
 *     The assertions are about what the page says, not about escaping,
 *     which is WordPress's job and is covered by its own tests.
 *   - wp_die() throws {@see WpDieException} rather than returning. It is
 *     a terminating function in production, so a test that reaches it must
 *     stop there; throwing makes the capability guards assertable.
 */
abstract class AdminTestCase extends TestCase
{
    /** Files created under ABSPATH by a test, removed on tearDown. */
    private array $createdFiles = [];

    /** What the stubbed current_user_can() returns; see denyCapability(). */
    protected bool $userCan = true;

    /** Plugin files the stubbed is_plugin_active() should report as active. */
    protected array $activePlugins = [];

    /** Version the stubbed get_plugin_data() reports. */
    protected string $pluginVersion = '1.0.0';

    /** Fixture plugin directories created under WP_PLUGIN_DIR. */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->userCan = true;
        $this->activePlugins = [];
        $this->pluginVersion = '1.0.0';
        $this->stubAdminFunctions();
    }

    /** Make current_user_can() return false, to reach the guard branches. */
    protected function denyCapability(): void
    {
        $this->userCan = false;
    }

    /**
     * Create a fixture plugin on disk under WP_PLUGIN_DIR.
     *
     * @param string      $file      Plugin file, e.g. "unity/unity.php".
     * @param string|null $buildDate Written as a "Build date:" readme header;
     *                               null writes no readme at all.
     */
    protected function makePlugin(string $file, ?string $buildDate = null, string $readmeName = 'readme.txt'): string
    {
        $full = WP_PLUGIN_DIR . '/' . $file;
        $dir  = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->createdDirs[] = $dir;
        }
        file_put_contents($full, "<?php\n// fixture plugin\n");

        if ($buildDate !== null) {
            file_put_contents(
                $dir . '/' . $readmeName,
                "=== Fixture ===\nStable tag: 1.0.0\nBuild date: {$buildDate}\n"
            );
        }

        return $full;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @chmod($file, 0644);
                @unlink($file);
            }
        }
        $this->createdFiles = [];

        // Fixture plugins are removed so one test's install state cannot
        // decide another's "installed" assertions.
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
        }
        $this->createdDirs = [];

        parent::tearDown();
    }

    /**
     * Write a throwaway wp-config.php into ABSPATH and return its path.
     * Removed automatically in tearDown.
     */
    protected function writeWpConfig(string $contents = "<?php\n\$table_prefix = 'wp_';\n"): string
    {
        $path = ABSPATH . 'wp-config.php';
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;
        // The atomic writer renames a sibling temp file into place; make sure
        // a leftover from a failed run can't survive into the next test.
        $this->createdFiles[] = $path . '.sentinel-tmp';

        return $path;
    }

    /** Read the current wp-config.php written by writeWpConfig(). */
    protected function readWpConfig(): string
    {
        return (string) file_get_contents(ABSPATH . 'wp-config.php');
    }

    /** Remove wp-config.php so the "not found" paths can be exercised. */
    protected function removeWpConfig(): void
    {
        $path = ABSPATH . 'wp-config.php';
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Capture everything a callable echoes.
     */
    protected function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * Stub the WordPress surface the admin pages call.
     */
    private function stubAdminFunctions(): void
    {
        // ── Escaping and translation ──────────────────────────────────
        foreach (['esc_attr', 'esc_html', 'esc_textarea', 'esc_url', 'esc_url_raw'] as $fn) {
            WP_Mock::userFunction($fn)->andReturnUsing(static fn ($v = ''): string => (string) $v);
        }
        foreach (['__', 'esc_html__', 'esc_attr__'] as $fn) {
            WP_Mock::userFunction($fn)->andReturnUsing(static fn (string $text = '', string $d = ''): string => $text);
        }
        foreach (['_e', 'esc_html_e', 'esc_attr_e'] as $fn) {
            WP_Mock::userFunction($fn)->andReturnUsing(static function (string $text = '', string $d = ''): void {
                echo $text;
            });
        }

        // ── Menu / settings registration (side-effect free here) ──────
        foreach ([
            'add_action', 'add_filter', 'add_settings_error', 'add_settings_field',
            'add_settings_section', 'register_setting', 'do_settings_sections',
            'settings_errors', 'settings_fields', 'submit_button', 'wp_nonce_field',
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_localize_script',
            'wp_add_dashboard_widget', 'wp_log', 'wp_log_flush', 'wp_safe_redirect',
        ] as $fn) {
            WP_Mock::userFunction($fn)->andReturn(null);
        }

        // checked()/selected()/disabled() echo the attribute in WordPress;
        // the exact markup is not what these tests assert, so they stay quiet.
        foreach (['checked', 'selected', 'disabled'] as $fn) {
            WP_Mock::userFunction($fn)->andReturn('');
        }

        // Formatting helpers used inside the rendered tables.
        WP_Mock::userFunction('number_format_i18n')
            ->andReturnUsing(static fn ($n = 0, $d = 0): string => number_format((float) $n, (int) $d));
        WP_Mock::userFunction('size_format')
            ->andReturnUsing(static fn ($b = 0): string => $b . ' B');
        WP_Mock::userFunction('human_time_diff')->andReturn('5 mins');
        WP_Mock::userFunction('absint')->andReturnUsing(static fn ($v = 0): int => abs((int) $v));
        WP_Mock::userFunction('wp_unslash')->andReturnUsing(static fn ($v = '') => $v);
        WP_Mock::userFunction('wp_kses_post')->andReturnUsing(static fn ($v = ''): string => (string) $v);
        WP_Mock::userFunction('esc_js')->andReturnUsing(static fn ($v = ''): string => (string) $v);

        WP_Mock::userFunction('add_menu_page')->andReturn('toplevel_page_sentinel');
        WP_Mock::userFunction('add_submenu_page')->andReturn('sentinel_page_stub');

        // ── Request / URL helpers ─────────────────────────────────────
        WP_Mock::userFunction('admin_url')
            ->andReturnUsing(static fn (string $p = ''): string => 'https://example.test/wp-admin/' . $p);
        WP_Mock::userFunction('add_query_arg')
            ->andReturnUsing(static fn (...$a): string => 'https://example.test/wp-admin/?stubbed=1');
        WP_Mock::userFunction('wp_create_nonce')->andReturn('test-nonce');
        WP_Mock::userFunction('check_admin_referer')->andReturn(true);
        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(static fn ($v): string => (string) json_encode($v));

        // ── Capability + termination ──────────────────────────────────
        // Routed through a property for the same reason as get_option: the
        // first matching WP_Mock expectation wins, so a per-test override
        // registered later would never be consulted. Call denyCapability()
        // to exercise the guard branches.
        WP_Mock::userFunction('current_user_can')
            ->andReturnUsing(fn (): bool => $this->userCan);
        WP_Mock::userFunction('is_admin')->andReturn(true);
        WP_Mock::userFunction('wp_die')->andReturnUsing(static function ($message = ''): void {
            throw new WpDieException(is_string($message) ? $message : 'wp_die');
        });

        // ── JSON responses (AJAX handlers) ────────────────────────────
        WP_Mock::userFunction('wp_send_json_success')->andReturnUsing(static function ($data = null): void {
            throw new JsonResponseException(true, $data);
        });
        WP_Mock::userFunction('wp_send_json_error')->andReturnUsing(static function ($data = null): void {
            throw new JsonResponseException(false, $data);
        });

        // ── Plugin introspection ──────────────────────────────────────
        // Routed through properties so a test can mark specific plugin files
        // active, or change the reported version, without fighting WP_Mock's
        // first-match-wins expectation resolution.
        WP_Mock::userFunction('is_plugin_active')
            ->andReturnUsing(fn (string $file = ''): bool => in_array($file, $this->activePlugins, true));
        WP_Mock::userFunction('get_plugin_data')
            ->andReturnUsing(fn (): array => ['Name' => 'Stub Plugin', 'Version' => $this->pluginVersion]);
        WP_Mock::userFunction('check_ajax_referer')->andReturn(true);

        // sanitize_text_field is stubbed in TestCase only for the logger's
        // narrow use; the admin pages pass arbitrary user input through it,
        // so mirror the real trimming/stripping behaviour closely enough
        // for the parsing assertions to mean something.
        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(static fn ($v = ''): string => trim(strip_tags((string) $v)));
    }
}

/** Thrown by the stubbed wp_die() so terminating paths are assertable. */
class WpDieException extends \RuntimeException
{
}

/** Thrown by the stubbed wp_send_json_* helpers. */
class JsonResponseException extends \RuntimeException
{
    public function __construct(public readonly bool $success, public readonly mixed $data)
    {
        parent::__construct($success ? 'json_success' : 'json_error');
    }
}
