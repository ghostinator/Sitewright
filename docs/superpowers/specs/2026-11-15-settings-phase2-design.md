# Settings Phase 2: per-page tuning (design pass)

Part of `2026-11-15-settings-area-design.md`; implements plan Tasks 7-11. Every default reproduces today's behaviour exactly; the existing tests are the regression guard.

## Groups added to the Settings page
`seo` (SEO scoring), `content` (Content AI), `site` (Images, sitemaps, redirects & 404 log), `dashboard` (Dashboard & analytics), `accounts` (Users & alerts).

## Settings (key = default)
**SEO scoring** (SeoAnalysisController; used by SEO Analysis and Competitor)
* Ranges: `seo.title_min`=30, `seo.title_max`=60, `seo.meta_min`=120, `seo.meta_max`=160.
* Thresholds: `seo.words_good`=300, `seo.words_great`=1000, `seo.alt_high`=90 (%), `seo.alt_mid`=50 (%), `seo.load_fast`=3 (s), `seo.load_ok`=5 (s), `seo.internal_min`=5 (links must exceed this).
* Weights in points (`seo.w.*`, 0-100 each): `title_present`=5, `title_ok`=5, `meta_present`=5, `meta_ok`=5, `h1`=10, `alt_high`=15, `alt_mid`=10, `alt_low`=5, `load_fast`=15, `load_ok`=10, `load_slow`=5, `viewport`=10, `https`=10, `schema`=10, `internal`=5, `external`=5, `words_good`=5, `words_great`=5.
* **Ruling: no normalisation.** The default weights add up to 110 and the score is `min(100, sum)` today. Changing that would change every existing score, so weights stay plain points and the cap stays. The page says "points; total is capped at 100".
* Cross-field rules: `title_min` <= `title_max`; `meta_min` <= `meta_max`; `words_good` <= `words_great`; `alt_mid` <= `alt_high`; `load_fast` <= `load_ok`.

**Content AI:** `content_ai.default_tone`=professional (enum), `content_ai.tones_enabled` (list, subset of the four built-in tones, default all), `content_ai.types_enabled` (subset of paragraph/title/meta_description/heading/conclusion, default all), `content_ai.temperature`=0.7 (0-2), `content_ai.max_tokens`=2048 (256-8192). The default tone must be an enabled tone. Disabled tone/type => HTTP 400; `GET /api/content-ai/status` returns `tones`, `types`, `default_tone` so the form only offers enabled ones.

**Images, sitemaps, redirects, 404 log:** `image.optimal_bytes`=200000, `image.alt_max_length`=125 (shown in tips), `sitemap.default_priority`=0.5 (0-1), `sitemap.default_changefreq`=weekly, `sitemap.default_max_pages`=100 (still capped by `limits.max_pages`), `redirects.default_type`=301, `monitor.ignore_patterns` (list; each starts with `/`, may use `*`; <= 200 chars, <= 100 entries) applied to manual adds, CSV imports and pulls from connected sites.

**Dashboard & analytics:** `dashboard.window_days`=30 (7-365; used by the dashboard stats and the analytics default range), `dashboard.cards` (ordered subset of seo, keywords, impressions, clicks; default all four).

**Users & alerts:** `users.default_role`=viewer (used when a created user has no role), `users.invite_days`=7, `users.reset_minutes`=60, `alerts.events` (list, subset of the event names `Alerts::notify` is called with; default all; an event not in the list is not sent).

## UI touch points (admin only, plain text links, no buttons on page headers)
SEO results note "Title length is judged against 30-60 characters." linking to `settings#seo`; 404 log note "3 ignore patterns are active."; dashboard note "Showing the last N days."; Content AI shows the enabled-tone count only when something is disabled.
