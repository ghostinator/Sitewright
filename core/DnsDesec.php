<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * deSEC (desec.io) with an API token. A name holds ONE record set, so a certificate for a name and its wildcard (two TXT values on one name) is
 * handled by reading the set, adding or removing only our value, and writing it back. Values are sent in quotes, as deSEC expects for TXT.
 */
final class DnsDesec extends DnsProvider
{
    private const BASE = 'https://desec.io/api/v1';
    private ?array $cache = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The deSEC token is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null, bool $allow404 = false): array
    {
        $r = $this->call('deSEC', $method, str_starts_with($path, 'https://') ? $path : self::BASE . $path, ['Authorization: Token ' . $this->config['token'], 'Content-Type: application/json'], $body === null ? null : (string)json_encode($body));
        if ($r['status'] === 404 && $allow404) { return $r; }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $why = is_array($r['json']) && isset($r['json']['detail']) ? self::message($r['json']['detail']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('deSEC refused it: ' . $why . (in_array($r['status'], [401, 403], true) ? ' (check the token)' : ''));
        }
        return $r;
    }

    public function domains(): ?array
    {
        if ($this->cache !== null) { return $this->cache; }
        $names = []; $url = '/domains/?cursor=';
        for ($i = 0; $i < 20 && $url !== ''; $i++) {
            $r = $this->api('GET', $url);
            foreach ((array)$r['json'] as $d) { if (is_array($d) && is_string($d['name'] ?? null) && ($n = Acme::cleanName($d['name'])) !== null) { $names[] = $n; } }
            $url = preg_match('/<(https:\/\/desec\.io\/api\/v1\/[^>]+)>\s*;\s*rel="next"/', (string)($r['headers']['link'] ?? ''), $m) ? $m[1] : '';
        }
        sort($names);
        return $this->cache = $names;
    }

    private function setPath(string $zone, string $sub): string { return '/domains/' . rawurlencode($zone) . '/rrsets/' . rawurlencode($sub === '' ? '@' : $sub) . '/TXT/'; }

    /** @return list<string> the TXT values now at this name */
    private function current(string $zone, string $sub): array
    {
        $r = $this->api('GET', $this->setPath($zone, $sub), null, true);
        return $r['status'] === 404 ? [] : array_values(array_filter((array)($r['json']['records'] ?? []), 'is_string'));
    }

    private function write(string $zone, string $sub, array $values): void
    {
        $exists = $this->api('GET', $this->setPath($zone, $sub), null, true)['status'] !== 404;
        if (!$values) { if ($exists) { $this->api('DELETE', $this->setPath($zone, $sub)); } return; }
        if ($exists) { $this->api('PATCH', $this->setPath($zone, $sub), ['records' => array_values($values), 'ttl' => 3600]); }
        else { $this->api('POST', '/domains/' . rawurlencode($zone) . '/rrsets/', ['subname' => $sub, 'type' => 'TXT', 'ttl' => 3600, 'records' => array_values($values)]); }
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = self::zoneIn($this->domains() ?? [], $name, 'deSEC'); $sub = self::relative($name, $zone);
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
