<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Talks to the Sitewright WordPress plugin. Direction matters: Sitewright always CALLS the site (pull 404s, push redirect rules);
 * the site never has to reach Sitewright, so Sitewright can stay on a private network. Every request goes through SafeHttpClient and is
 * signed: HMAC-SHA256(secret, METHOD "\n" route "\n" timestamp "\n" nonce "\n" sha256(body)); header X-Sitewright-Signature: t=..,n=..,s=..
 */
final class SiteSync
{
    /** The nonce makes every request's signature unique, so the plugin can refuse any signature it has seen (replay) without ever refusing two honest calls in the same second. */
    public static function sign(string $secret, string $method, string $route, int $t, string $nonce, string $body): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $route . "\n" . $t . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
    }

    /** Job handler: payload {site_id, force?}. */
    public static function handleJob(array $p): array
    {
        $id = (int)($p['site_id'] ?? 0);
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT * FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $id]);
        if (!$site) { throw new JobFailedException('Site no longer exists', 404); }
        if (!(int)$site['sync_enabled']) { return ['message' => 'This site does not use the plugin', 'data' => ['skipped' => true]]; }
        $out = ['pulled' => 0, 'pushed' => false];
        try {
            $secret = Crypto::decrypt((string)$site['secret_enc'], 'site:' . $id);
            $status = self::call($site, $secret, 'GET', '/sitewright/v1/status');
            $out['pulled'] = self::pull($site, $secret);
            $out['pushed'] = self::push($site, $secret, !empty($p['force']));
            $db->query('UPDATE `' . $db->t('sites') . "` SET last_sync_at = NOW(), last_status = 'ok', last_error = NULL, sync_requested = 0, plugin_version = :v WHERE id = :i", ['v' => mb_substr((string)($status['plugin_version'] ?? ''), 0, 20), 'i' => $id]);
            return ['message' => 'Synced', 'data' => $out];
        } catch (JobRetryException | JobFailedException | ConfigException $e) {
            $db->query('UPDATE `' . $db->t('sites') . "` SET last_sync_at = NOW(), last_status = 'error', last_error = :e, sync_requested = 0 WHERE id = :i", ['e' => mb_substr($e->getMessage(), 0, 250), 'i' => $id]);
            Alerts::notify('site_sync_failed', "Sync with {$site['name']} failed: " . $e->getMessage(), 'warning', 'site' . $id);
            throw $e instanceof ConfigException ? new JobFailedException($e->getMessage(), 500) : $e;
        }
    }

    /** @return int number of 404 rows stored */
    private static function pull(array $site, string $secret): int
    {
        $db = Database::getInstance();
        $t = $db->t('404_monitor');
        $cursor = (int)$site['cursor_seq']; $stored = 0;
        for ($page = 0; $page < 10; $page++) {
            $r = self::call($site, $secret, 'GET', '/sitewright/v1/404s', null, ['since_seq' => $cursor, 'limit' => 500]);
            $items = is_array($r['items'] ?? null) ? $r['items'] : [];
            foreach ($items as $it) {
                if (!is_array($it)) { continue; }
                $uri = self::cleanText($it['uri'] ?? '', 1000);
                if ($uri === '' || $uri[0] !== '/' || \Sitewright\Modules\Monitor\MonitorController::ignored($uri)) { continue; }   // plugin data is untrusted: only paths, minus ignored ones
                $ref = self::cleanText($it['referer'] ?? '', 1000);
                $ref = preg_match('#^https?://#i', $ref) ? $ref : null;
                $seen = strtotime((string)($it['last_seen'] ?? '')) ?: time();
                $db->query(
                    "INSERT INTO `$t` (project_id, uri, referer, hits, last_accessed) VALUES (:p, :u, :r, :h, :l)
                     ON DUPLICATE KEY UPDATE hits = VALUES(hits), last_accessed = VALUES(last_accessed), referer = COALESCE(VALUES(referer), referer)",
                    ['p' => (int)$site['id'], 'u' => $uri, 'r' => $ref, 'h' => max(1, min(2000000000, (int)($it['hits'] ?? 1))), 'l' => gmdate('Y-m-d H:i:s', $seen)]
                );
                $stored++;
                $cursor = max($cursor, (int)($it['seq'] ?? 0));
            }
            $cursor = max($cursor, (int)($r['max_seq'] ?? 0));
            if (count($items) < 500) { break; }
        }
        $db->query('UPDATE `' . $db->t('sites') . '` SET cursor_seq = :c WHERE id = :i', ['c' => $cursor, 'i' => (int)$site['id']]);
        return $stored;
    }

    private static function push(array $site, string $secret, bool $force): bool
    {
        $db = Database::getInstance();
        $rules = array_map(static fn (array $r): array => ['source' => $r['source_url'], 'target' => $r['target_url'], 'type' => (int)$r['redirect_type']],
            $db->fetchAll('SELECT source_url, target_url, redirect_type FROM `' . $db->t('redirections') . "` WHERE project_id = :p AND status = 'active' ORDER BY id LIMIT 5000", ['p' => (int)$site['id']]));
        $hash = hash('sha256', json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!$force && $hash === $site['rules_hash']) { return false; }
        self::call($site, $secret, 'POST', '/sitewright/v1/redirects', ['rules' => $rules, 'version' => $hash]);
        $db->query('UPDATE `' . $db->t('sites') . '` SET rules_hash = :h WHERE id = :i', ['h' => $hash, 'i' => (int)$site['id']]);
        return true;
    }

    /** @return array<string,mixed> decoded JSON body */
    public static function call(array $site, string $secret, string $method, string $route, ?array $json = null, array $query = []): array
    {
        $body = $json === null ? '' : (string)json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t = time(); $n = bin2hex(random_bytes(12));
        $url = rtrim((string)$site['base_url'], '/') . '/?rest_route=' . $route . ($query ? '&' . http_build_query($query) : '');
        $headers = ['Accept: application/json', 'X-Sitewright-Signature: t=' . $t . ',n=' . $n . ',s=' . self::sign($secret, $method, $route, $t, $n, $body)];
        if ($json !== null) { $headers[] = 'Content-Type: application/json'; }
        try {
            $res = SafeHttpClient::default()->request($method, $url, ['headers' => $headers, 'body' => $body, 'follow' => false, 'max_bytes' => 4194304, 'timeout' => 25, 'accept' => 'application/json']);
        } catch (FetchException $e) {
            throw new JobRetryException('Could not reach the site: ' . $e->getMessage(), 300);
        }
        $s = (int)$res['status'];
        if ($s >= 300 && $s < 400) { throw new JobFailedException('The site redirected the API call (check the site URL: http vs https, www, trailing path)', 502); }
        if ($s === 401 || $s === 403) { self::explainDenied((string)$res['body']); }
        if ($s === 404) { throw new JobFailedException('Plugin not found at that URL (is it installed and active, and is the REST API reachable?)', 502); }
        if ($s >= 500 || $s === 429) { throw new JobRetryException("The site answered HTTP $s", 300); }
        if ($s !== 200) { throw new JobFailedException("The site answered HTTP $s", 502); }
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) { throw new JobFailedException('The site returned something that is not JSON', 502); }
        return $data;
    }

    private static function cleanText(mixed $v, int $max): string
    {
        return is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $v) ?? ''), 0, $max) : '';
    }

    /** Turn the plugin's refusal into the actual cause. A body that is not the plugin's own (firewall, security plugin) is named as such. @throws JobFailedException|JobRetryException */
    private static function explainDenied(string $body): never
    {
        $msg = (string)((json_decode($body, true) ?: [])['message'] ?? '');
        throw match ($msg) {
            'Bad signature' => new JobFailedException('The secret in WordPress does not match this site\'s secret. Press New secret here, paste the new one into WordPress (no spaces or quotes; if wp-config.php defines an older SITEWRIGHT_SECRET, that one wins), then sync again.', 502),
            'Stale signature' => new JobFailedException('The clocks differ by more than 5 minutes. Sitewright\'s clock says ' . gmdate('H:i:s') . ' UTC; check the date and time of the WordPress server.', 502),
            'Secret not set' => new JobFailedException('The plugin has no secret yet. Add define(\'SITEWRIGHT_SECRET\', \'...\'); to wp-config.php, or paste it under Settings, Sitewright in WordPress.', 502),
            'Replay' => new JobRetryException('The plugin saw the same signature twice; trying again.', 60),
            default => new JobFailedException('The site refused the request, and the answer did not come from the Sitewright plugin: a firewall or security plugin (for example Wordfence or Cloudflare) may be blocking the REST API. Allow this server, or check the secret.', 502),
        };
    }

    // ------------------------------------------------------------------ scheduling

    /** Called by the worker every minute: queue a sync for every site that is due or has changed rules. */
    public static function scheduleDue(): int
    {
        $db = Database::getInstance();
        $every = max(1, (int)Config::get('sites.sync_minutes'));
        $n = 0;
        foreach ($db->fetchAll('SELECT id FROM `' . $db->t('sites') . "` WHERE sync_enabled = 1 AND (sync_requested = 1 OR last_sync_at IS NULL OR last_sync_at < NOW() - INTERVAL $every MINUTE)") as $s) {
            $payload = (string)json_encode(['site_id' => (int)$s['id']]);
            $open = $db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'site_sync' AND status IN ('queued','running') AND payload = :p", ['p' => $payload]);
            if (!$open) { Jobs::enqueue('site_sync', ['site_id' => (int)$s['id']], null, 0, 2); $n++; }
        }
        return $n;
    }

    /** Redirect rules changed: have the next scheduler pass look at every site (a push only happens where the rule set really differs). */
    public static function markDirty(): void
    {
        try {
            $db = Database::getInstance();
            $db->query('UPDATE `' . $db->t('sites') . '` SET sync_requested = 1 WHERE sync_enabled = 1');
        } catch (\Throwable) {
            // a missing sites table (older schema) must never break rule editing
        }
    }
}
