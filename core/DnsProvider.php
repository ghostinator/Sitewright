<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * A DNS host Sitewright may write TXT records to, only to prove that you own a name to the certificate service (DNS-01).
 * Give each provider an API token limited to the zones and to editing DNS records; Sitewright never needs more.
 */
abstract class DnsProvider
{
    /** type => label */
    public const TYPES = ['cloudflare' => 'Cloudflare', 'spaceship' => 'Spaceship', 'route53' => 'Amazon Route 53', 'digitalocean' => 'DigitalOcean', 'porkbun' => 'Porkbun', 'desec' => 'deSEC', 'gandi' => 'Gandi', 'linode' => 'Linode (Akamai)', 'vultr' => 'Vultr'];

    /** Test seam: type => fn(array $config): DnsProvider. */
    public static array $factories = [];

    /** @param array<string,string> $config */
    public static function make(string $type, array $config): self
    {
        if (isset(self::$factories[$type])) { return (self::$factories[$type])($config); }
        return match ($type) {
            'cloudflare' => new DnsCloudflare($config),
            'spaceship' => new DnsSpaceship($config),
            'route53' => new DnsRoute53($config),
            'digitalocean' => new DnsDigitalOcean($config),
            'porkbun' => new DnsPorkbun($config),
            'desec' => new DnsDesec($config),
            'gandi' => new DnsGandi($config),
            'linode' => new DnsLinode($config),
            'vultr' => new DnsVultr($config),
            default => throw new DnsProviderException('Unknown DNS provider type'),
        };
    }

    /** Fields to ask for: key => label. All are secrets and are stored encrypted. @return array<string,string> */
    public static function fields(string $type): array
    {
        return match ($type) {
            'cloudflare' => ['token' => 'API token (Zone: Read and DNS: Edit on the zones you need)'],
            'spaceship' => ['api_key' => 'API key', 'api_secret' => 'API secret'],
            'route53' => ['access_key_id' => 'Access key ID', 'secret_access_key' => 'Secret access key'],
            'digitalocean' => ['token' => 'API token (read and write)'],
            'porkbun' => ['api_key' => 'API key', 'api_secret' => 'Secret API key'],
            'desec' => ['token' => 'API token'],
            'gandi' => ['token' => 'Personal access token (LiveDNS)'],
            'linode' => ['token' => 'Personal access token (Domains read/write)'],
            'vultr' => ['token' => 'API key'],
            default => [],
        };
    }

    /** The domain names this account can really change DNS for (null when the provider cannot say). Used to catch a mistyped name before anything is requested. @return ?list<string> */
    public function domains(): ?array { return null; }

    /** Domains this account knows but whose DNS is hosted somewhere else, name => where. Filled by domains(). @return array<string,string> */
    public function elsewhere(): array { return []; }

    /**
     * When the registrar of this account says a domain registration runs out, for providers that are also registrars (null when this account does not
     * register the name or the provider cannot say). Used when the public registry lookup has no answer (for example .co).
     * @return ?array{expires:int,domain:string,registrar:string}
     */
    public function registration(string $host): ?array { return null; }

    /** Add a TXT record. @return string a handle to remove it again */
    abstract public function addTxt(string $name, string $value): string;

    abstract public function removeTxt(string $handle): void;

    /** Check the credentials. @return string what was found, in words @throws DnsProviderException */
    abstract public function verify(): string;

    /** Prove that records can really be added and removed for this domain, by adding and removing a harmless TXT record. @return string */
    public function test(string $domain): string
    {
        $name = Acme::cleanName($domain);
        if ($name === null || $name[0] === '*') { throw new DnsProviderException('Enter a domain name such as example.com'); }
        $h = $this->addTxt('_sitewright-test.' . $name, 'sitewright-dns-test');
        $this->removeTxt($h);
        return 'A test record was added to ' . $name . ' and removed again.';
    }

    /** One HTTPS call to a provider through the address guard. Throws a readable error when it cannot be made or the provider is rate limiting. @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
    protected function call(string $who, string $method, string $url, array $headers, ?string $body = null): array
    {
        try {
            $res = SafeHttpClient::default()->request($method, $url, ['headers' => $headers, 'body' => $body ?? '', 'accept' => '*/*', 'follow' => false, 'max_bytes' => 2097152, 'timeout' => 20]);
        } catch (FetchException $e) {
            throw new DnsProviderException('Could not reach ' . $who . ': ' . self::message($e->getMessage()));
        }
        if ($res['status'] === 429) { throw new DnsProviderException($who . ' is limiting this key for now; try again in a few minutes'); }
        return ['status' => (int)$res['status'], 'headers' => $res['headers'], 'body' => $res['body'], 'json' => json_decode($res['body'], true)];
    }

    /** The domain of the account that holds this name: the longest one that is the name or a parent of it. @param list<string> $domains */
    protected static function zoneIn(array $domains, string $name, string $who): string
    {
        $best = '';
        foreach ($domains as $d) { if (($name === $d || str_ends_with($name, '.' . $d)) && strlen($d) > strlen($best)) { $best = $d; } }
        if ($best === '') { throw new DnsProviderException('This ' . $who . ' key cannot see a domain for ' . $name . ' (is it in this account?)'); }
        return $best;
    }

    /** The record name inside the zone ("_acme-challenge.shop" for "_acme-challenge.shop.example.com" in "example.com"). */
    protected static function relative(string $name, string $zone): string
    {
        return $name === $zone ? '' : substr($name, 0, -strlen('.' . $zone));
    }

    /** "The key works and sees N domains: a, b, …" for verify(). @param list<string> $names */
    protected static function summary(array $names, string $thing = 'domain'): string
    {
        if (!$names) { return 'The key works, but it cannot see any ' . $thing . '. Check what it is allowed to use.'; }
        return 'The key works and can see ' . count($names) . ' ' . $thing . (count($names) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '') . '. Use "Test a domain" to prove it can edit DNS.';
    }

    protected static function message(mixed $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)) ?? ''), 0, 200);
    }
}
