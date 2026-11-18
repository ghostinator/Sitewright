<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Placeholder until the Spaceship API details are confirmed (see the ledger). */
final class DnsSpaceship extends DnsProvider
{
    public function __construct(private array $config) {}
    public function addTxt(string $name, string $value): string { throw new DnsProviderException('Spaceship is not available yet'); }
    public function removeTxt(string $handle): void { throw new DnsProviderException('Spaceship is not available yet'); }
    public function verify(): string { throw new DnsProviderException('Spaceship is not available yet'); }
}
