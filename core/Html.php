<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** HTML parsing helpers shared by the analysers (never fetches anything itself). */
final class Html
{
    /** Parse fetched bytes to a DOM, converting to UTF-8 using the HTTP charset or <meta charset>. */
    public static function load(string $body, string $contentTypeHeader = ''): \DOMDocument
    {
        $charset = null;
        if (preg_match('/charset=([\w\-]+)/i', $contentTypeHeader, $m)) { $charset = $m[1]; }
        elseif (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($body, 0, 4096), $m)) { $charset = $m[1]; }
        if ($charset !== null && !in_array(strtoupper($charset), ['UTF-8', 'UTF8'], true)
            && in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        } elseif (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        return $dom;
    }

    /** Visible text: script/style/noscript/template content is excluded. */
    public static function visibleText(\DOMDocument $dom): string
    {
        $xp = new \DOMXPath($dom);
        foreach (iterator_to_array($xp->query('//script|//style|//noscript|//template') ?: []) as $n) {
            $n->parentNode?->removeChild($n);
        }
        // Join text nodes with a space so adjacent block elements ("</h1><p>") never glue words together.
        $parts = [];
        foreach ($xp->query('//body//text() | //html[not(body)]//text()') ?: [] as $n) {
            $parts[] = $n->nodeValue;
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    /** Unicode-aware word count (letters/digits, keeping inner apostrophes and hyphens). */
    public static function wordCount(string $text): int
    {
        return (int)preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text);
    }

    /** Attributes that lazy-loading plugins use for the real address while "src" holds a blank placeholder, in the order they are tried. */
    private const LAZY_SRC = ['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-url'];
    private const LAZY_SET = ['data-srcset', 'data-lazy-srcset', 'srcset'];

    /**
     * The address of the picture an <img> really shows. Lazy-loading plugins (WP Rocket, Smush, Jetpack, a3, …) put a tiny inline placeholder in
     * "src" (usually a data: URI) and the real address in data-src and friends; the real one wins. Without a lazy attribute "src" is used, and
     * when there is no usable "src" the first entry of a srcset. A data:, javascript: or empty value is never an address. Null when nothing usable.
     */
    public static function imageSource(\DOMElement $img): ?string
    {
        $usable = static fn (string $v): bool => $v !== '' && !preg_match('/^(data|javascript|about|blob):/i', $v);
        $first = static fn (string $set): string => trim(explode(' ', trim(explode(',', $set)[0]))[0]);
        foreach (self::LAZY_SRC as $a) { $v = trim($img->getAttribute($a)); if ($usable($v)) { return $v; } }
        $v = trim($img->getAttribute('src'));
        if ($usable($v)) { return $v; }
        foreach (self::LAZY_SET as $a) { $v = $first($img->getAttribute($a)); if ($usable($v)) { return $v; } }
        return null;
    }

    /** A 1x1 (or 2x2) picture, the way tracking pixels (a Facebook pixel, a beacon) are written: not a picture anyone sees, so not part of an image check. */
    public static function isTrackingPixel(\DOMElement $img): bool
    {
        $w = (int)$img->getAttribute('width'); $h = (int)$img->getAttribute('height');
        return $w > 0 && $h > 0 && $w <= 2 && $h <= 2;
    }
}
