<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Closure;
use Guardian\Firebase\FirebaseIdTokenVerifier;
use Guardian\VerifiedIdentity;

/**
 * Native Google sign-in, as Monitor does it on Android and iOS.
 *
 * The handset shows the platform's own account picker, hands the Google
 * credential to Firebase Auth, and posts the Firebase ID token to
 * `/auth/device/firebase`. Guardian does the verifying; what this adds is
 * which Firebase project counts as ours.
 *
 * <b>The project comes from the FCM service account.</b> Sentinel already
 * holds one to push alerts, and it names the project Monitor's
 * `google-services.json` and `GoogleService-Info.plist` belong to — which
 * is exactly the project a genuine Monitor's Firebase tokens are minted
 * for. A second setting would be a second place to get it wrong. The
 * consequence is that native sign-in is off until push is configured,
 * which is the order an intergroup sets Sentinel up in anyway.
 */
final class FirebaseSignIn
{
    /** @var Closure(): string */
    private readonly Closure $projectId;

    /**
     * @param callable(): string $projectId The Firebase project id, or '' when
     *                                      none is configured. Read per call,
     *                                      so a replaced service account takes
     *                                      effect without a restart.
     */
    public function __construct(
        private readonly FirebaseIdTokenVerifier $verifier,
        callable $projectId,
    ) {
        $this->projectId = Closure::fromCallable($projectId);
    }

    public function isConfigured(): bool
    {
        return ($this->projectId)() !== '';
    }

    public function verify(string $idToken): ?VerifiedIdentity
    {
        $projectId = ($this->projectId)();
        if ($projectId === '' || $idToken === '') {
            return null;
        }

        return $this->verifier->verify($idToken, $projectId, FirebaseIdTokenVerifier::GOOGLE);
    }
}
