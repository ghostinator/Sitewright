<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Amazon Route 53 with an IAM access key (route53.amazonaws.com, AWS Signature Version 4). Give the key only: route53:ListHostedZones,
 * route53:ListResourceRecordSets, route53:ChangeResourceRecordSets and route53:GetChange (ideally on the hosted zones you need).
 * A name holds one record set, so a name and its wildcard (two TXT values on one name) are handled by reading the set, changing only our
 * value, and writing it back (UPSERT, or DELETE when nothing is left). A change is waited for until Route 53 reports it as in sync.
 */
final class DnsRoute53 extends DnsProvider
{
    private const HOST = 'route53.amazonaws.com';
    private const REGION = 'us-east-1';   // Route 53 is a global service signed for this region
    /** Test seam: fn(int $milliseconds). */
    public static $sleeper = null;

    /** @var array<string,string> zone name => zone id (public zones only) */
    private ?array $zones = null;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[A-Z0-9]{16,128}$/D', (string)($config['access_key_id'] ?? ''))) { throw new DnsProviderException('The AWS access key ID is missing or not the right shape'); }
        if (!preg_match('#^[A-Za-z0-9/+=]{30,100}$#D', (string)($config['secret_access_key'] ?? ''))) { throw new DnsProviderException('The AWS secret access key is missing or not the right shape'); }
    }

    /** The Authorization header of an AWS Signature Version 4 request (content-type, host and x-amz-date are the signed headers). */
    public static function authorization(string $method, string $host, string $path, string $query, string $contentType, string $payload, string $amzDate, string $accessKey, string $secret, string $region, string $service): string
    {
        $day = substr($amzDate, 0, 8);
        $signed = 'content-type;host;x-amz-date';
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $query . "\ncontent-type:" . $contentType . "\nhost:" . $host . "\nx-amz-date:" . $amzDate . "\n\n" . $signed . "\n" . hash('sha256', $payload);
        $scope = "$day/$region/$service/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);
        return "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signed, Signature=" . hash_hmac('sha256', $toSign, $k);
    }

    private function api(string $method, string $path, array $query = [], string $body = ''): \SimpleXMLElement
    {
        ksort($query); $q = implode('&', array_map(static fn ($k, $v) => rawurlencode((string)$k) . '=' . rawurlencode((string)$v), array_keys($query), $query));
        $ct = 'text/xml'; $date = gmdate('Ymd\THis\Z');
        $r = $this->call('Amazon Route 53', $method, 'https://' . self::HOST . $path . ($q !== '' ? '?' . $q : ''), ['Content-Type: ' . $ct, 'X-Amz-Date: ' . $date,
            'Authorization: ' . self::authorization($method, self::HOST, $path, $q, $ct, $body, $date, (string)$this->config['access_key_id'], (string)$this->config['secret_access_key'], self::REGION, 'route53')], $body === '' ? null : $body);
        $xml = $r['body'] !== '' ? @simplexml_load_string((string)preg_replace('/\sxmlns="[^"]*"/', '', $r['body'], 1), 'SimpleXMLElement', LIBXML_NONET) : false;
        if ($r['status'] < 200 || $r['status'] >= 300 || $xml === false) {
            $why = $xml !== false && isset($xml->Error->Message) ? self::message((string)$xml->Error->Message) : 'HTTP ' . $r['status'];
            $hint = in_array($r['status'], [401, 403], true) ? ' (check the access key, the secret and the policy attached to the user)' : '';
            throw new DnsProviderException('Amazon Route 53 refused it: ' . $why . $hint);
        }
        return $xml;
    }

    /** @return array<string,string> */
    private function zones(): array
    {
        if ($this->zones !== null) { return $this->zones; }
        $zones = []; $marker = '';
        for ($i = 0; $i < 20; $i++) {
            $x = $this->api('GET', '/2013-04-01/hostedzone', ['maxitems' => '100'] + ($marker !== '' ? ['marker' => $marker] : []));
            foreach ($x->HostedZones->HostedZone ?? [] as $z) {
                $name = Acme::cleanName(rtrim((string)$z->Name, '.')); $id = preg_replace('#^/hostedzone/#', '', (string)$z->Id);
                if ($name !== null && preg_match('/^[A-Z0-9]{5,32}$/D', (string)$id) && strtolower((string)$z->Config->PrivateZone) !== 'true' && !isset($zones[$name])) { $zones[$name] = (string)$id; }
            }
            if (strtolower((string)$x->IsTruncated) !== 'true') { break; }
            $marker = (string)$x->NextMarker;
        }
        ksort($zones);
        return $this->zones = $zones;
    }

    public function domains(): ?array { return array_keys($this->zones()); }

    /** @return array{ttl:int,values:list<string>} the TXT values now at this name */
    private function current(string $zoneId, string $name): array
    {
        $x = $this->api('GET', '/2013-04-01/hostedzone/' . $zoneId . '/rrset', ['name' => $name . '.', 'type' => 'TXT', 'maxitems' => '1']);
        foreach ($x->ResourceRecordSets->ResourceRecordSet ?? [] as $s) {
            if (strtolower((string)$s->Name) === strtolower($name) . '.' && (string)$s->Type === 'TXT') {
                $vals = []; foreach ($s->ResourceRecords->ResourceRecord ?? [] as $rr) { $vals[] = (string)$rr->Value; }
                return ['ttl' => max(1, (int)$s->TTL), 'values' => $vals];
            }
        }
        return ['ttl' => 60, 'values' => []];
    }

    private function change(string $zoneId, string $action, string $name, int $ttl, array $values): void
    {
        $rr = ''; foreach ($values as $v) { $rr .= '<ResourceRecord><Value>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Value></ResourceRecord>'; }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/"><ChangeBatch><Comment>Sitewright certificate validation</Comment><Changes><Change><Action>' . $action . '</Action>'
            . '<ResourceRecordSet><Name>' . htmlspecialchars($name, ENT_XML1, 'UTF-8') . '.</Name><Type>TXT</Type><TTL>' . $ttl . '</TTL><ResourceRecords>' . $rr . '</ResourceRecords></ResourceRecordSet></Change></Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
        $x = $this->api('POST', '/2013-04-01/hostedzone/' . $zoneId . '/rrset', [], $xml);
        $cid = preg_replace('#^/change/#', '', (string)$x->ChangeInfo->Id);
        if (!preg_match('/^[A-Z0-9]{5,32}$/D', (string)$cid)) { throw new DnsProviderException('Route 53 did not return the change'); }
        for ($i = 0; $i < 60; $i++) {   // up to about two minutes: Route 53 usually needs under a minute to reach every name server
            if ((string)$this->api('GET', '/2013-04-01/change/' . $cid)->ChangeInfo->Status === 'INSYNC') { return; }
            (self::$sleeper ?? static fn (int $ms) => usleep($ms * 1000))(2000);
        }
        throw new DnsProviderException('Route 53 did not finish the change in time');
    }

    public function addTxt(string $name, string $value): string
    {
        $zones = $this->zones(); $zone = self::zoneIn(array_keys($zones), $name, 'Amazon Route 53'); $id = $zones[$zone];
        $cur = $this->current($id, $name);
        $this->change($id, 'UPSERT', $name, 60, array_values(array_unique([...$cur['values'], '"' . $value . '"'])));
        return $id . '|' . $name . '|' . $value;
    }

    public function removeTxt(string $handle): void
    {
        if (!preg_match('/^([A-Z0-9]{5,32})\|([A-Za-z0-9_.-]{3,253})\|([A-Za-z0-9_-]{1,200})$/D', $handle, $m)) { throw new DnsProviderException('Not a record handle'); }
        $cur = $this->current($m[1], $m[2]);
        $left = array_values(array_diff($cur['values'], ['"' . $m[3] . '"']));
        if (count($left) === count($cur['values'])) { return; }   // already gone
        $this->change($m[1], $left ? 'UPSERT' : 'DELETE', $m[2], $cur['ttl'], $left ?: $cur['values']);
    }

    public function verify(): string { return self::summary($this->domains() ?? [], 'public hosted zone'); }
}
