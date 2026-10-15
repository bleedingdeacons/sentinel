<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\VerifiedIdentity;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Auth\PasswordCredential;

/**
 * Email + password sign-in for a Monitor handset.
 *
 * The login half of Reach's PasswordAuthenticator, unchanged. Setting and
 * resetting a password stays with Reach and the website: a member has one
 * password, held in Unity's credential store, and whichever plugin set it,
 * this checks the same row.
 *
 * It proves only "this caller knows the password for this email" — exactly
 * as an OAuth provider proves "this caller controls this email" — and
 * returns a {@see VerifiedIdentity}. Whether that identity may use Monitor
 * is decided by the controller, through
 * {@see \Sentinel\Monitor\Devices\ResponderGate}.
 *
 * Security posture:
 *   - Hashes are verified with {@see password_verify()} and rehashed
 *     transparently on login when the algorithm or parameters move on
 *     (Argon2id with OWASP baseline parameters, where it is compiled in).
 *   - A wrong password and an email with no password behave identically,
 *     including running a throwaway verify so the two cannot be told apart
 *     by response timing (no account enumeration).
 *   - Per-account lockout after {@see MAX_FAILED_ATTEMPTS} failures. The
 *     counter is Unity's, so failures here and on the website share it.
 */
final class PasswordAuthenticator
{
    /** Identity provider label for password sign-ins. */
    public const PROVIDER = 'password';

    /** Consecutive failures before the account is locked. */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** How long a locked account stays locked. */
    public const LOCKOUT_SECONDS = 15 * 60;

    public function __construct(
        private readonly PasswordCredentialRepository $credentials,
    ) {
    }

    /**
     * Verify an email + password. Returns a proven identity on success, or
     * null for any failure (unknown email, no password set, wrong password,
     * or a locked account) — the caller must not distinguish these.
     */
    public function attemptLogin(string $email, string $password, int $now): ?VerifiedIdentity
    {
        $email = $this->normaliseEmail($email);
        if ($email === '') {
            $this->burnTime($password);
            return null;
        }

        $cred = $this->credentials->find($email);
        if ($cred === null || !$cred->hasPassword()) {
            // Equalise timing with the real verify path below.
            $this->burnTime($password);
            return null;
        }

        if ($cred->isLocked($now)) {
            // Burn the same time as a real verify so a locked account can't
            // be told apart from a wrong password by response timing.
            $this->burnTime($password);
            return null;
        }

        if (!password_verify($password, $cred->passwordHash)) {
            $this->registerFailure($cred, $now);
            return null;
        }

        // Correct password. Rehash if the algorithm/parameters have moved on;
        // upsert also clears the failed-attempt counters, otherwise just clear
        // the counters.
        if (password_needs_rehash($cred->passwordHash, self::algorithm(), self::hashOptions())) {
            $this->credentials->upsertPasswordHash($email, $this->hashPassword($password), $now);
        } else {
            $this->credentials->resetFailedAttempts($email, $now);
        }

        return new VerifiedIdentity(
            email: $email,
            provider: self::PROVIDER,
            sub: $email,
        );
    }

    private function registerFailure(PasswordCredential $cred, int $now): void
    {
        $attempts    = $cred->failedAttempts + 1;
        $lockedUntil = 0;

        // On hitting the threshold, lock and reset the counter so the next
        // window starts fresh after the lockout expires.
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = $now + self::LOCKOUT_SECONDS;
            $attempts    = 0;
        }

        $this->credentials->recordFailedAttempt($cred->email, $attempts, $lockedUntil, $now);
    }

    private function normaliseEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return is_email($email) ? $email : '';
    }

    /**
     * Run a throwaway verify so the no-credential path costs the same as a
     * real one. The dummy hash uses the same algorithm as real credentials
     * so the timing genuinely matches. Computed once per request.
     */
    private function burnTime(string $password): void
    {
        static $dummy = null;
        if ($dummy === null) {
            $dummy = $this->hashPassword('monitor-timing-equaliser');
        }
        password_verify($password, $dummy);
    }

    /** Hash a password with the configured algorithm + parameters. */
    private function hashPassword(string $password): string
    {
        return password_hash($password, self::algorithm(), self::hashOptions());
    }

    /**
     * Argon2id where the platform provides it, otherwise the platform
     * default (bcrypt). Must match Reach's choice, or each plugin would
     * rehash the other's hashes on every alternate sign-in.
     */
    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * OWASP baseline Argon2id parameters (19 MiB memory, 2 iterations, 1
     * lane). Empty for the bcrypt fallback, which takes no such options.
     *
     * @return array<string, int>
     */
    private static function hashOptions(): array
    {
        if (self::algorithm() === PASSWORD_DEFAULT) {
            return [];
        }
        return [
            'memory_cost' => 19456,
            'time_cost'   => 2,
            'threads'     => 1,
        ];
    }
}
