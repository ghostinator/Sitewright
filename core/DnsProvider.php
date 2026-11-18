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
    public const TYPES = ['cloudflare' => 'Cloudflare', 'spaceship' => 'Spaceship'];

    /** Test seam: type => fn(array $config): DnsProvider. */
    public static array $factories = [];

    /** @param array<string,string> $config */
    public static function make(string $type, array $config): self
    {
        if (isset(self::$factories[$type])) { return (self::$factories[$type])($config); }
        return match ($type) {
            'cloudflare' => new DnsCloudflare($config),
            'spaceship' => new DnsSpaceship($config),
            default => throw new DnsProviderException('Unknown DNS provider type'),
        };
    }

    /** Fields to ask for: key => label. All are secrets and are stored encrypted. @return array<string,string> */
    public static function fields(string $type): array
    {
        return match ($type) {
            'cloudflare' => ['token' => 'API token (Zone: Read and DNS: Edit on the zones you need)'],
            'spaceship' => ['api_key' => 'API key', 'api_secret' => 'API secret'],
            default => [],
        };
    }

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

    protected static function message(mixed $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)) ?? ''), 0, 200);
    }
}
