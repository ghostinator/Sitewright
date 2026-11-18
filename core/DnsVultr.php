<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Vultr DNS (api.vultr.com/v2) with an API key. TXT values are sent in quotes, as Vultr stores them. */
final class DnsVultr extends DnsProvider
{
    private const BASE = 'https://api.vultr.com/v2';
    private ?array $cache = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The Vultr API key is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        $r = $this->call('Vultr', $method, self::BASE . $path, ['Authorization: Bearer ' . $this->config['token'], 'Content-Type: application/json'], $body === null ? null : (string)json_encode($body));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $why = is_array($r['json']) && isset($r['json']['error']) ? self::message($r['json']['error']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('Vultr refused it: ' . $why . (in_array($r['status'], [401, 403], true) ? ' (check the key, and that this server\'s address is allowed in the key\'s access control list)' : ''));
        }
        return $r;
    }

    public function domains(): ?array
    {
        if ($this->cache !== null) { return $this->cache; }
        $names = []; $cursor = '';
        for ($i = 0; $i < 20; $i++) {
            $j = $this->api('GET', '/domains?per_page=500' . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''))['json'];
            foreach ((array)($j['domains'] ?? []) as $d) { if (is_array($d) && is_string($d['domain'] ?? null) && ($n = Acme::cleanName($d['domain'])) !== null) { $names[] = $n; } }
            $cursor = (string)($j['meta']['links']['next'] ?? '');
            if ($cursor === '') { break; }
        }
        sort($names);
        return $this->cache = $names;
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = self::zoneIn($this->domains() ?? [], $name, 'Vultr');
        $r = $this->api('POST', '/domains/' . rawurlencode($zone) . '/records', ['name' => self::relative($name, $zone), 'type' => 'TXT', 'data' => '"' . $value . '"', 'ttl' => 120]);
        $id = $r['json']['record']['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{8,64}$/D', $id)) { throw new DnsProviderException('Vultr did not return the new record'); }
        return $zone . '|' . $id;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([a-z0-9.-]{3,253})\|([A-Za-z0-9-]{8,64})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('DELETE', '/domains/' . rawurlencode($m[1]) . '/records/' . $m[2]);
    }

    public function verify(): string { return self::summary($this->domains() ?? []); }
}
