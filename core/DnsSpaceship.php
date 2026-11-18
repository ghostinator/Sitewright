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

    public function verify(): string
    {
        $r = $this->api('GET', '/domains?take=100&skip=0');
        $names = array_values(array_filter(array_map(static fn ($d) => is_array($d) && is_string($d['name'] ?? null) ? self::message($d['name']) : '', (array)($r['json']['items'] ?? []))));
        if (!$names) { return 'The keys work, but this account has no domains.'; }
        return 'The keys work and the account has ' . count($names) . ' domain' . (count($names) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '') . '. Use "Test a domain" to prove it can edit DNS.';
    }
}
