<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Adds the domains a DNS account manages as sites, but only those that really serve a website. Each domain is looked at (https, with and without
 * "www"): a domain that does not answer, answers with an error, blocks automated visitors, is not a web page, or just redirects to another domain
 * is skipped with the reason, so parked domains and mail-only domains do not fill the Overview. Imported sites have no WordPress plugin; they are
 * read, watched and checked for certificate and domain expiry like any other site. Runs as a background job (a few seconds per domain).
 */
final class SiteImport
{
    public const MAX = 100;

    /** @return list<array{name:string,added:bool}> */
    public static function listing(array $domains): array
    {
        $have = self::existingHosts();
        return array_map(static fn (string $d): array => ['name' => $d, 'added' => isset($have[self::bare($d)])], $domains);
    }

    /** Only valid plain host names, lower case, no duplicates. @return list<string> */
    public static function clean(array $names): array
    {
        $out = [];
        foreach ($names as $n) { $c = is_string($n) ? Acme::cleanName($n) : null; if ($c !== null && $c[0] !== '*') { $out[$c] = true; } }
        return array_slice(array_keys($out), 0, self::MAX);
    }

    public static function bare(string $host): string { return preg_replace('/^www\./', '', strtolower($host)) ?? $host; }

    /** @return array<string,true> hosts (without "www.") of the sites that exist */
    private static function existingHosts(): array
    {
        $db = Database::getInstance(); $have = [];
        foreach ($db->fetchAll('SELECT base_url FROM `' . $db->t('sites') . '`') as $s) { $h = parse_url((string)$s['base_url'], PHP_URL_HOST); if (is_string($h)) { $have[self::bare($h)] = true; } }
        return $have;
    }

    /** @return array{url:?string,reason:?string} the address of the site if the domain serves a website, else why not */
    public static function probe(string $domain): array
    {
        $reason = 'Did not answer';
        foreach ([$domain, 'www.' . $domain] as $host) {
            try {
                $r = SafeHttpClient::default()->get('https://' . $host . '/', ['max_bytes' => 262144, 'timeout' => 10, 'deadline' => microtime(true) + 25]);
            } catch (FetchException $e) {
                $reason = 'Did not answer (' . mb_substr($e->getMessage(), 0, 80) . ')';
                continue;
            }
            $final = Url::normalize($r['final_url']);
            $fh = $final !== null ? strtolower((string)parse_url($final, PHP_URL_HOST)) : '';
            if ($fh === '') { $reason = 'Answered with an unusable address'; continue; }
            if (self::bare($fh) !== self::bare($domain) && !str_ends_with($fh, '.' . self::bare($domain))) { return ['url' => null, 'reason' => 'Redirects to ' . $fh]; }
            if ($r['status'] === 401 || $r['status'] === 403 || $r['status'] === 429) { return ['url' => null, 'reason' => 'Blocks automated visitors (HTTP ' . $r['status'] . ')']; }
            if ($r['status'] >= 400) { $reason = 'Answered with HTTP ' . $r['status']; continue; }
            if (!in_array($r['content_type'], ['text/html', 'application/xhtml+xml'], true)) { return ['url' => null, 'reason' => 'Not a web page (' . ($r['content_type'] ?: 'no content type') . ')']; }
            return ['url' => rtrim((string)Url::origin($final), '/'), 'reason' => null];
        }
        return ['url' => null, 'reason' => $reason];
    }

    /** Job: payload {domains:list<string>}. Never fails the job over one domain. */
    public static function handleJob(array $p): array
    {
        $db = Database::getInstance();
        $added = []; $skipped = [];
        $have = self::existingHosts();
        foreach (self::clean((array)($p['domains'] ?? [])) as $i => $domain) {
            if (isset($have[self::bare($domain)])) { $skipped[] = ['domain' => $domain, 'reason' => 'Already added']; continue; }
            if ($i > 0) { usleep(300000); }
            Jobs::touch();
            try { $r = self::probe($domain); } catch (\Throwable $e) { Logger::warn('site_import_failed', ['domain' => $domain, 'error' => get_class($e)]); $skipped[] = ['domain' => $domain, 'reason' => 'Could not be checked']; continue; }
            if ($r['url'] === null) { $skipped[] = ['domain' => $domain, 'reason' => (string)$r['reason']]; continue; }
            $host = (string)parse_url($r['url'], PHP_URL_HOST);
            if (isset($have[self::bare($host)])) { $skipped[] = ['domain' => $domain, 'reason' => 'Already added']; continue; }
            if ($db->fetchOne('SELECT id FROM `' . $db->t('sites') . '` WHERE base_url = :u', ['u' => $r['url']])) { $skipped[] = ['domain' => $domain, 'reason' => 'Already added']; continue; }
            $id = (int)$db->insert('sites', ['name' => $domain, 'base_url' => $r['url'], 'secret_enc' => '', 'sync_enabled' => 0]);
            Audit::log('site_added', null, '', $domain . ' (' . $r['url'] . ') imported from a DNS account');
            try { SiteProfile::queueUnlessOpen($id); } catch (\Throwable) { /* the scheduler picks it up */ }
            $have[self::bare($host)] = true;
            $added[] = ['domain' => $domain, 'url' => $r['url'], 'id' => $id];
        }
        return ['message' => 'Added ' . count($added) . ' site' . (count($added) === 1 ? '' : 's') . ', skipped ' . count($skipped), 'data' => ['added' => $added, 'skipped' => $skipped]];
    }
}
