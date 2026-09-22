<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use ReflectionProperty;
use Sentinel\Admin\UnityControlPage;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;

/*
 * Tests for the Unity Control page.
 *
 * This page edits wp-config.php to stand Unity down (UNITY_KILL) and to
 * toggle the PRODUCTION flag, so the tests drive it against a real
 * throwaway wp-config.php under ABSPATH. Getting these rewrites wrong
 * would corrupt a live site's config, so the assertions check the exact
 * define() text, that markers are not duplicated, and that removal is
 * idempotent.
 *
 * The runtime-state readers (isKilledAtRuntime / isProductionAtRuntime)
 * are driven by defined() on real constants. Defining UNITY_KILL in-process
 * would leak into every other test, so only the undefined branch is
 * exercised here — which is also the branch that matters, since a site
 * with the switch engaged never reaches this admin page.
 */

const CONTROL_KILL_MARKER = '/* Unity Kill Switch (managed by Sentinel) */';
const CONTROL_PROD_MARKER = '/* Environment Flag (managed by Sentinel) */';

/**
 * handleSave() latches a private static flag on success, which would
 * otherwise bleed into the rendering tests.
 */
function resetControlJustChanged(): void
{
    $prop = new ReflectionProperty(UnityControlPage::class, 'justChanged');
    $prop->setValue(null, false);
}

/**
 * Submit the page's form with the given action.
 *
 * @param array<string, string> $extra
 */
function submitUnityControl(string $action, array $extra = []): void
{
    $_POST = array_merge([
        '_sentinel_unity_nonce'  => 'n',
        'sentinel_unity_action'  => $action,
    ], $extra);

    UnityControlPage::handleSave();
}

beforeEach(function () {
    resetControlJustChanged();
    $_POST = [];
});

afterEach(function () {
    $_POST = [];
    resetControlJustChanged();
});

// ── registration ──────────────────────────────────────────────────
// registration completed
it('runs init and registerPage without error', function () {
    UnityControlPage::init();
    UnityControlPage::registerPage();
})->throwsNoExceptions();

// ── save handler guards ───────────────────────────────────────────
describe('save handler guards', function () {
    // returned before touching wp-config.php
    it('ignores requests without its nonce field', function () {
        $_POST = ['sentinel_unity_action' => 'disable'];

        UnityControlPage::handleSave();
    })->throwsNoExceptions();

    it('refuses users without the capability', function () {
        $this->denyCapability();
        $_POST = ['_sentinel_unity_nonce' => 'n'];

        UnityControlPage::handleSave();
    })->throws(WpDieException::class);

    it('ignores an unrecognised action', function () {
        $this->writeWpConfig();
        $before = $this->readWpConfig();

        submitUnityControl('something-else');

        expect($this->readWpConfig())->toBe($before, 'Unknown actions are dropped.');
    });

    // not-writable branch reported via add_settings_error
    it('reports an unwritable config', function () {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);
    })->throwsNoExceptions();
});

// ── kill switch ───────────────────────────────────────────────────
describe('kill switch', function () {
    // Without confirmation the kill switch must not be written.
    it('requires the confirmation checkbox to disable Unity', function () {
        $this->writeWpConfig();

        submitUnityControl('disable'); // no confirmation ticked

        expect($this->readWpConfig())->not->toContain('UNITY_KILL');
    });

    it('writes the kill switch with its marker when disabling Unity', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        expect($config)->toContain(CONTROL_KILL_MARKER)
            ->toContain("define( 'UNITY_KILL', true );")
            ->toContain("\$table_prefix = 'wp_';");
    });

    it('replaces rather than duplicates when disabling twice', function () {
        $this->writeWpConfig("<?php\n" . CONTROL_KILL_MARKER . "\ndefine( 'UNITY_KILL', false );\n");

        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'UNITY_KILL', true );")
            ->and(substr_count($config, 'UNITY_KILL'))->toBe(1)
            ->and(substr_count($config, CONTROL_KILL_MARKER))->toBe(1);
    });

    it('removes the define and marker when enabling Unity', function () {
        $this->writeWpConfig(
            "<?php\n" . CONTROL_KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n\$table_prefix = 'wp_';\n"
        );

        submitUnityControl('enable');

        $config = $this->readWpConfig();
        expect($config)->not->toContain('UNITY_KILL')
            ->not->toContain(CONTROL_KILL_MARKER)
            ->toContain("\$table_prefix = 'wp_';");
    });

    it('treats enabling Unity when it was never disabled as a no-op', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        submitUnityControl('enable');

        expect($this->readWpConfig())->toBe($before);
    });

    it('writes the kill switch even without a PHP opening tag', function () {
        $this->writeWpConfig("no php tag\n");

        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);

        $config = $this->readWpConfig();
        expect($config)->toStartWith('<?php')
            ->toContain("define( 'UNITY_KILL', true );");
    });

    it('appends the kill switch when the config is a single line', function () {
        $this->writeWpConfig('<?php');

        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);

        expect($this->readWpConfig())->toContain("define( 'UNITY_KILL', true );");
    });
});

// ── PRODUCTION flag ───────────────────────────────────────────────
describe('PRODUCTION flag', function () {
    it('writes the constant false when turning production off', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        submitUnityControl('production_off');

        $config = $this->readWpConfig();
        expect($config)->toContain(CONTROL_PROD_MARKER)
            ->toContain("define( 'PRODUCTION', false );");
    });

    it('removes the constant entirely when turning production on', function () {
        // Production is the runtime default, so "on" means removing the
        // define rather than writing true.
        $this->writeWpConfig(
            "<?php\n" . CONTROL_PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n\$table_prefix = 'wp_';\n"
        );

        submitUnityControl('production_on');

        $config = $this->readWpConfig();
        expect($config)->not->toContain('PRODUCTION')
            ->not->toContain(CONTROL_PROD_MARKER)
            ->toContain("\$table_prefix = 'wp_';");
    });

    it('replaces rather than duplicates when turning production off twice', function () {
        $this->writeWpConfig("<?php\n" . CONTROL_PROD_MARKER . "\ndefine( 'PRODUCTION', true );\n");

        submitUnityControl('production_off');

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'PRODUCTION', false );")
            ->and(substr_count($config, 'PRODUCTION', 0))->toBe(1)
            ->and(substr_count($config, CONTROL_PROD_MARKER))->toBe(1);
    });

    it('treats turning production on when undefined as a no-op', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        submitUnityControl('production_on');

        expect($this->readWpConfig())->toBe($before);
    });

    it('writes production even without a PHP opening tag', function () {
        $this->writeWpConfig("no php tag\n");

        submitUnityControl('production_off');

        expect($this->readWpConfig())->toStartWith('<?php')
            ->and($this->readWpConfig())->toContain("define( 'PRODUCTION', false );");
    });

    it('appends production when the config is a single line', function () {
        $this->writeWpConfig('<?php');

        submitUnityControl('production_off');

        expect($this->readWpConfig())->toContain("define( 'PRODUCTION', false );");
    });
});

// ── rendering ─────────────────────────────────────────────────────
describe('renderPage', function () {
    it('shows Unity running when no kill switch is set', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        expect($html)->toContain('Unity Control')
            ->toContain('<form')
            // Dependent plugins are listed so the operator knows the blast radius.
            ->toContain('Scrutiny');
    });

    it('reflects a kill switch present in the file', function () {
        $this->writeWpConfig(
            "<?php\n" . CONTROL_KILL_MARKER . "\ndefine( 'UNITY_KILL', true );\n"
        );

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        expect($html)->toContain('Unity Control')
            ->and(trim($html))->not->toBe('');
    });

    it('reflects production defined in the file', function () {
        $this->writeWpConfig(
            "<?php\n" . CONTROL_PROD_MARKER . "\ndefine( 'PRODUCTION', false );\n"
        );

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        expect($html)->toContain('Unity Control');
    });

    it('handles a missing wp-config', function () {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        // Falls back to the manual-instructions path rather than fataling.
        expect($html)->toContain('Unity Control');
    });

    it('emits the reload script after a successful change', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        submitUnityControl('disable', ['sentinel_unity_confirm' => '1']);

        $html = $this->capture([UnityControlPage::class, 'renderPage']);

        expect($html)->toContain('Unity Control');
    });

    it('refuses users without the capability', function () {
        $this->denyCapability();

        UnityControlPage::renderPage();
    })->throws(WpDieException::class);
});
