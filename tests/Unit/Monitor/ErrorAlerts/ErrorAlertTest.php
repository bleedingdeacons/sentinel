<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use BleedingDeacons\WpMocks\WpState;
use Sentinel\Monitor\Alerts\Alert;
use Sentinel\Monitor\Core\Schema;
use Sentinel\Monitor\Devices\Device;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertListener;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertRule;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSender;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSettings;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertThrottle;
use Sentinel\Monitor\ErrorAlerts\LoggedError;
use Sentinel\Tests\Monitor\Fixtures\InMemoryAlertRepository;
use Sentinel\Tests\Monitor\Fixtures\InMemoryDeviceRepository;
use Sentinel\Tests\Monitor\Fixtures\RecordingTransport;

/**
 * Paging opted-in handsets when an error is logged.
 *
 * Driven through the real listener, throttle, sender and settings over the
 * in-memory repositories, so what a test asserts is what a handset would get:
 * the rows it would poll and the pushes it would receive.
 */

function enrolHandset(InMemoryDeviceRepository $devices, string $email, bool $errorAlerts, string $push = 'fcm'): Device
{
    $device = $devices->create(
        str_repeat(substr(md5($email), 0, 1), 64),
        $email,
        7,
        'Phone of ' . $email,
        'android',
        $push,
        $push === '' ? '' : 'fcm-token-' . $email,
        1000,
        base64_encode(random_bytes(32)),
    );
    if ($errorAlerts) {
        $devices->setErrorAlerts($device->id, true);
    }

    return $devices->findById($device->id) ?? $device;
}

beforeEach(function () {
    require_once SENTINEL_PLUGIN_DIR . 'src/Logger/sentinel-logger.php';

    WpState::$options = [];
    WpState::$transients = [];
    update_option(Schema::OPTION, Schema::VERSION, true);

    $this->devices = new InMemoryDeviceRepository();
    $this->alerts = new InMemoryAlertRepository();
    $this->transport = new RecordingTransport();
    $this->settings = new ErrorAlertSettings();
    $this->sender = new ErrorAlertSender($this->devices, $this->alerts, $this->transport);

    ErrorAlertListener::reset();
    ErrorAlertListener::useFactories(fn () => $this->settings, fn () => $this->sender);

    $this->onCall = enrolHandset($this->devices, 'oncall@example.com', true);
    $this->responder = enrolHandset($this->devices, 'responder@example.com', false);

    $this->log = function (string $level, string $message, array $context = []): void {
        ErrorAlertListener::onLog($level, $message, $context + ['_channel' => 'unity']);
    };
});

afterEach(function () {
    ErrorAlertListener::reset();
    ErrorAlertListener::useFactories(null, null);
});

// --- the listener -----------------------------------------------------
test('an error is queued and nothing is sent until shutdown', function () {
    ($this->log)('error', 'Sync failed');

    $this->assertCount(1, ErrorAlertListener::queued());
    $this->assertSame([], $this->alerts->alerts);
});

test('below the threshold nothing is queued', function () {
    ($this->log)('warning', 'Slow query');
    ($this->log)('notice', 'Cache miss');

    $this->assertSame([], ErrorAlertListener::queued());
});

test('a rule from critical leaves plain errors alone', function () {
    $this->settings->save(['enabled' => '1', 'rules' => [
        ['channel' => '*', 'level' => 'critical', 'alert' => 'yellow', 'timeout_minutes' => '15'],
    ]]);

    ($this->log)('error', 'Sync failed');
    ($this->log)('critical', 'Database gone');

    $this->assertCount(1, ErrorAlertListener::queued());
    $this->assertSame('critical', ErrorAlertListener::queued()[0]->level);
});

test('wp_die and the module\'s own channel never raise an alert', function () {
    ErrorAlertListener::onLog('critical', 'wp_die: Sorry, you are not allowed', ['_channel' => 'wp']);
    ErrorAlertListener::onLog('error', 'Alert reached no handset', ['_channel' => 'monitor']);

    $this->assertSame([], ErrorAlertListener::queued());
});

test('switched off, nothing is queued', function () {
    $this->settings->save([]);

    ($this->log)('emergency', 'Everything is on fire');

    $this->assertSame([], ErrorAlertListener::queued());
});

test('a fatal, as the logger announces it, is queued like any error', function () {
    ErrorAlertListener::onLog('emergency', 'Fatal: Allowed memory size exhausted', [
        '_channel' => 'php',
        'file'     => '/home/site/public_html/wp-content/plugins/unity/src/Plugin.php',
        'line'     => 88,
        'type'     => E_ERROR,
    ]);
    ErrorAlertListener::deliver(5000);

    $alert = $this->alerts->alerts[0];
    $this->assertSame('[php] EMERGENCY', $alert->title);
    $this->assertSame(Alert::LEVEL_RED, $alert->level);
    $this->assertStringContainsString('plugins/unity/src/Plugin.php:88', $alert->body);
    $this->assertStringNotContainsString('/home/site', $alert->body);
});

test('the listener is hung on both logger hooks', function () {
    $this->captureActions(['sentinel_logger_after_write', 'sentinel_logger_fatal']);

    ErrorAlertListener::register();

    $this->assertCount(1, $this->actionCallbacks('sentinel_logger_after_write'));
    $this->assertCount(1, $this->actionCallbacks('sentinel_logger_fatal'));
});

// --- delivery ---------------------------------------------------------
test('only the opted-in handset is paged, by push and by poll', function () {
    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertCount(1, $this->transport->delivered);
    $this->assertSame($this->onCall->id, $this->transport->delivered[0]['device']->id);

    // The poll is the other way in, and it must agree.
    $this->assertCount(1, $this->alerts->pendingFor('oncall@example.com', $this->onCall->id, 5001, 50));
    $this->assertSame([], $this->alerts->pendingFor('responder@example.com', $this->responder->id, 5001, 50));
});

test('the alert is informational and says what was logged', function () {
    ($this->log)('error', 'Sync failed for member 4411');
    ErrorAlertListener::deliver(5000);

    $alert = $this->alerts->alerts[0];
    $this->assertSame(ErrorAlertSender::KIND, $alert->kind);
    $this->assertSame(ErrorAlertSender::SOURCE, $alert->source);
    $this->assertSame(Alert::RESPONSE_NONE, $alert->response);
    $this->assertSame(Alert::LEVEL_BLUE, $alert->level);
    $this->assertSame('[unity] ERROR', $alert->title);
    $this->assertSame('Sync failed for member 4411', $alert->body);
    $this->assertSame($this->onCall->id, $alert->targetDeviceId);
    $this->assertSame(5000 + 86400, $alert->expiresAt);
});

test('the log context never travels', function () {
    ($this->log)('error', 'Login failed', ['password' => 'hunter2', 'token' => 'secret-token']);
    ErrorAlertListener::deliver(5000);

    $alert = $this->alerts->alerts[0];
    $everything = json_encode([$alert->title, $alert->body, $alert->payload, $alert->reference]);
    $this->assertStringNotContainsString('hunter2', (string) $everything);
    $this->assertStringNotContainsString('secret-token', (string) $everything);
});

test('several opted-in handsets get one message between them', function () {
    $second = enrolHandset($this->devices, 'admin@example.com', true);

    ($this->log)('critical', 'Database gone');
    ErrorAlertListener::deliver(5000);

    $this->assertCount(2, $this->alerts->alerts);
    $this->assertEqualsCanonicalizing(
        [$this->onCall->id, $second->id],
        array_map(fn (Alert $a): int => $a->targetDeviceId, $this->alerts->alerts),
    );
    $this->assertSame($this->alerts->alerts[0]->messageUuid, $this->alerts->alerts[1]->messageUuid);
    $this->assertSame(Alert::LEVEL_YELLOW, $this->alerts->alerts[0]->level);
});

test('a revoked or poll-only handset is still handled correctly', function () {
    $pollOnly = enrolHandset($this->devices, 'poll@example.com', true, push: '');
    $this->devices->revoke($this->onCall->id, 2000);

    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    // The revoked one gets nothing; the poll-only one is stored for, not pushed to.
    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame($pollOnly->id, $this->alerts->alerts[0]->targetDeviceId);
    $this->assertSame([], $this->transport->delivered);
});

test('with nobody opted in nothing is stored', function () {
    $this->devices->setErrorAlerts($this->onCall->id, false);

    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    $this->assertSame([], $this->alerts->alerts);
});

test('nothing is sent before the monitor tables exist', function () {
    delete_option(Schema::OPTION);

    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    $this->assertSame([], $this->alerts->alerts);
    $this->assertSame([], ErrorAlertListener::queued());
});

test('anything logged while delivering is ignored', function () {
    // A refused push logs an error. Delivering must not queue it, or a
    // broken FCM key would page about itself for ever.
    $this->transport->accept = false;
    $inner = new ErrorAlertSender($this->devices, $this->alerts, $this->transport);
    ErrorAlertListener::useFactories(fn () => $this->settings, function () use ($inner) {
        ErrorAlertListener::onLog('error', 'FCM refused the push', ['_channel' => 'php']);
        return $inner;
    });

    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame([], ErrorAlertListener::queued());
});

test('the same error repeated in one request is one alert that says so', function () {
    ($this->log)('error', 'Sync failed for member 1');
    ($this->log)('error', 'Sync failed for member 2');
    ($this->log)('error', 'Sync failed for member 3');
    ($this->log)('error', 'Something else entirely');
    ErrorAlertListener::deliver(5000);

    $this->assertCount(2, $this->alerts->alerts);
    $this->assertStringContainsString('+2 similar since the last alert', $this->alerts->alerts[0]->body);
    $this->assertStringNotContainsString('similar', $this->alerts->alerts[1]->body);
});

test('a repeat inside the cooldown is held back and counted on the next alert', function () {
    ($this->log)('error', 'Sync failed for member 1');
    ErrorAlertListener::deliver(5000);

    ($this->log)('error', 'Sync failed for member 2');
    ErrorAlertListener::deliver(5000 + 60);
    ($this->log)('error', 'Sync failed for member 3');
    ErrorAlertListener::deliver(5000 + 120);

    $this->assertCount(1, $this->alerts->alerts);

    ($this->log)('error', 'Sync failed for member 4');
    ErrorAlertListener::deliver(5000 + 15 * 60);

    $this->assertCount(2, $this->alerts->alerts);
    $this->assertStringContainsString('+2 similar since the last alert', $this->alerts->alerts[1]->body);
});

test('a flood stops at the queue limit and is counted rather than dropped', function () {
    for ($i = 0; $i < 80; $i++) {
        ($this->log)('error', 'Distinct failure ' . str_repeat('x', $i));
    }

    $this->assertCount(50, ErrorAlertListener::queued());

    ErrorAlertListener::deliver(5000);

    // Twenty an hour by default; the rest wait their turn.
    $this->assertCount(20, $this->alerts->alerts);
    $this->assertStringContainsString('+30 similar since the last alert', $this->alerts->alerts[0]->body);
});

test('a long message is cut to fit and keeps its location', function () {
    ErrorAlertListener::onLog('error', str_repeat('a', 2000), ['_channel' => 'unity', 'file' => '/x/wp-content/plugins/unity/a.php', 'line' => 3]);
    ErrorAlertListener::deliver(5000);

    $body = $this->alerts->alerts[0]->body;
    $this->assertLessThanOrEqual(1000, strlen($body));
    $this->assertStringEndsWith("…\nplugins/unity/a.php:3", $body);
});

// --- the throttle -----------------------------------------------------
test('the throttle admits, holds back, then reports what it held', function () {
    $throttle = new ErrorAlertThrottle($this->settings);
    $timeout = 15 * 60;

    $this->assertSame(0, $throttle->admit('fp', 1000, $timeout));
    $this->assertNull($throttle->admit('fp', 1001, $timeout));
    $this->assertNull($throttle->admit('fp', 1002, $timeout, repeats: 3));
    $this->assertSame(5, $throttle->admit('fp', 1000 + $timeout, $timeout));
    $this->assertSame(0, $throttle->admit('other', 1000 + $timeout, $timeout));
});

test('a zero timeout sends every occurrence', function () {
    $throttle = new ErrorAlertThrottle($this->settings);

    $this->assertSame(0, $throttle->admit('fp', 1000, 0));
    $this->assertSame(0, $throttle->admit('fp', 1000, 0));
});

test('the hourly cap holds errors back until the next hour', function () {
    $this->settings->save(['enabled' => '1', 'hourly_cap' => '2']);
    $throttle = new ErrorAlertThrottle($this->settings);
    $hour = 3600 * 100;

    $this->assertSame(0, $throttle->admit('a', $hour, 900));
    $this->assertSame(0, $throttle->admit('b', $hour + 1, 900));
    $this->assertNull($throttle->admit('c', $hour + 2, 900));

    // The transient store keeps no clock, so the next hour is a new key.
    $this->assertSame(1, $throttle->admit('c', $hour + 3600, 900));
});

// --- the logged error -------------------------------------------------
test('the same failure with different numbers is one error', function () {
    $a = new LoggedError('error', 'unity', 'Member 4411 failed sync after 3 tries (request 9f8e7d6c5b)');
    $b = new LoggedError('error', 'unity', 'Member 12 failed sync after 15 tries (request 0a1b2c3d4e)');

    $this->assertSame($a->fingerprint(), $b->fingerprint());
});

test('a different channel, level or wording is a different error', function () {
    $base = new LoggedError('error', 'unity', 'Sync failed');

    $this->assertNotSame($base->fingerprint(), (new LoggedError('error', 'reach', 'Sync failed'))->fingerprint());
    $this->assertNotSame($base->fingerprint(), (new LoggedError('critical', 'unity', 'Sync failed'))->fingerprint());
    $this->assertNotSame($base->fingerprint(), (new LoggedError('error', 'unity', 'Sync refused'))->fingerprint());
});

test('a log entry without a channel is the default channel', function () {
    $error = LoggedError::fromLog('ERROR', 'x', []);

    $this->assertSame('default', $error->channel);
    $this->assertSame('error', $error->level);
    $this->assertSame('', $error->location());
});

// --- settings ---------------------------------------------------------
test('the defaults are on, alert from error, with wp and monitor off', function () {
    $this->assertTrue($this->settings->isEnabled());
    $this->assertSame(20, $this->settings->hourlyCap());
    $this->assertSame([
        ['channel' => '*', 'level' => 'emergency', 'alert' => 'red', 'timeout_minutes' => 15],
        ['channel' => '*', 'level' => 'alert', 'alert' => 'red', 'timeout_minutes' => 15],
        ['channel' => '*', 'level' => 'critical', 'alert' => 'yellow', 'timeout_minutes' => 15],
        ['channel' => '*', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => 15],
        ['channel' => 'wp', 'level' => 'debug', 'alert' => 'off', 'timeout_minutes' => 0],
        ['channel' => 'monitor', 'level' => 'debug', 'alert' => 'off', 'timeout_minutes' => 0],
    ], array_map(fn (ErrorAlertRule $r): array => $r->toArray(), $this->settings->rules()));
    $this->assertSame('error', $this->settings->lowestAlertingLevel());
});

test('saving cleans what the form sent', function () {
    $this->settings->save([
        'enabled'    => '1',
        'hourly_cap' => '0',
        'rules'      => [
            ['channel' => ' Unity ', 'level' => 'WARNING', 'alert' => 'Red', 'timeout_minutes' => '99999'],
            ['channel' => '', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => 'soon'],
            ['channel' => 'unity', 'level' => 'warning', 'alert' => 'yellow', 'timeout_minutes' => '5'],
            ['channel' => 'reach', 'level' => 'error', 'alert' => 'purple', 'timeout_minutes' => '5'],
            ['channel' => 'reach', 'level' => 'loud', 'alert' => 'red', 'timeout_minutes' => '5'],
            ['channel' => 'amber', 'level' => '', 'alert' => 'blue', 'timeout_minutes' => '15'],
            ['channel' => 'php', 'level' => 'error', 'alert' => 'red', 'timeout_minutes' => '1', 'remove' => '1'],
            'not a row',
        ],
    ]);

    // Cleaned and clamped; the duplicate, the unknown alert and level, the
    // blank row and the removed one are gone.
    $this->assertSame([
        ['channel' => 'unity', 'level' => 'warning', 'alert' => 'red', 'timeout_minutes' => 1440],
        ['channel' => '*', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => 15],
    ], array_map(fn (ErrorAlertRule $r): array => $r->toArray(), $this->settings->rules()));
    $this->assertSame(1, $this->settings->hourlyCap());
});

test('a malformed stored row falls back to the defaults', function () {
    update_option(ErrorAlertSettings::OPTION, 'not an array');

    $this->assertTrue($this->settings->isEnabled());
    $this->assertSame('error', $this->settings->lowestAlertingLevel());
    $this->assertSame(20, $this->settings->hourlyCap());

    update_option(ErrorAlertSettings::OPTION, ['enabled' => true, 'rules' => 'nonsense']);

    $this->assertCount(6, $this->settings->rules());
});

test('a logger set above the threshold is reported', function () {
    $this->assertFalse($this->settings->loggerDropsThreshold());

    \Brain\Monkey\Filters\expectApplied('sentinel_logger_level')->andReturn('critical');

    $this->assertTrue($this->settings->loggerDropsThreshold());
});

test('a fatal is sent at once, without waiting for a shutdown that will not come', function () {
    ($this->log)('error', 'Sync failed');

    ErrorAlertListener::onFatal('emergency', 'Fatal: Allowed memory size exhausted', ['_channel' => 'php']);

    // Both the queued error and the fatal went before wp_die() could exit.
    $this->assertCount(2, $this->alerts->alerts);
    $this->assertSame([], ErrorAlertListener::queued());
    $this->assertSame(Alert::LEVEL_RED, $this->alerts->alerts[1]->level);
});

test('shortening a timeout takes effect for an error already held back', function () {
    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(1000);
    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(1060);

    $this->assertCount(1, $this->alerts->alerts);

    $this->settings->save(['enabled' => '1', 'rules' => [
        ['channel' => '*', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => '1'],
    ]]);
    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(1061);

    $this->assertCount(2, $this->alerts->alerts);
    $this->assertStringContainsString('+1 similar since the last alert', $this->alerts->alerts[1]->body);
});

test('delivery hangs on the shutdown action, late, and runs from it', function () {
    $this->captureActions(['shutdown']);

    ErrorAlertListener::register();
    ($this->log)('error', 'Sync failed');

    $callbacks = $this->actionCallbacks('shutdown');
    $this->assertCount(1, $callbacks);

    $callbacks[0]();

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame([], ErrorAlertListener::queued());
});

// --- rules ------------------------------------------------------------
function saveRules(ErrorAlertSettings $settings, array $rules): void
{
    $settings->save(['enabled' => '1', 'rules' => $rules]);
}

test('a plugin rule overrides the general rule for the levels it covers, and only those', function () {
    saveRules($this->settings, [
        ['channel' => '*', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => '15'],
        ['channel' => 'unity', 'level' => 'critical', 'alert' => 'red', 'timeout_minutes' => '5'],
    ]);

    $this->assertSame('red', $this->settings->ruleFor('unity', 'critical')?->alert);
    $this->assertSame('red', $this->settings->ruleFor('unity', 'emergency')?->alert);
    $this->assertSame('blue', $this->settings->ruleFor('unity', 'error')?->alert);
    $this->assertSame('blue', $this->settings->ruleFor('reach', 'critical')?->alert);
    $this->assertNull($this->settings->ruleFor('unity', 'warning'));
});

test('of the rules an entry meets, the most severe wins', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'warning', 'alert' => 'blue', 'timeout_minutes' => '60'],
        ['channel' => 'unity', 'level' => 'error', 'alert' => 'yellow', 'timeout_minutes' => '15'],
        ['channel' => 'unity', 'level' => 'alert', 'alert' => 'red', 'timeout_minutes' => '1'],
    ]);

    $this->assertSame(['blue', 60], [$this->settings->ruleFor('unity', 'warning')?->alert, $this->settings->ruleFor('unity', 'warning')?->timeoutMinutes]);
    $this->assertSame('yellow', $this->settings->ruleFor('unity', 'critical')?->alert);
    $this->assertSame('red', $this->settings->ruleFor('unity', 'emergency')?->alert);
    $this->assertNull($this->settings->ruleFor('unity', 'notice'));
});

test('a rule set to off silences what it covers', function () {
    saveRules($this->settings, [
        ['channel' => '*', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => '15'],
        ['channel' => 'concordance', 'level' => 'error', 'alert' => 'off', 'timeout_minutes' => '0'],
        ['channel' => 'concordance', 'level' => 'emergency', 'alert' => 'red', 'timeout_minutes' => '0'],
    ]);

    ErrorAlertListener::onLog('error', 'Lookup failed', ['_channel' => 'concordance']);
    ErrorAlertListener::onLog('critical', 'Index broken', ['_channel' => 'concordance']);
    ErrorAlertListener::onLog('emergency', 'Gone', ['_channel' => 'concordance']);
    ErrorAlertListener::deliver(5000);

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame('[concordance] EMERGENCY', $this->alerts->alerts[0]->title);
    $this->assertSame(Alert::LEVEL_RED, $this->alerts->alerts[0]->level);
});

test('the alert rings at the level its rule sets', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'error', 'alert' => 'red', 'timeout_minutes' => '15'],
    ]);

    ($this->log)('error', 'Sync failed');
    ErrorAlertListener::deliver(5000);

    $this->assertSame(Alert::LEVEL_RED, $this->alerts->alerts[0]->level);
});

test('a rule may alert below error', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'warning', 'alert' => 'blue', 'timeout_minutes' => '15'],
    ]);

    ($this->log)('warning', 'Slow sync');
    ($this->log)('notice', 'Cache miss');
    ErrorAlertListener::deliver(5000);

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame('[unity] WARNING', $this->alerts->alerts[0]->title);
});

test('each rule holds repeats back for its own timeout', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => '60'],
        ['channel' => 'reach', 'level' => 'error', 'alert' => 'blue', 'timeout_minutes' => '5'],
    ]);
    $logBoth = function (int $at): void {
        ErrorAlertListener::onLog('error', 'Sync failed', ['_channel' => 'unity']);
        ErrorAlertListener::onLog('error', 'Send failed', ['_channel' => 'reach']);
        ErrorAlertListener::deliver($at);
    };

    $logBoth(5000);
    $logBoth(5000 + 5 * 60);

    // Reach's five minutes are up; Unity's hour is not.
    $this->assertSame(
        ['[unity] ERROR', '[reach] ERROR', '[reach] ERROR'],
        array_map(fn (Alert $a): string => $a->title, $this->alerts->alerts),
    );

    $logBoth(5000 + 60 * 60);

    $this->assertCount(5, $this->alerts->alerts);
    $this->assertStringContainsString('+1 similar since the last alert', $this->alerts->alerts[3]->body);
});

test('with every rule off or removed nothing is queued', function () {
    saveRules($this->settings, [
        ['channel' => '*', 'level' => 'debug', 'alert' => 'off', 'timeout_minutes' => '0'],
    ]);
    ErrorAlertListener::reset();

    ($this->log)('emergency', 'Everything is on fire');
    $this->assertNull($this->settings->lowestAlertingLevel());
    $this->assertSame([], ErrorAlertListener::queued());

    saveRules($this->settings, [
        ['channel' => '*', 'level' => 'error', 'alert' => 'red', 'timeout_minutes' => '0', 'remove' => '1'],
    ]);
    ErrorAlertListener::reset();

    // Removing the last rule leaves none, not the defaults back.
    $this->assertSame([], $this->settings->rules());
    ($this->log)('emergency', 'Everything is on fire');
    $this->assertSame([], ErrorAlertListener::queued());
});

test('a save without rules keeps the rules there are', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'error', 'alert' => 'red', 'timeout_minutes' => '5'],
    ]);

    $this->settings->save(['enabled' => '1', 'hourly_cap' => '7']);

    $this->assertCount(1, $this->settings->rules());
    $this->assertSame(7, $this->settings->hourlyCap());
});

test('a row saved before the rules reads as the rules that behave the same', function () {
    update_option(ErrorAlertSettings::OPTION, [
        'enabled'           => true,
        'threshold'         => 'critical',
        'level_map'         => ['emergency' => 'red', 'alert' => 'yellow', 'critical' => 'blue', 'error' => 'red'],
        'excluded_channels' => ['tsml-for-unity'],
        'cooldown_minutes'  => 30,
        'hourly_cap'        => 9,
    ]);

    $this->assertSame([
        ['channel' => '*', 'level' => 'emergency', 'alert' => 'red', 'timeout_minutes' => 30],
        ['channel' => '*', 'level' => 'alert', 'alert' => 'yellow', 'timeout_minutes' => 30],
        ['channel' => '*', 'level' => 'critical', 'alert' => 'blue', 'timeout_minutes' => 30],
        ['channel' => 'tsml-for-unity', 'level' => 'debug', 'alert' => 'off', 'timeout_minutes' => 0],
    ], array_map(fn (ErrorAlertRule $r): array => $r->toArray(), $this->settings->rules()));
    $this->assertSame(9, $this->settings->hourlyCap());

    // And they behave as the old settings did.
    $this->assertNull($this->settings->ruleFor('unity', 'error'));
    $this->assertSame('yellow', $this->settings->ruleFor('unity', 'alert')?->alert);
    $this->assertNull($this->settings->ruleFor('tsml-for-unity', 'emergency'));
});

test('a channel is matched however the logger spelled it', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'error', 'alert' => 'red', 'timeout_minutes' => '5'],
    ]);

    $this->assertSame('red', $this->settings->ruleFor('Unity', 'ERROR')?->alert);
    $this->assertNull($this->settings->ruleFor('unity', 'shouting'));
});

test('a logger set above the lowest alerting rule is reported', function () {
    saveRules($this->settings, [
        ['channel' => 'unity', 'level' => 'warning', 'alert' => 'blue', 'timeout_minutes' => '5'],
    ]);

    \Brain\Monkey\Filters\expectApplied('sentinel_logger_level')->andReturn('error');

    $this->assertTrue($this->settings->loggerDropsThreshold());
});

test('the sender rings an unknown level blue rather than not at all', function () {
    $this->sender->send(new LoggedError('error', 'unity', 'Sync failed'), 0, 5000, 'purple');

    $this->assertSame(Alert::LEVEL_BLUE, $this->alerts->alerts[0]->level);
});
