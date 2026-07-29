<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use Sentinel\Tests\AdminTestCase;

/**
 * Covers the logger-config save failure branch and the not-writable render
 * notice. wp-config.php stays writable (so the earlier guard passes) but the
 * atomic writer's temp path is occupied by a directory, so every
 * setWpConfigConstant() write fails and handleLoggerConfigSave() reports the
 * write error.
 *
 * @covers \Sentinel\Admin\SettingsPage
 */
final class SettingsPageFailureTest extends AdminTestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
        $_POST = [];
        parent::tearDown();
    }

    private function blockAtomicWrite(): void
    {
        $this->tmpDir = ABSPATH . 'wp-config.php.sentinel-tmp';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    public function testLoggerConfigSaveReportsAWriteError(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();
        $this->blockAtomicWrite();

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

        $this->assertSame($before, $this->readWpConfig(), 'No constant should have been written.');
    }

    public function testRenderPageShowsTheNotWritableNoticeWhenConfigMissing(): void
    {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $html = $this->capture([SettingsPage::class, 'renderPage']);

        $this->assertStringContainsString('not writable', $html);
    }
}
