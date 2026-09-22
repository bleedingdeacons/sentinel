<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;

/*
 * Covers the UnityControlPage branches the main suite leaves out: the
 * marker-present-insert / no-op writer branches.
 *
 * The runtime-killed and development-mode render paths that used to share
 * this file need the real constants defined, so they run in isolated
 * processes — which Pest refuses. They moved to UnityControlPageKilledTest,
 * which stays a PHPUnit class.
 */

covers(UnityControlPage::class);

const EXTRA_KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';
const EXTRA_PROD_MARKER = '/* Environment Flag (managed by Sentinel) */';

function resetExtraJustChanged(): void
{
    $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
    $prop->setValue(null, false);
}

/**
 * @param array<string, string> $extra
 */
function submitUnityExtra(string $action, array $extra = []): void
{
    $_POST = array_merge([
        '_sentinel_unity_nonce' => 'n',
        'sentinel_unity_action' => $action,
    ], $extra);
    UnityControlPage::handleSave();
}

beforeEach(function () {
    resetExtraJustChanged();
    $_POST = [];
});

afterEach(function () {
    $_POST = [];
    resetExtraJustChanged();
});

// ── writer marker-insert branches (in-process) ───────────────────────
describe('writer marker-insert branches', function () {
    it('inserts under an existing kill marker without a define on disable', function () {
        // Marker present but no define line → the "insert under marker" branch.
        $this->writeWpConfig("<?php\n" . EXTRA_KILL_MARKER . "\n\$table_prefix = 'wp_';\n");

        submitUnityExtra('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'UNITY_KILL', true );")
            ->and(substr_count($config, EXTRA_KILL_MARKER))->toBe(1);
    });

    it('inserts under an existing environment marker without a define on production off', function () {
        $this->writeWpConfig("<?php\n" . EXTRA_PROD_MARKER . "\n\$table_prefix = 'wp_';\n");

        submitUnityExtra('production_off');

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'PRODUCTION', false );")
            ->and(substr_count($config, EXTRA_PROD_MARKER))->toBe(1);
    });
});

// ── writer no-op branches (value already correct) ────────────────────
describe('writer no-op branches', function () {
    it('treats disabling when already true as a no-op success', function () {
        $this->writeWpConfig("<?php\n" . EXTRA_KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n");
        $before = $this->readWpConfig();

        submitUnityExtra('disable', ['sentinel_unity_confirm' => '1']);

        expect($this->readWpConfig())->toBe($before);
    });

    it('treats production off when already false as a no-op success', function () {
        $this->writeWpConfig("<?php\n" . EXTRA_PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n");
        $before = $this->readWpConfig();

        submitUnityExtra('production_off');

        expect($this->readWpConfig())->toBe($before);
    });
});
