<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Monitor\Alerts\Alert;

/**
 * How error alerts behave: whether they are on, the rules that decide which
 * log entries page a handset and how, and the hourly cap over all of them.
 *
 * One wp_options row, `sentinel_monitor_error_alerts`, autoloaded because the
 * listener reads it on every request that logs anything it might alert on.
 * Every getter falls back to the default when the stored value is missing or
 * malformed, so a half-written row can never switch the alerts off or flood a
 * phone.
 *
 * <b>Rules.</b> Each {@see ErrorAlertRule} names a channel (a plugin, or any),
 * the least severe level it covers, the handset level it rings at (or off),
 * and how long the same error is held back after it has paged. How an entry
 * finds its rule is {@see ruleFor()}.
 *
 * <b>Rows from before the rules</b> held one threshold, one level map, one
 * cooldown and a list of excluded channels. They are read as the rules that
 * behave the same — {@see legacyRules()} — until the form is next saved, so
 * an upgrade changes nothing about what pages whom.
 *
 * <b>On by default.</b> That is safe because the handsets are not: nothing is
 * sent until an admin opts a handset in on the devices screen.
 */
final class ErrorAlertSettings
{
    public const OPTION = 'sentinel_monitor_error_alerts';

    /** The PSR-3 levels, most severe first. Any of them may start a rule. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** The levels the rows from before the rules could alert from. */
    private const LEGACY_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /**
     * How each level was painted before there were rules, and still is by
     * default. Red rings like an alarm and loops until answered, so only the
     * levels that mean the site is down get it.
     */
    public const DEFAULT_LEVEL_MAP = [
        'emergency' => Alert::LEVEL_RED,
        'alert'     => Alert::LEVEL_RED,
        'critical'  => Alert::LEVEL_YELLOW,
        'error'     => Alert::LEVEL_BLUE,
    ];

    /**
     * Channels that never raise an alert by default, as rules that switch
     * them off at every level.
     *
     * `wp` because the logger records every wp_die() there at critical,
     * routine permission refusals included. `monitor` because it is this
     * module's own channel: a failed push is logged there, and alerting about
     * it would page someone about the paging.
     */
    public const DEFAULT_EXCLUDED_CHANNELS = ['wp', 'monitor'];

    public const DEFAULT_THRESHOLD = 'error';
    public const DEFAULT_TIMEOUT_MINUTES = 15;
    public const DEFAULT_HOURLY_CAP = 20;

    private const MAX_HOURLY_CAP = 500;

    /** @var array<string, mixed>|null The stored row {@see $rules} was read from. */
    private ?array $rulesReadFrom = null;

    /** @var array<int, ErrorAlertRule> */
    private array $rules = [];

    public function isEnabled(): bool
    {
        $all = $this->all();
        return !array_key_exists('enabled', $all) || $all['enabled'] === true;
    }

    /**
     * The rules, in the order they are shown.
     *
     * @return array<int, ErrorAlertRule>
     */
    public function rules(): array
    {
        // Read for every log entry the listener hears, so parsed once and
        // kept until the stored row changes.
        $all = $this->all();
        if ($all === $this->rulesReadFrom) {
            return $this->rules;
        }

        if (!array_key_exists('rules', $all)) {
            $rules = self::legacyRules($all);
        } elseif (!is_array($all['rules'])) {
            $rules = self::legacyRules([]);
        } else {
            $rules = self::distinct(array_map([ErrorAlertRule::class, 'fromArray'], array_values($all['rules'])));
        }

        $this->rulesReadFrom = $all;
        return $this->rules = $rules;
    }

    /**
     * The rule a log entry is alerted by, or null when it is not alerted on.
     *
     * The entry's own channel is looked at first, and only when none of its
     * rules covers the entry's level do the rules for any channel apply. So
     * a channel rule overrides the general ones for the levels it covers and
     * leaves the rest to them. Among the rules that cover the entry, the
     * most severe wins: with rules from error and from critical, a critical
     * entry takes the critical one.
     *
     * A rule that is off still wins its match, which is how a channel or a
     * band of levels is silenced.
     */
    public function ruleFor(string $channel, string $logLevel): ?ErrorAlertRule
    {
        $logLevel = strtolower($logLevel);
        if (!in_array($logLevel, self::LEVELS, true)) {
            return null;
        }

        $channel = sanitize_key($channel);
        if ($channel === '') {
            $channel = 'default';
        }

        $rules = $this->rules();
        $rule = self::mostSevere($rules, $logLevel, static fn(ErrorAlertRule $r): bool => $r->channel === $channel)
            ?? self::mostSevere($rules, $logLevel, static fn(ErrorAlertRule $r): bool => $r->isForAnyChannel());

        return $rule === null || $rule->isOff() ? null : $rule;
    }

    /**
     * The least severe level any rule alerts from, or null when no rule
     * alerts at all. Anything below it can never raise an alert, which lets
     * the listener drop it before doing any work.
     */
    public function lowestAlertingLevel(): ?string
    {
        $lowest = null;
        foreach ($this->rules() as $rule) {
            if (!$rule->isOff() && ($lowest === null || $rule->severity() > $lowest->severity())) {
                $lowest = $rule;
            }
        }

        return $lowest?->level;
    }

    public function hourlyCap(): int
    {
        return $this->clamp($this->all()['hourly_cap'] ?? null, self::DEFAULT_HOURLY_CAP, 1, self::MAX_HOURLY_CAP);
    }

    /**
     * Replace the stored settings from a submitted form.
     *
     * Rules come in as `rules[n][channel|level|alert|timeout_minutes]`, with
     * `rules[n][remove]` ticked to drop one. A row with no level is the
     * blank one for adding a rule and is skipped. A second rule for the same
     * channel and level is dropped, keeping the first, since only one of
     * them could ever match. When no rules are sent at all the stored ones
     * are kept.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $rules = $this->rules();
        if (isset($input['rules']) && is_array($input['rules'])) {
            $rules = [];
            foreach ($input['rules'] as $row) {
                if (is_array($row) && !empty($row['remove'])) {
                    continue;
                }
                $rules[] = ErrorAlertRule::fromArray($row);
            }
            $rules = self::distinct($rules);
        }

        update_option(self::OPTION, [
            'enabled'    => !empty($input['enabled']),
            'rules'      => array_map(static fn(ErrorAlertRule $rule): array => $rule->toArray(), $rules),
            'hourly_cap' => $this->clamp($input['hourly_cap'] ?? null, self::DEFAULT_HOURLY_CAP, 1, self::MAX_HOURLY_CAP),
        ], true);
    }

    /**
     * Whether the logger is set to drop entries that a rule alerts on before
     * they are ever written, which means they can never raise an alert.
     *
     * The logger reads its level from SENTINEL_LOG_LEVEL first and the
     * `sentinel_logger_level` filter second, and so does this.
     */
    public function loggerDropsThreshold(): bool
    {
        $lowest = $this->lowestAlertingLevel();
        if ($lowest === null || !class_exists('Sentinel_Log_Level')) {
            return false;
        }

        $logLevel = defined('SENTINEL_LOG_LEVEL')
            ? constant('SENTINEL_LOG_LEVEL')
            : apply_filters('sentinel_logger_level', 'debug');

        return is_string($logLevel) && !\Sentinel_Log_Level::meetsThreshold($lowest, $logLevel);
    }

    /** Whether logging is switched off altogether. */
    public function loggerDisabled(): bool
    {
        $enabled = defined('SENTINEL_LOG_ENABLED')
            ? constant('SENTINEL_LOG_ENABLED')
            : apply_filters('sentinel_logger_enabled', true);

        return $enabled === false;
    }

    /**
     * The rules a row from before the rules stands for, or the defaults
     * when given nothing: one rule for any channel at each level the old
     * threshold covered, at the colour the old map gave it and the old
     * cooldown, and one rule switching off each excluded channel.
     *
     * @param array<string, mixed> $legacy
     * @return array<int, ErrorAlertRule>
     */
    public static function legacyRules(array $legacy): array
    {
        $threshold = $legacy['threshold'] ?? null;
        if (!is_string($threshold) || !in_array($threshold, self::LEGACY_LEVELS, true)) {
            $threshold = self::DEFAULT_THRESHOLD;
        }

        $map = is_array($legacy['level_map'] ?? null) ? $legacy['level_map'] : [];
        $timeout = $legacy['cooldown_minutes'] ?? self::DEFAULT_TIMEOUT_MINUTES;

        $rows = [];
        foreach (self::LEGACY_LEVELS as $level) {
            $alert = $map[$level] ?? null;
            $rows[] = [
                'channel'         => ErrorAlertRule::ANY_CHANNEL,
                'level'           => $level,
                'alert'           => is_string($alert) && in_array($alert, Alert::LEVELS, true) ? $alert : self::DEFAULT_LEVEL_MAP[$level],
                'timeout_minutes' => $timeout,
            ];
            if ($level === $threshold) {
                break;
            }
        }

        $excluded = is_array($legacy['excluded_channels'] ?? null)
            ? $legacy['excluded_channels']
            : self::DEFAULT_EXCLUDED_CHANNELS;
        foreach ($excluded as $channel) {
            if (is_string($channel) && sanitize_key($channel) !== '') {
                $rows[] = ['channel' => $channel, 'level' => 'debug', 'alert' => ErrorAlertRule::OFF, 'timeout_minutes' => 0];
            }
        }

        return self::distinct(array_map([ErrorAlertRule::class, 'fromArray'], $rows));
    }

    /**
     * The non-null rules, keeping the first for any channel and level.
     *
     * @param array<int, ErrorAlertRule|null> $rules
     * @return array<int, ErrorAlertRule>
     */
    private static function distinct(array $rules): array
    {
        $kept = [];
        foreach ($rules as $rule) {
            if ($rule === null) {
                continue;
            }
            foreach ($kept as $existing) {
                if ($existing->sameScopeAs($rule)) {
                    continue 2;
                }
            }
            $kept[] = $rule;
        }

        return $kept;
    }

    /**
     * Of the rules that pass the filter and cover the level, the one with
     * the most severe level.
     *
     * @param array<int, ErrorAlertRule> $rules
     * @param callable(ErrorAlertRule): bool $filter
     */
    private static function mostSevere(array $rules, string $logLevel, callable $filter): ?ErrorAlertRule
    {
        $best = null;
        foreach ($rules as $rule) {
            if ($filter($rule) && $rule->covers($logLevel) && ($best === null || $rule->severity() < $best->severity())) {
                $best = $rule;
            }
        }

        return $best;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }

    private function clamp(mixed $value, int $default, int $min, int $max): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return max($min, min($max, (int) $value));
        }
        return $default;
    }
}
