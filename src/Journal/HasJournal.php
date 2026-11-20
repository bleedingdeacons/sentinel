<?php

declare(strict_types=1);

namespace Sentinel\Journal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drop-in safe journalling for any Bleeding Deacons plugin.
 *
 * The journal's companion to {@see \Sentinel\Logger\HasLogger}. Where a log
 * line is diagnostic and eventually pruned, a journal entry is a record: a
 * JSON document kept in its own table, {prefix}sentinel_journal, under a
 * uuid, with the site-time moment it was written. Sentinel's admin lists them
 * under Sentinel → Journal.
 *
 * Silently no-ops (returns null) when the shared logger mu-plugin, which
 * provides wp_journal(), has not been deployed.
 *
 * Usage:
 *
 *   class MyPlugin {
 *       use \Sentinel\Journal\HasJournal;
 *
 *       protected static function journalChannel(): string {
 *           return 'my-plugin';   // recorded against every entry
 *       }
 *   }
 *
 *   // JSON text, stored as given (it must be valid JSON):
 *   MyPlugin::journal('{"event":"shift_swapped","shift":"SHIFT-2026-08-15-N"}');
 *
 *   // Or a PHP object or array, encoded with json_encode() — so a
 *   // JsonSerializable decides its own shape, and any other object
 *   // contributes its public properties:
 *   $id = MyPlugin::journal((object) ['event' => 'shift_swapped']);
 *
 * Like HasLogger, a sibling plugin is expected to keep its own copy of this
 * trait in its own namespace, so it resolves through that plugin's
 * autoloader with no class-load dependency on Sentinel.
 */
trait HasJournal
{
    /**
     * Override this in the consuming class to set the channel name.
     *
     * The default is derived from the class name exactly as HasLogger's
     * logChannel() is, so a class using both traits without overriding
     * either journals and logs under the same name.
     */
    protected static function journalChannel(): string
    {
        $parts = explode('\\', static::class);
        return sanitize_key(end($parts));
    }

    /**
     * Record a journal entry.
     *
     * @param string|array<mixed>|object $entry JSON text, or a PHP object or array to encode as JSON.
     *
     * @return string|null The entry's uuid, or null when it was not recorded: the
     *                     journal is not available, the string was not valid JSON, the
     *                     value could not be encoded, or the write failed. The reason is
     *                     logged on the journal channel. Never throws.
     */
    public static function journal(string|array|object $entry): ?string
    {
        if (!function_exists('wp_journal')) {
            return null;
        }

        return wp_journal(static::journalChannel(), $entry);
    }
}
