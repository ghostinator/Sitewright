<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Namecheap as a REGISTRAR account (api.namecheap.com): it lists the domains registered there and when each registration ends. It never edits DNS:
 * Namecheap's DNS interface replaces a domain's WHOLE record list on every change, so a mistake would delete a domain's records, and Sitewright
 * does not use it for certificates (use the provider that hosts the DNS, for example Cloudflare). Namecheap only answers requests from addresses
 * on the account's API whitelist, so the account's "client address" (this server's public IPv4 address) is part of the credentials.
 */
final class DnsNamecheap extends DnsProvider
{
    private const URL = 'https://api.namecheap.com/xml.response';
    /** @var ?array<string,array{expires:int,own_dns:bool}> */
    private ?array $list = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{2,64}$/D', (string)($config['api_user'] ?? ''))) { throw new DnsProviderException('The Namecheap API user name is missing or not the right shape'); }
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/D', (string)($config['api_key'] ?? ''))) { throw new DnsProviderException('The Namecheap API key is missing or not the right shape'); }
        if (filter_var((string)($config['client_ip'] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { throw new DnsProviderException('The client address must be this server\'s public IPv4 address, the one on Namecheap\'s API whitelist'); }
    }

    /** One call. Namecheap answers HTTP 200 with an XML envelope whose Status is OK or ERROR. */
    private function api(string $command, array $params = []): \SimpleXMLElement
    {
        $body = http_build_query(['ApiUser' => $this->config['api_user'], 'ApiKey' => $this->config['api_key'], 'UserName' => $this->config['api_user'], 'ClientIp' => $this->config['client_ip'], 'Command' => $command] + $params);
        $r = $this->call('Namecheap', 'POST', self::URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($r['status'] === 405) { throw new DnsProviderException('Namecheap is limiting this key for now; try again in a few minutes'); }
        $x = $r['body'] !== '' ? @simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET) : false;
        if ($r['status'] !== 200 || $x === false) { throw new DnsProviderException('Namecheap answered in a way Sitewright cannot read (HTTP ' . $r['status'] . ')'); }
        if ((string)$x['Status'] !== 'OK') {
            $e = $x->Errors->Error[0] ?? null;
            $msg = $e !== null ? self::message((string)$e) : 'it refused the request';
            throw new DnsProviderException('Namecheap refused it: ' . $msg . ($e !== null ? ' (' . self::message((string)$e['Number']) . ')' : '') . '. Check that API access is switched on, the key is right, and that ' . $this->config['client_ip'] . ' is on the API whitelist.');
        }
        return $x;
    }

    /** @return array<string,array{expires:int,own_dns:bool}> */
    private function load(): array
    {
        if ($this->list !== null) { return $this->list; }
        $out = [];
        for ($page = 1; $page <= 50; $page++) {
            $x = $this->api('namecheap.domains.getList', ['PageSize' => '100', 'Page' => (string)$page, 'SortBy' => 'NAME']);
            $n = 0;
            foreach ($x->CommandResponse->DomainGetListResult->Domain ?? [] as $d) {
                $n++;
                $name = Acme::cleanName((string)$d['Name']);
                $t = strtotime(trim((string)$d['Expires']) . ' UTC');   // MM/DD/YYYY
                if ($name === null) { continue; }
                $out[$name] = ['expires' => $t !== false && $t > 946684800 ? $t : 0, 'own_dns' => strtolower((string)$d['IsOurDNS']) === 'true'];
            }
            $total = (int)($x->CommandResponse->Paging->TotalItems ?? 0);
            if ($n === 0 || $page * 100 >= $total) { break; }
        }
        ksort($out);
        return $this->list = $out;
    }

    public function domains(): ?array { return array_keys($this->load()); }

    public function elsewhere(): array
    {
        $this->load();
        return array_map(static fn () => 'name servers are not Namecheap\'s', array_filter($this->list ?? [], static fn (array $d): bool => !$d['own_dns']));
    }

    public function registration(string $host): ?array
    {
        $labels = explode('.', $host); $list = $this->load();
        for ($i = 0; $i < min(3, count($labels) - 1); $i++) {
            $cand = implode('.', array_slice($labels, $i));
            if (isset($list[$cand])) { return $list[$cand]['expires'] > 0 ? ['expires' => $list[$cand]['expires'], 'domain' => $cand, 'registrar' => 'Namecheap'] : null; }
        }
        return null;
    }

    public function addTxt(string $name, string $value): string { throw new DnsProviderException(self::NO_DNS); }
    public function removeTxt(string $handle): void { throw new DnsProviderException(self::NO_DNS); }
    private const NO_DNS = 'Namecheap is connected for domain lists and expiry dates only. Its DNS interface replaces a domain\'s whole record list on every change, so Sitewright does not use it for certificates; use the account of the provider that hosts the DNS (for example Cloudflare).';

    /** Instead of writing a record: check that Namecheap knows the domain and when it ends. */
    public function test(string $domain): string
    {
        $r = $this->registration(Acme::cleanName($domain) ?? '');
        if ($r === null) { throw new DnsProviderException($domain . ' is not a domain of this Namecheap account (or has no expiry date).'); }
        return 'Namecheap knows ' . $r['domain'] . ' and says the registration ends on ' . gmdate('Y-m-d', $r['expires']) . '.';
    }

    public function verify(): string
    {
        $list = $this->load();
        if (!$list) { return 'The key works, but this account has no domains.'; }
        $own = count(array_filter($list, static fn (array $d): bool => $d['own_dns']));
        return 'The key works and the account has ' . count($list) . ' domain' . (count($list) === 1 ? '' : 's') . ': ' . implode(', ', array_slice(array_keys($list), 0, 10)) . (count($list) > 10 ? ', …' : '') . '. '
            . $own . ' use Namecheap\'s own name servers. Used for domain lists and expiry dates only. Use "Test a domain" to check one.';
    }
}
