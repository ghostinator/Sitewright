<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * The single source of truth for settings that can be changed from the Settings page.
 *
 * Precedence: environment variable > saved setting (table `settings`) > config file > built-in default.
 * Defaults are NEVER repeated here: they are read from Config::defaults() by key path. Keys that cannot be edited from the UI
 * (they weaken the request guard, are needed to boot, or must be set on the server) are listed in readonlyKeys().
 * Secrets are stored encrypted (Crypto, context "setting:<key>") and are never returned by effective().
 */
final class Settings
{
    /** @var array<string,array>|null */
    private static ?array $registry = null;

    public const GROUPS = [
        'mail' => ['Mail & alerts', 'How Sitewright sends e-mail and who it alerts.'],
        'security' => ['Security', 'Sign-in limits, session lengths and rate limits.'],
        'integrations' => ['Integrations', 'Gemini, Google, geocoding and what the server may fetch.'],
        'crawl' => ['Crawling & jobs', 'How much a crawl or image batch may do, and how often sites sync.'],
        'data' => ['Data & logging', 'How long records are kept and how much is logged.'],
        'seo' => ['SEO scoring', 'What counts as a good title, description, speed and content, and how many points each check is worth. The score is the sum of the points, capped at 100.'],
    ];

    /** Keys shown read-only: file/environment only. */
    public static function readonlyKeys(): array
    {
        return ['db.host', 'db.port', 'db.name', 'db.user', 'db.pass', 'db.prefix', 'app_key', 'base_url', 'allowed_hosts', 'trusted_proxies', 'debug', 'hsts',
            'fetch.allow_ports', 'fetch.dev_allow_cidrs', 'gemini.endpoint', 'session.secure', 'session.name', 'limits.body_bytes', 'log.file'];
    }

    /** @return array<string,array> key => definition */
    public static function registry(): array
    {
        if (self::$registry !== null) { return self::$registry; }
        $r = [];
        $add = static function (string $key, string $group, string $label, string $help, string $type, array $o = []) use (&$r): void {
            $r[$key] = $o + ['group' => $group, 'label' => $label, 'help' => $help, 'type' => $type, 'default' => self::defaultOf($key),
                'min' => null, 'max' => null, 'enum' => null, 'pattern' => null, 'unit' => '', 'secret' => $type === 'secret', 'env' => null, 'apply' => 'live', 'danger' => false, 'danger_effects' => []];
        };
        // ---- Mail & alerts
        $add('mail.driver', 'mail', 'Mail method', 'Postal HTTP API, or a generic SMTP server.', 'enum', ['enum' => ['smtp', 'postal'], 'env' => 'SW_MAIL_DRIVER']);
        $add('mail.postal_url', 'mail', 'Postal address', 'The https address of your Postal server. Changing it clears the stored API key.', 'url', ['env' => 'SW_MAIL_POSTAL_URL', 'danger' => true, 'danger_effects' => ['Clears the stored Postal API key; enter it again.']]);
        $add('mail.postal_key', 'mail', 'Postal API key', 'The server API key from Postal (Credentials, type API).', 'secret', ['env' => 'SW_MAIL_POSTAL_KEY']);
        $add('mail.host', 'mail', 'SMTP server', 'Only used when the mail method is SMTP. Changing it clears the stored SMTP password.', 'string', ['pattern' => '/^[A-Za-z0-9.-]{0,253}$/D', 'env' => 'SW_MAIL_HOST', 'danger' => true, 'danger_effects' => ['Clears the stored SMTP password; enter it again.']]);
        $add('mail.port', 'mail', 'SMTP port', 'Usually 587 (STARTTLS) or 465 (TLS).', 'int', ['min' => 1, 'max' => 65535, 'env' => 'SW_MAIL_PORT', 'danger' => true, 'danger_effects' => ['Clears the stored SMTP password; enter it again.']]);
        $add('mail.encryption', 'mail', 'SMTP encryption', 'starttls, tls or none.', 'enum', ['enum' => ['starttls', 'tls', 'none'], 'env' => 'SW_MAIL_ENCRYPTION', 'danger' => true, 'danger_effects' => ['Clears the stored SMTP password; enter it again.']]);
        $add('mail.username', 'mail', 'SMTP username', 'Login for the SMTP server (blank if it needs none).', 'string', ['env' => 'SW_MAIL_USERNAME']);
        $add('mail.password', 'mail', 'SMTP password', 'Stored encrypted; never shown again.', 'secret', ['env' => 'SW_MAIL_PASSWORD']);
        $add('mail.verify_tls', 'mail', 'Check the mail server certificate', 'Leave on unless your SMTP server uses a certificate you cannot install.', 'bool', ['env' => 'SW_MAIL_VERIFY_TLS']);
        $add('mail.from', 'mail', 'From address', 'Must be on a domain verified in Postal / allowed by your SMTP server.', 'email', ['env' => 'SW_MAIL_FROM']);
        $add('mail.from_name', 'mail', 'From name', 'The sender name recipients see.', 'string', ['max' => 100, 'env' => 'SW_MAIL_FROM_NAME']);
        $add('mail.timeout', 'mail', 'Mail timeout', 'How long to wait for the mail server.', 'int', ['min' => 1, 'max' => 60, 'unit' => 'seconds']);
        $add('alerts.email_to', 'mail', 'Send alerts to', 'E-mail addresses that receive alerts (needs mail set up).', 'email_list', ['env' => 'SW_ALERT_EMAIL_TO']);
        $add('alerts.teams_webhook', 'mail', 'Teams webhook', 'Microsoft Teams Workflows webhook address (not tested yet).', 'secret', ['env' => 'SW_ALERT_TEAMS_WEBHOOK']);
        $add('alerts.telegram_token', 'mail', 'Telegram bot token', 'Not tested yet.', 'secret', ['env' => 'SW_ALERT_TELEGRAM_TOKEN']);
        $add('alerts.telegram_chat_id', 'mail', 'Telegram chat id', 'Not tested yet.', 'string', ['pattern' => '/^-?\d{0,20}$/D', 'env' => 'SW_ALERT_TELEGRAM_CHAT_ID']);
        $add('alerts.cooldown', 'mail', 'Alert cooldown', 'The same alert is sent at most once in this time.', 'int', ['min' => 0, 'max' => 86400, 'unit' => 'seconds']);
        $add('alerts.login_failures', 'mail', 'Failed sign-ins before an alert', 'Alert when this many sign-ins fail within 10 minutes.', 'int', ['min' => 1, 'max' => 1000]);
        // ---- Security
        $add('session.idle_timeout', 'security', 'Session length when idle', 'Sign out after this much inactivity.', 'int', ['min' => 300, 'max' => 86400, 'unit' => 'seconds']);
        $add('session.absolute_timeout', 'security', 'Longest session', 'Sign out after this long, however active.', 'int', ['min' => 900, 'max' => 2592000, 'unit' => 'seconds']);
        $add('security.require_mfa', 'security', 'Require two-step verification for administrators', 'Administrators without it can only reach My Account until they set it up. You must have it on yourself first. Break-glass: SW_REQUIRE_MFA=false on the server.', 'bool', ['env' => 'SW_REQUIRE_MFA', 'danger' => true, 'danger_effects' => ['Other administrators without two-step verification are limited to My Account until they enrol.']]);
        $add('limits.login_attempts', 'security', 'Failed sign-ins per account', 'Wrong passwords allowed per account in the window below.', 'int', ['min' => 1, 'max' => 100]);
        $add('limits.login_ip_attempts', 'security', 'Failed sign-ins per address', 'Wrong passwords allowed from one IP address in the window below.', 'int', ['min' => 1, 'max' => 1000]);
        $add('limits.login_window', 'security', 'Sign-in limit window', 'How long failed sign-ins are counted.', 'int', ['min' => 60, 'max' => 86400, 'unit' => 'seconds']);
        $add('limits.expensive_per_min', 'security', 'Heavy actions per minute', 'Analyses, crawls and AI requests allowed per user per minute.', 'int', ['min' => 1, 'max' => 600]);
        // ---- Integrations
        $add('gemini.api_key', 'integrations', 'Gemini API key', 'Content AI uses templates when this is empty.', 'secret', ['env' => 'SW_GEMINI_API_KEY']);
        $add('gemini.model', 'integrations', 'Gemini model', 'For example gemini-3.6-flash.', 'string', ['pattern' => '/^[A-Za-z0-9._\-]{1,80}$/D', 'env' => 'SW_GEMINI_MODEL']);
        $add('enable_geocoding', 'integrations', 'Look up coordinates for new locations', 'Sends the address to OpenStreetMap Nominatim when a location has no coordinates.', 'bool', ['env' => 'SW_ENABLE_GEOCODING']);
        $add('contact', 'integrations', 'Contact in the crawler User-Agent', 'Shown to the sites Sitewright fetches (a URL or e-mail).', 'string', ['max' => 200, 'env' => 'SW_CONTACT']);
        $add('google.client_id', 'integrations', 'Google OAuth client id', 'For Search Console.', 'string', ['pattern' => '/^[A-Za-z0-9._\-]{0,200}$/D', 'env' => 'SW_GOOGLE_CLIENT_ID']);
        $add('google.client_secret', 'integrations', 'Google OAuth client secret', 'Stored encrypted; never shown again.', 'secret', ['env' => 'SW_GOOGLE_CLIENT_SECRET']);
        $add('google.redirect_uri', 'integrations', 'Google redirect address', 'Must match the authorized redirect URI in Google Cloud. Changing it clears the client secret.', 'url', ['env' => 'SW_GOOGLE_REDIRECT_URI', 'danger' => true, 'danger_effects' => ['Clears the stored Google client secret; enter it again.']]);
        $add('fetch.allow_hosts', 'integrations', 'Only fetch these sites', 'When not empty, Sitewright fetches only these host names. Narrows what is allowed; never widens it.', 'list', ['env' => 'SW_FETCH_ALLOW_HOSTS']);
        $add('fetch.block_hosts', 'integrations', 'Never fetch these sites', 'Host names Sitewright refuses to fetch.', 'list', ['env' => 'SW_FETCH_BLOCK_HOSTS']);
        $add('fetch.connect_timeout', 'integrations', 'Connect timeout', 'How long to wait to open a connection to a site.', 'int', ['min' => 1, 'max' => 30, 'unit' => 'seconds']);
        $add('fetch.timeout', 'integrations', 'Request timeout', 'Total time for one fetch.', 'int', ['min' => 2, 'max' => 60, 'unit' => 'seconds']);
        $add('fetch.dns_timeout', 'integrations', 'Name lookup timeout', 'How long to wait for a host name to resolve.', 'int', ['min' => 1, 'max' => 10, 'unit' => 'seconds']);
        $add('fetch.max_html_bytes', 'integrations', 'Largest page', 'Pages bigger than this are cut off.', 'int', ['min' => 65536, 'max' => 33554432, 'unit' => 'bytes']);
        $add('fetch.max_image_bytes', 'integrations', 'Largest image', 'Images bigger than this are skipped.', 'int', ['min' => 65536, 'max' => 52428800, 'unit' => 'bytes']);
        $add('fetch.max_redirects', 'integrations', 'Redirects followed', 'How many redirects to follow before giving up.', 'int', ['min' => 0, 'max' => 10]);
        // ---- Crawling & jobs
        $add('limits.max_pages', 'crawl', 'Most pages per crawl', 'Upper limit; a crawl can ask for fewer.', 'int', ['min' => 1, 'max' => 5000]);
        $add('limits.crawl_seconds', 'crawl', 'Longest crawl', 'A crawl stops after this long and keeps what it found.', 'int', ['min' => 10, 'max' => 900, 'unit' => 'seconds']);
        $add('limits.crawl_delay_ms', 'crawl', 'Pause between pages', 'Be polite to the site you crawl.', 'int', ['min' => 0, 'max' => 5000, 'unit' => 'milliseconds']);
        $add('limits.bulk_images', 'crawl', 'Most images per analysis', 'Images beyond this are not analysed.', 'int', ['min' => 1, 'max' => 200]);
        $add('limits.import_rows', 'crawl', 'Most rows per import', 'Larger CSV files are refused.', 'int', ['min' => 100, 'max' => 100000]);
        $add('limits.jobs_per_user', 'crawl', 'Background jobs per user', 'Queued plus running jobs one user may have.', 'int', ['min' => 1, 'max' => 50]);
        $add('limits.job_stale_seconds', 'crawl', 'Declare a job stuck after', 'A job whose worker stopped reporting is re-queued after this long.', 'int', ['min' => 60, 'max' => 7200, 'unit' => 'seconds']);
        $add('sites.sync_minutes', 'crawl', 'Site sync interval', 'How often connected WordPress sites are synced.', 'int', ['min' => 1, 'max' => 1440, 'unit' => 'minutes']);
        // ---- Data & logging
        $add('retention_days_404', 'data', 'Keep 404 entries', 'Older entries are deleted automatically.', 'int', ['min' => 1, 'max' => 3650, 'unit' => 'days']);
        $add('retention_days_audit', 'data', 'Keep audit log entries', 'Older entries are deleted automatically.', 'int', ['min' => 1, 'max' => 3650, 'unit' => 'days']);
        $add('limits.job_retention_days', 'data', 'Keep finished jobs', 'Finished and failed jobs older than this are deleted.', 'int', ['min' => 1, 'max' => 365, 'unit' => 'days']);
        $add('log.level', 'data', 'Log detail', 'debug, info, warn or error.', 'enum', ['enum' => ['debug', 'info', 'warn', 'error'], 'env' => 'SW_LOG_LEVEL']);
        $add('log.requests', 'data', 'Log every request', 'One line per request (path without query string).', 'bool');
        // ---- SEO scoring (Phase 2)
        $seo = [
            'title_min' => ['Shortest good title', 'characters', 1, 200, 'A title shorter than this is not judged optimal.'], 'title_max' => ['Longest good title', 'characters', 1, 300, 'A title longer than this is not judged optimal.'],
            'meta_min' => ['Shortest good description', 'characters', 1, 400, 'Meta description lower bound.'], 'meta_max' => ['Longest good description', 'characters', 1, 600, 'Meta description upper bound.'],
            'words_good' => ['Words for a "good" page', 'words', 1, 20000, 'Pages with at least this many words earn the first content points.'], 'words_great' => ['Words for a "great" page', 'words', 1, 50000, 'Pages with at least this many words earn the second content points.'],
            'alt_high' => ['Images with alt text: high', 'percent', 1, 100, 'At or above this share of images with alt text earns the most image points.'], 'alt_mid' => ['Images with alt text: medium', 'percent', 1, 100, 'At or above this share earns the middle image points.'],
            'load_fast' => ['Fast page fetch', 'seconds', 0, 60, 'Faster than this earns the most speed points.'], 'load_ok' => ['Acceptable page fetch', 'seconds', 0, 120, 'Faster than this earns the middle speed points.'],
            'internal_min' => ['Internal links needed', 'links', 0, 1000, 'A page needs MORE than this many internal links to earn the link points.'],
        ];
        foreach ($seo as $k => [$label, $unit, $min, $max, $help]) {
            $r["seo.$k"] = ['group' => 'seo', 'label' => $label, 'help' => $help, 'type' => in_array($k, ['load_fast', 'load_ok'], true) ? 'float' : 'int', 'default' => self::defaultOf("seo.$k"),
                'min' => $min, 'max' => $max, 'enum' => null, 'pattern' => null, 'unit' => $unit, 'secret' => false, 'env' => null, 'apply' => 'live', 'danger' => false, 'danger_effects' => []];
        }
        $weights = ['title_present' => 'Title present', 'title_ok' => 'Title length good', 'meta_present' => 'Description present', 'meta_ok' => 'Description length good', 'h1' => 'Exactly one main heading',
            'alt_high' => 'Alt text on most images', 'alt_mid' => 'Alt text on many images', 'alt_low' => 'Alt text on some images', 'load_fast' => 'Fast page fetch', 'load_ok' => 'Acceptable page fetch',
            'load_slow' => 'Slow page fetch', 'viewport' => 'Mobile viewport tag', 'https' => 'Served over HTTPS', 'schema' => 'Structured data present', 'internal' => 'Enough internal links',
            'external' => 'Has external links', 'words_good' => 'Enough words', 'words_great' => 'Plenty of words'];
        foreach ($weights as $k => $label) {
            $r["seo.w.$k"] = ['group' => 'seo', 'label' => 'Points: ' . $label, 'help' => 'Points added to the score when this check passes.', 'type' => 'int', 'default' => self::defaultOf("seo.w.$k"),
                'min' => 0, 'max' => 100, 'enum' => null, 'pattern' => null, 'unit' => 'points', 'secret' => false, 'env' => null, 'apply' => 'live', 'danger' => false, 'danger_effects' => []];
        }
        return self::$registry = $r;
    }

    /** Extra definitions (Phase 2 and later features) register themselves here before first use. */
    public static function define(string $key, array $def): void
    {
        self::registry();
        self::$registry[$key] = $def;
    }

    public static function def(string $key): ?array
    {
        return self::registry()[$key] ?? null;
    }

    private static function defaultOf(string $key): mixed
    {
        $n = Config::defaults();
        foreach (explode('.', $key) as $p) { if (!is_array($n) || !array_key_exists($p, $n)) { return null; } $n = $n[$p]; }
        return $n;
    }

    /** Validate and normalise one value exactly as its registry type says. @throws \InvalidArgumentException with a message fit for the form */
    public static function coerce(string $key, mixed $raw): mixed
    {
        $d = self::def($key) ?? throw new \InvalidArgumentException("Unknown setting $key");
        $label = $d['label'];
        $bad = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException("$label: $why");
        $text = static function (mixed $v, int $max) use ($bad): string {
            if (!is_string($v)) { throw $bad('must be text'); }
            if (preg_match('/[\x00-\x1F\x7F]/', $v)) { throw $bad('must not contain control characters'); }
            if (mb_strlen($v) > $max) { throw $bad("must be at most $max characters"); }
            return $v;
        };
        switch ($d['type']) {
            case 'bool':
                if (!is_bool($raw)) { throw $bad('must be on or off'); }
                return $raw;
            case 'int':
                if (!is_int($raw)) { throw $bad('must be a whole number'); }
                break;
            case 'float':
                if (!is_int($raw) && !is_float($raw)) { throw $bad('must be a number'); }
                $raw = (float)$raw;
                break;
            case 'enum':
                if (!is_string($raw) || !in_array($raw, $d['enum'], true)) { throw $bad('must be one of ' . implode(', ', $d['enum'])); }
                return $raw;
            case 'string':
                $v = $text($raw, (int)($d['max'] ?? 500));
                if ($d['pattern'] !== null && !preg_match($d['pattern'], $v)) { throw $bad('has an unexpected format'); }
                return trim($v);
            case 'secret':
                $v = $text($raw, 1000);
                if ($v === '') { throw $bad('cannot be empty (use Remove to clear it)'); }
                return $v;
            case 'email':
                $v = $text($raw, 100);
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { throw $bad('must be an e-mail address'); }
                return $v;
            case 'url':
                $v = rtrim($text($raw, 255), '/');
                if ($v !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/%-]*)?$#D', $v)) { throw $bad('must be an https:// address'); }
                return $v;
            case 'list':
            case 'email_list':
                if (!is_array($raw) || ($raw !== [] && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > 200) { throw $bad('must be a list (at most 200 entries)'); }
                $out = [];
                foreach ($raw as $item) {
                    $v = trim($text($item, 200));
                    if ($d['type'] === 'email_list' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { throw $bad("“{$v}” is not an e-mail address"); }
                    if ($v !== '') { $out[] = $v; }
                }
                return array_values(array_unique($out));
            default:
                throw $bad('has an unsupported type');
        }
        if ($d['min'] !== null && $raw < $d['min'] || $d['max'] !== null && $raw > $d['max']) {
            throw $bad("must be between {$d['min']} and {$d['max']}" . ($d['unit'] !== '' ? " {$d['unit']}" : ''));
        }
        return $raw;
    }

    public static function envLocked(string $key): bool
    {
        $e = self::def($key)['env'] ?? null;
        return $e !== null && ($v = getenv($e)) !== false && $v !== '';
    }

    /**
     * Saved values, validated. Anything invalid, unknown, read-only or undecryptable is skipped with a warning, so a bad row can never stop the app.
     * @return array<string,mixed>
     */
    public static function saved(): array
    {
        $db = Database::getInstance();
        $out = [];
        foreach ($db->fetchAll('SELECT skey, svalue, is_secret FROM `' . $db->t('settings') . '`') as $row) {
            $key = (string)$row['skey'];
            try {
                if (self::def($key) === null) { throw new \InvalidArgumentException('unknown or read-only key'); }
                $raw = (int)$row['is_secret'] === 1 ? Crypto::decrypt((string)$row['svalue'], "setting:$key") : json_decode((string)$row['svalue'], true, 16, JSON_THROW_ON_ERROR);
                $out[$key] = self::coerce($key, $raw);
            } catch (\Throwable $e) {
                Logger::warn('setting_ignored', ['setting' => $key, 'reason' => get_class($e)]);
            }
        }
        return $out;
    }

    /** @return array{value:mixed,source:string,locked:bool,fallback:string} the secret's value is never returned: value is ['set'=>bool] */
    public static function effective(string $key): array
    {
        $d = self::def($key) ?? throw new \InvalidArgumentException("Unknown setting $key");
        $locked = self::envLocked($key);
        $savedKeys = Config::savedKeys();
        $source = $locked ? 'env' : (in_array($key, $savedKeys, true) ? 'saved' : (Config::fileHas($key) ? 'file' : 'default'));
        $value = Config::get($key);
        if ($d['secret']) { $value = ['set' => $value !== null && $value !== '']; }
        $fallback = Config::fileHas($key)
            ? 'config file value ' . ($d['secret'] ? '(set)' : json_encode(Config::fileValue($key), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : 'built-in default ' . ($d['secret'] ? '(none)' : json_encode($d['default'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['value' => $value, 'source' => $source, 'locked' => $locked, 'fallback' => $fallback];
    }

    // ------------------------------------------------------------------ saving

    /** Saving these changes also removes the stored secret(s) of the same integration (unless a new one arrives together). */
    private const DANGER_CLEARS = [
        'mail.postal_url' => ['mail.postal_key'], 'mail.host' => ['mail.password'], 'mail.port' => ['mail.password'],
        'mail.encryption' => ['mail.password'], 'google.redirect_uri' => ['google.client_secret'],
    ];

    /**
     * Validate a batch without writing anything.
     * @param array<string,mixed> $changes
     * @return array{status:int,message:string,errors:array<string,mixed>,values:array<string,mixed>,effects:string[],clears:string[],secrets:string[]}
     *         status 200 means "ready to commit"
     */
    public static function prepare(array $changes, bool $confirm): array
    {
        $fail = static fn (int $status, string $message, array $errors = []): array => ['status' => $status, 'message' => $message, 'errors' => $errors, 'values' => [], 'effects' => [], 'clears' => [], 'secrets' => []];
        if ($changes === []) { return $fail(400, 'Nothing to save'); }
        $errors = []; $locked = []; $values = [];
        foreach ($changes as $key => $raw) {
            $key = (string)$key;
            if (self::def($key) === null) { $errors[$key] = 'Unknown or read-only setting'; continue; }
            if (self::envLocked($key)) { $locked[] = $key . ' (set by ' . self::def($key)['env'] . ' on the server)'; continue; }
            try { $values[$key] = self::coerce($key, $raw); } catch (\InvalidArgumentException $e) { $errors[$key] = $e->getMessage(); }
        }
        if ($errors) { return $fail(400, 'Some values are not valid; nothing was saved.', $errors); }
        if ($locked) { return $fail(409, 'These settings are locked by the server: ' . implode(', ', $locked) . '. Change them there.'); }
        $secrets = array_keys(array_filter($values, static fn ($v, $k) => self::def($k)['secret'], ARRAY_FILTER_USE_BOTH));
        if ($secrets && !Crypto::configured()) { return $fail(409, 'Secrets can only be saved after app_key is set (php bin/gen-key.php, then app_key in the config file).'); }
        // drop no-ops (non-secrets equal to the current value) so they are neither written nor audited
        foreach ($values as $k => $v) { if (!self::def($k)['secret'] && Config::get($k) === $v) { unset($values[$k]); } }
        // cross-field rules, evaluated on the values that WOULD be in effect
        $now = static fn (string $k): mixed => array_key_exists($k, $values) ? $values[$k] : Config::get($k);
        $xerr = [];
        if ($now('session.idle_timeout') > $now('session.absolute_timeout')) { $xerr[array_key_exists('session.absolute_timeout', $values) ? 'session.absolute_timeout' : 'session.idle_timeout'] = 'The idle timeout cannot be longer than the longest session.'; }
        if ($now('fetch.connect_timeout') > $now('fetch.timeout')) { $xerr[array_key_exists('fetch.timeout', $values) ? 'fetch.timeout' : 'fetch.connect_timeout'] = 'The connect timeout cannot be longer than the request timeout.'; }
        if ($now('alerts.email_to') !== [] && !self::mailReady($now)) { $xerr['alerts.email_to'] = 'Set up mail first: alert e-mails need a working mail method and a From address.'; }
        foreach ([['seo.title_min', 'seo.title_max', 'The shortest good title cannot be longer than the longest.'], ['seo.meta_min', 'seo.meta_max', 'The shortest good description cannot be longer than the longest.'],
                  ['seo.words_good', 'seo.words_great', 'The "good" word count cannot be above the "great" one.'], ['seo.alt_mid', 'seo.alt_high', 'The medium alt-text share cannot be above the high one.'],
                  ['seo.load_fast', 'seo.load_ok', 'The fast limit cannot be slower than the acceptable limit.']] as [$lo, $hi, $why]) {
            if ($now($lo) > $now($hi)) { $xerr[array_key_exists($hi, $values) ? $hi : $lo] = $why; }
        }
        if ($xerr) { return $fail(400, 'Some values conflict; nothing was saved.', $xerr); }
        $effects = []; $clears = [];
        foreach (array_keys($values) as $k) {
            if (!self::def($k)['danger']) { continue; }
            foreach (self::def($k)['danger_effects'] as $e) { $effects[] = $e; }
            foreach (self::DANGER_CLEARS[$k] ?? [] as $c) { if (!array_key_exists($c, $values)) { $clears[] = $c; } }
        }
        $effects = array_values(array_unique($effects)); $clears = array_values(array_unique($clears));
        if ($effects && !$confirm) { return $fail(409, 'This change has side effects and needs confirmation.', ['code' => 'confirm_required', 'effects' => $effects]); }
        return ['status' => 200, 'message' => 'ok', 'errors' => [], 'values' => $values, 'effects' => $effects, 'clears' => $clears, 'secrets' => $secrets];
    }

    private static function mailReady(callable $now): bool
    {
        if ((string)$now('mail.from') === '') { return false; }
        return $now('mail.driver') === 'postal' ? ((string)$now('mail.postal_url') !== '' && (string)$now('mail.postal_key') !== '') : (string)$now('mail.host') !== '';
    }

    /** Write a prepared batch in one transaction, audit it, and make it effective. @return array{changed:string[],cleared:string[]} */
    public static function commit(array $prepared, int $userId): array
    {
        $db = Database::getInstance(); $t = $db->t('settings');
        $old = []; foreach ($prepared['values'] as $k => $_) { $old[$k] = Config::get($k); }
        $cleared = [];
        $db->transaction(function (Database $db) use ($prepared, $userId, $t, &$cleared): void {
            foreach ($prepared['values'] as $k => $v) {
                $secret = self::def($k)['secret'];
                $db->query("INSERT INTO `$t` (skey, svalue, is_secret, updated_by) VALUES (:k, :v, :s, :u) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)",
                    ['k' => $k, 'v' => $secret ? Crypto::encrypt((string)$v, "setting:$k") : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 's' => $secret ? 1 : 0, 'u' => $userId]);
            }
            foreach ($prepared['clears'] as $c) { if ($db->query("DELETE FROM `$t` WHERE skey = :k", ['k' => $c])->rowCount() > 0) { $cleared[] = $c; } }
        });
        Config::refreshSettings();
        $fmt = static fn (mixed $v): string => mb_substr(is_string($v) ? $v : (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 120);
        foreach ($prepared['values'] as $k => $v) {
            Audit::log('setting_changed', $userId, '', self::def($k)['secret'] ? "$k: changed" : "$k: " . $fmt($old[$k]) . ' -> ' . $fmt($v));
            if (self::def($k)['danger'] || self::def($k)['group'] === 'security') { Alerts::notify('setting_changed', "Setting $k was changed by an administrator.", 'warning', $k); }
        }
        foreach ($cleared as $c) { Audit::log('setting_cleared', $userId, '', "$c: removed (integration address changed)"); }
        return ['changed' => array_keys($prepared['values']), 'cleared' => $cleared];
    }

    /** Remove a saved value (the setting falls back to the config file, then the built-in default). */
    public static function reset(string $key, int $userId): bool
    {
        if (self::def($key) === null) { return false; }
        $db = Database::getInstance();
        $n = $db->query('DELETE FROM `' . $db->t('settings') . '` WHERE skey = :k', ['k' => $key])->rowCount();
        Config::refreshSettings();
        if ($n > 0) { Audit::log('setting_reset', $userId, '', $key); }
        return true;
    }

    /** Copy the config-file value of $key into saved (encrypted if secret) storage. @throws \RuntimeException when there is nothing to copy */
    public static function promote(string $key, int $userId): void
    {
        $d = self::def($key) ?? throw new \RuntimeException('Unknown setting');
        if (!Config::fileHas($key) || in_array($key, Config::savedKeys(), true)) { throw new \RuntimeException('There is no config-file value to move.'); }
        if ($d['secret'] && !Crypto::configured()) { throw new \RuntimeException('Set app_key first.'); }
        $p = ['status' => 200, 'message' => 'ok', 'errors' => [], 'values' => [$key => self::coerce($key, Config::fileValue($key))], 'effects' => [], 'clears' => [], 'secrets' => []];
        self::commit($p, $userId);
    }
}

