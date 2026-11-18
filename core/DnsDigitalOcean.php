<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** DigitalOcean DNS (api.digitalocean.com/v2) with a personal access token that may read and write. Records are created with the minimum TTL (30 s) and deleted by id. */
final class DnsDigitalOcean extends DnsProvider
{
    private const BASE = 'https://api.digitalocean.com/v2';
    private ?array $cache = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The DigitalOcean API token is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        $r = $this->call('DigitalOcean', $method, self::BASE . $path, ['Authorization: Bearer ' . $this->config['token'], 'Content-Type: application/json'], $body === null ? null : (string)json_encode($body));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $why = is_array($r['json']) && isset($r['json']['message']) ? self::message($r['json']['message']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('DigitalOcean refused it: ' . $why . (in_array($r['status'], [401, 403], true) ? ' (check the token and that it can write)' : ''));
        }
        return $r;
    }

    public function domains(): ?array
    {
        if ($this->cache !== null) { return $this->cache; }
        $names = [];
        for ($page = 1; $page <= 20; $page++) {
            $j = $this->api('GET', '/domains?per_page=200&page=' . $page)['json'];
            foreach ((array)($j['domains'] ?? []) as $d) { if (is_array($d) && is_string($d['name'] ?? null) && ($n = Acme::cleanName($d['name'])) !== null) { $names[] = $n; } }
            if (empty($j['links']['pages']['next'])) { break; }
        }
        sort($names);
        return $this->cache = $names;
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = self::zoneIn($this->domains() ?? [], $name, 'DigitalOcean');
        $r = $this->api('POST', '/domains/' . rawurlencode($zone) . '/records', ['type' => 'TXT', 'name' => self::relative($name, $zone) ?: '@', 'data' => $value, 'ttl' => 30]);
        $id = $r['json']['domain_record']['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) { throw new DnsProviderException('DigitalOcean did not return the new record'); }
        return $zone . '|' . $id;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([a-z0-9.-]{3,253})\|(\d{1,20})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('DELETE', '/domains/' . rawurlencode($m[1]) . '/records/' . $m[2]);
    }

    public function verify(): string { return self::summary($this->domains() ?? []); }
}
