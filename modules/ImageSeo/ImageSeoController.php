<?php
declare(strict_types=1);

namespace Sitewright\Modules\ImageSeo;

use Sitewright\Core\{Config, Database, FetchException, Html, Request, Response, SafeHttpClient, Url, Validator};

/**
 * Image checks: alt text present, file size, pixel dimensions. Bytes are downloaded through SafeHttpClient
 * (size-capped) and measured with getimagesizefromstring(); URLs are never handed to getimagesize().
 * Limitation: <img> tags are read (the real address from lazy-loading attributes, else src, else the first srcset entry); CSS background images and images injected by JavaScript are not seen.
 */
class ImageSeoController
{
    private static function optimalBytes(): int
    {
        return (int)Config::get('image.optimal_bytes');
    }

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getImages(array $params): never
    {
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $opt = Request::query('optimized');
        $sql = 'SELECT id, project_id, image_url, alt_text, title, caption, description, file_size, dimensions, optimized, created_at FROM `' . $this->db->t('image_seo') . '` WHERE 1=1';
        $q = [];
        if ($projectId > 0) { $sql .= ' AND project_id = :p'; $q['p'] = $projectId; }
        if ($opt === 'unknown') { $sql .= ' AND optimized IS NULL'; }
        elseif ($opt === '0' || $opt === '1') { $sql .= ' AND optimized = :o'; $q['o'] = (int)$opt; }
        Response::success('Images retrieved', $this->db->fetchAll($sql . ' ORDER BY created_at DESC, id DESC LIMIT 1000', $q));
    }

    public function analyzeImage(array $params): never
    {
        $in = Request::json();
        $url = Validator::url($in, 'image_url', 1000);
        $projectId = Validator::projectId($in);
        $info = $this->measure($url, microtime(true) + 30);
        if ($info === null) { Response::error('Could not download or read that image', 502); }

        $id = $this->save($projectId, $info['url'], null, null, $info);
        Response::success('Image analyzed', [
            'id' => $id, 'image_url' => $info['url'], 'file_size' => $info['size'], 'dimensions' => $info['dimensions'],
            'is_optimized' => $info['size'] === null ? null : $info['size'] < self::optimalBytes(),
            'recommendations' => self::recommendations($info['size']),
        ]);
    }

    public function updateImage(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $in = Request::json();
        $data = [];
        foreach (['alt_text' => 500, 'title' => 500, 'caption' => 2000, 'description' => 2000] as $f => $max) {
            if (array_key_exists($f, $in)) { $data[$f] = Validator::str($in, $f, $max); }
        }
        if (array_key_exists('optimized', $in)) {
            $o = $in['optimized'];
            if ($o !== null && $o !== 0 && $o !== 1 && $o !== true && $o !== false) { Validator::fail('optimized', 'must be true, false or null'); }
            $data['optimized'] = $o === null ? null : (int)$o;
        }
        if (!$data) { Response::error('No data to update', 400); }
        $this->db->update('image_seo', $data, ['id' => $id]) >= 0;
        Response::success('Image updated');
    }

    public function bulkAnalyze(array $params): never
    {
        $in = Request::json();
        $pageUrl = Validator::url($in, 'url');
        $projectId = Validator::projectId($in);
        $maxImages = (int)Config::get('limits.bulk_images');
        $deadline = microtime(true) + 90;
        set_time_limit(120);

        try {
            $page = SafeHttpClient::default()->get($pageUrl, ['max_bytes' => (int)Config::get('fetch.max_html_bytes'), 'deadline' => $deadline]);
        } catch (FetchException $e) {
            Response::error('Could not fetch the page: ' . $e->getMessage(), $e->httpStatus === 400 ? 400 : 502);
        }
        if ($page['status'] >= 400) { Response::error('The page answered with HTTP ' . $page['status'], 502); }

        $dom = Html::load($page['body'], (string)($page['headers']['content-type'] ?? ''));
        $found = []; $seen = [];
        foreach ($dom->getElementsByTagName('img') as $img) {
            $raw = Html::imageSource($img);
            if ($raw === null || Html::isTrackingPixel($img)) { continue; }
            $abs = Url::resolve($page['final_url'], $raw);
            $norm = $abs !== null ? Url::normalize($abs) : null;
            if ($norm === null || isset($seen[$norm])) { continue; }
            $seen[$norm] = true;
            $found[] = ['url' => $norm, 'alt' => trim($img->getAttribute('alt')), 'title' => trim($img->getAttribute('title'))];
        }

        $truncated = count($found) > $maxImages;
        $out = []; $skipped = 0;
        foreach (array_slice($found, 0, $maxImages) as $f) {
            if (microtime(true) > $deadline) { $truncated = true; break; }
            $info = $this->measure($f['url'], $deadline);
            if ($info === null) { $skipped++; continue; } // unreachable, not an image, or blocked address
            $id = $this->save($projectId, $info['url'], $f['alt'], $f['title'], $info);
            $out[] = [
                'id' => $id, 'src' => $info['url'], 'alt' => $f['alt'], 'title' => $f['title'], 'size' => $info['size'], 'dimensions' => $info['dimensions'],
                'has_alt' => $f['alt'] !== '', 'optimized' => $this->optimized($f['alt'], $info['size']),
            ];
        }
        Response::success('Analyzed ' . count($out) . ' images', ['images' => $out, 'found' => count($found), 'skipped' => $skipped, 'truncated' => $truncated, 'limit' => $maxImages, 'project_id' => $projectId]);
    }

    /** Filename-derived suggestion. Not AI: it only turns "red-shoes_2.jpg" into "Red Shoes 2". */
    public function suggestAltText(array $params): never
    {
        $url = Validator::str(['u' => Request::query('image_url')], 'u', 1000, true);
        $file = pathinfo(basename((string)parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);
        Response::success('Alt text suggestion generated', [
            'suggestion' => ucwords(trim(preg_replace('/[\s_\-]+/', ' ', $file) ?? '')),
            'source' => 'filename_heuristic',
            'tips' => ['Describe what is in the image', 'Include relevant keywords naturally', 'Keep it under ' . (int)Config::get('image.alt_max_length') . ' characters', 'Avoid "image of" or "picture of"'],
        ]);
    }

    public function getOptimizationTips(array $params): never
    {
        $img = $this->db->fetchOne('SELECT * FROM `' . $this->db->t('image_seo') . '` WHERE id = :id', ['id' => Validator::id($params['id'] ?? null)]) ?? Response::notFound('Image not found');
        $tips = [];
        if (empty($img['alt_text'])) { $tips[] = ['type' => 'error', 'message' => 'Missing alt text - add descriptive alt text for accessibility and SEO']; }
        if (empty($img['title'])) { $tips[] = ['type' => 'warning', 'message' => 'Missing title attribute (optional, minor)']; }
        if ($img['file_size'] === null) { $tips[] = ['type' => 'warning', 'message' => 'File size is unknown']; }
        elseif ($img['file_size'] > self::optimalBytes()) { $tips[] = ['type' => 'error', 'message' => 'Large file - consider compressing below ' . (int)round(self::optimalBytes() / 1000) . ' KB']; }
        elseif ($img['file_size'] > 100000) { $tips[] = ['type' => 'warning', 'message' => 'Could be smaller - consider compressing below 100 KB']; }
        if (!$tips) { $tips[] = ['type' => 'success', 'message' => 'No issues found by these checks']; }
        Response::success('Optimization tips generated', $tips);
    }

    /** Download (size-capped) and measure. Null when unreachable, blocked, or not an image. @return array{url:string,size:?int,dimensions:?string}|null */
    private function measure(string $url, float $deadline): ?array
    {
        try {
            $res = SafeHttpClient::default()->get($url, [
                'max_bytes' => (int)Config::get('fetch.max_image_bytes'), 'timeout' => 15, 'deadline' => $deadline, 'accept' => 'image/*,*/*;q=0.5',
            ]);
        } catch (FetchException) {
            return null;
        }
        if ($res['status'] !== 200) { return null; }
        $dim = @getimagesizefromstring($res['body']);
        if ($dim === false) { return null; }
        return ['url' => $res['final_url'], 'size' => $res['bytes'], 'dimensions' => $dim[0] . 'x' . $dim[1]];
    }

    private function optimized(string $alt, ?int $size): ?bool
    {
        return $size === null ? null : ($alt !== '' && $size < self::optimalBytes()); // unknown size => unknown, never "optimized"
    }

    private function save(int $projectId, string $url, ?string $alt, ?string $title, array $info): int
    {
        $t = $this->db->t('image_seo');
        $opt = $alt === null ? ($info['size'] === null ? null : (int)($info['size'] < self::optimalBytes())) : ($this->optimized($alt, $info['size']) === null ? null : (int)$this->optimized($alt, $info['size']));
        $this->db->query(
            "INSERT INTO `$t` (project_id, image_url, alt_text, title, file_size, dimensions, optimized) VALUES (:p, :u, :a, :t, :s, :d, :o)
             ON DUPLICATE KEY UPDATE file_size = VALUES(file_size), dimensions = VALUES(dimensions), optimized = VALUES(optimized),
               alt_text = COALESCE(VALUES(alt_text), alt_text), title = COALESCE(VALUES(title), title)",
            ['p' => $projectId, 'u' => $url, 'a' => $alt, 't' => $title, 's' => $info['size'], 'd' => $info['dimensions'], 'o' => $opt]
        );
        return (int)($this->db->fetchOne("SELECT id FROM `$t` WHERE project_id = :p AND url_hash = SHA2(:u, 256)", ['p' => $projectId, 'u' => $url])['id'] ?? 0);
    }

    private static function recommendations(?int $size): array
    {
        $r = [];
        if ($size !== null && $size > self::optimalBytes()) { $r[] = 'Compress the image to reduce file size below ' . (int)round(self::optimalBytes() / 1000) . ' KB'; }
        $r[] = 'Add descriptive alt text where the image is used';
        $r[] = 'Use a descriptive file name';
        return $r;
    }
}
