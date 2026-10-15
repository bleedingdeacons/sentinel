<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use Sentinel\Monitor\Auth\OutreachEligibility;
use Sentinel\Tests\Monitor\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;

/**
 * Reach's sign-in rule, which Monitor's SSO callback still applies before
 * its own gate. These are the cases Reach pins for it; if Reach's rule
 * changes, both copies and both tests move together.
 * {@see OAuthCallbackControllerTest} drives it through the callback.
 */

test('twelfth stepper is admitted', function () {
    $this->assertTrue(OutreachEligibility::permits(
        new MemberStub(personalEmail: 'a@example.com', twelfthStepper: true),
    ));
});

test('certified telephone responder is admitted', function () {
    $this->assertTrue(OutreachEligibility::permits(new MemberStub(
        personalEmail: 'a@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    )));
});

test('uncertified telephone responder is refused', function (ResponderCertification $certification) {
    $this->assertFalse(OutreachEligibility::permits(new MemberStub(
        personalEmail: 'a@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: $certification,
    )));
})->with('uncertifiedStates');

/** @return array<string, array{0: ResponderCertification}> */
dataset('uncertifiedStates', function (): array {
    return [
        'none'        => [ResponderCertification::None],
        'applied'     => [ResponderCertification::Applied],
        'in training' => [ResponderCertification::InTraining],
        'pending'     => [ResponderCertification::Pending],
    ];
});

test('twelfth stepper is admitted even when also an uncertified responder', function () {
    // The two roles are independent, and the 12th-step role is
    // sufficient on its own — an uncertified responder badge must not
    // subtract an entitlement the member already has.
    $this->assertTrue(OutreachEligibility::permits(new MemberStub(
        personalEmail: 'a@example.com',
        twelfthStepper: true,
        telephoneResponder: true,
        responderCertification: ResponderCertification::InTraining,
    )));
});

test('member with neither role is refused', function () {
    $this->assertFalse(OutreachEligibility::permits(
        new MemberStub(personalEmail: 'a@example.com', twelfthStepper: false),
    ));
});

test('null is refused', function () {
    // No member matched the verified email. Callers pass a lookup
    // result straight in, so this must be a refusal rather than a
    // TypeError.
    $this->assertFalse(OutreachEligibility::permits(null));
});
