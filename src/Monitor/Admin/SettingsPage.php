<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\Admin\ProviderCredentialsSection;
use Guardian\Admin\ProviderField;
use Sentinel\Monitor\Alerts\Fcm\ServiceAccount;
use Sentinel\Monitor\Core\Settings;
use Sentinel\Monitor\Rest\OAuthCallbackController;
use Sentinel\Plugin;

/**
 * Monitor Settings, under the Sentinel menu.
 *
 * Two groups, both moved from Reach's settings page with the Monitor app's
 * server side:
 *
 *   - Push notifications — the Firebase service account and the iOS
 *     critical-alert switch.
 *
 *   - Authentication — Google, Microsoft and Facebook, rendered and saved by
 *     Guardian's ProviderCredentialsSection: a client ID field and a
 *     write-only client secret field for each, with the redirect URI to
 *     register. Apple is not offered: it signs in client-side, and the
 *     handset flow has only ever accepted server-side providers.
 *
 * Secrets are AES-256-GCM encrypted at rest by {@see Settings} and never come
 * back to the browser. Submitting an empty secret field leaves the stored
 * value untouched — clearing requires ticking the explicit "clear" checkbox.
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'sentinel-monitor-settings';
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 20);
        add_action('admin_post_sentinel_monitor_save_settings', [$this, 'handleSave']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            Plugin::MENU_SLUG,
            'Monitor Settings',
            'Monitor Settings',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $notice = '';
        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        // The stored key file is never echoed back — like the OAuth
        // client secrets, it is write-only once saved. What is shown is
        // whether one is present and which Firebase project it belongs
        // to, which is the part an admin actually needs to confirm.
        $fcmAccount = ServiceAccount::fromJson($this->settings->getFcmServiceAccount());
        $hasFcm = $fcmAccount !== null;
        $fcmProject = $fcmAccount !== null ? $fcmAccount->projectId : '';

        ?>
        <div class="wrap">
            <h1>Monitor Settings</h1>
            <?php echo $notice; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sentinel_monitor_save_settings">
                <?php wp_nonce_field('sentinel_monitor_save_settings'); ?>

                <h2>Push notifications</h2>
                <p>
                    Optional. Alerts reach Monitor handsets either way &mdash; every handset polls
                    as well as listening &mdash; but without Firebase an alert arrives at the next poll
                    rather than instantly, and a phone with the app closed will not ring at all.
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="sentinel_monitor_fcm_service_account">Firebase service account</label></th>
                        <td>
                            <textarea id="sentinel_monitor_fcm_service_account"
                                      name="fcm_service_account"
                                      rows="6"
                                      class="large-text code"
                                      autocomplete="off"
                                      placeholder="<?php echo $hasFcm
                                        ? 'A service account is saved. Paste a new key file to replace it.'
                                        : 'Paste the whole service-account JSON key file here.'; ?>"></textarea>
                            <p class="description">
                                From the Firebase console: <em>Project settings &rarr; Service accounts &rarr;
                                Generate new private key</em>. Paste the entire JSON file. It is encrypted at rest,
                                and is never shown again once saved &mdash; leave this blank to keep the existing one.
                                <?php if ($hasFcm) : ?>
                                    <br><strong>Status:</strong> a service account is saved for project
                                    <code><?php echo esc_html($fcmProject); ?></code>.
                                <?php else : ?>
                                    <br><strong>Status:</strong> not configured &mdash; handsets are polling only.
                                <?php endif; ?>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="fcm_service_account_clear" value="1">
                                    Remove the stored service account
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sentinel_monitor_apns_critical">iOS critical alerts</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="sentinel_monitor_apns_critical"
                                       name="apns_critical"
                                       value="1"
                                       <?php checked($this->settings->isApnsCriticalEnabled()); ?>>
                                Send urgent alerts as critical notifications on iOS
                            </label>
                            <p class="description">
                                Critical alerts sound through the ringer switch and Do&nbsp;Not&nbsp;Disturb.
                                Apple gates them behind the
                                <code>com.apple.developer.usernotifications.critical-alerts</code> entitlement, which
                                is granted only on application.
                                <strong>Do not switch this on until that entitlement is in the Monitor app&rsquo;s
                                provisioning profile:</strong> without it Apple <em>rejects</em> the notification
                                rather than downgrading it, so this would silence the very alerts it is meant to make
                                louder. Leave it off and urgent alerts still use the time&#8209;sensitive level, which
                                breaks through a Focus mode.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Authentication</h2>
                <p>
                    The OAuth providers a Monitor handset can sign in with. These can be the same client
                    registrations Reach uses: add the redirect URI shown below to each one, alongside
                    Reach&rsquo;s. Secrets are encrypted at rest.
                </p>

                <?php $this->providerSection()->render(); ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions', '', ['response' => 403]);
        }
        check_admin_referer('sentinel_monitor_save_settings');

        $this->saveFromRequest();

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Write the submitted form into {@see Settings}.
     *
     * Split out of {@see handleSave()} so it can be driven in a test:
     * everything above it is a guard and everything below is
     * `wp_safe_redirect(); exit;`, and the `exit` takes the test runner
     * with it.
     */
    private function saveFromRequest(): void
    {
        // Firebase service account. Same write-only handling as the
        // OAuth client secrets: an empty submission leaves the stored
        // value untouched, and removing one takes an explicit checkbox
        // so a blank textarea can never wipe it by accident.
        //
        // Deliberately not sanitize_text_field: this is a JSON document
        // whose private_key contains newlines, and collapsing those
        // would corrupt the key into something that cannot sign.
        if (!empty($_POST['fcm_service_account_clear'])) {
            $this->settings->setFcmServiceAccount('');
        } elseif (isset($_POST['fcm_service_account']) && is_string($_POST['fcm_service_account'])) {
            $serviceAccount = trim(wp_unslash($_POST['fcm_service_account']));
            if ($serviceAccount !== '') {
                $this->settings->setFcmServiceAccount($serviceAccount);
            }
        }

        $this->settings->setApnsCriticalEnabled(!empty($_POST['apns_critical']));

        $this->providerSection()->save($_POST);
    }

    /**
     * The client id and secret rows for the server-side providers, from
     * Guardian.
     */
    private function providerSection(): ProviderCredentialsSection
    {
        $callbackUrl = OAuthCallbackController::callbackUrl();

        return new ProviderCredentialsSection($this->settings, [
            ProviderField::google($callbackUrl),
            ProviderField::microsoft($callbackUrl),
            ProviderField::facebook($callbackUrl),
        ]);
    }
}
