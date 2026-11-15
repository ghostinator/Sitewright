<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

/** RFC 3986 reference resolution and http(s) URL normalisation, shared by the crawler and image analysis. */
final class Url
{
    /** @return array{scheme:?string,authority:?string,path:string,query:?string,fragment:?string}|null */
    public static function parse(string $url): ?array
    {
        // Browsers drop tab/CR/LF inside URLs; other control characters make the URL invalid.
        $url = str_replace(["\t", "\r", "\n"], '', trim($url));
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }
        if (!preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $url, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $scheme = $m[1];
        if ($scheme !== null && !preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*$/D', $scheme)) {
            return null;
        }
        return [
            'scheme'    => $scheme !== null ? strtolower($scheme) : null,
            'authority' => $m[2],
            'path'      => (string)$m[3],
            'query'     => $m[4],
            'fragment'  => $m[5],
        ];
    }

    /** Resolve $ref against absolute $base (RFC 3986 §5.2). Returns null if either is unusable. */
    public static function resolve(string $base, string $ref): ?string
    {
        $b = self::parse($base);
        $r = self::parse($ref);
        if ($b === null || $r === null || $b['scheme'] === null) {
            return null;
        }
        if ($r['scheme'] !== null) {
            $t = ['scheme' => $r['scheme'], 'authority' => $r['authority'], 'path' => self::removeDots($r['path']), 'query' => $r['query']];
        } elseif ($r['authority'] !== null) {
            $t = ['scheme' => $b['scheme'], 'authority' => $r['authority'], 'path' => self::removeDots($r['path']), 'query' => $r['query']];
        } else {
            if ($r['path'] === '') {
                $path = $b['path'];
                $query = $r['query'] ?? $b['query'];
            } else {
                if ($r['path'][0] === '/') {
                    $path = self::removeDots($r['path']);
                } else {
                    $merged = ($b['authority'] !== null && $b['path'] === '')
                        ? '/' . $r['path']
                        : (($pos = strrpos($b['path'], '/')) === false ? $r['path'] : substr($b['path'], 0, $pos + 1) . $r['path']);
                    $path = self::removeDots($merged);
                }
                $query = $r['query'];
            }
            $t = ['scheme' => $b['scheme'], 'authority' => $b['authority'], 'path' => $path, 'query' => $query];
        }
        $out = $t['scheme'] . ':';
        if ($t['authority'] !== null) { $out .= '//' . $t['authority']; }
        $out .= $t['path'];
        if ($t['query'] !== null) { $out .= '?' . $t['query']; }
        return $out; // fragment intentionally dropped: it is never sent to servers
    }

    /** Canonical http(s) URL for de-duplication: lower-case scheme/host, default port removed, dot segments removed, no fragment. */
    public static function normalize(string $url): ?string
    {
        $p = self::parse($url);
        if ($p === null || !in_array($p['scheme'], ['http', 'https'], true) || $p['authority'] === null || $p['authority'] === '') {
            return null;
        }
        $auth = $p['authority'];
        if (str_contains($auth, '@')) { return null; }
        if (!preg_match('/^(\[[0-9a-fA-F:.]+\]|[^:\[\]]+)(?::(\d{1,5}))?$/D', $auth, $m)) { return null; }
        $host = strtolower($m[1]);
        $port = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null;
        if ($port === ($p['scheme'] === 'https' ? 443 : 80)) { $port = null; }
        $path = self::removeDots($p['path']);
        $path = $path === '' ? '/' : $path;
        $path = str_replace([' ', '"', '<', '>', '`'], ['%20', '%22', '%3C', '%3E', '%60'], $path);
        $q = $p['query'] !== null ? '?' . str_replace([' ', '"', '<', '>', '`'], ['%20', '%22', '%3C', '%3E', '%60'], $p['query']) : '';
        return $p['scheme'] . '://' . $host . ($port !== null ? ':' . $port : '') . $path . $q;
    }

    public static function removeDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($seg === '.') {
                if ($i === $last) { $out[] = ''; }
                continue;
            }
            if ($seg === '..') {
                if (count($out) > 1 || (count($out) === 1 && $out[0] !== '')) { array_pop($out); }
                if ($i === $last) { $out[] = ''; }
                continue;
            }
            $out[] = $seg;
        }
        $res = implode('/', $out);
        if ($path !== '' && $path[0] === '/' && ($res === '' || $res[0] !== '/')) { $res = '/' . $res; }
        return $res;
    }

    /** host[:port] of an http(s) URL, lower-cased; null if not http(s). */
    public static function origin(string $url): ?string
    {
        $n = self::normalize($url);
        if ($n === null) { return null; }
        $p = self::parse($n);
        return $p['scheme'] . '://' . $p['authority'];
    }
}
