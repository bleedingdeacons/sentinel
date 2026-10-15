<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\Member;

/**
 * Reach's sign-in eligibility rule, applied to Monitor's SSO sign-in.
 *
 * Monitor's browser sign-in used to run through Reach's OAuth callback,
 * which checked this rule before Monitor's own {@see
 * \Sentinel\Monitor\Devices\ResponderGate} ever saw the identity. Keeping
 * authentication the same when the callback moved here means keeping that
 * first check too, so an SSO sign-in still has to pass both: a 12th-step
 * volunteer, or a telephone responder whose certification is current.
 *
 * Password sign-in never ran it — it went straight to the gate — and still
 * does not.
 *
 * <b>A copy of Reach's class, not a dependency on it.</b> Sentinel cannot
 * require Reach. If Reach's rule changes, change this one with it; the
 * test beside it pins the same cases Reach's does.
 */
final class OutreachEligibility
{
    /**
     * Whether a member may sign in through SSO. Null — no member
     * matched the verified email — is refused, so callers can pass a
     * lookup result straight in.
     */
    public static function permits(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        if ($member->isTwelfthStepper()) {
            return true;
        }

        return $member->isTelephoneResponder()
            && $member->getResponderCertification()->isCertified();
    }
}
