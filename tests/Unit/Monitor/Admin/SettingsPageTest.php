<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use function Brain\Monkey\Functions\when;

use BleedingDeacons\WpMocks\WpState;
use ReflectionMethod;
use Sentinel\Monitor\Admin\SettingsPage;
use Sentinel\Monitor\Core\Settings;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSender;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSettings;
use Sentinel\Tests\Monitor\Fixtures\InMemoryAlertRepository;
use Sentinel\Tests\Monitor\Fixtures\InMemoryDeviceRepository;
use Sentinel\Tests\Monitor\Fixtures\RecordingTransport;

/**
 * Monitor Settings: the error-alert section and its test button.
 *
 * The save and test handlers end `wp_safe_redirect(); exit;`, so their
 * bodies are split out and driven here through reflection, as on the
 * devices screen.
 */

function settingsPageCall(SettingsPage $page, string $method): mixed
{
    return (new ReflectionMethod(SettingsPage::class, $method))->invoke($page);
}

beforeEach(function () {
    require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';

    WpState::$options = [];
    $_GET = [];
    $_POST = [];

    // Nonces and submit_button() are wp-admin's, and not under test here.
    when('check_admin_referer')->justReturn(true);
    when('submit_button')->alias(function (string $text = 'Save Changes'): void {
        echo '<button type="submit">' . $text . '</button>';
    });

    $this->devices = new InMemoryDeviceRepository();
    $this->alerts = new InMemoryAlertRepository();
    $this->errorAlerts = new ErrorAlertSettings();
    $this->page = new SettingsPage(
        new Settings(),
        $this->errorAlerts,
        new ErrorAlertSender($this->devices, $this->alerts, new RecordingTransport(), $this->errorAlerts),
    );

    $this->render = function (): string {
        ob_start();
        try {
            $this->page->render();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    };
});

test('the page shows the error alert settings', function () {
    $html = ($this->render)();

    $this->assertStringContainsString('<h2>Error alerts</h2>', $html);
    $this->assertStringContainsString('name="error_alerts[threshold]"', $html);
    $this->assertStringContainsString('value="wp, monitor"', $html);
    $this->assertStringContainsString(SettingsPage::TEST_ERROR_ALERT_ACTION, $html);
});

test('saving the form saves the error alert settings', function () {
    $_POST = ['error_alerts' => [
        'threshold'         => 'critical',
        'excluded_channels' => 'wp',
        'cooldown_minutes'  => '30',
        'hourly_cap'        => '5',
    ]];

    settingsPageCall($this->page, 'saveFromRequest');

    // The box was unticked, so it was not sent at all.
    $this->assertFalse($this->errorAlerts->isEnabled());
    $this->assertSame('critical', $this->errorAlerts->threshold());
    $this->assertSame(['wp'], $this->errorAlerts->excludedChannels());
    $this->assertSame(30, $this->errorAlerts->cooldownMinutes());
    $this->assertSame(5, $this->errorAlerts->hourlyCap());
});

test('the test button reaches the opted-in handsets and says how many', function () {
    $device = $this->devices->create(str_repeat('a', 64), 'oncall@example.com', 7, 'Phone', 'android', 'fcm', 't', 1000, '');
    $this->devices->setErrorAlerts($device->id, true);

    $target = (string) settingsPageCall($this->page, 'testErrorAlertFromRequest');

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame($device->id, $this->alerts->alerts[0]->targetDeviceId);
    $this->assertStringContainsString('error_alert_test=1', $target);

    $_GET = ['error_alert_test' => '1'];
    $this->assertStringContainsString('Test error alert sent to 1 handset.', ($this->render)());
});

test('with nobody opted in the test says so', function () {
    $target = (string) settingsPageCall($this->page, 'testErrorAlertFromRequest');

    $this->assertSame([], $this->alerts->alerts);
    $this->assertStringContainsString('error_alert_test=0', $target);

    $_GET = ['error_alert_test' => '0'];
    $this->assertStringContainsString('No handset is opted in to error alerts', ($this->render)());
});

test('a logger that drops errors is warned about', function () {
    \Brain\Monkey\Filters\expectApplied('sentinel_logger_level')->andReturn('critical');

    $this->assertStringContainsString('SENTINEL_LOG_LEVEL', ($this->render)());
});
