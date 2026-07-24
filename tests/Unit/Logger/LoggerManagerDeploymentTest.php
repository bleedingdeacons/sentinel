<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Sentinel\Logger\LoggerManager;
use Sentinel\Tests\TestCase;
use WP_Mock;

/**
 * Tests for LoggerManager's deployment lifecycle.
 *
 * The logger ships inside Sentinel but has to run as an mu-plugin, so this
 * class copies it into mu-plugins/ and keeps the deployed copy in step with
 * the bundled one. The risky parts are all filesystem-shaped — a stale copy
 * left behind, or a legacy filename surviving an upgrade and causing a
 * "Cannot redeclare" fatal — so these tests run against a real temp
 * WPMU_PLUGIN_DIR rather than mocking the file operations away.
 */
final class LoggerManagerDeploymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WP_Mock::userFunction('wp_mkdir_p')->andReturnUsing(
            static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true)
        );
        WP_Mock::userFunction('delete_option')->andReturn(true);

        if (!is_dir(WPMU_PLUGIN_DIR)) {
            mkdir(WPMU_PLUGIN_DIR, 0777, true);
        }
        $this->clearMuPluginDir();
    }

    protected function tearDown(): void
    {
        $this->clearMuPluginDir();
        parent::tearDown();
    }

    private function clearMuPluginDir(): void
    {
        foreach ((array) glob(WPMU_PLUGIN_DIR . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // ── paths ─────────────────────────────────────────────────────────

    /** @test */
    public function source_and_destination_paths_are_derived_from_the_plugin_constants(): void
    {
        $this->assertStringEndsWith('src/Logger/sentinel-logger.php', LoggerManager::sourcePath());
        $this->assertStringStartsWith(WPMU_PLUGIN_DIR, LoggerManager::destinationPath());
        $this->assertFileExists(LoggerManager::sourcePath(), 'The bundled logger must ship with the plugin.');
    }

    // ── deployment ────────────────────────────────────────────────────

    /** @test */
    public function deploy_copies_the_bundled_logger_into_mu_plugins(): void
    {
        $this->assertFalse(LoggerManager::isDeployed());

        LoggerManager::deploy();

        $this->assertTrue(LoggerManager::isDeployed());
        $this->assertFileEquals(LoggerManager::sourcePath(), LoggerManager::destinationPath());
    }

    /** @test */
    public function a_freshly_deployed_copy_reports_as_current(): void
    {
        LoggerManager::deploy();

        $this->assertTrue(LoggerManager::isCurrentVersion());
    }

    /** @test */
    public function a_stale_copy_is_detected_and_replaced(): void
    {
        file_put_contents(LoggerManager::destinationPath(), "<?php // an old build\n");

        $this->assertTrue(LoggerManager::isDeployed());
        $this->assertFalse(LoggerManager::isCurrentVersion(), 'A differing copy is not current.');

        LoggerManager::deploy();

        $this->assertTrue(LoggerManager::isCurrentVersion(), 'deploy() refreshes a stale copy.');
    }

    /** @test */
    public function deploying_an_identical_copy_leaves_the_file_untouched(): void
    {
        LoggerManager::deploy();
        $firstMtime = filemtime(LoggerManager::destinationPath());

        // The hash matches, so the second deploy should skip the copy
        // entirely rather than rewriting the file.
        clearstatcache();
        LoggerManager::deploy();

        $this->assertSame($firstMtime, filemtime(LoggerManager::destinationPath()));
    }

    /** @test */
    public function force_redeploys_even_when_the_copy_is_identical(): void
    {
        LoggerManager::deploy();

        LoggerManager::deploy(true);

        $this->assertTrue(LoggerManager::isCurrentVersion());
    }

    /** @test */
    public function is_current_version_is_false_when_nothing_is_deployed(): void
    {
        $this->assertFalse(LoggerManager::isCurrentVersion());
    }

    // ── legacy cleanup ────────────────────────────────────────────────

    /** @test */
    public function deploy_removes_legacy_named_copies(): void
    {
        // An older release deployed under a different filename; leaving it
        // in place would redeclare the logger class and fatal the site.
        $legacy = WPMU_PLUGIN_DIR . '/bd-shared-logger.php';
        file_put_contents($legacy, "<?php // legacy\n");

        LoggerManager::deploy();

        $this->assertFileDoesNotExist($legacy);
        $this->assertTrue(LoggerManager::isDeployed());
    }

    /** @test */
    public function remove_legacy_is_safe_when_there_is_nothing_to_remove(): void
    {
        LoggerManager::removeLegacy();

        $this->assertTrue(true, 'no legacy files, no error');
    }

    // ── removal ───────────────────────────────────────────────────────

    /** @test */
    public function remove_deletes_the_deployed_logger(): void
    {
        LoggerManager::deploy();
        $this->assertTrue(LoggerManager::isDeployed());

        LoggerManager::remove();

        $this->assertFalse(LoggerManager::isDeployed());
    }

    /** @test */
    public function remove_is_safe_when_nothing_is_deployed(): void
    {
        LoggerManager::remove();

        $this->assertFalse(LoggerManager::isDeployed());
    }

    // ── log data cleanup ──────────────────────────────────────────────

    /** @test */
    public function clean_logs_drops_the_table_through_the_logger(): void
    {
        global $wpdb;
        $before = count($wpdb->queries);

        LoggerManager::cleanLogs();

        $this->assertGreaterThan($before, count($wpdb->queries), 'A DROP TABLE was issued.');
        $this->assertStringContainsString('DROP TABLE', end($wpdb->queries));
    }
}
