<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use BleedingDeacons\WpMocks\WpState;
use Guardian\ProviderRegistry;
use Guardian\VerifiedIdentity;
use Sentinel\Monitor\Auth\DeviceCodeStore;
use Sentinel\Monitor\Auth\DeviceRedirectValidator;
use Sentinel\Monitor\Auth\StateStore;
use Sentinel\Monitor\Core\RateLimiter;
use Sentinel\Monitor\Devices\ResponderGate;
use Sentinel\Monitor\Rest\OAuthCallbackController;
use Sentinel\Tests\Monitor\Fixtures\ConfigurableProvider;
use Sentinel\Tests\Monitor\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The device half of Reach's OAuth callback, now Monitor's own.
 *
 * What these pin is that sign-in did not change in the move: the same
 * refusals, in the same order, each routed back to the app as
 * `?error=<slug>`, and a success that ends in a one-time exchange code
 * rather than a session. The two gates are both exercised — Reach's
 * website rule first, then Monitor's own — because each lets through
 * someone the other refuses.
 */

beforeEach(function () {
    WpState::$transients = [];
    WpState::$options = [];
    WpState::$restRoutes = [];
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

    $this->codes = new DeviceCodeStore();
    $this->stateStore = new StateStore();

    $this->identity = fn (string $email): VerifiedIdentity => new VerifiedIdentity(
        email: $email,
        provider: 'google',
        sub: 'oauth-sub-42',
        providerEmail: $email,
    );

    /** @param array<int, MemberStub> $members */
    $this->controller = function (array $members, ?VerifiedIdentity $identity, bool $serverSide = true): OAuthCallbackController {
        $repository = new InMemoryMemberRepository($members);
        $registry = new ProviderRegistry();
        $registry->register(new ConfigurableProvider('google', $serverSide, $identity));

        return new OAuthCallbackController(
            $registry,
            $this->stateStore,
            $repository,
            $this->codes,
            new DeviceRedirectValidator(),
            new ResponderGate($repository),
            new RateLimiter(),
        );
    };

    /** A state as /auth/device/start issues it, carrying the app redirect. */
    $this->deviceState = fn (?string $redirect = 'monitor://auth'): string
        => $this->stateStore->issue('google', '', 'verifier', $redirect)['state'];

    $this->callback = fn (OAuthCallbackController $controller, string $state): WP_REST_Response|WP_Error
        => $controller->callback(new WP_REST_Request(['state' => $state, 'code' => 'auth-code']));

    $this->location = function (WP_REST_Response|WP_Error $result): string {
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());

        return (string) ($result->get_headers()['Location'] ?? '');
    };

    $this->certified = fn (string $email, int $homeGroup = 88): MemberStub => new MemberStub(
        personalEmail: $email,
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
        homeGroup: $homeGroup,
    );
});

test('the callback is registered under sentinel v1', function () {
    ($this->controller)([], null)->registerRoutes();

    $routes = array_map(fn (array $r): string => $r['namespace'] . $r['route'], WpState::$restRoutes);

    $this->assertContains('sentinel/v1/oauth/callback', $routes);
});

test('the callback url is the one to register with each provider', function () {
    $this->assertStringEndsWith('sentinel/v1/oauth/callback', OAuthCallbackController::callbackUrl());
});

test('an unknown state is a plain error, there being nowhere to send the app', function () {
    $result = ($this->callback)(($this->controller)([], null), 'never-issued');

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_invalid_state', $result->get_error_code());
    $this->assertSame(400, $result->get_error_data()['status']);
});

test('a state with no app redirect is refused rather than becoming a web sign in', function () {
    // Reach's callback turned such a state into a website session. Monitor
    // has no website to sign anyone in to.
    $controller = ($this->controller)([($this->certified)('r@example.com')], ($this->identity)('r@example.com'));

    $result = ($this->callback)($controller, ($this->deviceState)(null));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_invalid_state', $result->get_error_code());
});

test('a certified responder gets a one time code back in the app', function () {
    $controller = ($this->controller)([($this->certified)('r@example.com')], ($this->identity)('r@example.com'));

    $location = ($this->location)(($this->callback)($controller, ($this->deviceState)()));

    $this->assertStringStartsWith('monitor://auth?code=', $location);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $identity = $this->codes->consume((string) $query['code']);
    $this->assertNotNull($identity);
    $this->assertSame('r@example.com', $identity->email);
});

test('the state is single use', function () {
    $controller = ($this->controller)([($this->certified)('r@example.com')], ($this->identity)('r@example.com'));
    $state = ($this->deviceState)();

    ($this->callback)($controller, $state);
    $replayed = ($this->callback)($controller, $state);

    $this->assertInstanceOf(WP_Error::class, $replayed);
    $this->assertSame('sentinel_invalid_state', $replayed->get_error_code());
});

test('a provider that proves nothing sends the app signin failed', function () {
    $controller = ($this->controller)([($this->certified)('r@example.com')], null);

    $this->assertSame(
        'monitor://auth?error=signin_failed',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('a client side provider in the state sends the app signin failed', function () {
    $controller = ($this->controller)(
        [($this->certified)('r@example.com')],
        ($this->identity)('r@example.com'),
        serverSide: false,
    );

    $this->assertSame(
        'monitor://auth?error=signin_failed',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('an anonymised relay address is refused before either gate runs', function () {
    // A member exists on the relay address and would pass both gates, so
    // the refusal can only be the anonymisation check.
    $relay = 'abc123hash@privaterelay.facebook.com';
    $controller = ($this->controller)([($this->certified)($relay)], ($this->identity)($relay));

    $this->assertSame(
        'monitor://auth?error=email_required',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('an address matching no member is not eligible', function () {
    $controller = ($this->controller)([], ($this->identity)('nobody@example.com'));

    $this->assertSame(
        'monitor://auth?error=not_eligible',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('reach\'s website rule still applies: an uncertified responder is refused', function () {
    // Passes Monitor's own gate (a real address and a home group), so this
    // refusal is OutreachEligibility's — the check Reach's callback ran
    // first and that moving the callback must not have dropped.
    $trainee = new MemberStub(
        personalEmail: 'trainee@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::InTraining,
    );
    $controller = ($this->controller)([$trainee], ($this->identity)('trainee@example.com'));

    $this->assertTrue((new ResponderGate(new InMemoryMemberRepository([$trainee])))->isAuthorised($trainee));
    $this->assertSame(
        'monitor://auth?error=not_eligible',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('monitor\'s own gate still applies: a member with no home group is refused', function () {
    // Passes the website rule (a 12th-stepper), so this refusal is
    // ResponderGate's.
    $stub = new MemberStub(personalEmail: 'stub@example.com', twelfthStepper: true, homeGroup: 0);
    $controller = ($this->controller)([$stub], ($this->identity)('stub@example.com'));

    $this->assertSame(
        'monitor://auth?error=not_eligible',
        ($this->location)(($this->callback)($controller, ($this->deviceState)())),
    );
});

test('a loopback redirect is honoured as it was at start', function () {
    $controller = ($this->controller)([($this->certified)('r@example.com')], ($this->identity)('r@example.com'));

    $location = ($this->location)(($this->callback)($controller, ($this->deviceState)('http://127.0.0.1:50123/cb')));

    $this->assertStringStartsWith('http://127.0.0.1:50123/cb?code=', $location);
});

test('the callback is rate limited per ip', function () {
    $limiter = new RateLimiter();
    for ($i = 0; $i < 120; $i++) {
        $limiter->overLimit('oauth:198.51.100.7', 120, 15 * 60);
    }
    $controller = ($this->controller)([($this->certified)('r@example.com')], ($this->identity)('r@example.com'));

    $result = ($this->callback)($controller, ($this->deviceState)());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_rate_limited', $result->get_error_code());
    $this->assertSame(429, $result->get_error_data()['status']);
});
