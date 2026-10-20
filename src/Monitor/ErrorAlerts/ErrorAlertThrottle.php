<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a logged error may page someone now.
 *
 * Two limits, both held in transients so they hold across requests:
 *
 *  - **A timeout per distinct error**, set by the rule the error matched. An
 *    error that logs on every page load would otherwise page on every page
 *    load. While its timeout runs, repeats are counted rather than sent, and
 *    the next alert for it carries the count ("+12 similar"), so a quiet
 *    phone never means a quiet site.
 *  - **An hourly cap across all errors**, for the day something breaks in a
 *    hundred different ways at once. Errors over the cap are counted against
 *    their own fingerprint the same way, so none is lost from the tally.
 *
 * Races between concurrent requests can let an extra alert through. That is
 * the safe direction to be wrong in, and not worth a lock.
 */
final class ErrorAlertThrottle
{
    private const COOLDOWN_PREFIX = 'sentinel_monitor_cd_';
    private const HOUR_PREFIX = 'sentinel_monitor_ea_hour_';

    /**
     * Kept well past any timeout, so a count of suppressed repeats is still
     * there to report when the timeout has run out.
     */
    private const RECORD_TTL = 2 * DAY_IN_SECONDS;

    public function __construct(private readonly ErrorAlertSettings $settings)
    {
    }

    /**
     * Admit one occurrence of an error, or count it as suppressed.
     *
     * @param int $timeoutSeconds How long the error is held back after it
     *     has paged: the timeout of the rule it matched. Zero sends every
     *     occurrence.
     * @param int $repeats Further occurrences of the same error in this
     *     request, folded in before deciding; they are never sent separately.
     * @return int|null Null when this occurrence must not be sent. Otherwise
     *     how many repeats were held back since this error last paged, to
     *     report alongside it.
     */
    public function admit(string $fingerprint, int $now, int $timeoutSeconds, int $repeats = 0): ?int
    {
        $key = self::COOLDOWN_PREFIX . $fingerprint;
        $record = $this->record($key);

        // Measured from when it last paged, against the timeout as it is set
        // now, so shortening a rule's timeout takes effect at once.
        if ($record['sent_at'] > 0 && $now < $record['sent_at'] + max(0, $timeoutSeconds)) {
            $this->store($key, $record['sent_at'], $record['suppressed'] + 1 + $repeats);
            return null;
        }

        $hourKey = self::HOUR_PREFIX . intdiv($now, HOUR_IN_SECONDS);
        $sentThisHour = (int) get_transient($hourKey);
        if ($sentThisHour >= $this->settings->hourlyCap()) {
            // Not a timeout: the error may page as soon as the cap allows.
            $this->store($key, $record['sent_at'], $record['suppressed'] + 1 + $repeats);
            return null;
        }

        set_transient($hourKey, $sentThisHour + 1, HOUR_IN_SECONDS);
        $this->store($key, $now, 0);

        return $record['suppressed'] + $repeats;
    }

    /** @return array{sent_at: int, suppressed: int} */
    private function record(string $key): array
    {
        $value = get_transient($key);
        if (!is_array($value)) {
            return ['sent_at' => 0, 'suppressed' => 0];
        }

        return [
            'sent_at'    => isset($value['sent_at']) && is_int($value['sent_at']) ? $value['sent_at'] : 0,
            'suppressed' => isset($value['suppressed']) && is_int($value['suppressed']) ? $value['suppressed'] : 0,
        ];
    }

    private function store(string $key, int $sentAt, int $suppressed): void
    {
        set_transient($key, ['sent_at' => $sentAt, 'suppressed' => $suppressed], self::RECORD_TTL);
    }
}
