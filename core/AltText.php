<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Suggests alt text for an image from what is known about it, with an honest confidence. Rules only: nothing is sent anywhere and the same input always
 * gives the same answer. Order of trust: the page or product the image belongs to (its title says what the picture is of: "good"); else words that can be
 * read in the image's own title or file name once camera, tool and random-code noise is removed ("fair": a starting point to check); else nothing ("none":
 * a person has to look at it). Never longer than 125 characters (what screen readers handle well).
 */
final class AltText
{
    public const MAX = 125;
    private const NOISE = ['img', 'image', 'images', 'photo', 'picture', 'pic', 'dsc', 'dscn', 'pxl', 'screenshot', 'screen', 'shot', 'cleanshot', 'gemini', 'generated', 'untitled', 'copy', 'final', 'new', 'wp', 'edited', 'scaled', 'upscaled', 'gigapixel', 'fidelity', 'high', 'hq', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'am', 'pm', 'at', 'the', 'of', 'and', 'for', 'with', 'from'];
    private const VIEWS = ['front' => 'front view', 'back' => 'back view', 'side' => 'side view', 'left' => 'left view', 'right' => 'right view', 'detail' => 'detail', 'closeup' => 'close-up', 'lifestyle' => 'in use', 'model' => 'on a model'];

    /** @return array{text:string,confidence:string} confidence is "good", "fair" or "none" */
    public static function suggest(string $title, string $url, string $parentTitle = ''): array
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';   // a generated id is not a description, whatever words its digits spell
        if (preg_match($uuid, trim($title)) || ($title === '' && preg_match($uuid, self::fileStem($url)))) { return ['text' => '', 'confidence' => 'none']; }
        $own = self::words($title !== '' ? $title : self::fileStem($url));
        $parent = SiteProfile::clean($parentTitle, 200) ?? '';
        if (count(self::plainWords($parent)) >= 2) {
            $view = '';
            foreach (array_merge($own, self::words(self::fileStem($url))) as $w) { if (isset(self::VIEWS[$w])) { $view = self::VIEWS[$w]; break; } }
            return ['text' => self::cut($parent . ($view !== '' ? ' (' . $view . ')' : '')), 'confidence' => 'good'];
        }
        $written = SiteProfile::clean($title, 200) ?? '';   // a title someone typed as words ("Riverwalk mural at sunset") is kept as written; a file name used as a title is not
        if (count(self::plainWords($written)) >= 3 && !preg_match('/[_]|\d{3,}|\d+x\d+|\bwp\b/i', $written) && !preg_match('/-\w+-/', $written)) { return ['text' => self::cut($written), 'confidence' => 'fair']; }
        $real = array_values(array_filter($own, static fn (string $w): bool => !isset(self::VIEWS[$w]) || count($own) === 1));
        if (!$real) { $real = array_values(array_filter(self::words(self::fileStem($url)))); }
        if (!$real) { return ['text' => '', 'confidence' => 'none']; }
        return ['text' => self::cut(ucfirst(implode(' ', $real))), 'confidence' => 'fair'];
    }

    private static function fileStem(string $url): string
    {
        return preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)))) ?? '';
    }

    /** The meaningful lower-case words of a name: separators, sizes, dates, camera and tool words and random codes removed. @return list<string> */
    private static function words(string $s): array
    {
        $s = preg_replace('/\b\d{2,5}x\d{2,5}\b/i', ' ', (string)(SiteProfile::clean($s, 300) ?? '')) ?? '';
        $out = [];
        foreach (preg_split('/[\s_\-.+,;:()\[\]]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            $t = strtolower($t);
            if (preg_match('/^\d+$/', $t) || preg_match('/^v\d+$/', $t) || preg_match('/^\d+x$/', $t)) { continue; }                       // numbers, v2, 4x
            if (strlen($t) >= 10 && preg_match('/\d/', $t) && preg_match('/[a-z]/', $t)) { continue; }                                    // random codes: d9ggy0d9ggy0d9gg
            if (strlen($t) >= 24) { continue; }
            $t = preg_replace('/\d+$/', '', $t) ?? $t;                                                                                    // designer8 -> designer
            if (mb_strlen($t) < 3 || in_array($t, self::NOISE, true) || !preg_match('/^[\p{L}\']+$/u', $t)) { continue; }
            $out[] = $t;
        }
        return $out;
    }

    /** @return list<string> the words of a title worth counting (a title of one stray word is not a description) */
    private static function plainWords(string $s): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $w): bool => mb_strlen($w) >= 2 && preg_match('/\pL/u', $w) === 1));
    }

    private static function cut(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if (mb_strlen($s) <= self::MAX) { return $s; }
        $c = mb_substr($s, 0, self::MAX);
        $sp = mb_strrpos($c, ' ');
        return rtrim($sp !== false && $sp > 60 ? mb_substr($c, 0, $sp) : $c, ' ,;:-(');
    }
}
