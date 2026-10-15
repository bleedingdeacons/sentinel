<?php

declare(strict_types=1);

namespace Sentinel\Monitor\ErrorAlerts;

if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Monitor\Alerts\Alert;

/**
 * How error alerts behave: whether they are on, what level raises one, which
 * channels never do, and how hard they are throttled.
 *
 * One wp_options row, `sentinel_monitor_error_alerts`, autoloaded because the
 * listener reads it on every request that logs an error. Every getter falls
 * back to the default when the stored value is missing or malformed, so a
 * half-written row can never switch the alerts off or flood a phone.
 *
 * <b>On by default.</b> That is safe because the handsets are not: nothing is
 * sent until an admin opts a handset in on the devices screen.
 */
final class ErrorAlertSettings
{
    public const OPTION = 'sentinel_monitor_error_alerts';

    /** The PSR-3 levels that may raise an alert, most severe first. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /**
     * How each log level is painted on the handset. Red rings like an alarm
     * and loops until answered, so only the levels that mean the site is down
     * get it by default.
     */
    public const DEFAULT_LEVEL_MAP = [
        'emergency' => Alert::LEVEL_RED,
        'alert'     => Alert::LEVEL_RED,
        'critical'  => Alert::LEVEL_YELLOW,
        'error'     => Alert::LEVEL_BLUE,
    ];

    /**
     * Channels that never raise an alert.
     *
     * `wp` because the logger records every wp_die() there at critical,
     * routine permission refusals included. `monitor` because it is this
     * module's own channel: a failed push is logged there, and alerting about
     * it would page someone about the paging.
     */
    public const DEFAULT_EXCLUDED_CHANNELS = ['wp', 'monitor'];

    public const DEFAULT_THRESHOLD = 'error';
    public const DEFAULT_COOLDOWN_MINUTES = 15;
    public const DEFAULT_HOURLY_CAP = 20;

    private const MAX_COOLDOWN_MINUTES = 1440;
    private const MAX_HOURLY_CAP = 500;

    public function isEnabled(): bool
    {
        $all = $this->all();
        return !array_key_exists('enabled', $all) || $all['enabled'] === true;
    }

    /** The least severe level that raises an alert. */
    public function threshold(): string
    {
        $value = $this->all()['threshold'] ?? null;
        return is_string($value) && in_array($value, self::LEVELS, true) ? $value : self::DEFAULT_THRESHOLD;
    }

    /** The handset level (red, yellow or blue) a log level is shown as. */
    public function handsetLevelFor(string $logLevel): string
    {
        $map = $this->all()['level_map'] ?? null;
        $value = is_array($map) ? ($map[$logLevel] ?? null) : null;

        if (is_string($value) && in_array($value, Alert::LEVELS, true)) {
            return $value;
        }

        return self::DEFAULT_LEVEL_MAP[$logLevel] ?? Alert::LEVEL_BLUE;
    }

    /** @return array<string, string> */
    public function levelMap(): array
    {
        $map = [];
        foreach (self::LEVELS as $level) {
            $map[$level] = $this->handsetLevelFor($level);
        }
        return $map;
    }

    /** @return array<int, string> */
    public function excludedChannels(): array
    {
        $value = $this->all()['excluded_channels'] ?? null;
        if (!is_array($value)) {
            return self::DEFAULT_EXCLUDED_CHANNELS;
        }

        return array_values(array_filter(array_map(
            static fn($channel): string => is_string($channel) ? sanitize_key($channel) : '',
            $value,
        ), static fn(string $channel): bool => $channel !== ''));
    }

    public function cooldownSeconds(): int
    {
        return $this->bounded('cooldown_minutes', self::DEFAULT_COOLDOWN_MINUTES, 0, self::MAX_COOLDOWN_MINUTES) * 60;
    }

    public function cooldownMinutes(): int
    {
        return intdiv($this->cooldownSeconds(), 60);
    }

    public function hourlyCap(): int
    {
        return $this->bounded('hourly_cap', self::DEFAULT_HOURLY_CAP, 1, self::MAX_HOURLY_CAP);
    }

    /**
     * Replace the stored settings from a submitted form.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $levelMap = [];
        foreach (self::LEVELS as $level) {
            $value = $input['level_map'][$level] ?? null;
            $levelMap[$level] = is_string($value) && in_array($value, Alert::LEVELS, true)
                ? $value
                : self::DEFAULT_LEVEL_MAP[$level];
        }

        $channels = is_string($input['excluded_channels'] ?? null)
            ? preg_split('/[\s,]+/', $input['excluded_channels']) ?: []
            : [];

        $threshold = $input['threshold'] ?? null;

        update_option(self::OPTION, [
            'enabled'           => !empty($input['enabled']),
            'threshold'         => is_string($threshold) && in_array($threshold, self::LEVELS, true)
                ? $threshold
                : self::DEFAULT_THRESHOLD,
            'level_map'         => $levelMap,
            'excluded_channels' => array_values(array_unique(array_filter(array_map('sanitize_key', $channels)))),
            'cooldown_minutes'  => $this->clamp($input['cooldown_minutes'] ?? null, self::DEFAULT_COOLDOWN_MINUTES, 0, self::MAX_COOLDOWN_MINUTES),
            'hourly_cap'        => $this->clamp($input['hourly_cap'] ?? null, self::DEFAULT_HOURLY_CAP, 1, self::MAX_HOURLY_CAP),
        ], true);
    }

    /**
     * Whether the logger is set to drop entries at the threshold before
     * they are ever written, which means they can never raise an alert.
     *
     * The logger reads its level from SENTINEL_LOG_LEVEL first and the
     * `sentinel_logger_level` filter second, and so does this.
     */
    public function loggerDropsThreshold(): bool
    {
        if (!class_exists('Sentinel_Log_Level')) {
            return false;
        }

        $logLevel = defined('SENTINEL_LOG_LEVEL')
            ? constant('SENTINEL_LOG_LEVEL')
            : apply_filters('sentinel_logger_level', 'debug');

        return is_string($logLevel) && !\Sentinel_Log_Level::meetsThreshold($this->threshold(), $logLevel);
    }

    /** Whether logging is switched off altogether. */
    public function loggerDisabled(): bool
    {
        $enabled = defined('SENTINEL_LOG_ENABLED')
            ? constant('SENTINEL_LOG_ENABLED')
            : apply_filters('sentinel_logger_enabled', true);

        return $enabled === false;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }

    private function bounded(string $key, int $default, int $min, int $max): int
    {
        return $this->clamp($this->all()[$key] ?? null, $default, $min, $max);
    }

    private function clamp(mixed $value, int $default, int $min, int $max): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return max($min, min($max, (int) $value));
        }
        return $default;
    }
}
