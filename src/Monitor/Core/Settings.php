<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\Credentials\CredentialStore;

/**
 * Settings store for the Monitor module.
 *
 * Public fields (client ids, the APNs critical flag) live in one wp_option
 * as a plain array. Secrets (OAuth client secrets and the FCM service
 * account) are AES-256-GCM encrypted before being written, keyed by a hash
 * of WordPress's AUTH_KEY salt, so a database dump alone never yields usable
 * credentials. Two rows, so the admin form renders without decrypting
 * anything when no secret is being edited.
 *
 * The Monitor half of Reach's Settings, unchanged in behaviour; only the
 * option names and the key-derivation domain are Monitor's own. The values
 * are not carried over from Reach — they are entered afresh on the settings
 * page — so a different domain strands nothing.
 *
 * It is the {@see CredentialStore} Guardian's providers read their client
 * ids and secrets from, and the one the settings page's provider section
 * writes to.
 */
final class Settings implements CredentialStore
{
    public const OPTION_PUBLIC = 'sentinel_monitor_settings';
    public const OPTION_SECRETS = 'sentinel_monitor_secrets';

    private const CIPHER = 'aes-256-gcm';

    public function getClientId(string $provider): string
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        $key = 'client_id_' . $this->normaliseProvider($provider);
        return is_array($all) && isset($all[$key]) && is_string($all[$key]) ? $all[$key] : '';
    }

    public function setClientId(string $provider, string $value): void
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all['client_id_' . $this->normaliseProvider($provider)] = $value;
        update_option(self::OPTION_PUBLIC, $all, false);
    }

    public function getClientSecret(string $provider): string
    {
        $all = get_option(self::OPTION_SECRETS, []);
        $key = 'client_secret_' . $this->normaliseProvider($provider);
        if (!is_array($all) || !isset($all[$key]) || !is_string($all[$key])) {
            return '';
        }
        return $this->decrypt($all[$key]);
    }

    public function setClientSecret(string $provider, string $value): void
    {
        $all = get_option(self::OPTION_SECRETS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $key = 'client_secret_' . $this->normaliseProvider($provider);
        if ($value === '') {
            unset($all[$key]);
        } else {
            $all[$key] = $this->encrypt($value);
        }
        update_option(self::OPTION_SECRETS, $all, false);
    }

    /**
     * The Google service-account key file used to push alerts to Monitor
     * handsets through Firebase Cloud Messaging, as raw JSON.
     *
     * Stored encrypted alongside the OAuth client secrets, and for the
     * same reason: the private key inside it can push a notification to
     * every handset in the Firebase project, so a database dump must
     * not yield a usable one.
     *
     * Empty means FCM is not configured. That is a supported state, not
     * a broken one — every handset polls as well as listening, so alerts
     * still arrive, just at the poll interval rather than instantly.
     */
    public function getFcmServiceAccount(): string
    {
        $all = get_option(self::OPTION_SECRETS, []);
        if (!is_array($all) || !isset($all['fcm_service_account']) || !is_string($all['fcm_service_account'])) {
            return '';
        }
        return $this->decrypt($all['fcm_service_account']);
    }

    public function setFcmServiceAccount(string $value): void
    {
        $all = get_option(self::OPTION_SECRETS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $value = trim($value);
        if ($value === '') {
            unset($all['fcm_service_account']);
        } else {
            $all['fcm_service_account'] = $this->encrypt($value);
        }
        update_option(self::OPTION_SECRETS, $all, false);
    }

    /**
     * Whether urgent alerts may ask iOS for a *critical* notification —
     * the kind that sounds through the ringer switch and Do Not Disturb.
     *
     * Off by default, and deliberately so. Critical alerts need the
     * `com.apple.developer.usernotifications.critical-alerts`
     * entitlement, which Apple grants only on application. Until it has
     * been granted, sending the critical flag gets the notification
     * *rejected* rather than quietly downgraded — so switching this on
     * before Apple says yes would silence the very alerts it is meant to
     * make louder.
     */
    public function isApnsCriticalEnabled(): bool
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        return is_array($all) && !empty($all['apns_critical']);
    }

    public function setApnsCriticalEnabled(bool $enabled): void
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        if (!is_array($all)) {
            $all = [];
        }
        if ($enabled) {
            $all['apns_critical'] = true;
        } else {
            unset($all['apns_critical']);
        }
        update_option(self::OPTION_PUBLIC, $all, false);
    }

    /**
     * Derive a 32-byte encryption key from WordPress's AUTH_KEY salt.
     * Rotating the salt (a recovery action WP already supports)
     * invalidates stored secrets — which is the right behaviour after a
     * suspected DB breach.
     */
    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|sentinel-monitor-secrets', true);
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            return '';
        }
        // Pack iv|tag|ciphertext and base64 for safe option storage.
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plaintext === false ? '' : $plaintext;
    }

    private function normaliseProvider(string $provider): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($provider)) ?? '';
    }
}
