<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Name, address, phone consistency. Reads the phone numbers and addresses a page states in a machine-readable way (tel: links and JSON-LD structured
 * data; free text is not guessed at, it produces too many false alarms) and compares them with what the owner chose in the setup guide, or with each
 * other when nothing was chosen. Everything read from a page is untrusted: shortened, stripped of markup and used only for comparison and display.
 */
final class Nap
{
    private const MAX_PER_PAGE = 10;

    /** @return array{phones:list<string>,addresses:list<array{street:string,postal:string}>} what one page states */
    public static function extract(\DOMDocument $dom): array
    {
        $xp = new \DOMXPath($dom);
        $phones = []; $addresses = [];
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            $h = trim((string)$a->getAttribute('href'));
            if (preg_match('/^tel:(.{1,60})$/i', $h, $m) && self::key(rawurldecode($m[1])) !== null) { $phones[] = (string)SiteProfile::clean(rawurldecode($m[1]), 30); }
            if (count($phones) >= self::MAX_PER_PAGE) { break; }
        }
        $nodes = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode((string)$script->textContent, true, 64);
            if (is_array($data)) { SiteProfile::collectNodes($data, 0, $nodes); }
            if (count($nodes) >= 200) { break; }
        }
        foreach ($nodes as ['n' => $n]) {
            foreach ((array)($n['telephone'] ?? []) as $t) { if (is_string($t) && self::key($t) !== null && count($phones) < self::MAX_PER_PAGE) { $phones[] = (string)SiteProfile::clean($t, 30); } }
            $addr = ($n['@type'] ?? null) === 'PostalAddress' ? $n : ($n['address'] ?? null);
            if (is_array($addr) && count($addresses) < self::MAX_PER_PAGE) {
                $street = (string)SiteProfile::clean($addr['streetAddress'] ?? '', 200); $postal = (string)SiteProfile::clean($addr['postalCode'] ?? '', 20);
                if ($street !== '' || $postal !== '') { $addresses[] = ['street' => $street, 'postal' => $postal]; }
            }
        }
        return ['phones' => array_values(array_unique($phones)), 'addresses' => array_values(array_unique($addresses, SORT_REGULAR))];
    }

    /** Digits only; a leading 1 on an 11-digit number is dropped; null when it is not a plausible phone number (7 to 15 digits). */
    public static function key(string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($d) === 11 && $d[0] === '1') { $d = substr($d, 1); }
        return strlen($d) >= 7 && strlen($d) <= 15 ? $d : null;
    }

    /** Two numbers are the same when their digits match, or one is the other with a country code in front. */
    public static function samePhone(string $a, string $b): bool
    {
        $x = self::key($a); $y = self::key($b);
        if ($x === null || $y === null) { return false; }
        return $x === $y || (strlen($x) >= 10 && strlen($y) >= 10 && substr($x, -10) === substr($y, -10));
    }

    /** House number (leading digits of the street) and postal code are what is compared; the rest of an address is written too many ways. */
    public static function sameAddress(array $a, array $b): ?bool
    {
        $pa = strtolower(preg_replace('/\s+/', '', $a['postal']) ?? ''); $pb = strtolower(preg_replace('/\s+/', '', $b['postal']) ?? '');
        $na = preg_match('/^\s*(\d+)/', $a['street'], $m1) ? $m1[1] : ''; $nb = preg_match('/^\s*(\d+)/', $b['street'], $m2) ? $m2[1] : '';
        $checks = [];
        if ($pa !== '' && $pb !== '') { $checks[] = $pa === $pb; }
        if ($na !== '' && $nb !== '') { $checks[] = $na === $nb; }
        return $checks === [] ? null : !in_array(false, $checks, true);
    }

    /**
     * @param array<string,array{phones:list<string>,addresses:list<array>}> $perPage by page address
     * @param array<string,string> $chosen the owner's chosen profile values (phone, street, postal_code)
     * @return list<array{0:string,1:string,2:string}> issues [code, url, detail]
     */
    public static function issues(array $perPage, array $chosen): array
    {
        $out = [];
        $phone = $chosen['phone'] ?? ''; $ref = ['street' => $chosen['street'] ?? '', 'postal' => $chosen['postal_code'] ?? ''];
        if ($phone !== '' && self::key($phone) !== null) {
            foreach ($perPage as $url => $p) {
                foreach ($p['phones'] as $t) { if (!self::samePhone($t, $phone)) { $out[] = ['nap_phone_differs', $url, 'Shows ' . $t . '; your phone is ' . $phone]; } }
            }
        } else {
            $count = [];
            foreach ($perPage as $p) { foreach ($p['phones'] as $t) { $k = self::key($t); if ($k !== null) { $count[$k]['shown'] = $count[$k]['shown'] ?? $t; $count[$k]['pages'] = ($count[$k]['pages'] ?? 0) + 1; } } }
            if (count($count) > 1) {
                uasort($count, static fn ($a, $b) => $b['pages'] <=> $a['pages']);
                $first = (string)array_key_first($perPage);
                $list = implode('; ', array_map(static fn ($c) => $c['shown'] . ' (' . $c['pages'] . ' page' . ($c['pages'] === 1 ? '' : 's') . ')', array_slice($count, 0, 5)));
                $out[] = ['nap_phone_varies', $first, 'Phone numbers shown: ' . $list . '. Choose yours in the setup guide to see which pages differ.'];
            }
        }
        if ($ref['street'] !== '' || $ref['postal'] !== '') {
            foreach ($perPage as $url => $p) {
                foreach ($p['addresses'] as $a) {
                    if (self::sameAddress($a, $ref) === false) { $out[] = ['nap_address_differs', $url, 'Shows ' . trim($a['street'] . ' ' . $a['postal']) . '; your address is ' . trim($ref['street'] . ' ' . $ref['postal'])]; }
                }
            }
        }
        return $out;
    }
}
