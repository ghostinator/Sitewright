<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Porkbun DNS (api.porkbun.com/api/json/v3) with an API key and secret, and API access switched on for the domain in Porkbun's settings.
 * Porkbun accepts a record even for a domain that is not delegated to it and only answers with a warning; that is treated as a failure
 * here (the certificate service would never see the record), and the record is removed again.
 */
final class DnsPorkbun extends DnsProvider
{
    private const BASE = 'https://api.porkbun.com/api/json/v3';
    private ?array $cache = null;

    public function __construct(private array $config)
    {
        foreach (['api_key', 'api_secret'] as $k) {
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/D', (string)($config[$k] ?? ''))) { throw new DnsProviderException('The Porkbun API key or secret is missing or not the right shape'); }
        }
    }

    /** Porkbun answers HTTP 200 with a "status" of SUCCESS or ERROR. */
    private function api(string $path, array $body = []): array
    {
        $r = $this->call('Porkbun', 'POST', self::BASE . $path, ['X-API-Key: ' . $this->config['api_key'], 'X-Secret-API-Key: ' . $this->config['api_secret'], 'Content-Type: application/json'], (string)json_encode((object)$body));
        $j = $r['json'];
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($j) || ($j['status'] ?? '') !== 'SUCCESS') {
            $why = is_array($j) && isset($j['message']) ? self::message($j['message']) : 'HTTP ' . $r['status'];
            throw new DnsProviderException('Porkbun refused it: ' . $why . ' (check the keys, and that API access is on for the domain)');
        }
        return $j;
    }

    public function domains(): ?array
    {
        if ($this->cache !== null) { return $this->cache; }
        $names = [];
        for ($start = 0; $start < 20000; $start += 1000) {
            $j = $this->api('/domain/listAll', ['start' => (string)$start]);
            $page = (array)($j['domains'] ?? []);
            foreach ($page as $d) { if (is_array($d) && is_string($d['domain'] ?? null) && ($n = Acme::cleanName($d['domain'])) !== null) { $names[] = $n; } }
            if (count($page) < 1000) { break; }
        }
        sort($names);
        return $this->cache = $names;
    }

    public function addTxt(string $name, string $value): string
    {
        $zone = self::zoneIn($this->domains() ?? [], $name, 'Porkbun');
        $j = $this->api('/dns/create/' . rawurlencode($zone), ['name' => self::relative($name, $zone), 'type' => 'TXT', 'content' => $value, 'ttl' => '600']);
        $id = $j['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) { throw new DnsProviderException('Porkbun did not return the new record'); }
        if (!empty($j['warnings'])) {
            try { $this->api('/dns/delete/' . rawurlencode($zone) . '/' . $id); } catch (DnsProviderException) { /* the warning is the message that matters */ }
            throw new DnsProviderException('Porkbun stored the record but says it will not be seen: ' . self::message(implode(' ', array_filter((array)$j['warnings'], 'is_string'))));
        }
        return $zone . '|' . $id;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([a-z0-9.-]{3,253})\|(\d{1,20})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $this->api('/dns/delete/' . rawurlencode($m[1]) . '/' . $m[2]);
    }

    public function verify(): string { return self::summary($this->domains() ?? []); }
}
