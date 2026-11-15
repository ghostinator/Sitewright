<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

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
        $body = $dom->getElementsByTagName('body')->item(0);
        return trim(preg_replace('/\s+/u', ' ', (string)($body?->textContent ?? $dom->textContent)) ?? '');
    }

    /** Unicode-aware word count (letters/digits, keeping inner apostrophes and hyphens). */
    public static function wordCount(string $text): int
    {
        return (int)preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text);
    }
}
