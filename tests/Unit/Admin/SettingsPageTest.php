<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;

/*
 * Tests for the Sentinel settings page.
 *
 * Two areas carry the real risk and get the closest attention:
 *
 *   - The plugin-list parser, which turns a free-text textarea into the
 *     {key => [file, label]} map the dashboard iterates. Malformed lines
 *     are user input and must be dropped rather than blow up a widget.
 *   - The wp-config.php rewriter, which edits a live PHP file. These tests
 *     run it against a real throwaway wp-config.php under ABSPATH so the
 *     regex replacement, marker insertion and atomic rename are all
 *     genuinely exercised rather than mocked away.
 */

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    // registration completed
    it('runs init and the registration hooks without error', function () {
        SettingsPage::init();
        SettingsPage::registerPage();
        SettingsPage::registerSettings();
    })->throwsNoExceptions();

    // enqueue guarded by hook suffix
    it('loads assets only on its own screen', function () {
        // registerPage() records the hook suffix returned by
        // add_submenu_page(); anything else must be ignored.
        SettingsPage::registerPage();

        SettingsPage::enqueueAssets('some-other-page');
        SettingsPage::enqueueAssets($this->submenuHook('sentinel-settings'));
    })->throwsNoExceptions();
});

// ── sanitizePluginList ────────────────────────────────────────────
describe('sanitizePluginList', function () {
    it('returns an empty string for a non-string', function () {
        expect(SettingsPage::sanitizePluginList(null))->toBe('')
            ->and(SettingsPage::sanitizePluginList(['a']))->toBe('')
            ->and(SettingsPage::sanitizePluginList(42))->toBe('');
    });

    it('drops blank and comment lines', function () {
        $input = "unity/unity.php|Unity\r\n\r\n# a comment\n   \nreach/reach.php|Reach\r";

        expect(SettingsPage::sanitizePluginList($input))->toBe(
            "unity/unity.php|Unity\nreach/reach.php|Reach",
            'CRLF is normalised and blank/comment lines are stripped.'
        );
    });
});

// ── plugin list parsing ───────────────────────────────────────────
describe('plugin list parsing', function () {
    it('falls back to the shipped default list for mandatory plugins', function () {
        $plugins = SettingsPage::getMandatoryPlugins();

        expect($plugins)->toHaveKey('unity')
            ->and($plugins['unity']['file'])->toBe('unity/unity.php')
            ->and($plugins['unity']['label'])->toBe('Unity')
            ->and($plugins)->toHaveKey('scrutiny');
    });

    it('falls back to the shipped default list for optional plugins', function () {
        $plugins = SettingsPage::getOptionalPlugins();

        expect($plugins)->toHaveKey('reach')
            ->and($plugins['reach']['label'])->toBe('Reach');
    });

    // Promises is optional rather than mandatory: it is an MCP server, present
    // on the sites that connect a client and absent everywhere else, so a site
    // without it is not a site with something missing.
    it('monitors Promises as an optional plugin', function () {
        $plugins = SettingsPage::getOptionalPlugins();

        expect($plugins)->toHaveKey('promises')
            ->and($plugins['promises']['file'])->toBe('promises/promises.php')
            ->and($plugins['promises']['label'])->toBe('Promises')
            ->and(SettingsPage::getMandatoryPlugins())->not->toHaveKey('promises');
    });

    // Fellowship is mandatory rather than optional, which is a deliberate
    // difference from Reach and Promises beside it.
    //
    // <b>It is the server half of a pair.</b> Link is on members' phones,
    // and a handset whose Fellowship has stopped does not say so — it goes
    // on polling and quietly collects nothing. That is the failure the
    // stability indicator exists to catch, and it cannot catch it for a
    // plugin it only watches when present.
    //
    // The cost is the ordinary cost of mandatory: a site that has never
    // installed Fellowship now reports it missing. Move it to the optional
    // list if that is ever the wrong trade.
    it('monitors Fellowship as a mandatory plugin', function () {
        $plugins = SettingsPage::getMandatoryPlugins();

        expect($plugins)->toHaveKey('fellowship')
            ->and($plugins['fellowship']['file'])->toBe('fellowship/fellowship.php')
            ->and($plugins['fellowship']['label'])->toBe('Fellowship')
            ->and(SettingsPage::getOptionalPlugins())->not->toHaveKey('fellowship');
    });

    it('derives a humanised label when none is given', function () {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "my-great_plugin/file.php");

        $plugins = SettingsPage::getMandatoryPlugins();

        expect($plugins['my-great_plugin']['label'])->toBe('My Great Plugin');
    });

    it('keeps the first of a duplicated key', function () {
        $this->setOption(
            SettingsPage::OPTION_MANDATORY_PLUGINS,
            "unity/unity.php|First\nunity/other.php|Second"
        );

        $plugins = SettingsPage::getMandatoryPlugins();

        expect($plugins)->toHaveCount(1)
            ->and($plugins['unity']['label'])->toBe('First');
    });

    it('handles an entry with no directory segment', function () {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "single.php|Single");

        $plugins = SettingsPage::getMandatoryPlugins();

        // With no slash the whole entry becomes the key, via sanitize_key.
        expect($plugins)->not->toBeEmpty()
            ->and(reset($plugins)['file'])->toBe('single.php');
    });

    it('skips lines with an empty file or key', function () {
        // "|Label" has no file; "###" sanitises to an empty key.
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "|Label\n/leading-slash.php|X\nok/ok.php|OK");

        $plugins = SettingsPage::getMandatoryPlugins();

        expect($plugins)->toHaveKey('ok')
            ->not->toHaveKey('');
    });

    it('returns an empty array for an empty option', function () {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, '');

        expect(SettingsPage::getMandatoryPlugins())->toBe([]);
    });
});

// ── drop-table option ─────────────────────────────────────────────
it('does not drop the table unless explicitly opted in', function () {
    expect(SettingsPage::shouldDropTable())->toBeFalse();

    $this->setOption(SettingsPage::OPTION_DROP_TABLE, '1');
    expect(SettingsPage::shouldDropTable())->toBeTrue();

    $this->setOption(SettingsPage::OPTION_DROP_TABLE, '');
    expect(SettingsPage::shouldDropTable())->toBeFalse();
});

// ── field renderers ───────────────────────────────────────────────
describe('field renderers', function () {
    it('renders the section descriptions', function () {
        $monitored = $this->capture([SettingsPage::class, 'renderMonitoredPluginsSectionDescription']);
        $uninstall = $this->capture([SettingsPage::class, 'renderUninstallSectionDescription']);

        expect($monitored)->toContain('folder/file.php|Label')
            ->and($uninstall)->toContain('class="description"');
    });

    it('renders the stored value in the plugin list fields', function () {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, 'stored/mandatory.php|M');
        $this->setOption(SettingsPage::OPTION_OPTIONAL_PLUGINS, 'stored/optional.php|O');

        $mandatory = $this->capture([SettingsPage::class, 'renderMandatoryPluginsField']);
        $optional  = $this->capture([SettingsPage::class, 'renderOptionalPluginsField']);

        expect($mandatory)->toContain('stored/mandatory.php|M')
            ->toContain('<textarea')
            ->and($optional)->toContain('stored/optional.php|O');
    });

    it('renders the drop-table field as a checkbox', function () {
        $html = $this->capture([SettingsPage::class, 'renderDropTableField']);

        expect($html)->toContain('type="checkbox"')
            ->toContain(SettingsPage::OPTION_DROP_TABLE);
    });
});

// ── wp-config.php location ────────────────────────────────────────
describe('wp-config.php location', function () {
    it('finds the file in ABSPATH', function () {
        $path = $this->writeWpConfig();

        expect(SettingsPage::wpConfigPath())->toBe($path)
            ->and(SettingsPage::isWpConfigWritable())->toBeTrue();
    });

    it('is null when no file exists', function () {
        $this->removeWpConfig();

        // ABSPATH's parent is the system temp dir; only assert the negative
        // when that genuinely has no wp-config.php of its own.
        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        expect(SettingsPage::wpConfigPath())->toBeNull()
            ->and(SettingsPage::isWpConfigWritable())->toBeFalse();
    });
});

// ── wp-config.php constant writing ────────────────────────────────
describe('setWpConfigConstant', function () {
    it('inserts the marker and the define', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'warning'))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->toContain('/* Sentinel Logger Configuration */')
            ->toContain("define( 'SENTINEL_LOG_LEVEL', 'warning' );")
            // The original contents survive the rewrite.
            ->toContain("\$table_prefix = 'wp_';");
    });

    it('replaces an existing constant in place', function () {
        $this->writeWpConfig("<?php\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n");

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'error'))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'SENTINEL_LOG_LEVEL', 'error' );")
            ->not->toContain("'debug'")
            // Replacement, not duplication.
            ->and(substr_count($config, 'SENTINEL_LOG_LEVEL'))->toBe(1);
    });

    it('appends below an existing marker', function () {
        $this->writeWpConfig("<?php\n/* Sentinel Logger Configuration */\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n");

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_MAX_ROWS', 25000))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'SENTINEL_LOG_MAX_ROWS', 25000 );")
            // Only one marker — it was reused, not re-added.
            ->and(substr_count($config, '/* Sentinel Logger Configuration */'))->toBe(1);
    });

    it('formats values by PHP type', function () {
        $this->writeWpConfig();

        SettingsPage::setWpConfigConstant('SENTINEL_LOG_ENABLED', true);
        SettingsPage::setWpConfigConstant('SENTINEL_CAPTURE_ERRORS', false);
        SettingsPage::setWpConfigConstant('SENTINEL_LOG_BUFFER_SIZE', 100);
        SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', "it's odd");

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'SENTINEL_LOG_ENABLED', true );")
            ->toContain("define( 'SENTINEL_CAPTURE_ERRORS', false );")
            ->toContain("define( 'SENTINEL_LOG_BUFFER_SIZE', 100 );")
            // Quotes in a string value are escaped rather than breaking the file.
            ->toContain("it\\'s odd");
    });

    it('fails when there is no wp-config', function () {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'warning'))->toBeFalse()
            ->and(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'))->toBeFalse();
    });

    it('still gives a config without an opening tag the block', function () {
        $this->writeWpConfig("no php tag here\n");

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'notice'))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->toStartWith('<?php')
            ->toContain("define( 'SENTINEL_LOG_LEVEL', 'notice' );");
    });

    it('appends the block at the end of a single-line config', function () {
        // No newline after the opening tag, so there is no end-of-line to
        // insert after and the block is appended instead.
        $this->writeWpConfig('<?php');

        expect(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'alert'))->toBeTrue()
            ->and($this->readWpConfig())->toContain("define( 'SENTINEL_LOG_LEVEL', 'alert' );");
    });
});

// ── wp-config.php constant removal ────────────────────────────────
describe('removing constants', function () {
    it('deletes the line and the orphaned marker', function () {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n\$table_prefix = 'wp_';\n"
        );

        expect(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->not->toContain('SENTINEL_LOG_LEVEL')
            // Last Sentinel constant gone, so the marker goes too.
            ->not->toContain('/* Sentinel Logger Configuration */')
            ->toContain("\$table_prefix = 'wp_';");
    });

    it('keeps the marker while others remain', function () {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\n"
            . "define( 'SENTINEL_LOG_LEVEL', 'debug' );\n"
            . "define( 'SENTINEL_LOG_MAX_ROWS', 10000 );\n"
        );

        expect(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'))->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->not->toContain('SENTINEL_LOG_LEVEL')
            ->toContain('SENTINEL_LOG_MAX_ROWS')
            ->toContain('/* Sentinel Logger Configuration */');
    });

    it('treats removing an absent constant as a successful no-op', function () {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        expect(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'))->toBeTrue()
            ->and($this->readWpConfig())->toBe($before, 'File untouched when nothing matched.');
    });

    it('clears every Sentinel constant with removeAll', function () {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\n"
            . "define( 'SENTINEL_LOG_ENABLED', true );\n"
            . "define( 'SENTINEL_LOG_LEVEL', 'debug' );\n"
            . "define( 'SENTINEL_LOG_MAX_ROWS', 10000 );\n"
            . "define( 'SENTINEL_LOG_BUFFER_SIZE', 50 );\n"
            . "define( 'SENTINEL_CAPTURE_ERRORS', true );\n"
            . "\$table_prefix = 'wp_';\n"
        );

        expect(SettingsPage::removeAllWpConfigConstants())->toBeTrue();

        $config = $this->readWpConfig();
        expect($config)->not->toContain('SENTINEL_')
            ->toContain("\$table_prefix = 'wp_';");
    });
});

// ── logger config save handler ────────────────────────────────────
describe('logger config save handler', function () {
    // returned early without touching wp-config.php
    it('ignores requests without its nonce field', function () {
        $_POST = [];

        SettingsPage::handleLoggerConfigSave();
    })->throwsNoExceptions();

    it('writes every constant', function () {
        $this->writeWpConfig();
        $_POST = [
            '_sentinel_logger_nonce'    => 'n',
            'sentinel_log_enabled'      => '1',
            'sentinel_log_level'        => 'warning',
            'sentinel_log_max_rows'     => '25000',
            'sentinel_log_buffer_size'  => '100',
            'sentinel_capture_errors'   => '1',
        ];

        SettingsPage::handleLoggerConfigSave();

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'SENTINEL_LOG_ENABLED', true );")
            ->toContain("define( 'SENTINEL_LOG_LEVEL', 'warning' );")
            ->toContain("define( 'SENTINEL_LOG_MAX_ROWS', 25000 );")
            ->toContain("define( 'SENTINEL_LOG_BUFFER_SIZE', 100 );")
            ->toContain("define( 'SENTINEL_CAPTURE_ERRORS', true );");

        $_POST = [];
    });

    it('rejects an unknown level and clamps numbers', function () {
        $this->writeWpConfig();
        $_POST = [
            '_sentinel_logger_nonce'   => 'n',
            'sentinel_log_level'       => 'not-a-level',
            'sentinel_log_max_rows'    => '5',        // below the 100 floor
            'sentinel_log_buffer_size' => '99999',    // above the 500 ceiling
        ];

        SettingsPage::handleLoggerConfigSave();

        $config = $this->readWpConfig();
        expect($config)->toContain("define( 'SENTINEL_LOG_LEVEL', 'debug' );")
            ->toContain("define( 'SENTINEL_LOG_MAX_ROWS', 100 );")
            ->toContain("define( 'SENTINEL_LOG_BUFFER_SIZE', 500 );")
            // Unchecked checkboxes save as false.
            ->toContain("define( 'SENTINEL_LOG_ENABLED', false );")
            ->toContain("define( 'SENTINEL_CAPTURE_ERRORS', false );");

        $_POST = [];
    });

    // not-writable branch reported via add_settings_error
    it('reports when wp-config is missing', function () {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $_POST = ['_sentinel_logger_nonce' => 'n'];

        try {
            SettingsPage::handleLoggerConfigSave();
        } finally {
            $_POST = [];
        }
    })->throwsNoExceptions();
});

// ── page rendering ────────────────────────────────────────────────
describe('renderPage', function () {
    it('outputs the settings screen', function () {
        $this->writeWpConfig();

        $html = $this->capture([SettingsPage::class, 'renderPage']);

        expect($html)->toContain('Sentinel Settings')
            ->toContain('<form')
            // The logger constants table is built from getLoggingConfig().
            ->toContain('SENTINEL_LOG_LEVEL')
            ->toContain('SENTINEL_LOG_MAX_ROWS')
            ->toContain('SENTINEL_CAPTURE_ERRORS');
    });

    it('refuses users without the capability', function () {
        $this->denyCapability();

        SettingsPage::renderPage();
    })->throws(WpDieException::class);
});
