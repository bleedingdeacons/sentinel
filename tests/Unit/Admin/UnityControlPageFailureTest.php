<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;
use Sentinel\Tests\AdminTestCase;

/**
 * Covers the "write failed" branches of UnityControlPage. wp-config.php is
 * writable (so the earlier guard passes) but the atomic writer's temp path is
 * occupied by a directory, so file_put_contents() fails and each action falls
 * into its failure branch — leaving wp-config.php untouched.
 *
 * @covers \Sentinel\Admin\UnityControlPage
 */
final class UnityControlPageFailureTest extends AdminTestCase
{
    private const KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';
    private const PROD_MARKER = '/* Environment Flag (managed by Sentinel) */';

    /** The temp path UnityControlPage::atomicWrite() writes to. */
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
        $prop->setValue(null, false);
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

    /** Occupy the atomic writer's temp path with a directory so the write fails. */
    private function blockAtomicWrite(): void
    {
        $this->tmpDir = ABSPATH . 'wp-config.php.sentinel-unity-tmp';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    private function submit(string $action, array $extra = []): void
    {
        $_POST = array_merge(['_sentinel_unity_nonce' => 'n', 'sentinel_unity_action' => $action], $extra);

        // atomicWrite()'s file_put_contents() to the blocked temp path emits a
        // "Permission denied" warning before returning false; swallow it so the
        // failure branch (not the warning) is what the test observes.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            UnityControlPage::handleSave();
        } finally {
            restore_error_handler();
        }
    }

    public function testDisableReportsAWriteFailure(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();
        $this->blockAtomicWrite();

        $this->submit('disable', ['sentinel_unity_confirm' => '1']);

        $this->assertSame($before, $this->readWpConfig(), 'wp-config.php must be left untouched.');
    }

    public function testEnableReportsARemoveFailure(): void
    {
        $this->writeWpConfig("<?php\n" . self::KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n");
        $before = $this->readWpConfig();
        $this->blockAtomicWrite();

        $this->submit('enable');

        $this->assertSame($before, $this->readWpConfig());
    }

    public function testProductionOffReportsAWriteFailure(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();
        $this->blockAtomicWrite();

        $this->submit('production_off');

        $this->assertSame($before, $this->readWpConfig());
    }

    public function testProductionOnReportsARemoveFailure(): void
    {
        $this->writeWpConfig("<?php\n" . self::PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n");
        $before = $this->readWpConfig();
        $this->blockAtomicWrite();

        $this->submit('production_on');

        $this->assertSame($before, $this->readWpConfig());
    }
}
