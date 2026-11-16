<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** HTTP side of the job queue: validate quickly, enqueue, answer 202; the status endpoint is what the front end polls. */
final class JobController
{
    public function crawl(array $params): never
    {
        $in = Request::json();
        $payload = [
            'base_url' => Validator::url($in, 'base_url'),
            'project_id' => Validator::projectId($in),
            'max_pages' => Validator::int($in, 'max_pages', 1, PHP_INT_MAX, (int)Config::get('sitemap.default_max_pages')),
        ];
        Response::success('Crawl queued', ['job_id' => Jobs::enqueue('crawl', $payload, self::uid())], 202);
    }

    public function bulkImages(array $params): never
    {
        $in = Request::json();
        $payload = ['url' => Validator::url($in, 'url'), 'project_id' => Validator::projectId($in)];
        Response::success('Image analysis queued', ['job_id' => Jobs::enqueue('bulk_images', $payload, self::uid())], 202);
    }

    public function status(array $params): never
    {
        $u = Auth::user();
        $job = Jobs::status(Validator::id($params['id'] ?? null), (int)$u['id'], Auth::isAdmin());
        $job ? Response::success('ok', $job) : Response::notFound('Job not found');
    }

    private static function uid(): ?int
    {
        return PHP_SAPI === 'cli' ? null : (int)(Auth::user()['id'] ?? 0);   // the CLI has no session (in-process tests)
    }
}
