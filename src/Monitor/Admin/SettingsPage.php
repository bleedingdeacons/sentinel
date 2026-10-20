<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\Admin\ProviderCredentialsSection;
use Guardian\Admin\ProviderField;
use Sentinel\Monitor\Alerts\Alert;
use Sentinel\Monitor\Alerts\Fcm\ServiceAccount;
use Sentinel\Monitor\Core\Settings;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertRule;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSender;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSettings;
use Sentinel\Monitor\ErrorAlerts\LoggedError;
use Sentinel\Monitor\Rest\OAuthCallbackController;
use Sentinel\Plugin;

/**
 * Monitor Settings, under the Sentinel menu.
 *
 * Three groups. The first two moved from Reach's settings page with the
 * Monitor app's server side:
 *
 *   - Push notifications — the Firebase service account and the iOS
 *     critical-alert switch.
 *
 *   - Error alerts — whether a logged error pages the handsets opted in on
 *     the devices screen, and the rules that say which plugins and levels
 *     do, how loudly, and how often the same one may repeat; and a button
 *     that sends a test one.
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
    public const TEST_ERROR_ALERT_ACTION = 'sentinel_monitor_test_error_alert';
    private const CAPABILITY = 'manage_options';

    /** How each alert reads in the rules' selects. */
    private const ALERT_LABELS = [
        Alert::LEVEL_RED    => 'Red — alarm, loops until answered',
        Alert::LEVEL_YELLOW => 'Yellow — notification',
        Alert::LEVEL_BLUE   => 'Blue — quiet notice',
        ErrorAlertRule::OFF => 'Don\'t alert',
    ];

    /** Blank rows under the rules, for adding new ones without script. */
    private const BLANK_RULE_ROWS = 2;

    public function __construct(
        private readonly Settings $settings,
        private readonly ErrorAlertSettings $errorAlerts,
        private readonly ErrorAlertSender $errorAlertSender,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 20);
        add_action('admin_post_sentinel_monitor_save_settings', [$this, 'handleSave']);
        add_action('admin_post_' . self::TEST_ERROR_ALERT_ACTION, [$this, 'handleTestErrorAlert']);
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
        $notice .= $this->testNotice();

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

                <?php $this->renderErrorAlerts(); ?>

                <h2>Authentication</h2>
                <p>
                    The OAuth providers a Monitor handset can sign in with. These can be the same client
                    registrations Reach uses: add the redirect URI shown below to each one, alongside
                    Reach&rsquo;s. Secrets are encrypted at rest.
                </p>

                <?php $this->providerSection()->render(); ?>

                <?php submit_button(); ?>
            </form>

            <h2>Test an error alert</h2>
            <p>
                Sends one blue alert, as a logged error would, to every handset opted in on
                <a href="<?php echo esc_url(add_query_arg('page', DevicesPage::PAGE_SLUG, admin_url('admin.php'))); ?>">Monitor Devices</a>.
                It skips the rules, their timeouts and the hourly cap, so it always goes.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ERROR_ALERT_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ERROR_ALERT_ACTION); ?>
                <?php submit_button('Send test error alert', 'secondary', 'submit', false); ?>
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

        $errorAlerts = isset($_POST['error_alerts']) && is_array($_POST['error_alerts'])
            ? wp_unslash($_POST['error_alerts'])
            : [];
        $this->errorAlerts->save(is_array($errorAlerts) ? $errorAlerts : []);

        $this->providerSection()->save($_POST);
    }

    public function handleTestErrorAlert(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions', '', ['response' => 403]);
        }

        wp_safe_redirect($this->testErrorAlertFromRequest());
        exit;
    }

    /**
     * Send the test error alert and return where the browser goes next.
     * Split out of {@see handleTestErrorAlert()} so it can be driven in a
     * test, for the same reason as {@see saveFromRequest()}.
     */
    private function testErrorAlertFromRequest(): string
    {
        check_admin_referer(self::TEST_ERROR_ALERT_ACTION);

        $sent = $this->errorAlertSender->send(
            new LoggedError('error', 'sentinel', 'Test error alert, sent from Monitor Settings. Nothing is wrong.'),
            0,
            time(),
        );

        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'error_alert_test' => (string) $sent],
            admin_url('admin.php'),
        );
    }

    private function testNotice(): string
    {
        if (!isset($_GET['error_alert_test']) || !is_string($_GET['error_alert_test'])) {
            return '';
        }

        $sent = (int) $_GET['error_alert_test'];
        if ($sent === 0) {
            return '<div class="notice notice-warning is-dismissible"><p>No handset is opted in to error alerts, '
                . 'so nothing was sent. Turn them on for a handset on Monitor Devices first.</p></div>';
        }

        return '<div class="notice notice-success is-dismissible"><p>'
            . esc_html(sprintf('Test error alert sent to %d %s.', $sent, $sent === 1 ? 'handset' : 'handsets'))
            . '</p></div>';
    }

    private function renderErrorAlerts(): void
    {
        $settings = $this->errorAlerts;
        $rules = $settings->rules();
        ?>
        <h2>Error alerts</h2>
        <p>
            Pages the handsets opted in on
            <a href="<?php echo esc_url(add_query_arg('page', DevicesPage::PAGE_SLUG, admin_url('admin.php'))); ?>">Monitor Devices</a>
            when a log entry matches one of the rules below, including PHP fatals. No handset is opted in until you
            turn it on there. Only the channel, level, message and file are sent &mdash; never the log entry's
            context &mdash; but the message is whatever the code that logged it wrote.
        </p>
        <?php if ($settings->loggerDisabled()) : ?>
            <div class="notice notice-warning inline"><p>
                Logging is switched off (<code>SENTINEL_LOG_ENABLED</code>), so no error can raise an alert.
            </p></div>
        <?php elseif ($settings->loggerDropsThreshold()) : ?>
            <div class="notice notice-warning inline"><p>
                The logger is set to record less than the rules below alert on (<code>SENTINEL_LOG_LEVEL</code>), so
                some of the entries that should raise an alert are never written and never will.
            </p></div>
        <?php endif; ?>
        <table class="form-table">
            <tr>
                <th>Send error alerts</th>
                <td>
                    <label>
                        <input type="checkbox" name="error_alerts[enabled]" value="1" <?php checked($settings->isEnabled()); ?>>
                        Alert opted-in handsets when a log entry matches a rule
                    </label>
                </td>
            </tr>
        </table>

        <h3>Rules</h3>
        <p>
            Each rule covers one plugin&rsquo;s log channel, or <code>*</code> for any plugin, from a level upwards.
            A log entry takes its own plugin&rsquo;s rule when one covers its level, and the <code>*</code> rules
            otherwise; of those, the rule with the most severe level it reaches wins. So
            <code>*</code>&nbsp;from&nbsp;Error alongside <code>unity</code>&nbsp;from&nbsp;Critical alerts every
            plugin&rsquo;s errors, and rings Unity&rsquo;s critical ones the way that rule says.
            <strong>Don&rsquo;t alert</strong> silences everything a rule covers.
        </p>
        <p>
            <strong>Timeout</strong> is how long the same error is held back after it has paged. Repeats in between
            are counted, and the next alert says how many. Use the blank rows to add rules; save to get more.
        </p>
        <table class="widefat striped sentinel-error-alert-rules" style="max-width:56em;">
            <thead>
                <tr>
                    <th scope="col">Plugin</th>
                    <th scope="col">From level</th>
                    <th scope="col">Alert</th>
                    <th scope="col">Timeout</th>
                    <th scope="col">Remove</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $index = 0;
                foreach ($rules as $rule) {
                    $this->renderRuleRow($index++, $rule);
                }
                for ($blank = 0; $blank < self::BLANK_RULE_ROWS; $blank++) {
                    $this->renderRuleRow($index++, null);
                }
                ?>
            </tbody>
        </table>
        <datalist id="sentinel_monitor_error_channels">
            <option value="<?php echo esc_attr(ErrorAlertRule::ANY_CHANNEL); ?>">Any plugin</option>
            <?php foreach ($this->knownChannels($rules) as $channel) : ?>
                <option value="<?php echo esc_attr($channel); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="description">
            <code>php</code> is PHP warnings and fatals, <code>wp</code> every <code>wp_die()</code>, routine permission
            refusals included, and <code>monitor</code> this module&rsquo;s own channel: alerting on it means a failed
            push could page someone about the paging.
        </p>

        <table class="form-table">
            <tr>
                <th><label for="sentinel_monitor_error_cap">At most</label></th>
                <td>
                    <input type="number" min="1" max="500" id="sentinel_monitor_error_cap" class="small-text"
                           name="error_alerts[hourly_cap]"
                           value="<?php echo esc_attr((string) $settings->hourlyCap()); ?>"> alerts an hour, across all rules
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * One row of the rules table. A null rule is a blank row for adding
     * one: its level starts unchosen, and a row saved without a level is
     * skipped.
     */
    private function renderRuleRow(int $index, ?ErrorAlertRule $rule): void
    {
        $name = static fn(string $field): string => sprintf('error_alerts[rules][%d][%s]', $index, $field);
        $id = 'sentinel_monitor_error_rule_' . $index;
        ?>
        <tr>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '_channel'); ?>">Plugin</label>
                <input type="text" id="<?php echo esc_attr($id . '_channel'); ?>" class="regular-text code"
                       style="width:12em;" list="sentinel_monitor_error_channels" autocomplete="off"
                       name="<?php echo esc_attr($name('channel')); ?>"
                       placeholder="<?php echo esc_attr(ErrorAlertRule::ANY_CHANNEL . ' (any plugin)'); ?>"
                       value="<?php echo esc_attr($rule->channel ?? ''); ?>">
            </td>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '_level'); ?>">From level</label>
                <select id="<?php echo esc_attr($id . '_level'); ?>" name="<?php echo esc_attr($name('level')); ?>">
                    <?php if ($rule === null) : ?>
                        <option value="" selected>&mdash; Add a rule &mdash;</option>
                    <?php endif; ?>
                    <?php foreach (ErrorAlertSettings::LEVELS as $level) : ?>
                        <option value="<?php echo esc_attr($level); ?>" <?php selected($rule->level ?? '', $level); ?>>
                            <?php echo esc_html($level === 'debug' ? 'Debug and above (everything)' : ucfirst($level) . ' and above'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '_alert'); ?>">Alert</label>
                <select id="<?php echo esc_attr($id . '_alert'); ?>" name="<?php echo esc_attr($name('alert')); ?>">
                    <?php foreach (self::ALERT_LABELS as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($rule->alert ?? Alert::LEVEL_BLUE, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '_timeout'); ?>">Timeout in minutes</label>
                <input type="number" min="0" max="<?php echo esc_attr((string) ErrorAlertRule::MAX_TIMEOUT_MINUTES); ?>"
                       id="<?php echo esc_attr($id . '_timeout'); ?>" class="small-text"
                       name="<?php echo esc_attr($name('timeout_minutes')); ?>"
                       value="<?php echo esc_attr((string) ($rule->timeoutMinutes ?? ErrorAlertSettings::DEFAULT_TIMEOUT_MINUTES)); ?>"> min
            </td>
            <td>
                <?php if ($rule !== null) : ?>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($name('remove')); ?>" value="1">
                        <span class="screen-reader-text">Remove this rule</span>
                    </label>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * The channels to suggest in the plugin field: the ones that have
     * logged anything on this site, the ones the rules name, and the few
     * that are always there. A channel that has never logged can still be
     * typed in.
     *
     * @param array<int, ErrorAlertRule> $rules
     * @return array<int, string>
     */
    private function knownChannels(array $rules): array
    {
        $channels = ['default', 'monitor', 'php', 'wp'];
        foreach ($rules as $rule) {
            if (!$rule->isForAnyChannel()) {
                $channels[] = $rule->channel;
            }
        }

        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'get_col') && class_exists('Sentinel_Logger')) {
            try {
                $table = '`' . esc_sql(\Sentinel_Logger::tableName()) . '`';
                $quiet = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : null;
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
                $logged = $wpdb->get_col("SELECT DISTINCT channel FROM {$table} LIMIT 200");
                if ($quiet !== null) {
                    $wpdb->suppress_errors($quiet);
                }
                foreach (is_array($logged) ? $logged : [] as $channel) {
                    if (is_string($channel)) {
                        $channels[] = $channel;
                    }
                }
            } catch (\Throwable) {
                // Suggestions only; the field takes any channel without them.
            }
        }

        $channels = array_values(array_unique(array_filter(array_map('sanitize_key', $channels))));
        sort($channels);

        return $channels;
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
