<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Sentinel\Logger\LoggerManager;

/*
 * Tests for LoggerManager's deployment lifecycle.
 *
 * The logger ships inside Sentinel but has to run as an mu-plugin, so this
 * class copies it into mu-plugins/ and keeps the deployed copy in step with
 * the bundled one. The risky parts are all filesystem-shaped — a stale copy
 * left behind, or a legacy filename surviving an upgrade and causing a
 * "Cannot redeclare" fatal — so these tests run against a real temp
 * WPMU_PLUGIN_DIR rather than mocking the file operations away.
 */

function clearMuPluginDir(): void
{
    foreach ((array) glob(WPMU_PLUGIN_DIR . '/*') as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

beforeEach(function () {
    // wp_mkdir_p() and delete_option() are real stubs in wp-mocks — the
    // first creating the directory for real, which is what these
    // filesystem-shaped tests want anyway.
    if (!is_dir(WPMU_PLUGIN_DIR)) {
        mkdir(WPMU_PLUGIN_DIR, 0777, true);
    }
    clearMuPluginDir();
});

afterEach(function () {
    clearMuPluginDir();
});

// ── paths ─────────────────────────────────────────────────────────
it('derives the source and destination paths from the plugin constants', function () {
    expect(LoggerManager::sourcePath())->toEndWith('src/Logger/sentinel-logger.php')
        ->and(LoggerManager::destinationPath())->toStartWith(WPMU_PLUGIN_DIR)
        ->and(LoggerManager::sourcePath())->toBeFile('The bundled logger must ship with the plugin.');
});

// ── deployment ────────────────────────────────────────────────────
describe('deploy', function () {
    it('copies the bundled logger into mu-plugins', function () {
        expect(LoggerManager::isDeployed())->toBeFalse();

        LoggerManager::deploy();

        expect(LoggerManager::isDeployed())->toBeTrue()
            ->and(file_get_contents(LoggerManager::destinationPath()))
            ->toBe(file_get_contents(LoggerManager::sourcePath()));
    });

    it('reports a freshly deployed copy as current', function () {
        LoggerManager::deploy();

        expect(LoggerManager::isCurrentVersion())->toBeTrue();
    });

    it('detects and replaces a stale copy', function () {
        file_put_contents(LoggerManager::destinationPath(), "<?php // an old build\n");

        expect(LoggerManager::isDeployed())->toBeTrue()
            ->and(LoggerManager::isCurrentVersion())->toBeFalse('A differing copy is not current.');

        LoggerManager::deploy();

        expect(LoggerManager::isCurrentVersion())->toBeTrue('deploy() refreshes a stale copy.');
    });

    it('leaves the file untouched when deploying an identical copy', function () {
        LoggerManager::deploy();
        $firstMtime = filemtime(LoggerManager::destinationPath());

        // The hash matches, so the second deploy should skip the copy
        // entirely rather than rewriting the file.
        clearstatcache();
        LoggerManager::deploy();

        expect(filemtime(LoggerManager::destinationPath()))->toBe($firstMtime);
    });

    it('redeploys when forced even when the copy is identical', function () {
        LoggerManager::deploy();

        LoggerManager::deploy(true);

        expect(LoggerManager::isCurrentVersion())->toBeTrue();
    });

    it('reports isCurrentVersion false when nothing is deployed', function () {
        expect(LoggerManager::isCurrentVersion())->toBeFalse();
    });
});

// ── legacy cleanup ────────────────────────────────────────────────
describe('legacy cleanup', function () {
    it('removes legacy-named copies on deploy', function () {
        // An older release deployed under a different filename; leaving it
        // in place would redeclare the logger class and fatal the site.
        $legacy = WPMU_PLUGIN_DIR . '/bd-shared-logger.php';
        file_put_contents($legacy, "<?php // legacy\n");

        LoggerManager::deploy();

        expect($legacy)->not->toBeFile()
            ->and(LoggerManager::isDeployed())->toBeTrue();
    });

    // no legacy files, no error
    it('is safe to run removeLegacy when there is nothing to remove', function () {
        LoggerManager::removeLegacy();
    })->throwsNoExceptions();
});

// ── removal ───────────────────────────────────────────────────────
describe('remove', function () {
    it('deletes the deployed logger', function () {
        LoggerManager::deploy();
        expect(LoggerManager::isDeployed())->toBeTrue();

        LoggerManager::remove();

        expect(LoggerManager::isDeployed())->toBeFalse();
    });

    it('is safe when nothing is deployed', function () {
        LoggerManager::remove();

        expect(LoggerManager::isDeployed())->toBeFalse();
    });
});

// ── log data cleanup ──────────────────────────────────────────────
it('drops the table through the logger when cleaning logs', function () {
    global $wpdb;
    $before = count($wpdb->queries);

    LoggerManager::cleanLogs();

    expect(count($wpdb->queries))->toBeGreaterThan($before, 'A DROP TABLE was issued.')
        ->and(end($wpdb->queries))->toContain('DROP TABLE');
});
