<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use Guardian\VerifiedIdentity;
use Sentinel\Monitor\Auth\PasswordAuthenticator;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;

/**
 * Unit tests for {@see PasswordAuthenticator} — the email + password
 * sign-in path a handset enrols through.
 *
 * Setting and resetting a password stays with Reach, so only the login
 * half of Reach's tests came across. Credentials live in Unity's in-memory
 * fake, which mirrors the wpdb repository's UPDATE-only semantics for
 * failed-attempt recording (unknown emails never get a row).
 */

beforeEach(function () {
    $this->makeAuth = fn (InMemoryPasswordCredentialRepository $repo): PasswordAuthenticator
        => new PasswordAuthenticator($repo);
});

// --- login ------------------------------------------------------------
test('login returns password identity for correct password', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo);

    $identity = $auth->attemptLogin('user@example.com', 'correcthorse10', 1000);

    $this->assertInstanceOf(VerifiedIdentity::class, $identity);
    $this->assertSame('password', $identity->provider);
    $this->assertSame('user@example.com', $identity->email);
});

test('login normalises email case and whitespace', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo);

    $identity = $auth->attemptLogin('  User@Example.COM ', 'correcthorse10', 1000);

    $this->assertNotNull($identity);
    $this->assertSame('user@example.com', $identity->email);
});

test('login fails and counts failure for wrong password', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo);

    $this->assertNull($auth->attemptLogin('user@example.com', 'wrong-password', 1000));
    $this->assertSame(1, $repo->find('user@example.com')->failedAttempts);
});

test('login fails for unknown email without creating a row', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo);

    $this->assertNull($auth->attemptLogin('nobody@example.com', 'whatever-pw', 1000));
    // No credential row must be seeded for an unknown email.
    $this->assertNull($repo->find('nobody@example.com'));
});

test('account locks after five failures then unlocks later', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo);

    for ($i = 0; $i < PasswordAuthenticator::MAX_FAILED_ATTEMPTS; $i++) {
        $auth->attemptLogin('user@example.com', 'wrong-password', 1000);
    }

    // Even the correct password is refused while locked.
    $this->assertNull($auth->attemptLogin('user@example.com', 'correcthorse10', 1000));

    // Once the lockout window has passed, the correct password works.
    $later = 1000 + PasswordAuthenticator::LOCKOUT_SECONDS + 1;
    $this->assertNotNull($auth->attemptLogin('user@example.com', 'correcthorse10', $later));
});
