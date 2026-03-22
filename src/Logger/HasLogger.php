<?php

declare(strict_types=1);

namespace Sentinel\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drop-in safe logging for any Bleeding Deacons plugin.
 *
 * Use this trait in a plugin's main class (or a dedicated logger class) to get
 * safe convenience methods that silently no-op if the shared logger mu-plugin
 * hasn't been deployed yet.
 *
 * Usage:
 *
 *   class MyPlugin {
 *       use \Sentinel\Logger\HasLogger;
 *
 *       protected static function logChannel(): string {
 *           return 'my-plugin';   // appears in every log line
 *       }
 *   }
 *
 *   // Then anywhere:
 *   MyPlugin::log()->info('Hello world');
 *   MyPlugin::logInfo('Shorthand works too', ['key' => 'val']);
 */
trait HasLogger
{
    private static ?\BD_Log_Channel $loggerChannel = null;

    /**
     * Override this in the consuming class to set the channel name.
     */
    protected static function logChannel(): string
    {
        // Default: derive from class name → "sentinel", "unity", etc.
        $parts = explode('\\', static::class);
        return sanitize_key(end($parts));
    }

    /**
     * Get the log channel instance (or null if logger isn't available).
     */
    public static function log(): ?\BD_Log_Channel
    {
        if (self::$loggerChannel === null && function_exists('wp_log')) {
            self::$loggerChannel = wp_log(static::logChannel());
        }
        return self::$loggerChannel;
    }

    // ── Convenience static methods — safe to call even without the mu-plugin ──

    public static function logEmergency(string $message, array $context = []): void
    {
        static::log()?->emergency($message, $context);
    }

    public static function logAlert(string $message, array $context = []): void
    {
        static::log()?->alert($message, $context);
    }

    public static function logCritical(string $message, array $context = []): void
    {
        static::log()?->critical($message, $context);
    }

    public static function logError(string $message, array $context = []): void
    {
        static::log()?->error($message, $context);
    }

    public static function logWarning(string $message, array $context = []): void
    {
        static::log()?->warning($message, $context);
    }

    public static function logNotice(string $message, array $context = []): void
    {
        static::log()?->notice($message, $context);
    }

    public static function logInfo(string $message, array $context = []): void
    {
        static::log()?->info($message, $context);
    }

    public static function logDebug(string $message, array $context = []): void
    {
        static::log()?->debug($message, $context);
    }
}
