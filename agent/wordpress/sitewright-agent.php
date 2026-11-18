<?php
/**
 * Plugin Name: Sitewright Agent
 * Description: Lets a Sitewright server (which calls this site; this site never calls out) read the 404 log, push redirect rules that this plugin enforces locally, and read only the extra details the site owner approves under Settings > Sitewright.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * License: MIT
 *
 * Install as a normal plugin or drop into wp-content/mu-plugins/. Secret: define('SITEWRIGHT_SECRET', '...') in wp-config.php (preferred)
 * or paste it under Settings > Sitewright. Redirect rules are cached in the database and enforced without contacting Sitewright, so the
 * site keeps working if Sitewright is offline. Stored per 404: path (no query string), referer origin+path, hit counter, last-seen time.
 * No IP addresses and no user agents are stored.
 * Extra details (site info, page list, search titles, existing redirects, store address, media counts, version counts) are OFF until the owner ticks
 * each one under Settings > Sitewright. Never offered: users, e-mail addresses, customers, orders, page text, comments, plugin names.
 */
if (!defined('ABSPATH')) { exit; }

final class Sitewright_Agent
{
    const VERSION = '1.1.0';
    const MAX_SKEW = 300;          // seconds a signature stays valid
    const MAX_RULES = 5000;
    const MAX_404_ROWS = 5000;
    const MAX_ITEMS = 200;         // rows per page of a list route
    const MAX_REDIRECTS = 1000;

    /** Every optional data type: label and the plain-words list of exactly what is sent. All are off until the owner turns them on. */
    const CONSENT = [
        'site' => ['Site details', 'Site title, tagline, language and time zone.'],
        'content' => ['Page list', 'For each public page and post: its address, title and when it last changed. No text, authors or comments.'],
        'seo' => ['Search titles and descriptions', 'The search title, description and "noindex" flag that Yoast or Rank Math holds for each page.'],
        'redirects' => ['Existing redirects', 'The redirects already set up in the Redirection or Rank Math plugin (from, to, type).'],
        'store' => ['Shop address', 'The shop address saved in WooCommerce settings. No customers, orders or products.'],
        'media' => ['Media library counts', 'How many images there are and how many have no alt text. Counts only; no files or file names.'],
        'versions' => ['Version and update counts', 'WordPress and PHP versions, and how many updates are waiting. Counts only; no plugin or theme names.'],
    ];

    public static function boot(): void
    {
        add_action('init', [self::class, 'enforce_redirects'], 1);
        add_action('template_redirect', [self::class, 'log_404'], 99);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('sitewright_cleanup', [self::class, 'cleanup']);
        if (!wp_next_scheduled('sitewright_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'sitewright_cleanup'); }
        self::maybe_install();
    }

    // ------------------------------------------------------------------ storage

    private static function table(): string { global $wpdb; return $wpdb->prefix . 'sitewright_404'; }
    private static function seq_table(): string { global $wpdb; return $wpdb->prefix . 'sitewright_seq'; }

    private static function maybe_install(): void
    {
        if (get_option('sitewright_db_version') === self::VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uri_hash CHAR(40) NOT NULL,
            uri VARCHAR(1000) NOT NULL,
            referer VARCHAR(1000) NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL,
            seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uri_hash (uri_hash),
            KEY seq (seq)
        ) $c;");
        dbDelta("CREATE TABLE " . self::seq_table() . " (id TINYINT UNSIGNED NOT NULL, n BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY  (id)) $c;");
        $wpdb->query("INSERT IGNORE INTO " . self::seq_table() . " (id, n) VALUES (1, 0)");
        update_option('sitewright_db_version', self::VERSION, false);
    }

    private static function next_seq(): int
    {
        global $wpdb;
        $wpdb->query("UPDATE " . self::seq_table() . " SET n = LAST_INSERT_ID(n + 1) WHERE id = 1");
        return (int)$wpdb->get_var("SELECT LAST_INSERT_ID()");
    }

    /** A pasted secret: surrounding spaces and quotes are ignored; anything that is not exactly 64 hex characters is refused (null), never silently shortened. */
    public static function normalise_secret(string $raw): ?string
    {
        $s = strtolower(trim($raw, " \t\n\r\0\x0B\"'"));
        return preg_match('/^[a-f0-9]{64}$/D', $s) === 1 ? $s : null;
    }

    private static function secret(): string
    {
        if (defined('SITEWRIGHT_SECRET') && is_string(SITEWRIGHT_SECRET) && SITEWRIGHT_SECRET !== '') { return SITEWRIGHT_SECRET; }
        return (string)get_option('sitewright_secret', '');
    }

    // ------------------------------------------------------------------ 404 capture

    public static function log_404(): void
    {
        if (!is_404() || is_admin() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { return; }
        $uri = self::normalize_request_path();
        if ($uri === '' || strlen($uri) > 1000) { return; }
        $ref = '';
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $p = wp_parse_url((string)$_SERVER['HTTP_REFERER']);
            if (!empty($p['scheme']) && !empty($p['host']) && in_array($p['scheme'], ['http', 'https'], true)) {
                $ref = substr($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? ''), 0, 1000);
            }
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (uri_hash, uri, referer, hits, last_seen, seq) VALUES (%s, %s, %s, 1, UTC_TIMESTAMP(), %d)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = UTC_TIMESTAMP(), seq = VALUES(seq), referer = IF(VALUES(referer) = '', referer, VALUES(referer))",
            sha1($uri), $uri, $ref, self::next_seq()
        ));
        if (wp_rand(1, 200) === 1) { self::cleanup(); }
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $t = self::table();
        $wpdb->query("DELETE FROM $t WHERE last_seen < (UTC_TIMESTAMP() - INTERVAL 60 DAY)");
        $over = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t") - self::MAX_404_ROWS;
        if ($over > 0) { $wpdb->query("DELETE FROM $t ORDER BY last_seen ASC LIMIT " . (int)($over + 500)); }
    }

    /** Request path relative to the site root, query string removed, percent-decoding applied once. */
    private static function normalize_request_path(): string
    {
        $path = (string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $home = rtrim((string)wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home !== '' && strpos($path, $home) === 0) { $path = substr($path, strlen($home)); }
        $path = rawurldecode($path);
        $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path);
        return $path === '' ? '/' : $path;
    }

    // ------------------------------------------------------------------ redirect enforcement

    public static function enforce_redirects(): void
    {
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || isset($_GET['rest_route'])) { return; }
        $rules = get_option('sitewright_rules', []);
        if (!$rules || !is_array($rules)) { return; }
        $path = self::normalize_request_path();
        if (strpos($path, '/wp-') === 0) { return; }
        $key = $path;
        if (!isset($rules[$key])) { $key = substr($path, -1) === '/' && $path !== '/' ? rtrim($path, '/') : $path . '/'; }
        if (!isset($rules[$key])) { return; }
        [$target, $type] = $rules[$key];
        if ($type === 410 || $type === 451) {
            status_header($type);
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo $type === 410 ? 'Gone' : 'Unavailable For Legal Reasons';
            exit;
        }
        if ($target === '' || self::same_path($target, $path)) { return; }          // never redirect to itself
        if ($target[0] === '/') {
            $q = (string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $target = home_url($target) . ($q !== '' && strpos($target, '?') === false ? '?' . $q : '');
        }
        wp_redirect($target, $type, 'Sitewright');
        exit;
    }

    private static function same_path(string $target, string $path): bool
    {
        $t = $target[0] === '/' ? $target : (string)wp_parse_url($target, PHP_URL_PATH);
        return rtrim($t, '/') === rtrim($path, '/') && ($target[0] === '/' || wp_parse_url($target, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST));
    }

    // ------------------------------------------------------------------ REST (called by Sitewright)

    public static function register_routes(): void
    {
        $perm = [self::class, 'authorize'];
        register_rest_route('sitewright/v1', '/status', ['methods' => 'GET', 'callback' => [self::class, 'route_status'], 'permission_callback' => $perm]);
        register_rest_route('sitewright/v1', '/404s', ['methods' => 'GET', 'callback' => [self::class, 'route_404s'], 'permission_callback' => $perm]);
        register_rest_route('sitewright/v1', '/redirects', ['methods' => 'POST', 'callback' => [self::class, 'route_redirects'], 'permission_callback' => $perm]);
        foreach (array_keys(self::CONSENT) as $type) {
            // 'redirects' is the data type; its route is /existing-redirects because POST /redirects already pushes rules the other way
            $path = $type === 'redirects' ? 'existing-redirects' : $type;
            $cb = $type === 'redirects' ? 'route_redirects_list' : 'route_' . $type;
            register_rest_route('sitewright/v1', '/' . $path, ['methods' => 'GET', 'callback' => [self::class, $cb], 'permission_callback' => $perm]);
        }
    }

    /** HMAC-SHA256(secret, METHOD \n route \n t \n nonce \n sha256(body)); time window; each signature usable once. */
    public static function authorize(WP_REST_Request $req)
    {
        $secret = self::secret();
        if ($secret === '') { return new WP_Error('sitewright_unconfigured', 'Secret not set', ['status' => 403]); }
        $h = (string)$req->get_header('x-sitewright-signature');
        if (!preg_match('/^t=(\d{9,12}),n=([a-f0-9]{16,32}),s=([a-f0-9]{64})$/D', $h, $m)) { return new WP_Error('sitewright_denied', 'Bad signature', ['status' => 401]); }
        $t = (int)$m[1];
        if (abs(time() - $t) > self::MAX_SKEW) { return new WP_Error('sitewright_denied', 'Stale signature', ['status' => 401]); }
        $expect = hash_hmac('sha256', strtoupper($req->get_method()) . "\n" . $req->get_route() . "\n" . $t . "\n" . $m[2] . "\n" . hash('sha256', (string)$req->get_body()), $secret);
        if (!hash_equals($expect, $m[3])) { return new WP_Error('sitewright_denied', 'Bad signature', ['status' => 401]); }
        $nonce = 'sitewright_sig_' . substr($m[3], 0, 40);
        if (get_transient($nonce) !== false) { return new WP_Error('sitewright_denied', 'Replay', ['status' => 401]); }
        set_transient($nonce, 1, self::MAX_SKEW * 2);
        return true;
    }

    public static function route_status(WP_REST_Request $req): array
    {
        $rules = get_option('sitewright_rules', []);
        return ['plugin_version' => self::VERSION, 'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION,
            'rules_version' => (string)get_option('sitewright_rules_version', ''), 'rules_count' => is_array($rules) ? count($rules) : 0, 'time' => time(),
            'features' => self::consent()];
    }

    public static function route_404s(WP_REST_Request $req): array
    {
        global $wpdb;
        $since = max(0, (int)$req->get_param('since_seq'));
        $limit = max(1, min(500, (int)($req->get_param('limit') ?: 500)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT seq, uri, referer, hits, last_seen FROM " . self::table() . " WHERE seq > %d ORDER BY seq ASC LIMIT %d", $since, $limit), ARRAY_A) ?: [];
        $items = array_map(static function (array $r): array {
            return ['seq' => (int)$r['seq'], 'uri' => $r['uri'], 'referer' => $r['referer'], 'hits' => (int)$r['hits'], 'last_seen' => $r['last_seen'] . ' UTC'];
        }, $rows);
        return ['items' => $items, 'max_seq' => $items ? $items[count($items) - 1]['seq'] : $since];
    }

    public static function route_redirects(WP_REST_Request $req)
    {
        $p = $req->get_json_params();
        $in = is_array($p['rules'] ?? null) ? $p['rules'] : null;
        $version = is_string($p['version'] ?? null) ? substr($p['version'], 0, 64) : '';
        if ($in === null || count($in) > self::MAX_RULES) { return new WP_Error('sitewright_bad_rules', 'rules must be a list of at most ' . self::MAX_RULES, ['status' => 400]); }
        $rules = [];
        foreach ($in as $r) {
            $src = is_array($r) && is_string($r['source'] ?? null) ? $r['source'] : '';
            $dst = is_array($r) && is_string($r['target'] ?? null) ? $r['target'] : '';
            $type = is_array($r) ? (int)($r['type'] ?? 0) : 0;
            if ($src === '' || $src[0] !== '/' || strlen($src) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $src . $dst)) { continue; }
            if (!in_array($type, [301, 302, 307, 308, 410, 451], true)) { continue; }
            if ($type < 400) {
                $okTarget = ($dst !== '' && $dst[0] === '/' && substr($dst, 0, 2) !== '//') || (bool)preg_match('#^https?://[^\s/]+#i', $dst);
                if (!$okTarget || strlen($dst) > 1000) { continue; }
            } else { $dst = ''; }
            $rules[$src] = [$dst, $type];
        }
        update_option('sitewright_rules', $rules, true);
        update_option('sitewright_rules_version', $version, false);
        return ['accepted' => count($rules), 'version' => $version];
    }

    // ------------------------------------------------------------------ owner consent and the optional routes

    /** @return array<string,bool> every data type; true only when the owner ticked it (anything else, including a missing option, is off). */
    public static function consent(): array
    {
        $saved = get_option('sitewright_consent', []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (array_keys(self::CONSENT) as $type) { $out[$type] = ($saved[$type] ?? false) === true; }
        return $out;
    }

    private static function gate(string $type): ?WP_Error
    {
        return self::consent()[$type] ? null : new WP_Error('sitewright_not_approved', 'The site owner has not approved sharing this', ['status' => 403]);
    }

    /** Path (and query, for plain permalinks) relative to the site root. */
    private static function rel_path(string $url): string
    {
        $p = wp_parse_url($url);
        $path = (string)($p['path'] ?? '/');
        $home = rtrim((string)wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home !== '' && strpos($path, $home) === 0) { $path = substr($path, strlen($home)); }
        return ($path === '' ? '/' : $path) . (!empty($p['query']) ? '?' . $p['query'] : '');
    }

    private static function text($v, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string)$v)) ?? ''), 0, $max);
    }

    public static function route_site(WP_REST_Request $req)
    {
        if ($e = self::gate('site')) { return $e; }
        return ['title' => self::text(get_bloginfo('name'), 200), 'tagline' => self::text(get_bloginfo('description'), 300), 'language' => get_bloginfo('language'),
            'timezone' => wp_timezone_string(), 'url' => home_url('/')];
    }

    /** @return array{0:array<int,array>,1:?int} one page of public pages and posts (ID order, cursor = last ID of the previous page); no attachments and no password-protected pages */
    private static function page_of_posts(WP_REST_Request $req): array
    {
        global $wpdb;
        $cursor = max(0, (int)$req->get_param('cursor'));
        $limit = max(1, min(self::MAX_ITEMS, (int)($req->get_param('limit') ?: self::MAX_ITEMS)));
        $types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));
        if (!$types) { return [[], null]; }
        $in = implode(',', array_fill(0, count($types), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_title, post_modified_gmt FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d",
            array_merge([$cursor], $types, [$limit + 1])
        ), ARRAY_A) ?: [];
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [$rows, $more && $rows ? (int)end($rows)['ID'] : null];
    }

    public static function route_content(WP_REST_Request $req)
    {
        if ($e = self::gate('content')) { return $e; }
        [$rows, $next] = self::page_of_posts($req);
        $items = [];
        foreach ($rows as $r) {
            $link = get_permalink((int)$r['ID']);
            if (!$link) { continue; }
            $items[] = ['id' => (int)$r['ID'], 'type' => (string)$r['post_type'], 'path' => self::rel_path($link), 'title' => self::text($r['post_title'], 300), 'modified' => (string)$r['post_modified_gmt'] . ' UTC'];
        }
        return ['items' => $items, 'next' => $next];
    }

    public static function route_seo(WP_REST_Request $req)
    {
        if ($e = self::gate('seo')) { return $e; }
        $plugin = defined('WPSEO_VERSION') ? 'yoast' : (defined('RANK_MATH_VERSION') ? 'rank_math' : 'none');
        if ($plugin === 'none') { return ['plugin' => 'none', 'items' => [], 'next' => null]; }
        [$rows, $next] = self::page_of_posts($req);
        // a value that still holds a template variable (%%title%%, %title%) is not what search engines show; it is left out and counted
        $plain = static function ($v) { $v = self::text($v, 500); return $v === '' || preg_match('/%%?[a-z_]+%%?/i', $v) ? null : $v; };
        $items = []; $templated = 0;
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            if ($plugin === 'yoast') {
                $title = get_post_meta($id, '_yoast_wpseo_title', true); $desc = get_post_meta($id, '_yoast_wpseo_metadesc', true);
                $noindex = get_post_meta($id, '_yoast_wpseo_meta-robots-noindex', true) === '1';
            } else {
                $title = get_post_meta($id, 'rank_math_title', true); $desc = get_post_meta($id, 'rank_math_description', true);
                $robots = get_post_meta($id, 'rank_math_robots', true); $noindex = is_array($robots) && in_array('noindex', $robots, true);
            }
            $t = $plain($title); $d = $plain($desc);
            if (($t === null && self::text($title, 5) !== '') || ($d === null && self::text($desc, 5) !== '')) { $templated++; }
            if ($t === null && $d === null && !$noindex) { continue; }
            $link = get_permalink($id);
            if ($link) { $items[] = ['id' => $id, 'path' => self::rel_path($link), 'seo_title' => $t, 'seo_description' => $d, 'noindex' => $noindex]; }
        }
        return ['plugin' => $plugin, 'items' => $items, 'next' => $next, 'templated' => $templated];
    }

    public static function route_redirects_existing(): array
    {
        global $wpdb;
        $out = [];
        $has = static fn (string $t): bool => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t;
        $t = $wpdb->prefix . 'redirection_items';
        if ($has($t)) {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT url, action_data, action_code FROM $t WHERE status = 'enabled' AND regex = 0 AND action_type = 'url' AND match_type = 'url' ORDER BY id LIMIT %d", self::MAX_REDIRECTS), ARRAY_A) ?: [] as $r) {
                $out[] = ['source' => (string)$r['url'], 'target' => (string)$r['action_data'], 'type' => (int)$r['action_code'], 'from' => 'redirection'];
            }
        }
        $t = $wpdb->prefix . 'rank_math_redirections';
        if ($has($t) && count($out) < self::MAX_REDIRECTS) {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT sources, url_to, header_code FROM $t WHERE status = 'active' ORDER BY id LIMIT %d", self::MAX_REDIRECTS), ARRAY_A) ?: [] as $r) {
                $src = maybe_unserialize($r['sources']);
                foreach (is_array($src) ? $src : [] as $one) {
                    if (is_array($one) && ($one['comparison'] ?? '') === 'exact' && is_string($one['pattern'] ?? null)) {
                        $out[] = ['source' => '/' . ltrim($one['pattern'], '/'), 'target' => (string)$r['url_to'], 'type' => (int)$r['header_code'], 'from' => 'rank_math'];
                    }
                }
            }
        }
        return array_slice($out, 0, self::MAX_REDIRECTS);
    }

    /** GET /existing-redirects (the POST /redirects route pushes rules the other way). */
    public static function route_redirects_list(WP_REST_Request $req)
    {
        if ($e = self::gate('redirects')) { return $e; }
        return ['items' => self::route_redirects_existing()];
    }

    public static function route_store(WP_REST_Request $req)
    {
        if ($e = self::gate('store')) { return $e; }
        if (!class_exists('WooCommerce') && get_option('woocommerce_store_address', null) === null) { return ['available' => false]; }
        $country = explode(':', (string)get_option('woocommerce_default_country', ''), 2);
        return ['available' => true, 'street' => self::text(get_option('woocommerce_store_address', ''), 200), 'street2' => self::text(get_option('woocommerce_store_address_2', ''), 200),
            'city' => self::text(get_option('woocommerce_store_city', ''), 100), 'postcode' => self::text(get_option('woocommerce_store_postcode', ''), 20),
            'country' => self::text($country[0], 2), 'region' => self::text($country[1] ?? '', 40)];
    }

    public static function route_media(WP_REST_Request $req)
    {
        if ($e = self::gate('media')) { return $e; }
        global $wpdb;
        $images = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'");
        $noAlt = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_value IS NULL OR m.meta_value = '')");
        return ['images' => $images, 'images_without_alt' => $noAlt];
    }

    public static function route_versions(WP_REST_Request $req)
    {
        if ($e = self::gate('versions')) { return $e; }
        $core = get_site_transient('update_core'); $pl = get_site_transient('update_plugins'); $th = get_site_transient('update_themes');
        $coreUpdate = false;
        foreach (is_object($core) && is_array($core->updates ?? null) ? $core->updates : [] as $u) { if (is_object($u) && ($u->response ?? '') === 'upgrade') { $coreUpdate = true; } }
        $checked = 0;
        foreach ([$core, $pl, $th] as $o) { if (is_object($o) && !empty($o->last_checked)) { $checked = max($checked, (int)$o->last_checked); } }
        return ['wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'core_update' => $coreUpdate,
            'plugin_updates' => is_object($pl) && is_array($pl->response ?? null) ? count($pl->response) : 0,
            'theme_updates' => is_object($th) && is_array($th->response ?? null) ? count($th->response) : 0,
            'active_plugins' => count((array)get_option('active_plugins', [])), 'checked_at' => $checked];
    }

    // ------------------------------------------------------------------ settings page

    public static function admin_menu(): void
    {
        add_options_page('Sitewright', 'Sitewright', 'manage_options', 'sitewright', [self::class, 'settings_page']);
    }

    public static function settings_page(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $fromConst = defined('SITEWRIGHT_SECRET') && SITEWRIGHT_SECRET !== '';
        if (isset($_POST['sitewright_secret']) && check_admin_referer('sitewright_save') && !$fromConst) {
            $s = self::normalise_secret((string)wp_unslash($_POST['sitewright_secret']));
            if ($s === null) {
                echo '<div class="notice notice-error"><p>Not saved. The secret must be exactly 64 characters, 0-9 and a-f, as shown once by Sitewright (Sites, New secret). Check that your browser did not fill in a saved password.</p></div>';
            } else {
                update_option('sitewright_secret', $s, false);
                echo '<div class="updated"><p>Saved.</p></div>';
            }
        }
        if (isset($_POST['sitewright_consent_form']) && check_admin_referer('sitewright_consent_save')) {
            $ticked = isset($_POST['sitewright_consent']) && is_array($_POST['sitewright_consent']) ? array_map('strval', wp_unslash($_POST['sitewright_consent'])) : [];
            $new = [];
            foreach (array_keys(self::CONSENT) as $type) { $new[$type] = in_array($type, $ticked, true); }
            update_option('sitewright_consent', $new, false);
            echo '<div class="updated"><p>Saved. Sitewright can read only what is ticked.</p></div>';
        }
        $rules = get_option('sitewright_rules', []);
        echo '<div class="wrap"><h1>Sitewright</h1>';
        echo '<p>Secret: <strong>' . (self::secret() !== '' ? 'set' : 'NOT set') . '</strong>' . ($fromConst ? ' (from wp-config.php)' : '') . '. Rules enforced: <strong>' . (is_array($rules) ? count($rules) : 0) . '</strong>. Version ' . esc_html(self::VERSION) . '.</p>';
        if (!$fromConst) {
            echo '<form method="post">'; wp_nonce_field('sitewright_save');
            echo '<p><label>Secret (64 hex characters from Sitewright)<br><input type="password" name="sitewright_secret" class="regular-text" autocomplete="new-password" spellcheck="false"></label></p>';
            submit_button('Save'); echo '</form>';
        }
        $on = self::consent();
        echo '<h2>What Sitewright may read</h2><p>Sitewright always reads the list of missing pages (404s) and sends you the redirects to apply. Everything below is <strong>off</strong> until you tick it. Nothing else is ever offered: no users, e-mail addresses, customers, orders, page text, comments or plugin names.</p>';
        echo '<form method="post">'; wp_nonce_field('sitewright_consent_save'); echo '<input type="hidden" name="sitewright_consent_form" value="1">';
        foreach (self::CONSENT as $type => [$label, $what]) {
            echo '<p><label><input type="checkbox" name="sitewright_consent[]" value="' . esc_attr($type) . '"' . checked($on[$type], true, false) . '> <strong>' . esc_html($label) . '</strong></label><br><span class="description">' . esc_html($what) . '</span></p>';
        }
        submit_button('Save choices'); echo '</form>';
        echo '</div>';
    }
}

Sitewright_Agent::boot();
