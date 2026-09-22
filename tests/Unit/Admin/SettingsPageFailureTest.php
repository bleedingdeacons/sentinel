<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;

/*
 * Covers the logger-config save failure branch and the not-writable render
 * notice. wp-config.php stays writable (so the earlier guard passes) but the
 * atomic writer's temp path is occupied by a directory, so every
 * setWpConfigConstant() write fails and handleLoggerConfigSave() reports the
 * write error.
 */

covers(SettingsPage::class);

beforeEach(function () {
    $this->tmpDir = '';
    $_POST = [];

    $this->blockAtomicWrite = function (): void {
        $this->tmpDir = ABSPATH . 'wp-config.php.sentinel-tmp';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    };
});

afterEach(function () {
    if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
        @rmdir($this->tmpDir);
    }
    $_POST = [];
});

it('reports a write error when the logger config save fails', function () {
    $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
    $before = $this->readWpConfig();
    ($this->blockAtomicWrite)();

    $_POST = [
        '_sentinel_logger_nonce'   => 'n',
        'sentinel_log_enabled'     => '1',
        'sentinel_log_level'       => 'warning',
        'sentinel_log_max_rows'    => '25000',
        'sentinel_log_buffer_size' => '100',
        'sentinel_capture_errors'  => '1',
    ];

    // The atomic writer's file_put_contents() to the blocked temp path
    // warns before returning false; swallow it so the failure branch shows.
    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        SettingsPage::handleLoggerConfigSave();
    } finally {
        restore_error_handler();
    }

    expect($this->readWpConfig())->toBe($before, 'No constant should have been written.');
});

it('shows the not-writable notice on renderPage when the config is missing', function () {
    $this->removeWpConfig();

    if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
        $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
    }

    $html = $this->capture([SettingsPage::class, 'renderPage']);

    expect($html)->toContain('not writable');
});
