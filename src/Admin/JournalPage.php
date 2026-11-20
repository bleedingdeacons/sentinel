<?php

declare(strict_types=1);

namespace Sentinel\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Sentinel\Plugin;

use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function wp_enqueue_style;

/**
 * Class JournalPage
 *
 * Sentinel → Journal: the entries plugins have recorded through
 * wp_journal() or the {@see \Sentinel\Journal\HasJournal} trait, newest
 * first, a page at a time, optionally narrowed to one channel. Each entry's
 * JSON is shown pretty-printed under a disclosure.
 *
 * Read-only on purpose. The log viewer can clear its table because the log is
 * diagnostic and pruned anyway; the journal is a record, and the only way to
 * remove it is the opt-in drop on uninstall.
 */
class JournalPage
{
    public const PAGE_SLUG  = 'sentinel-journal';
    private const MENU_TITLE = 'Journal';
    private const PAGE_TITLE = 'Sentinel Journal';
    private const CAPABILITY = 'manage_options';

    /** Entries shown per page. */
    public const PER_PAGE = 50;

    /** @var string The hook suffix returned by add_submenu_page(). */
    private static string $hookSuffix = '';

    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Register the admin page as a submenu under Sentinel.
     */
    public static function registerPage(): void
    {
        self::$hookSuffix = (string) add_submenu_page(
            Plugin::MENU_SLUG,
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Enqueue CSS only on our page.
     */
    public static function enqueueAssets(string $hook): void
    {
        if (self::$hookSuffix === '' || $hook !== self::$hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'sentinel-journal',
            SENTINEL_PLUGIN_URL . 'assets/journal.css',
            [],
            SENTINEL_VERSION
        );
    }

    /**
     * Whether the journal table exists. False too when the mu-plugin is not
     * loaded, since its class is what names the table.
     */
    private static function tableExists(): bool
    {
        global $wpdb;
        if (!class_exists('Sentinel_Journal')) {
            return false;
        }
        $table = \Sentinel_Journal::tableName();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    /**
     * The channel filter from the query string; '' for all channels.
     */
    private static function requestedChannel(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
        $raw = $_GET['channel'] ?? '';
        return is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';
    }

    /**
     * The 1-based page number from the query string.
     */
    private static function requestedPage(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging
        $raw = $_GET['paged'] ?? 1;
        return max(1, is_scalar($raw) ? absint($raw) : 1);
    }

    /**
     * One page of entries, plus what the filter and pager need.
     *
     * @return array{
     *     entries: list<array{id: string, recorded_at: string, channel: string, data: string}>,
     *     channels: list<string>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     channel: string,
     *     table_name: string|null
     * }
     */
    public static function getPageData(string $channel, int $page): array
    {
        global $wpdb;

        $result = [
            'entries'    => [],
            'channels'   => [],
            'total'      => 0,
            'page'       => 1,
            'pages'      => 1,
            'channel'    => $channel,
            'table_name' => null,
        ];

        if (!self::tableExists()) {
            return $result;
        }

        $table = \Sentinel_Journal::tableName();
        $result['table_name'] = $table;
        $escapedTable = '`' . esc_sql($table) . '`';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $channelRows = $wpdb->get_results("SELECT DISTINCT channel FROM {$escapedTable} ORDER BY channel ASC");
        foreach ((array) $channelRows as $row) {
            if (is_object($row) && isset($row->channel) && is_string($row->channel)) {
                $result['channels'][] = $row->channel;
            }
        }
        $result['channels'] = array_values(array_unique($result['channels']));

        $where  = '';
        $params = [];
        if ($channel !== '') {
            $where    = 'WHERE channel = %s';
            $params[] = $channel;
        }

        $countSql = "SELECT COUNT(*) FROM {$escapedTable} {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix; the WHERE value is prepared
        $result['total'] = (int) $wpdb->get_var($params === [] ? $countSql : $wpdb->prepare($countSql, ...$params));

        if ($result['total'] === 0) {
            return $result;
        }

        $result['pages'] = (int) ceil($result['total'] / self::PER_PAGE);
        $result['page']  = min($page, $result['pages']);
        $offset          = ($result['page'] - 1) * self::PER_PAGE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $sql = "SELECT id, recorded_at, channel, data FROM {$escapedTable} {$where}
                ORDER BY recorded_at DESC, id DESC
                LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...[...$params, self::PER_PAGE, $offset]));

        foreach ((array) $rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $result['entries'][] = [
                'id'          => (string) ($row->id ?? ''),
                'recorded_at' => (string) ($row->recorded_at ?? ''),
                'channel'     => (string) ($row->channel ?? ''),
                'data'        => (string) ($row->data ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Pretty-print stored JSON for display, falling back to the raw value
     * when it does not decode or re-encode.
     */
    public static function prettyJson(string $json): string
    {
        if ($json === '') {
            return '';
        }

        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            return json_encode(
                $decoded,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException) {
            return $json;
        }
    }

    /**
     * A one-line preview of an entry's JSON for the collapsed row.
     */
    private static function preview(string $json): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $json);
        return mb_strlen($flat) > 120 ? mb_substr($flat, 0, 119) . '…' : $flat;
    }

    /**
     * URL of this page with the given channel and page number.
     */
    private static function pageUrl(string $channel, int $page): string
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($channel !== '') {
            $args['channel'] = $channel;
        }
        if ($page > 1) {
            $args['paged'] = $page;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Render the full admin page.
     */
    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sentinel'));
        }

        $deployed = class_exists('Sentinel_Journal');
        $data     = self::getPageData(self::requestedChannel(), self::requestedPage());

        ?>
        <div class="wrap sentinel-journal">
            <h1><?php echo esc_html(self::PAGE_TITLE); ?></h1>

            <?php if (!$deployed) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('The shared logger (mu-plugin), which provides the journal, is not deployed. Run', 'sentinel'); ?>
                        <code>wp sentinel deploy-logger</code>
                        <?php esc_html_e('or deactivate and reactivate Sentinel.', 'sentinel'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="sentinel-journal-section">
                <?php self::renderToolbar($data); ?>
                <?php self::renderEntries($data); ?>
                <?php self::renderPager($data); ?>

                <?php if ($data['table_name'] !== null) : ?>
                    <p class="description sentinel-table-name">
                        <?php esc_html_e('Database table:', 'sentinel'); ?>
                        <code><?php echo esc_html($data['table_name']); ?></code>
                        &middot;
                        <?php esc_html_e('Times are site time', 'sentinel'); ?>
                        (<code><?php echo esc_html(wp_timezone()->getName()); ?></code>).
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * The entry count and the channel filter.
     *
     * @param array{channels: list<string>, total: int, channel: string} $data
     */
    private static function renderToolbar(array $data): void
    {
        ?>
        <div class="sentinel-journal-toolbar">
            <span class="sentinel-journal-stat">
                <strong><?php echo esc_html(number_format_i18n($data['total'])); ?></strong>
                <?php esc_html_e('entries', 'sentinel'); ?>
            </span>

            <?php if ($data['channels'] !== []) : ?>
                <form method="get" class="sentinel-journal-filter">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                    <label for="sentinel-journal-channel"><?php esc_html_e('Channel', 'sentinel'); ?></label>
                    <select id="sentinel-journal-channel" name="channel" onchange="this.form.submit()">
                        <option value=""><?php esc_html_e('All channels', 'sentinel'); ?></option>
                        <?php foreach ($data['channels'] as $channel) : ?>
                            <option value="<?php echo esc_attr($channel); ?>" <?php selected($data['channel'], $channel); ?>>
                                <?php echo esc_html($channel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="button"><?php esc_html_e('Filter', 'sentinel'); ?></button></noscript>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The entries table, or an empty state.
     *
     * @param array{entries: list<array{id: string, recorded_at: string, channel: string, data: string}>, channel: string} $data
     */
    private static function renderEntries(array $data): void
    {
        if ($data['entries'] === []) {
            echo '<div class="sentinel-journal-empty">';
            echo '<span class="dashicons dashicons-book"></span>';
            echo '<p>' . esc_html(
                $data['channel'] === ''
                    ? __('No journal entries yet.', 'sentinel')
                    : __('No journal entries on this channel.', 'sentinel')
            ) . '</p>';
            echo '</div>';
            return;
        }

        ?>
        <table class="sentinel-journal-table widefat striped">
            <thead>
            <tr>
                <th class="sentinel-journal-col-time"><?php esc_html_e('Recorded', 'sentinel'); ?></th>
                <th class="sentinel-journal-col-channel"><?php esc_html_e('Channel', 'sentinel'); ?></th>
                <th class="sentinel-journal-col-data"><?php esc_html_e('Entry', 'sentinel'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($data['entries'] as $entry) :
                $pretty = self::prettyJson($entry['data']);
                ?>
                <tr>
                    <td class="sentinel-journal-col-time">
                        <span class="sentinel-journal-time"><?php echo esc_html(substr($entry['recorded_at'], 0, 19)); ?></span>
                    </td>
                    <td class="sentinel-journal-col-channel">
                        <a href="<?php echo esc_url(self::pageUrl($entry['channel'], 1)); ?>"><code><?php echo esc_html($entry['channel']); ?></code></a>
                    </td>
                    <td class="sentinel-journal-col-data">
                        <details>
                            <summary><code class="sentinel-journal-preview"><?php echo esc_html(self::preview($entry['data'])); ?></code></summary>
                            <pre class="sentinel-journal-json"><?php echo esc_html($pretty); ?></pre>
                            <p class="sentinel-journal-id">
                                <?php esc_html_e('ID', 'sentinel'); ?> <code><?php echo esc_html($entry['id']); ?></code>
                                &middot;
                                <?php echo esc_html($entry['recorded_at']); ?>
                            </p>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Previous / next links when there is more than one page.
     *
     * @param array{page: int, pages: int, channel: string} $data
     */
    private static function renderPager(array $data): void
    {
        if ($data['pages'] <= 1) {
            return;
        }

        ?>
        <div class="sentinel-journal-pager">
            <?php if ($data['page'] > 1) : ?>
                <a class="button" href="<?php echo esc_url(self::pageUrl($data['channel'], $data['page'] - 1)); ?>">&lsaquo; <?php esc_html_e('Newer', 'sentinel'); ?></a>
            <?php endif; ?>
            <span class="sentinel-journal-page">
                <?php
                /* translators: 1: current page, 2: number of pages */
                echo esc_html(sprintf(__('Page %1$d of %2$d', 'sentinel'), $data['page'], $data['pages']));
                ?>
            </span>
            <?php if ($data['page'] < $data['pages']) : ?>
                <a class="button" href="<?php echo esc_url(self::pageUrl($data['channel'], $data['page'] + 1)); ?>"><?php esc_html_e('Older', 'sentinel'); ?> &rsaquo;</a>
            <?php endif; ?>
        </div>
        <?php
    }
}
