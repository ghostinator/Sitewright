<?php
/**
 * Plugin Name: Sitewright Agent
 * Description: Lets a Sitewright server (which calls this site; this site never calls out) read the 404 log and push redirect rules that this plugin enforces locally.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * License: MIT
 *
 * Install as a normal plugin or drop into wp-content/mu-plugins/. Secret: define('SITEWRIGHT_SECRET', '...') in wp-config.php (preferred)
 * or paste it under Settings > Sitewright. Redirect rules are cached in the database and enforced without contacting Sitewright, so the
 * site keeps working if Sitewright is offline. Stored per 404: path (no query string), referer origin+path, hit counter, last-seen time.
 * No IP addresses and no user agents are stored.
 */
if (!defined('ABSPATH')) { exit; }

final class Sitewright_Agent
{
    const VERSION = '1.0.0';
    const MAX_SKEW = 300;          // seconds a signature stays valid
    const MAX_RULES = 5000;
    const MAX_404_ROWS = 5000;

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
            'rules_version' => (string)get_option('sitewright_rules_version', ''), 'rules_count' => is_array($rules) ? count($rules) : 0, 'time' => time()];
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
            $s = preg_replace('/[^a-f0-9]/', '', strtolower((string)wp_unslash($_POST['sitewright_secret'])));
            update_option('sitewright_secret', $s, false);
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $rules = get_option('sitewright_rules', []);
        echo '<div class="wrap"><h1>Sitewright</h1>';
        echo '<p>Secret: <strong>' . (self::secret() !== '' ? 'set' : 'NOT set') . '</strong>' . ($fromConst ? ' (from wp-config.php)' : '') . '. Rules enforced: <strong>' . (is_array($rules) ? count($rules) : 0) . '</strong>. Version ' . esc_html(self::VERSION) . '.</p>';
        if (!$fromConst) {
            echo '<form method="post">'; wp_nonce_field('sitewright_save');
            echo '<p><label>Secret (64 hex characters from Sitewright)<br><input type="password" name="sitewright_secret" class="regular-text" autocomplete="off"></label></p>';
            submit_button('Save'); echo '</form>';
        }
        echo '</div>';
    }
}

Sitewright_Agent::boot();
