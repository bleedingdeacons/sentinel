<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\ProviderRegistry;
use Guardian\VerifiedIdentity;
use Sentinel\Monitor\Auth\AnonymisedEmailDetector;
use Sentinel\Monitor\Auth\DeviceCodeStore;
use Sentinel\Monitor\Auth\DeviceRedirectValidator;
use Sentinel\Monitor\Auth\OutreachEligibility;
use Sentinel\Monitor\Auth\StateStore;
use Sentinel\Monitor\Core\RateLimiter;
use Sentinel\Monitor\Devices\ResponderGate;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * REST controller: the OAuth provider's redirect target for a handset sign-in.
 *
 *   GET /sentinel/v1/oauth/callback?code=...&state=...
 *
 * This is the device half of Reach's OAuth callback, moved here when the
 * Monitor app's server side did. Reach's callback served the website and the
 * app alike, branching on whether the state carried an app redirect; this
 * one only ever finishes a flow {@see DeviceAuthController::start()} began,
 * so a state without one is refused rather than turned into a web session.
 *
 * The checks run in the order Reach ran them, which is what keeps sign-in
 * the same:
 *
 *   1. the state is consumed (single use, and it carries the provider, the
 *      nonce, the PKCE verifier and the validated app redirect);
 *   2. the provider proves the identity;
 *   3. an anonymised relay address is refused (`email_required`);
 *   4. Reach's website rule, {@see OutreachEligibility} (`not_eligible`);
 *   5. Monitor's own {@see ResponderGate} (`not_eligible`);
 *   6. a one-time exchange code goes back to the app, which trades it for a
 *      device token at `/auth/device/exchange`.
 *
 * Every refusal after the state is read goes back to the app as
 * `?error=<slug>`: a page rendered inside an in-app browser tab is a dead end
 * the app never hears about.
 *
 * This URL is the one registered with Google, Microsoft and Facebook. It is
 * a second redirect URI on the same client registrations Reach uses, and the
 * settings page shows it.
 */
final class OAuthCallbackController
{
    public const NAMESPACE = 'sentinel/v1';

    /**
     * Per-IP cap on the callback, as Reach's whole OAuth surface had. See
     * Reach's OAuthController for why it is generous: REMOTE_ADDR behind a
     * CDN is the edge, so a whole intergroup can share one bucket.
     */
    private const OAUTH_IP_MAX = 120;
    private const OAUTH_IP_WINDOW = 15 * 60;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly StateStore $stateStore,
        private readonly MemberRepository $members,
        private readonly DeviceCodeStore $deviceCodes,
        private readonly DeviceRedirectValidator $deviceRedirects,
        private readonly ResponderGate $responderGate,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public static function callbackUrl(): string
    {
        return rest_url(self::NAMESPACE . '/oauth/callback');
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/oauth/callback',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'callback'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'code'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'state' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );
    }

    public function callback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($this->overOAuthLimit()) {
            return new WP_Error(
                'sentinel_rate_limited',
                'Too many sign-in attempts. Please wait a little while and try again.',
                ['status' => 429],
            );
        }

        $stored = $this->stateStore->consume((string) $request->get_param('state'));
        if ($stored === null || $stored['device_redirect'] === null) {
            // Stale, replayed, or never one of ours. With no app redirect
            // there is nowhere to send a friendly refusal, so it is a plain
            // error — the same as Reach gave for a tampered state.
            return new WP_Error(
                'sentinel_invalid_state',
                'That sign-in attempt has expired. Please start again from the app.',
                ['status' => 400],
            );
        }

        // Validated against the allow-list at /auth/device/start and out of
        // the request's reach ever since, so it can be trusted here.
        $deviceRedirect = $stored['device_redirect'];

        $provider = $this->providers->get($stored['provider']);
        if ($provider === null || !$provider->isServerSide()) {
            return $this->deny($deviceRedirect, 'signin_failed');
        }

        $identity = $provider->handleCallback(
            (string) $request->get_param('code'),
            $stored['nonce'],
            self::callbackUrl(),
            $stored['code_verifier'],
        );
        if ($identity === null) {
            return $this->deny($deviceRedirect, 'signin_failed');
        }

        if (AnonymisedEmailDetector::isAnonymised($identity->email)) {
            return $this->deny($deviceRedirect, 'email_required');
        }

        if (!OutreachEligibility::permits($this->members->findByEmail($identity->email))) {
            return $this->deny($deviceRedirect, 'not_eligible');
        }

        return $this->completeDeviceSignIn($identity, $deviceRedirect);
    }

    /**
     * Mint the single-use exchange code and bounce it to the app. The code,
     * not the token, travels back through the browser; see
     * {@see DeviceCodeStore} for why.
     */
    private function completeDeviceSignIn(VerifiedIdentity $identity, string $deviceRedirect): WP_REST_Response
    {
        if ($this->responderGate->authorisedMember($identity->email) === null) {
            return $this->deny($deviceRedirect, 'not_eligible');
        }

        $code = $this->deviceCodes->issue($identity);

        return $this->redirect(
            $this->deviceRedirects->withParams($deviceRedirect, ['code' => $code]),
        );
    }

    private function deny(string $deviceRedirect, string $slug): WP_REST_Response
    {
        return $this->redirect(
            $this->deviceRedirects->withParams($deviceRedirect, ['error' => $slug]),
        );
    }

    private function overOAuthLimit(): bool
    {
        return $this->rateLimiter->overLimit(
            'oauth:' . $this->rateLimiter->clientIp(),
            self::OAUTH_IP_MAX,
            self::OAUTH_IP_WINDOW,
        );
    }

    private function redirect(string $url): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        return $response;
    }
}
