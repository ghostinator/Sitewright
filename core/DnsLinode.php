<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Linode (Akamai) DNS Manager (api.linode.com/v4) with a personal access token that has Domains read/write. Only "master" domains can be changed. */
final class DnsLinode extends DnsProvider
{
    private const BASE = 'https://api.linode.com/v4';
    /** @var array<string,int> domain => id */
    private ?array $ids = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', (string)($config['token'] ?? ''))) { throw new DnsProviderException('The Linode token is missing or not the right shape'); }
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        $r = $this->call('Linode', $method, self::BASE . $path, ['Authorization: Bearer ' . $this->config['token'], 'Content-Type: application/json'], $body === null ? null : (string)json_encode($body));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $why = is_array($r['json']) && isset($r['json']['errors'][0]['reason']) ? self::message($r['json']['errors'][0]['reason']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('Linode refused it: ' . $why . (in_array($r['status'], [401, 403], true) ? ' (check the token and its scopes)' : ''));
        }
        return $r;
    }

    private function loadIds(): array
    {
        if ($this->ids !== null) { return $this->ids; }
        $ids = [];
        for ($page = 1; $page <= 20; $page++) {
            $j = $this->api('GET', '/domains?page_size=100&page=' . $page)['json'];
            foreach ((array)($j['data'] ?? []) as $d) { if (is_array($d) && ($d['type'] ?? '') === 'master' && is_string($d['domain'] ?? null) && is_int($d['id'] ?? null) && ($n = Acme::cleanName($d['domain'])) !== null) { $ids[$n] = $d['id']; } }
            if ($page >= (int)($j['pages'] ?? 1)) { break; }
        }
        ksort($ids);
        return $this->ids = $ids;
    }

    public function domains(): ?array { return array_keys($this->loadIds()); }

    public function addTxt(string $name, string $value): string
    {
        $ids = $this->loadIds(); $zone = self::zoneIn(array_keys($ids), $name, 'Linode');
        $r = $this->api('POST', '/domains/' . $ids[$zone] . '/records', ['type' => 'TXT', 'name' => self::relative($name, $zone), 'target' => $value, 'ttl_sec' => 30]);
        $id = $r['json']['id'] ?? null;
        if (!is_int($id)) { throw new DnsProviderException('Linode did not return the new record'); }
        return $ids[$zone] . '|' . $id;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^(\d{1,20})\|(\d{1,20})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('DELETE', '/domains/' . $m[1] . '/records/' . $m[2]);
    }

    public function verify(): string { return self::summary($this->domains() ?? []); }
}
