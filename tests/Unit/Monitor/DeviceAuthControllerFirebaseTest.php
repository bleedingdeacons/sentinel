<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use BleedingDeacons\WpMocks\WpState;
use Guardian\Firebase\FirebaseIdTokenVerifier;
use Guardian\Jwt\JwtVerifier;
use Guardian\ProviderRegistry;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Sentinel\Monitor\Auth\DeviceCodeStore;
use Sentinel\Monitor\Auth\DeviceRedirectValidator;
use Sentinel\Monitor\Auth\DeviceTokenMinter;
use Sentinel\Monitor\Auth\FirebaseSignIn;
use Sentinel\Monitor\Auth\PasswordAuthenticator;
use Sentinel\Monitor\Auth\StateStore;
use Sentinel\Monitor\Core\RateLimiter;
use Sentinel\Monitor\Devices\CurrentDevice;
use Sentinel\Monitor\Devices\ResponderGate;
use Sentinel\Monitor\Rest\DeviceAuthController;
use Sentinel\Tests\Monitor\Fixtures\InMemoryDeviceRepository;
use Sentinel\Tests\Monitor\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/auth/device/firebase`: enrolling a handset from native Google sign-in.
 *
 * Guardian owns what makes a Firebase token good, and tests it. What is
 * Sentinel's here is the wiring: that the project the token is pinned to is
 * the one Sentinel was configured with, that the gate still runs, and that
 * the three refusals stay distinguishable — Monitor falls back to the
 * browser on one of them and must not on the others.
 *
 * A real verifier and real RS256 tokens, with only the key-set fetch
 * stubbed, because a stub verifier would test nothing that matters.
 */

const FIREBASE_PROJECT = 'sentinel-67404';
const FIREBASE_KID = 'firebase-key-1';

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        $this->markTestSkipped('OpenSSL could not generate a keypair. Set OPENSSL_CONF.');
    }
    $this->key = $key;

    $details = openssl_pkey_get_details($key);
    $jwks = (string) json_encode(['keys' => [[
        'kty' => 'RSA',
        'kid' => FIREBASE_KID,
        'alg' => 'RS256',
        'n'   => firebaseBase64Url($details['rsa']['n']),
        'e'   => firebaseBase64Url($details['rsa']['e']),
    ]]]);
    $this->fetched = [];
    $this->stubHttp(function (string $url) use ($jwks): array {
        $this->fetched[] = $url;
        return ['response' => ['code' => 200], 'body' => $jwks];
    });

    WpState::$transients = [];
    WpState::$options = [];
    WpState::$restRoutes = [];

    $this->devices = new InMemoryDeviceRepository();
    $this->minter = new DeviceTokenMinter();
    $this->audit = new SpyAuditLogger();
    $this->project = FIREBASE_PROJECT;

    $this->controller = function (?FirebaseSignIn $firebase, MemberStub ...$members): DeviceAuthController {
        $gate = new ResponderGate(new InMemoryMemberRepository($members));

        return new DeviceAuthController(
            $this->devices,
            $this->minter,
            new DeviceCodeStore(),
            new DeviceRedirectValidator(),
            $gate,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new PasswordAuthenticator(new InMemoryPasswordCredentialRepository()),
            new ProviderRegistry(),
            new StateStore(),
            new RateLimiter(),
            $this->audit,
            $firebase,
        );
    };

    $this->signIn = fn(): FirebaseSignIn => new FirebaseSignIn(
        new FirebaseIdTokenVerifier(new JwtVerifier('Sentinel/test'), 'monitor'),
        fn(): string => $this->project,
    );

    $this->certified = fn(string $email): MemberStub => new MemberStub(
        personalEmail: $email,
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    );

    /** @param array<string, mixed> $claims */
    $this->token = function (array $claims = []): string {
        $claims = array_merge([
            'iss'            => 'https://securetoken.google.com/' . FIREBASE_PROJECT,
            'aud'            => FIREBASE_PROJECT,
            'sub'            => 'firebase-uid-1',
            'email'          => 'responder@example.com',
            'email_verified' => true,
            'auth_time'      => time() - 5,
            'iat'            => time(),
            'exp'            => time() + 3600,
            'firebase'       => ['sign_in_provider' => 'google.com', 'identities' => ['google.com' => ['1102']]],
        ], $claims);

        $head = firebaseBase64Url((string) json_encode(['alg' => 'RS256', 'kid' => FIREBASE_KID, 'typ' => 'JWT']));
        $body = firebaseBase64Url((string) json_encode($claims));
        $signature = '';
        openssl_sign($head . '.' . $body, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $head . '.' . $body . '.' . firebaseBase64Url($signature);
    };

    $this->request = fn(string $idToken): WP_REST_Request => new WP_REST_Request([
        'id_token'      => $idToken,
        'platform'      => 'android',
        'label'         => 'Pixel',
        'push_provider' => 'fcm',
        'push_token'    => 'fcm-token',
    ]);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
});

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
});

test('the firebase route is declared with the shared device arguments', function () {
    ($this->controller)(null)->registerRoutes();

    $args = null;
    foreach (WpState::$restRoutes as $route) {
        if ($route['route'] === '/auth/device/firebase') {
            $args = $route['args'];
        }
    }

    $this->assertNotNull($args, 'route not registered');
    $this->assertSame('__return_true', $args['permission_callback']);
    $this->assertTrue($args['args']['id_token']['required']);
    // A JWT is verified byte for byte; sanitising it could only break it.
    $this->assertArrayNotHasKey('sanitize_callback', $args['args']['id_token']);
    $this->assertTrue($args['args']['platform']['required']);
    $this->assertSame('', $args['args']['push_token']['default']);
});

test('a certified responder is enrolled from a native Google sign-in', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));

    $result = $controller->firebase(($this->request)(($this->token)()));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(201, $result->get_status());
    $this->assertNotSame('', $result->get_data()['token']);
    $this->assertNotSame('', $result->get_data()['payload_key']);
    $this->assertSame('fcm', $result->get_data()['push_provider']);
    $this->assertCount(1, $this->devices->devices);
    $this->assertSame([FirebaseIdTokenVerifier::JWKS_URL], $this->fetched);
});

test('native enrolment is audited as such', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));

    $controller->firebase(($this->request)(($this->token)()));

    $this->assertCount(1, $this->audit->entries);
    $this->assertStringContainsString('Monitor device enrolled via google (native)', $this->audit->entries[0]['detail']);
});

test('the email is matched case insensitively', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));

    $result = $controller->firebase(($this->request)(($this->token)(['email' => 'Responder@Example.COM'])));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
});

test('a token from another Firebase project is refused as a bad token', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));

    $result = $controller->firebase(($this->request)(($this->token)([
        'iss' => 'https://securetoken.google.com/someone-else',
        'aud' => 'someone-else',
    ])));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_invalid_id_token', $result->get_error_code());
    $this->assertSame(401, $result->get_error_data()['status']);
    $this->assertSame([], $this->devices->devices);
});

test('the same token cannot enrol two handsets', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));
    $token = ($this->token)();

    $controller->firebase(($this->request)($token));
    $result = $controller->firebase(($this->request)($token));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status']);
    $this->assertCount(1, $this->devices->devices);
});

test('a verified Google account that is not a certified responder is told so', function () {
    $controller = ($this->controller)(($this->signIn)());

    $result = $controller->firebase(($this->request)(($this->token)()));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_not_eligible', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status']);
    $this->assertSame([], $this->devices->devices);
});

test('with no push service account it reports itself unavailable', function () {
    // 503, not 401: the app reads this as "use the browser instead",
    // which is the right answer for an intergroup that has not set
    // Firebase up, and the wrong one for a bad token.
    $this->project = '';
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));

    $result = $controller->firebase(($this->request)(($this->token)()));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_firebase_unavailable', $result->get_error_code());
    $this->assertSame(503, $result->get_error_data()['status']);
    $this->assertSame([], $this->fetched);
});

test('a controller built without native sign-in reports itself unavailable', function () {
    $controller = ($this->controller)(null, ($this->certified)('responder@example.com'));

    $result = $controller->firebase(($this->request)(($this->token)()));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(503, $result->get_error_data()['status']);
});

test('native enrolment shares the per ip limit', function () {
    $controller = ($this->controller)(($this->signIn)(), ($this->certified)('responder@example.com'));
    $limiter = new RateLimiter();
    for ($i = 0; $i < 30; $i++) {
        $limiter->overLimit('device:198.51.100.7', 30, 15 * 60);
    }

    $result = $controller->firebase(($this->request)(($this->token)()));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_rate_limited', $result->get_error_code());
    $this->assertSame([], $this->devices->devices);
});

function firebaseBase64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}
