<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Devices;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * The member behind a handset could not be looked up at all.
 *
 * Distinct from "looked up and refused", which revokes the handset. A lookup
 * that throws says nothing about the member — Unity's storage failed, or a
 * repository it depends on did — and revoking on it would sign every handset
 * out the moment Unity has a bad minute. {@see \Sentinel\Monitor\Plugin}
 * turns it into a 503 for any Monitor route, which the app treats as a
 * network failure and keeps its credentials through.
 */
final class MemberLookupUnavailable extends RuntimeException
{
}
