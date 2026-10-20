<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Monitor\Alerts\Alert;

/**
 * One line of the error-alert rules: which log entries it covers, how loudly
 * they ring, and how long the same one is held back after it has paged.
 *
 *  - **Channel** — the plugin or subsystem that logged the entry (`unity`,
 *    `concordance`, `php`…), or {@see ANY_CHANNEL} for every channel without
 *    a rule of its own.
 *  - **Level** — the least severe log level the rule covers. A rule from
 *    `error` covers error, critical, alert and emergency.
 *  - **Alert** — the handset level the entry is shown at, red, yellow or
 *    blue, or {@see OFF} for entries that must never page anyone.
 *  - **Timeout** — minutes the same error is held back after it has paged.
 *    Repeats in between are counted and reported on the next alert.
 *
 * How a log entry finds its rule is {@see ErrorAlertSettings::ruleFor()}.
 */
final class ErrorAlertRule
{
    /** The channel of a rule that covers every channel without its own. */
    public const ANY_CHANNEL = '*';

    /** The alert of a rule that silences what it covers. */
    public const OFF = 'off';

    /** Every value the alert may take, loudest first. */
    public const ALERTS = [Alert::LEVEL_RED, Alert::LEVEL_YELLOW, Alert::LEVEL_BLUE, self::OFF];

    public const MAX_TIMEOUT_MINUTES = 1440;

    public function __construct(
        public readonly string $channel,
        public readonly string $level,
        public readonly string $alert,
        public readonly int $timeoutMinutes,
    ) {
    }

    /**
     * Build one from a stored or submitted row, or null when the row is not
     * a rule: no level, an unknown level, or an unknown alert.
     *
     * The channel is cleaned as a log channel is, and an empty one means
     * every channel. A missing or malformed timeout takes the default rather
     * than dropping the rule, so a half-filled row still alerts.
     *
     * @param mixed $row
     */
    public static function fromArray($row): ?self
    {
        if (!is_array($row)) {
            return null;
        }

        $level = is_string($row['level'] ?? null) ? strtolower(trim($row['level'])) : '';
        if (!in_array($level, ErrorAlertSettings::LEVELS, true)) {
            return null;
        }

        $alert = is_string($row['alert'] ?? null) ? strtolower(trim($row['alert'])) : '';
        if (!in_array($alert, self::ALERTS, true)) {
            return null;
        }

        return new self(
            self::cleanChannel($row['channel'] ?? null),
            $level,
            $alert,
            self::cleanTimeout($row['timeout_minutes'] ?? null),
        );
    }

    /** @return array{channel: string, level: string, alert: string, timeout_minutes: int} */
    public function toArray(): array
    {
        return [
            'channel'         => $this->channel,
            'level'           => $this->level,
            'alert'           => $this->alert,
            'timeout_minutes' => $this->timeoutMinutes,
        ];
    }

    public function isOff(): bool
    {
        return $this->alert === self::OFF;
    }

    public function isForAnyChannel(): bool
    {
        return $this->channel === self::ANY_CHANNEL;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutMinutes * 60;
    }

    /** Whether an entry at this log level is at or above the rule's level. */
    public function covers(string $logLevel): bool
    {
        $entry = array_search($logLevel, ErrorAlertSettings::LEVELS, true);
        $floor = array_search($this->level, ErrorAlertSettings::LEVELS, true);

        return $entry !== false && $floor !== false && $entry <= $floor;
    }

    /**
     * Where the rule's level sits, 0 for emergency. Lower is more severe,
     * and the most severe rule an entry meets is the one it takes.
     */
    public function severity(): int
    {
        $index = array_search($this->level, ErrorAlertSettings::LEVELS, true);
        return $index === false ? PHP_INT_MAX : $index;
    }

    /** Whether two rules cover the same channel from the same level. */
    public function sameScopeAs(self $other): bool
    {
        return $this->channel === $other->channel && $this->level === $other->level;
    }

    /** @param mixed $channel */
    private static function cleanChannel($channel): string
    {
        if (!is_string($channel)) {
            return self::ANY_CHANNEL;
        }

        $channel = trim($channel);
        if ($channel === '' || $channel === self::ANY_CHANNEL) {
            return self::ANY_CHANNEL;
        }

        $clean = sanitize_key($channel);
        return $clean === '' ? self::ANY_CHANNEL : $clean;
    }

    /** @param mixed $value */
    private static function cleanTimeout($value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return max(0, min(self::MAX_TIMEOUT_MINUTES, (int) $value));
        }

        return ErrorAlertSettings::DEFAULT_TIMEOUT_MINUTES;
    }
}
