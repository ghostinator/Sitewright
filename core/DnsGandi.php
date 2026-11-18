<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Gandi LiveDNS (api.gandi.net/v5/livedns) with a personal access token. A name holds one record set, so two TXT values at one name (a name and its
 * wildcard) are handled by reading it, changing only our value, and writing it back. Values are sent in quotes.
 */
final class DnsGandi extends DnsProvider
{
    private const BASE = 'https://api.gandi.net/v5/livedns';
    private ?array $cache = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The Gandi token is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null, bool $allow404 = false): array
    {
        $r = $this->call('Gandi', $method, self::BASE . $path, ['Authorization: Bearer ' . $this->config['token'], 'Content-Type: application/json'], $body === null ? null : (string)json_encode($body));
        if ($r['status'] === 404 && $allow404) { return $r; }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $why = is_array($r['json']) && isset($r['json']['message']) ? self::message($r['json']['message']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('Gandi refused it: ' . $why . (in_array($r['status'], [401, 403], true) ? ' (check the token and its permissions)' : ''));
        }
        return $r;
    }

    public function domains(): ?array
    {
        if ($this->cache !== null) { return $this->cache; }
        $names = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->api('GET', '/domains?per_page=100&page=' . $page); $list = (array)$r['json'];
            foreach ($list as $d) { if (is_array($d) && is_string($d['fqdn'] ?? null) && ($n = Acme::cleanName($d['fqdn'])) !== null) { $names[] = $n; } }
            if (count($list) < 100) { break; }
        }
        sort($names);
        return $this->cache = $names;
    }

    private function path(string $zone, string $sub): string { return '/domains/' . rawurlencode($zone) . '/records/' . rawurlencode($sub === '' ? '@' : $sub) . '/TXT'; }

    private function current(string $zone, string $sub): array
    {
        $r = $this->api('GET', $this->path($zone, $sub), null, true);
        return $r['status'] === 404 ? [] : array_values(array_filter((array)($r['json']['rrset_values'] ?? []), 'is_string'));
    }

    private function write(string $zone, string $sub, array $values): void
    {
        if (!$values) { $this->api('DELETE', $this->path($zone, $sub), null, true); return; }
        $this->api('PUT', $this->path($zone, $sub), ['rrset_ttl' => 300, 'rrset_values' => array_values($values)]);
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = self::zoneIn($this->domains() ?? [], $name, 'Gandi'); $sub = self::relative($name, $zone);
        $this->write($zone, $sub, array_values(array_unique([...$this->current($zone, $sub), '"' . $value . '"'])));
        return $zone . '|' . $sub . '|' . $value;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([a-z0-9.-]{3,253})\|([A-Za-z0-9_.-]{1,253})\|([A-Za-z0-9_-]{1,200})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->write($m[1], $m[2], array_values(array_diff($this->current($m[1], $m[2]), ['"' . $m[3] . '"'])));
    }

    public function verify(): string { return self::summary($this->domains() ?? []); }
}
