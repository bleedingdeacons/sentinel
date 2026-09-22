<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;

/*
 * Covers the "write failed" branches of UnityControlPage. wp-config.php is
 * writable (so the earlier guard passes) but the atomic writer's temp path is
 * occupied by a directory, so file_put_contents() fails and each action falls
 * into its failure branch — leaving wp-config.php untouched.
 */

covers(UnityControlPage::class);

const FAILURE_KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';
const FAILURE_PROD_MARKER = '/* Environment Flag (managed by Sentinel) */';

/**
 * @param array<string, string> $extra
 */
function submitUnityFailure(string $action, array $extra = []): void
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

beforeEach(function () {
    // The temp path UnityControlPage::atomicWrite() writes to.
    $this->tmpDir = '';

    $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
    $prop->setValue(null, false);
    $_POST = [];

    // Occupy the atomic writer's temp path with a directory so the write fails.
    $this->blockAtomicWrite = function (): void {
        $this->tmpDir = ABSPATH . 'wp-config.php.sentinel-unity-tmp';
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

it('reports a write failure on disable', function () {
    $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
    $before = $this->readWpConfig();
    ($this->blockAtomicWrite)();

    submitUnityFailure('disable', ['sentinel_unity_confirm' => '1']);

    expect($this->readWpConfig())->toBe($before, 'wp-config.php must be left untouched.');
});

it('reports a remove failure on enable', function () {
    $this->writeWpConfig("<?php\n" . FAILURE_KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n");
    $before = $this->readWpConfig();
    ($this->blockAtomicWrite)();

    submitUnityFailure('enable');

    expect($this->readWpConfig())->toBe($before);
});

it('reports a write failure on production off', function () {
    $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
    $before = $this->readWpConfig();
    ($this->blockAtomicWrite)();

    submitUnityFailure('production_off');

    expect($this->readWpConfig())->toBe($before);
});

it('reports a remove failure on production on', function () {
    $this->writeWpConfig("<?php\n" . FAILURE_PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n");
    $before = $this->readWpConfig();
    ($this->blockAtomicWrite)();

    submitUnityFailure('production_on');

    expect($this->readWpConfig())->toBe($before);
});
