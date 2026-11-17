<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Core Web Vitals through Google PageSpeed Insights (opt-in). Google loads the page itself, so only public addresses are ever sent.
 * The API key travels in a request header, never in the address, so no log or error can print it. A refusal, rate limit or garbage answer
 * is RECORDED on the result row, not thrown: it must never fail a job or turn the app's health check red.
 */
final class PageSpeed
{
    private const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public static function enabled(): bool
    {
        return (bool)Config::get('pagespeed.enabled');
    }

    /** @return array<string,mixed> the stored row (status ok | rate_limited | error) @throws AnalysisException when off (409) or the address is not public (400) */
    public static function run(string $url, int $projectId = 0, ?int $watchId = null): array
    {
        if (!self::enabled()) { throw new AnalysisException('Core Web Vitals measurements are turned off. An administrator can turn them on under Settings, Integrations.', 409); }
        $client = SafeHttpClient::default();
        try { $client->validate($url); } catch (FetchException $e) { throw new AnalysisException('That address cannot be measured: ' . $e->getMessage(), 400); }
        $strategy = Config::get('pagespeed.strategy') === 'desktop' ? 'desktop' : 'mobile';
        $key = (string)Config::get('pagespeed.api_key');
        $headers = ['Accept: application/json'];
        if ($key !== '') { $headers[] = 'X-Goog-Api-Key: ' . $key; }
        $base = ['project_id' => $projectId, 'watch_id' => $watchId, 'url' => mb_substr($url, 0, 500), 'strategy' => $strategy];
        try {
            $res = $client->request('GET', self::API . '?url=' . rawurlencode($url) . '&strategy=' . $strategy . '&category=performance', ['headers' => $headers, 'timeout' => 90, 'max_bytes' => 4194304, 'accept' => 'application/json', 'follow' => false]);
        } catch (FetchException $e) {
            return self::record($base, 'error', 'Could not reach Google PageSpeed: ' . $e->getMessage());
        }
        $j = json_decode((string)$res['body'], true);
        if ($res['status'] === 429) { return self::record($base, 'rate_limited', 'Google\'s PageSpeed quota is used up. Without an API key you share a free quota that is almost always exhausted: create a free key and save it under Settings, Integrations.'); }
        if ($res['status'] !== 200) {
            $why = is_array($j) && is_string($j['error']['message'] ?? null) ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $j['error']['message']) ?? '', 0, 150) : '';
            return self::record($base, 'error', 'Google answered HTTP ' . $res['status'] . $why);
        }
        if (!is_array($j) || !isset($j['lighthouseResult'])) { return self::record($base, 'error', 'Google answered with something unexpected.'); }
        return self::record($base, 'ok', null, self::parse($j));
    }

    /** @return array<string,int|float|string|null> */
    public static function parse(array $j): array
    {
        $a = (array)($j['lighthouseResult']['audits'] ?? []);
        $ms = static fn (string $id): ?int => isset($a[$id]['numericValue']) ? (int)round((float)$a[$id]['numericValue']) : null;
        $score = $j['lighthouseResult']['categories']['performance']['score'] ?? null;
        $m = (array)($j['loadingExperience']['metrics'] ?? []);
        $p = static fn (string $k): ?float => isset($m[$k]['percentile']) ? (float)$m[$k]['percentile'] : null;
        return [
            'perf_score' => $score === null ? null : (int)round((float)$score * 100), 'lcp_ms' => $ms('largest-contentful-paint'),
            'cls' => isset($a['cumulative-layout-shift']['numericValue']) ? round((float)$a['cumulative-layout-shift']['numericValue'], 3) : null,
            'tbt_ms' => $ms('total-blocking-time'), 'fcp_ms' => $ms('first-contentful-paint'), 'ttfb_ms' => $ms('server-response-time'),
            'field_category' => is_string($j['loadingExperience']['overall_category'] ?? null) ? mb_substr($j['loadingExperience']['overall_category'], 0, 12) : null,
            'field_lcp_ms' => ($v = $p('LARGEST_CONTENTFUL_PAINT_MS')) === null ? null : (int)round($v),
            'field_cls' => ($v = $p('CUMULATIVE_LAYOUT_SHIFT_SCORE')) === null ? null : round($v / 100, 3),
            'field_inp_ms' => ($v = $p('INTERACTION_TO_NEXT_PAINT')) === null ? null : (int)round($v),
        ];
    }

    /** Job handler: payload {url, project_id?, watch_id?}. Always returns normally. */
    public static function handleJob(array $p): array
    {
        try {
            $r = self::run((string)($p['url'] ?? ''), (int)($p['project_id'] ?? 0), isset($p['watch_id']) ? (int)$p['watch_id'] : null);
        } catch (AnalysisException $e) {
            return ['message' => $e->getMessage(), 'data' => ['status' => 'error']];
        }
        return ['message' => $r['status'] === 'ok' ? 'Measured' : (string)$r['error'], 'data' => ['status' => $r['status'], 'perf_score' => $r['perf_score'] ?? null]];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $metrics @return array<string,mixed> */
    private static function record(array $base, string $status, ?string $error, array $metrics = []): array
    {
        $row = $base + ['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255)] + $metrics;
        $row['id'] = (int)Database::getInstance()->insert('pagespeed_runs', $row);
        return $row + array_fill_keys(['perf_score', 'lcp_ms', 'cls', 'tbt_ms', 'fcp_ms', 'ttfb_ms', 'field_category', 'field_lcp_ms', 'field_cls', 'field_inp_ms'], null);
    }
}
