<?php

declare(strict_types=1);

namespace Sentinel\Tests;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Sentinel\Plugin;

/**
 * Base case for the admin-page tests.
 *
 * The admin classes are thin over WordPress: they register menus, read
 * options, and emit large blocks of HTML. Testing them therefore means
 * standing up enough of WordPress that a render call runs end to end.
 *
 * Most of that surface now comes from bleedingdeacons/wp-mocks, which keeps the
 * same two deliberate choices this class used to make by hand:
 *
 *   - Escaping and translation helpers pass their input straight through.
 *     The assertions are about what the page says, not about escaping,
 *     which is WordPress's job and is covered by its own tests.
 *   - wp_die() throws {@see WpDieException} rather than returning. It is
 *     a terminating function in production, so a test that reaches it must
 *     stop there; throwing makes the capability guards assertable.
 *
 * What is left here is the part the shared package does not carry: the Settings
 * API no-ops, and the two stubs that have to read a per-test property.
 */
abstract class AdminTestCase extends TestCase
{
    /** Files created under ABSPATH by a test, removed on tearDown. */
    private array $createdFiles = [];

    /** Plugin files the stubbed is_plugin_active() should report as active. */
    protected array $activePlugins = [];

    /** Version the stubbed get_plugin_data() reports. */
    protected string $pluginVersion = '1.0.0';

    /** Fixture plugin directories created under WP_PLUGIN_DIR. */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activePlugins = [];
        $this->pluginVersion = '1.0.0';
        $this->stubAdminFunctions();
    }

    /** Make current_user_can() return false, to reach the guard branches. */
    protected function denyCapability(): void
    {
        WpState::$userCan = false;
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
     * The hook suffix add_submenu_page() hands back for a page under Sentinel.
     *
     * enqueueAssets() compares the current screen against the suffix its
     * registerPage() stored, so a test that wants the assets to load has to
     * name the real one. wp-mocks builds it the way WordPress does, from the
     * parent and the page slug — before this migration the stub returned a
     * fixed string and the tests named that instead.
     */
    protected function submenuHook(string $pageSlug): string
    {
        return Plugin::MENU_SLUG . '_page_' . $pageSlug;
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
     * Stub the part of the WordPress surface wp-mocks does not already cover.
     *
     * Everything else — escaping, translation, formatting, menus, nonces, URLs,
     * capabilities, wp_die() and the wp_send_json_* helpers — comes from the
     * shared stubs, so it is not repeated here.
     *
     * Nothing registers add_action()/add_filter() either: Brain Monkey owns the
     * hook layer, and stubbing over it would silently break every hook
     * assertion in the suite.
     */
    private function stubAdminFunctions(): void
    {
        // The Settings API. Registration is a side effect these tests do not
        // assert on, so it is enough that the calls are harmless.
        foreach ([
            'add_settings_error', 'add_settings_field', 'add_settings_section',
            'register_setting', 'do_settings_sections', 'settings_errors',
            'settings_fields', 'submit_button',
        ] as $fn) {
            Functions\when($fn)->justReturn(null);
        }

        // Routed through properties so a test can mark specific plugin files
        // active, or change the reported version, mid-test. Functions\when()
        // sets no call-count expectation, which is what a blanket base-class
        // stub wants; Functions\expect() would demand at least one call.
        Functions\when('is_plugin_active')
            ->alias(fn (string $file = ''): bool => in_array($file, $this->activePlugins, true));
        Functions\when('get_plugin_data')
            ->alias(fn (): array => ['Name' => 'Stub Plugin', 'Version' => $this->pluginVersion]);
    }
}
