<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Cloudflare DNS through its API (api.cloudflare.com), with a scoped API token. Records are created with a short TTL and removed again after validation. */
final class DnsCloudflare extends DnsProvider
{
    private const BASE = 'https://api.cloudflare.com/client/v4';
    /** @var array<string,string> zone name => zone id */
    private array $zones = [];

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The Cloudflare API token is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        try {
            $res = SafeHttpClient::default()->request($method, self::BASE . $path, ['headers' => ['Authorization: Bearer ' . $this->config['token'], 'Content-Type: application/json'],
                'body' => $body === null ? '' : (string)json_encode($body), 'accept' => 'application/json', 'follow' => false, 'max_bytes' => 1048576, 'timeout' => 20]);
        } catch (FetchException $e) {
            throw new DnsProviderException('Could not reach Cloudflare: ' . self::message($e->getMessage()));
        }
        $j = json_decode($res['body'], true);
        if ($res['status'] === 429) { throw new DnsProviderException('Cloudflare is limiting this token for now; try again in a few minutes'); }
        if (!is_array($j) || empty($j['success'])) {
            $why = is_array($j) && is_array($j['errors'][0] ?? null) ? self::message($j['errors'][0]['message'] ?? '') : 'HTTP ' . $res['status'];
            throw new DnsProviderException('Cloudflare refused it' . ($why !== '' ? ': ' . $why : '') . ($res['status'] === 403 || $res['status'] === 401 ? ' (check the token and its permissions)' : ''));
        }
        return $j;
    }

    /** The zone that holds this name: the longest suffix of the name that is a zone in this account. */
    private function zoneFor(string $name): string
    {
        $labels = explode('.', $name);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $zone = implode('.', array_slice($labels, $i));
            if (isset($this->zones[$zone])) { return $this->zones[$zone]; }
            $r = $this->api('GET', '/zones?status=active&per_page=1&name=' . rawurlencode($zone));
            $id = $r['result'][0]['id'] ?? null;
            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)) { return $this->zones[$zone] = $id; }
        }
        throw new DnsProviderException('This Cloudflare token cannot see a zone for ' . $name . ' (is the domain in this account, and does the token cover it?)');
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = $this->zoneFor($name);
        $r = $this->api('POST', '/zones/' . $zone . '/dns_records', ['type' => 'TXT', 'name' => $name, 'content' => $value, 'ttl' => 60, 'comment' => 'Sitewright certificate validation (removed afterwards)']);
        $id = $r['result']['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new DnsProviderException('Cloudflare did not return the new record'); }
        return $zone . '/' . $id;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('#^([a-f0-9]{32})/([a-f0-9]{32})$#D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('DELETE', '/zones/' . $m[1] . '/dns_records/' . $m[2]);
    }

    public function domains(): ?array
    {
        $names = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->api('GET', '/zones?status=active&per_page=50&page=' . $page);
            foreach ((array)$r['result'] as $z) { if (is_array($z) && is_string($z['name'] ?? null) && ($n = Acme::cleanName($z['name'])) !== null) { $names[] = $n; } }
            if ($page >= (int)($r['result_info']['total_pages'] ?? 1)) { break; }
        }
        sort($names);
        return $names;
    }

    public function verify(): string
    {
        $names = $this->domains() ?? [];
        if (!$names) { return 'The token works, but it cannot see any zone. Give it Zone: Read and DNS: Edit on the zones you need.'; }
        return 'The token works and can see ' . count($names) . ' zone' . (count($names) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '') . '. Use "Test a domain" to prove it can edit DNS.';
    }
}
