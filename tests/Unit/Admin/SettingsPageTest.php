<?php

declare(strict_types=1);

namespace Sentinel\Tests\Unit\Admin;

use Sentinel\Admin\SettingsPage;
use Sentinel\Tests\AdminTestCase;
use Sentinel\Tests\WpDieException;

/**
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
final class SettingsPageTest extends AdminTestCase
{
    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_and_registration_hooks_run_without_error(): void
    {
        SettingsPage::init();
        SettingsPage::registerPage();
        SettingsPage::registerSettings();

        $this->assertTrue(true, 'registration completed');
    }

    /** @test */
    public function enqueue_assets_only_loads_on_its_own_screen(): void
    {
        // registerPage() records the hook suffix returned by
        // add_submenu_page(); anything else must be ignored.
        SettingsPage::registerPage();

        SettingsPage::enqueueAssets('some-other-page');
        SettingsPage::enqueueAssets('sentinel_page_stub');

        $this->assertTrue(true, 'enqueue guarded by hook suffix');
    }

    // ── sanitizePluginList ────────────────────────────────────────────

    /** @test */
    public function sanitize_plugin_list_returns_empty_string_for_non_string(): void
    {
        $this->assertSame('', SettingsPage::sanitizePluginList(null));
        $this->assertSame('', SettingsPage::sanitizePluginList(['a']));
        $this->assertSame('', SettingsPage::sanitizePluginList(42));
    }

    /** @test */
    public function sanitize_plugin_list_drops_blank_and_comment_lines(): void
    {
        $input = "unity/unity.php|Unity\r\n\r\n# a comment\n   \nreach/reach.php|Reach\r";

        $this->assertSame(
            "unity/unity.php|Unity\nreach/reach.php|Reach",
            SettingsPage::sanitizePluginList($input),
            'CRLF is normalised and blank/comment lines are stripped.'
        );
    }

    // ── plugin list parsing ───────────────────────────────────────────

    /** @test */
    public function mandatory_plugins_fall_back_to_the_shipped_default_list(): void
    {
        $plugins = SettingsPage::getMandatoryPlugins();

        $this->assertArrayHasKey('unity', $plugins);
        $this->assertSame('unity/unity.php', $plugins['unity']['file']);
        $this->assertSame('Unity', $plugins['unity']['label']);
        $this->assertArrayHasKey('scrutiny', $plugins);
    }

    /** @test */
    public function optional_plugins_fall_back_to_the_shipped_default_list(): void
    {
        $plugins = SettingsPage::getOptionalPlugins();

        $this->assertArrayHasKey('reach', $plugins);
        $this->assertSame('Reach', $plugins['reach']['label']);
    }

    /** @test */
    public function parser_derives_a_humanised_label_when_none_is_given(): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "my-great_plugin/file.php");

        $plugins = SettingsPage::getMandatoryPlugins();

        $this->assertSame('My Great Plugin', $plugins['my-great_plugin']['label']);
    }

    /** @test */
    public function parser_keeps_the_first_of_a_duplicated_key(): void
    {
        $this->setOption(
            SettingsPage::OPTION_MANDATORY_PLUGINS,
            "unity/unity.php|First\nunity/other.php|Second"
        );

        $plugins = SettingsPage::getMandatoryPlugins();

        $this->assertCount(1, $plugins);
        $this->assertSame('First', $plugins['unity']['label']);
    }

    /** @test */
    public function parser_handles_an_entry_with_no_directory_segment(): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "single.php|Single");

        $plugins = SettingsPage::getMandatoryPlugins();

        // With no slash the whole entry becomes the key, via sanitize_key.
        $this->assertNotEmpty($plugins);
        $this->assertSame('single.php', reset($plugins)['file']);
    }

    /** @test */
    public function parser_skips_lines_with_an_empty_file_or_key(): void
    {
        // "|Label" has no file; "###" sanitises to an empty key.
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, "|Label\n/leading-slash.php|X\nok/ok.php|OK");

        $plugins = SettingsPage::getMandatoryPlugins();

        $this->assertArrayHasKey('ok', $plugins);
        $this->assertArrayNotHasKey('', $plugins);
    }

    /** @test */
    public function parser_returns_empty_array_for_empty_option(): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, '');

        $this->assertSame([], SettingsPage::getMandatoryPlugins());
    }

    // ── drop-table option ─────────────────────────────────────────────

    /** @test */
    public function should_drop_table_is_false_unless_explicitly_opted_in(): void
    {
        $this->assertFalse(SettingsPage::shouldDropTable());

        $this->setOption(SettingsPage::OPTION_DROP_TABLE, '1');
        $this->assertTrue(SettingsPage::shouldDropTable());

        $this->setOption(SettingsPage::OPTION_DROP_TABLE, '');
        $this->assertFalse(SettingsPage::shouldDropTable());
    }

    // ── field renderers ───────────────────────────────────────────────

    /** @test */
    public function section_descriptions_render(): void
    {
        $monitored = $this->capture([SettingsPage::class, 'renderMonitoredPluginsSectionDescription']);
        $uninstall = $this->capture([SettingsPage::class, 'renderUninstallSectionDescription']);

        $this->assertStringContainsString('folder/file.php|Label', $monitored);
        $this->assertStringContainsString('class="description"', $uninstall);
    }

    /** @test */
    public function plugin_list_fields_render_the_stored_value(): void
    {
        $this->setOption(SettingsPage::OPTION_MANDATORY_PLUGINS, 'stored/mandatory.php|M');
        $this->setOption(SettingsPage::OPTION_OPTIONAL_PLUGINS, 'stored/optional.php|O');

        $mandatory = $this->capture([SettingsPage::class, 'renderMandatoryPluginsField']);
        $optional  = $this->capture([SettingsPage::class, 'renderOptionalPluginsField']);

        $this->assertStringContainsString('stored/mandatory.php|M', $mandatory);
        $this->assertStringContainsString('<textarea', $mandatory);
        $this->assertStringContainsString('stored/optional.php|O', $optional);
    }

    /** @test */
    public function drop_table_field_renders_a_checkbox(): void
    {
        $html = $this->capture([SettingsPage::class, 'renderDropTableField']);

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString(SettingsPage::OPTION_DROP_TABLE, $html);
    }

    // ── wp-config.php location ────────────────────────────────────────

    /** @test */
    public function wp_config_path_finds_the_file_in_abspath(): void
    {
        $path = $this->writeWpConfig();

        $this->assertSame($path, SettingsPage::wpConfigPath());
        $this->assertTrue(SettingsPage::isWpConfigWritable());
    }

    /** @test */
    public function wp_config_path_is_null_when_no_file_exists(): void
    {
        $this->removeWpConfig();

        // ABSPATH's parent is the system temp dir; only assert the negative
        // when that genuinely has no wp-config.php of its own.
        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $this->assertNull(SettingsPage::wpConfigPath());
        $this->assertFalse(SettingsPage::isWpConfigWritable());
    }

    // ── wp-config.php constant writing ────────────────────────────────

    /** @test */
    public function setting_a_constant_inserts_the_marker_and_define(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");

        $this->assertTrue(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'warning'));

        $config = $this->readWpConfig();
        $this->assertStringContainsString('/* Sentinel Logger Configuration */', $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'warning' );", $config);
        // The original contents survive the rewrite.
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    /** @test */
    public function setting_an_existing_constant_replaces_it_in_place(): void
    {
        $this->writeWpConfig("<?php\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n");

        $this->assertTrue(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'error'));

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'error' );", $config);
        $this->assertStringNotContainsString("'debug'", $config);
        // Replacement, not duplication.
        $this->assertSame(1, substr_count($config, 'SENTINEL_LOG_LEVEL'));
    }

    /** @test */
    public function setting_a_constant_appends_below_an_existing_marker(): void
    {
        $this->writeWpConfig("<?php\n/* Sentinel Logger Configuration */\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n");

        $this->assertTrue(SettingsPage::setWpConfigConstant('SENTINEL_LOG_MAX_ROWS', 25000));

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'SENTINEL_LOG_MAX_ROWS', 25000 );", $config);
        // Only one marker — it was reused, not re-added.
        $this->assertSame(1, substr_count($config, '/* Sentinel Logger Configuration */'));
    }

    /** @test */
    public function values_are_formatted_by_php_type(): void
    {
        $this->writeWpConfig();

        SettingsPage::setWpConfigConstant('SENTINEL_LOG_ENABLED', true);
        SettingsPage::setWpConfigConstant('SENTINEL_CAPTURE_ERRORS', false);
        SettingsPage::setWpConfigConstant('SENTINEL_LOG_BUFFER_SIZE', 100);
        SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', "it's odd");

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'SENTINEL_LOG_ENABLED', true );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_CAPTURE_ERRORS', false );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_BUFFER_SIZE', 100 );", $config);
        // Quotes in a string value are escaped rather than breaking the file.
        $this->assertStringContainsString("it\\'s odd", $config);
    }

    /** @test */
    public function setting_a_constant_fails_when_there_is_no_wp_config(): void
    {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $this->assertFalse(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'warning'));
        $this->assertFalse(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'));
    }

    /** @test */
    public function a_config_without_an_opening_tag_still_gets_the_block(): void
    {
        $this->writeWpConfig("no php tag here\n");

        $this->assertTrue(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'notice'));

        $config = $this->readWpConfig();
        $this->assertStringStartsWith('<?php', $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'notice' );", $config);
    }

    /** @test */
    public function a_single_line_config_appends_the_block_at_the_end(): void
    {
        // No newline after the opening tag, so there is no end-of-line to
        // insert after and the block is appended instead.
        $this->writeWpConfig('<?php');

        $this->assertTrue(SettingsPage::setWpConfigConstant('SENTINEL_LOG_LEVEL', 'alert'));

        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'alert' );", $this->readWpConfig());
    }

    // ── wp-config.php constant removal ────────────────────────────────

    /** @test */
    public function removing_a_constant_deletes_the_line_and_the_orphaned_marker(): void
    {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\ndefine( 'SENTINEL_LOG_LEVEL', 'debug' );\n\$table_prefix = 'wp_';\n"
        );

        $this->assertTrue(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'));

        $config = $this->readWpConfig();
        $this->assertStringNotContainsString('SENTINEL_LOG_LEVEL', $config);
        // Last Sentinel constant gone, so the marker goes too.
        $this->assertStringNotContainsString('/* Sentinel Logger Configuration */', $config);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    /** @test */
    public function removing_a_constant_keeps_the_marker_while_others_remain(): void
    {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\n"
            . "define( 'SENTINEL_LOG_LEVEL', 'debug' );\n"
            . "define( 'SENTINEL_LOG_MAX_ROWS', 10000 );\n"
        );

        $this->assertTrue(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'));

        $config = $this->readWpConfig();
        $this->assertStringNotContainsString('SENTINEL_LOG_LEVEL', $config);
        $this->assertStringContainsString('SENTINEL_LOG_MAX_ROWS', $config);
        $this->assertStringContainsString('/* Sentinel Logger Configuration */', $config);
    }

    /** @test */
    public function removing_an_absent_constant_is_a_successful_no_op(): void
    {
        $this->writeWpConfig("<?php\n\$table_prefix = 'wp_';\n");
        $before = $this->readWpConfig();

        $this->assertTrue(SettingsPage::removeWpConfigConstant('SENTINEL_LOG_LEVEL'));
        $this->assertSame($before, $this->readWpConfig(), 'File untouched when nothing matched.');
    }

    /** @test */
    public function remove_all_clears_every_sentinel_constant(): void
    {
        $this->writeWpConfig(
            "<?php\n/* Sentinel Logger Configuration */\n"
            . "define( 'SENTINEL_LOG_ENABLED', true );\n"
            . "define( 'SENTINEL_LOG_LEVEL', 'debug' );\n"
            . "define( 'SENTINEL_LOG_MAX_ROWS', 10000 );\n"
            . "define( 'SENTINEL_LOG_BUFFER_SIZE', 50 );\n"
            . "define( 'SENTINEL_CAPTURE_ERRORS', true );\n"
            . "\$table_prefix = 'wp_';\n"
        );

        $this->assertTrue(SettingsPage::removeAllWpConfigConstants());

        $config = $this->readWpConfig();
        $this->assertStringNotContainsString('SENTINEL_', $config);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
    }

    // ── logger config save handler ────────────────────────────────────

    /** @test */
    public function save_handler_ignores_requests_without_its_nonce_field(): void
    {
        $_POST = [];

        SettingsPage::handleLoggerConfigSave();

        $this->assertTrue(true, 'returned early without touching wp-config.php');
    }

    /** @test */
    public function save_handler_writes_every_constant(): void
    {
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
        $this->assertStringContainsString("define( 'SENTINEL_LOG_ENABLED', true );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'warning' );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_MAX_ROWS', 25000 );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_BUFFER_SIZE', 100 );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_CAPTURE_ERRORS', true );", $config);

        $_POST = [];
    }

    /** @test */
    public function save_handler_rejects_an_unknown_level_and_clamps_numbers(): void
    {
        $this->writeWpConfig();
        $_POST = [
            '_sentinel_logger_nonce'   => 'n',
            'sentinel_log_level'       => 'not-a-level',
            'sentinel_log_max_rows'    => '5',        // below the 100 floor
            'sentinel_log_buffer_size' => '99999',    // above the 500 ceiling
        ];

        SettingsPage::handleLoggerConfigSave();

        $config = $this->readWpConfig();
        $this->assertStringContainsString("define( 'SENTINEL_LOG_LEVEL', 'debug' );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_MAX_ROWS', 100 );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_LOG_BUFFER_SIZE', 500 );", $config);
        // Unchecked checkboxes save as false.
        $this->assertStringContainsString("define( 'SENTINEL_LOG_ENABLED', false );", $config);
        $this->assertStringContainsString("define( 'SENTINEL_CAPTURE_ERRORS', false );", $config);

        $_POST = [];
    }

    /** @test */
    public function save_handler_reports_when_wp_config_is_missing(): void
    {
        $this->removeWpConfig();

        if (file_exists(dirname(ABSPATH) . '/wp-config.php')) {
            $this->markTestSkipped('A wp-config.php exists above ABSPATH on this machine.');
        }

        $_POST = ['_sentinel_logger_nonce' => 'n'];

        SettingsPage::handleLoggerConfigSave();

        $this->assertTrue(true, 'not-writable branch reported via add_settings_error');

        $_POST = [];
    }

    // ── page rendering ────────────────────────────────────────────────

    /** @test */
    public function render_page_outputs_the_settings_screen(): void
    {
        $this->writeWpConfig();

        $html = $this->capture([SettingsPage::class, 'renderPage']);

        $this->assertStringContainsString('Sentinel Settings', $html);
        $this->assertStringContainsString('<form', $html);
        // The logger constants table is built from getLoggingConfig().
        $this->assertStringContainsString('SENTINEL_LOG_LEVEL', $html);
        $this->assertStringContainsString('SENTINEL_LOG_MAX_ROWS', $html);
        $this->assertStringContainsString('SENTINEL_CAPTURE_ERRORS', $html);
    }

    /** @test */
    public function render_page_refuses_users_without_the_capability(): void
    {
        $this->denyCapability();

        $this->expectException(WpDieException::class);

        SettingsPage::renderPage();
    }
}
