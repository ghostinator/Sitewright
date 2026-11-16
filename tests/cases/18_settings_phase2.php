<?php
use Sitewright\Core\{Config, Settings};
use Sitewright\Modules\SeoAnalysis\SeoAnalysisController as Seo;

/** A fully "good" analysis result, then tweak pieces. */
$good = fn (array $o = []) => array_replace_recursive([
    'basic' => ['title' => 'T', 'title_optimal' => true, 'word_count' => 1200], 'meta' => ['description' => 'D', 'description_optimal' => true],
    'headings' => ['h1_optimal' => true], 'images' => ['alt_ratio' => 100], 'performance' => ['load_time' => 1.0, 'load_time_optimal' => true],
    'viewport' => ['has_viewport' => true], 'security' => ['uses_https' => true], 'structured_data' => ['has_schema' => true], 'links' => ['internal_links' => 9, 'external_links' => 2],
], $o);

test('SEO settings: defaults reproduce today\'s scores exactly (including the 100 cap on a 110-point total)', function () use ($good) {
    eq(Seo::calculateScore($good()), 100, 'every check passes: 110 points capped at 100');
    eq(Seo::calculateScore($good(['basic' => ['title' => '', 'word_count' => 0], 'meta' => ['description' => ''], 'images' => ['alt_ratio' => 0]])), 65, 'h1 10 + fast 15 + viewport 10 + https 10 + schema 10 + links 5 + 5');
    eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 60], 'performance' => ['load_time' => 4.0, 'load_time_optimal' => false], 'links' => ['internal_links' => 5]])), 95, 'mid alt -5, acceptable speed -5, 5 links is not "more than 5" -5');
    eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 20], 'performance' => ['load_time' => 9.0, 'load_time_optimal' => false], 'basic' => ['word_count' => 400], 'links' => ['internal_links' => 0, 'external_links' => 0], 'security' => ['uses_https' => false]])), 65);
});
test('SEO settings: weights, thresholds and ranges are read from settings; defaults still hold when unset', function () use ($good) {
    foreach (['h1', 'viewport', 'schema', 'https'] as $k) { Config::set("seo.w.$k", 0); }
    eq(Seo::calculateScore($good()), 70, 'four checks worth nothing: 110 - 40');
    foreach (['h1', 'viewport', 'schema', 'https'] as $k) { Config::set("seo.w.$k", 10); }
    Config::set('seo.words_good', 5000); Config::set('seo.words_great', 9000);
    eq(Seo::calculateScore($good(['basic' => ['title' => '', 'word_count' => 1200], 'meta' => ['description' => ''], 'images' => ['alt_ratio' => 0], 'performance' => ['load_time' => 9.0, 'load_time_optimal' => false]])), 55, 'words no longer count at 1200; slow fetch 5');
    Config::set('seo.words_good', 300); Config::set('seo.words_great', 1000);
    Config::set('seo.alt_high', 50); eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 60], 'viewport' => ['has_viewport' => false]])), 100, 'alt 60% now counts as high: 110 - 10 = 100'); Config::set('seo.alt_high', 90);
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
test('SEO settings: the title range decides "optimal" in a real analysis', function () use ($db) {
    $title = str_repeat('t', 65);
    $page = [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html lang=\"en\"><head><title>$title</title></head><body><h1>x</h1></body></html>"];
    $run = function () use ($page) { fakeWeb(['https://example.com/' => $page]); return call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'https://example.com/']])['json']['data']['results']['basic']['title_optimal']; };
    eq($run(), false, 'a 65-character title is too long with the default 30-60');
    Config::set('seo.title_max', 70); eq($run(), true); Config::set('seo.title_max', 60);
});
test('SEO settings: registry rules (ranges, weights 0-100, cross-field) through Settings::prepare', function () {
    eq(Settings::prepare(['seo.title_min' => 50, 'seo.title_max' => 40], true)['status'], 400, 'min above max');
    eq(Settings::prepare(['seo.w.https' => 101], true)['status'], 400); eq(Settings::prepare(['seo.w.https' => -1], true)['status'], 400);
    eq(Settings::prepare(['seo.load_fast' => 6, 'seo.load_ok' => 5], true)['status'], 400);
    eq(Settings::prepare(['seo.title_min' => 25, 'seo.title_max' => 70, 'seo.load_fast' => 2.5], true)['status'], 200);
    ok(count(array_filter(array_keys(Settings::registry()), fn ($k) => str_starts_with($k, 'seo.w.'))) === 18);
});
