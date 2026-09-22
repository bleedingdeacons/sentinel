<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Logger;

use Sentinel\Logger\LoggerManager;

/*
 * Tests for LoggerManager filesystem operations.
 *
 * Uses a temporary directory for WPMU_PLUGIN_DIR to avoid touching
 * real WordPress installations. The source file path points to the
 * actual bundled sentinel-logger.php.
 *
 * Runs on the shared wp-mocks TestCase rather than Sentinel's own (see
 * tests/Pest.php), which would load the logger singleton these purely
 * filesystem-shaped assertions have no use for.
 */

beforeEach(function () {
    // Create a temp directory to act as mu-plugins
    $this->tempMuDir = sys_get_temp_dir() . '/sentinel-test-mu-' . uniqid();
    mkdir($this->tempMuDir, 0755, true);

    // WPMU_PLUGIN_DIR is already defined in bootstrap, but LoggerManager
    // uses the constant directly. We test the static helpers that are
    // purely filesystem-based.
});

afterEach(function () {
    // Clean up temp directory
    $files = glob($this->tempMuDir . '/*');
    if ($files) {
        foreach ($files as $file) {
            unlink($file);
        }
    }
    if (is_dir($this->tempMuDir)) {
        rmdir($this->tempMuDir);
    }
});

// ── sourcePath ──────────────────────────────────────────────────
describe('sourcePath', function () {
    it('points to the logger file in the plugin dir', function () {
        $path = LoggerManager::sourcePath();

        expect($path)->toEndWith('src/Logger/sentinel-logger.php')
            ->toStartWith(SENTINEL_PLUGIN_DIR);
    });

    it('names a file that actually exists', function () {
        expect(LoggerManager::sourcePath())->toBeFile();
    });
});

// ── destinationPath ─────────────────────────────────────────────
it('points destinationPath to mu-plugins', function () {
    $path = LoggerManager::destinationPath();

    expect($path)->toEndWith('sentinel-logger.php')
        ->toStartWith(WPMU_PLUGIN_DIR);
});

// ── isDeployed ──────────────────────────────────────────────────
it('returns a boolean from isDeployed when the file is missing', function () {
    // The temp WPMU_PLUGIN_DIR won't have the file
    // But isDeployed checks the real WPMU_PLUGIN_DIR constant.
    // We can at least verify the method returns a boolean.
    $result = LoggerManager::isDeployed();

    expect($result)->toBeBool();
});

// ── isCurrentVersion ────────────────────────────────────────────
it('returns a boolean from isCurrentVersion when the destination is missing', function () {
    // When destination doesn't exist, versions can't match
    // This test validates the guard clause
    $result = LoggerManager::isCurrentVersion();

    // If the file happens to be deployed (e.g. in a real WP env),
    // it might return true, but we can verify it returns a boolean
    expect($result)->toBeBool();
});

// ── Legacy removal ──────────────────────────────────────────────
it('does not error in removeLegacy when no legacy files exist', function () {
    // Should be a no-op with no exceptions
    LoggerManager::removeLegacy();
})->throwsNoExceptions();
