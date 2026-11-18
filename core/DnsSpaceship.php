<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Spaceship DNS through its public API (spaceship.dev/api/v1) with an API key and secret that have the dnsrecords:read, dnsrecords:write and
 * domains:read scopes. Record names are relative to the domain ("_acme-challenge", "@" for the apex), TXT records are matched exactly.
 * Only used for domains whose name servers are Spaceship's own; a domain whose DNS is elsewhere (for example Cloudflare) needs that provider.
 */
final class DnsSpaceship extends DnsProvider
{
    private const BASE = 'https://spaceship.dev/api/v1';

    public function __construct(private array $config)
    {
        foreach (['api_key', 'api_secret'] as $k) {
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/D', (string)($config[$k] ?? ''))) { throw new DnsProviderException('The Spaceship API key or secret is missing or not the right shape'); }
        }
    }

    /** @return array{status:int,json:mixed} */
    private function api(string $method, string $path, mixed $body = null, bool $allow404 = false): array
    {
        try {
            $res = SafeHttpClient::default()->request($method, self::BASE . $path, ['headers' => ['X-API-Key: ' . $this->config['api_key'], 'X-API-Secret: ' . $this->config['api_secret'], 'Content-Type: application/json'],
                'body' => $body === null ? '' : (string)json_encode($body), 'accept' => 'application/json', 'follow' => false, 'max_bytes' => 1048576, 'timeout' => 20]);
        } catch (FetchException $e) {
            throw new DnsProviderException('Could not reach Spaceship: ' . self::message($e->getMessage()));
        }
        $j = json_decode($res['body'], true);
        if ($res['status'] === 404 && $allow404) { return ['status' => 404, 'json' => $j]; }
        if ($res['status'] === 429) { throw new DnsProviderException('Spaceship is limiting these keys for now; try again in a few minutes'); }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $why = is_array($j) && isset($j['detail']) ? self::message($j['detail']) : 'HTTP ' . $res['status'];
            throw new DnsProviderException('Spaceship refused it: ' . $why . (in_array($res['status'], [401, 403], true) ? ' (check the key, the secret and their scopes)' : ''));
        }
        return ['status' => (int)$res['status'], 'json' => $j];
    }

    /** The domain in this account that holds the name: the longest suffix of the name that Spaceship has DNS records for. */
    private function zoneFor(string $name): string
    {
        $labels = explode('.', $name);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $zone = implode('.', array_slice($labels, $i));
            if ($this->api('GET', '/dns/records/' . rawurlencode($zone) . '?take=1&skip=0', null, true)['status'] === 200) { return $zone; }
        }
        throw new DnsProviderException('This Spaceship key cannot see a domain for ' . $name . ' (is it in this account, and are its name servers Spaceship\'s?)');
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = $this->zoneFor($name);
        $rel = $name === $zone ? '@' : substr($name, 0, -strlen('.' . $zone));
        $this->api('PUT', '/dns/records/' . rawurlencode($zone), ['items' => [['type' => 'TXT', 'name' => $rel, 'value' => $value, 'ttl' => 60]]]);
        return $zone . '|' . $rel . '|' . $value;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([a-z0-9.-]{3,253})\|([A-Za-z0-9_.@*-]{1,253})\|([A-Za-z0-9_-]{1,200})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('DELETE', '/dns/records/' . rawurlencode($m[1]), [['type' => 'TXT', 'name' => $m[2], 'value' => $m[3]]]);
    }

    /** @var array<string,string> */
    private array $elsewhere = [];

    /** Only domains whose name servers are Spaceship's own: a record added for any other domain would be accepted but never seen by the certificate service. */
    public function domains(): ?array
    {
        $own = []; $this->elsewhere = [];
        for ($skip = 0; $skip < 1000; $skip += 100) {
            $r = $this->api('GET', '/domains?take=100&skip=' . $skip);
            $items = (array)($r['json']['items'] ?? []);
            foreach ($items as $d) {
                $n = is_array($d) && is_string($d['name'] ?? null) ? Acme::cleanName($d['name']) : null;
                if ($n === null) { continue; }
                if (($d['nameservers']['provider'] ?? '') === 'custom') { $this->elsewhere[$n] = self::message(implode(', ', array_filter((array)($d['nameservers']['hosts'] ?? []), 'is_string'))); }
                else { $own[] = $n; }
            }
            if (count($items) < 100 || $skip + 100 >= (int)($r['json']['total'] ?? 0)) { break; }
        }
        sort($own);
        return $own;
    }

    public function elsewhere(): array { return $this->elsewhere; }

    public function verify(): string
    {
        $own = $this->domains() ?? [];
        $other = count($this->elsewhere);
        if (!$own && !$other) { return 'The keys work, but this account has no domains.'; }
        $msg = 'The keys work. ' . count($own) . ' domain' . (count($own) === 1 ? '' : 's') . ' use Spaceship DNS' . ($own ? ': ' . implode(', ', array_slice($own, 0, 10)) . (count($own) > 10 ? ', …' : '') : '') . '.';
        if ($other) { $msg .= ' ' . $other . ' more use name servers elsewhere (for example Cloudflare), so certificates for them need the DNS provider that hosts them.'; }
        return $msg . ' Use "Test a domain" to prove it can edit DNS.';
    }
}
