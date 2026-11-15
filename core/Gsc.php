<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Google Search Console, read-only. OAuth 2.0 authorization-code flow with PKCE (S256) and a session-bound, single-use `state`.
 * Scope: webmasters.readonly only. The refresh token is encrypted at rest (Crypto, context "gsc:<id>"); access tokens live only in memory.
 * All calls go through SafeHttpClient to fixed Google hosts. Nothing here ever logs a token, code or response body.
 */
final class Gsc
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const API = 'https://www.googleapis.com/webmasters/v3';
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const MAX_ROWS = 100000;

    public static function configured(): bool
    {
        $g = (array)Config::get('google');
        return $g['client_id'] !== '' && $g['client_secret'] !== '' && $g['redirect_uri'] !== '' && Crypto::configured();
    }

    private static function b64url(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    /** Start the flow. $session is $_SESSION (by reference) so the pending state is bound to this browser session. */
    public static function authUrl(array &$session): string
    {
        if (!self::configured()) { throw new GscException('Google Search Console is not configured (client id/secret, redirect URI and app_key are required)'); }
        $state = self::b64url(random_bytes(24));
        $verifier = self::b64url(random_bytes(48));
        $session['gsc_oauth'] = ['state' => $state, 'verifier' => $verifier, 'until' => time() + 600];
        $g = (array)Config::get('google');
        return self::AUTH . '?' . http_build_query([
            'client_id' => $g['client_id'], 'redirect_uri' => $g['redirect_uri'], 'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false', 'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Finish the flow. @return int the new connection id. @throws GscException */
    public static function handleCallback(array &$session, array $query, int $userId): int
    {
        $pending = $session['gsc_oauth'] ?? null;
        unset($session['gsc_oauth']);                                   // single use, whatever happens next
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        if (!is_array($pending) || time() > (int)$pending['until'] || $state === '' || !hash_equals((string)$pending['state'], $state)) {
            throw new GscException('The sign-in with Google expired or did not start here. Start again from Analytics.');
        }
        if (isset($query['error'])) { throw new GscException('Google did not grant access.'); }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '' || strlen($code) > 2048) { throw new GscException('Google returned no authorization code.'); }
        $g = (array)Config::get('google');
        $tok = self::tokenRequest([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $g['redirect_uri'],
            'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'code_verifier' => $pending['verifier'],
        ]);
        $refresh = $tok['refresh_token'] ?? null;
        if (!is_string($refresh) || $refresh === '') { throw new GscException('Google did not return a refresh token. Remove Sitewright under your Google account\'s third-party access and connect again.'); }
        if (!str_contains((string)($tok['scope'] ?? self::SCOPE), 'webmasters')) { throw new GscException('The Search Console permission was not granted.'); }
        $db = Database::getInstance();
        $id = (int)$db->insert('gsc_connections', ['user_id' => $userId, 'refresh_token_enc' => 'pending']);
        $db->update('gsc_connections', ['refresh_token_enc' => Crypto::encrypt($refresh, 'gsc:' . $id)], ['id' => $id]);
        Audit::log('gsc_connected', $userId);
        return $id;
    }

    /** @return array<string,mixed> @throws GscException */
    private static function tokenRequest(array $form): array
    {
        try {
            $res = SafeHttpClient::default()->request('POST', self::TOKEN, [
                'headers' => ['Content-Type: application/x-www-form-urlencoded'], 'body' => http_build_query($form), 'follow' => false, 'max_bytes' => 65536, 'timeout' => 20, 'accept' => 'application/json',
            ]);
        } catch (FetchException) {
            throw new GscException('Could not reach Google.');
        }
        $j = json_decode((string)$res['body'], true);
        if ($res['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            $err = is_array($j) && is_string($j['error'] ?? null) ? $j['error'] : 'http_' . $res['status'];
            throw new GscException($err === 'invalid_grant' ? 'invalid_grant' : 'Google rejected the request (' . preg_replace('/[^a-z_0-9 ]/i', '', $err) . ').');
        }
        return $j;
    }

    private static function connection(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT * FROM `' . $db->t('gsc_connections') . '` WHERE id = :i', ['i' => $id]) ?? throw new GscException('Connection not found');
    }

    /** Fresh access token for a connection. A revoked/expired grant marks the connection "reauth". @throws GscException */
    private static function accessToken(array $conn): string
    {
        $g = (array)Config::get('google');
        try {
            $t = self::tokenRequest([
                'grant_type' => 'refresh_token', 'refresh_token' => Crypto::decrypt((string)$conn['refresh_token_enc'], 'gsc:' . $conn['id']),
                'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'],
            ]);
        } catch (GscException $e) {
            if ($e->getMessage() === 'invalid_grant') {
                $db = Database::getInstance();
                $db->update('gsc_connections', ['last_status' => 'reauth', 'last_error' => 'Google access was revoked or expired; connect again.'], ['id' => (int)$conn['id']]);
                throw new GscException('Google access was revoked or expired; connect again.');
            }
            throw $e;
        } catch (ConfigException) {
            throw new GscException('The stored token cannot be decrypted (app_key changed?); connect again.');
        }
        return (string)$t['access_token'];
    }

    /** @return array<int,array{site_url:string,permission:string}> @throws GscException */
    public static function properties(int $connId): array
    {
        $conn = self::connection($connId);
        $j = self::api('GET', self::API . '/sites', self::accessToken($conn));
        $out = [];
        foreach ((array)($j['siteEntry'] ?? []) as $e) {
            if (is_array($e) && is_string($e['siteUrl'] ?? null) && ($e['permissionLevel'] ?? '') !== 'siteUnverifiedUser') { $out[] = ['site_url' => $e['siteUrl'], 'permission' => (string)($e['permissionLevel'] ?? '')]; }
        }
        return $out;
    }

    public static function setProperty(int $connId, string $siteUrl): void
    {
        if (!in_array($siteUrl, array_column(self::properties($connId), 'site_url'), true)) { throw new GscException('That property is not available to this Google account.'); }
        Database::getInstance()->update('gsc_connections', ['property_url' => $siteUrl, 'last_status' => 'new', 'last_error' => null], ['id' => $connId]);
        Audit::log('gsc_property_set', null, '', $siteUrl);
    }

    public static function disconnect(int $connId): void
    {
        $conn = self::connection($connId);
        try {   // best effort: tell Google to forget the grant
            SafeHttpClient::default()->request('POST', self::REVOKE, ['headers' => ['Content-Type: application/x-www-form-urlencoded'], 'body' => http_build_query(['token' => Crypto::decrypt((string)$conn['refresh_token_enc'], 'gsc:' . $connId)]), 'follow' => false, 'max_bytes' => 4096, 'timeout' => 10]);
        } catch (\Throwable) {
        }
        Database::getInstance()->delete('gsc_connections', ['id' => $connId]);
        Audit::log('gsc_disconnected');
    }

    /** @return array<string,mixed> */
    private static function api(string $method, string $url, string $token, ?array $json = null): array
    {
        try {
            $res = SafeHttpClient::default()->request($method, $url, [
                'headers' => array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], $json !== null ? ['Content-Type: application/json'] : []),
                'body' => $json !== null ? (string)json_encode($json) : '', 'follow' => false, 'max_bytes' => 16777216, 'timeout' => 30, 'accept' => 'application/json',
            ]);
        } catch (FetchException) {
            throw new GscException('Could not reach Google.');
        }
        $j = json_decode((string)$res['body'], true);
        if ($res['status'] === 403) { throw new GscException('Google refused access to that property (is the account a verified owner/user of it?).'); }
        if ($res['status'] === 429 || $res['status'] >= 500) { throw new JobRetryException('Google answered HTTP ' . $res['status'], 600); }
        if ($res['status'] !== 200 || !is_array($j)) { throw new GscException('Google answered HTTP ' . $res['status'] . '.'); }
        return $j;
    }

    // ------------------------------------------------------------------ sync

    /** Job handler: payload {connection_id}. Imports the last 28 days (Search Console data lags about two days). */
    public static function handleJob(array $p): array
    {
        $id = (int)($p['connection_id'] ?? 0);
        $db = Database::getInstance();
        try {
            $conn = self::connection($id);
            if ($conn['property_url'] === '') { throw new GscException('Choose a Search Console property first.'); }
            $token = self::accessToken($conn);
            $end = gmdate('Y-m-d', strtotime('-2 days')); $start = gmdate('Y-m-d', strtotime('-29 days'));
            $url = self::API . '/sites/' . rawurlencode((string)$conn['property_url']) . '/searchAnalytics/query';
            $rows = []; $total = 0;
            for ($offset = 0; $offset < self::MAX_ROWS; $offset += 25000) {
                $j = self::api('POST', $url, $token, ['startDate' => $start, 'endDate' => $end, 'dimensions' => ['date', 'query'], 'rowLimit' => 25000, 'startRow' => $offset]);
                $batch = (array)($j['rows'] ?? []);
                foreach ($batch as $r) {
                    $k = $r['keys'] ?? null;
                    if (!is_array($k) || count($k) < 2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$k[0])) { continue; }
                    $kw = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$k[1]) ?? ''), 0, 500);
                    if ($kw === '') { continue; }
                    $rows[] = [$kw, max(0, (int)($r['impressions'] ?? 0)), max(0, (int)($r['clicks'] ?? 0)), round(min(999.99, max(0.0, (float)($r['position'] ?? 0))), 2), round(min(999.99, max(0.0, (float)($r['ctr'] ?? 0) * 100)), 2), (string)$k[0]];
                }
                $total += count($batch);
                if (count($batch) < 25000) { break; }
            }
            self::store($rows);
            $db->update('gsc_connections', ['last_sync_at' => gmdate('Y-m-d H:i:s'), 'last_status' => 'ok', 'last_error' => null, 'rows_synced' => count($rows)], ['id' => $id]);
            return ['message' => 'Search Console synced', 'data' => ['rows' => count($rows), 'from' => $start, 'to' => $end]];
        } catch (GscException $e) {
            $msg = mb_substr($e->getMessage(), 0, 250);
            $db->query('UPDATE `' . $db->t('gsc_connections') . "` SET last_sync_at = NOW(), last_status = IF(last_status = 'reauth', 'reauth', 'error'), last_error = :e WHERE id = :i", ['e' => $msg, 'i' => $id]);
            Alerts::notify('gsc_sync_failed', 'Search Console sync failed: ' . $msg, 'warning', 'gsc' . $id);
            throw new JobFailedException($msg, 502);
        }
    }

    /** @param array<int,array{0:string,1:int,2:int,3:float,4:float,5:string}> $rows */
    private static function store(array $rows): void
    {
        $db = Database::getInstance();
        $t = $db->t('analytics_keywords');
        $db->transaction(function (Database $db) use ($rows, $t): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                $ph = []; $bind = [];
                foreach ($chunk as $i => [$kw, $imp, $clk, $pos, $ctr, $date]) {
                    $ph[] = "(0, :k$i, :i$i, :c$i, :p$i, :r$i, :d$i)";
                    $bind += ["k$i" => $kw, "i$i" => $imp, "c$i" => $clk, "p$i" => $pos, "r$i" => $ctr, "d$i" => $date];
                }
                $db->query("INSERT INTO `$t` (project_id, keyword, impressions, clicks, position, ctr, `date`) VALUES " . implode(',', $ph)
                    . ' ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), position = VALUES(position), ctr = VALUES(ctr), is_sample = 0', $bind);
            }
        });
    }

    /** Called by the worker: queue a daily sync for every connection that has a property chosen. */
    public static function scheduleDue(): int
    {
        $db = Database::getInstance();
        $n = 0;
        foreach ($db->fetchAll('SELECT id FROM `' . $db->t('gsc_connections') . "` WHERE property_url <> '' AND last_status <> 'reauth' AND (last_sync_at IS NULL OR last_sync_at < NOW() - INTERVAL 1 DAY)") as $c) {
            $payload = (string)json_encode(['connection_id' => (int)$c['id']]);
            if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'gsc_sync' AND status IN ('queued','running') AND payload = :p", ['p' => $payload])) { Jobs::enqueue('gsc_sync', ['connection_id' => (int)$c['id']], null, 0, 2); $n++; }
        }
        return $n;
    }
}
