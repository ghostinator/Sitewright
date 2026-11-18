# Site setup, discovery, alert controls and certificate monitoring: design and progress ledger

Owner request (2026-11-17): sites should tell Sitewright about themselves so the domain and details are not typed everywhere; a setup guide where the owner chooses what to use or types an override; plus: certificate expiry alerts, version/update warnings, a setup checklist, a weekly digest, phone/address consistency, **tunable alerts** ("I was getting hammered with both"), and an ACME (Let's Encrypt) certificate manager. Decisions given: the WordPress **site owner approves each data type on the WordPress side**; everything a site sends is **untrusted**; version and update warnings are **in**. Order below.

## Evidence that shaped the order (measured on the staging VM, 2026-11-17)
* The flood was **health alerts**: 44 sent directly in 3 days, one **every minute** while unhealthy, to email and Teams. Cause: `storage/` is `sitewright:www-data 0755`, so the health service (www-data) cannot write `storage/health.state`; every unhealthy run looks like a state change. Health alerts also bypass `alerts.cooldown`.
* Queue alerts that day: `site_sync_failed` and `job_failed` x4 each per channel (a wrong WordPress secret), `setting_changed`. The saved cooldown is 86400 s (24 h, the maximum): "the same event for the same item at most once a day". It does not limit different alerts.
* Time zones: the VM moved to America/Denver; MariaDB still reports UTC (`@@system_time_zone` UTC), PHP is UTC. Code mixes PHP `gmdate()` with SQL `NOW()`. A DB restart under a different system zone would silently shift every comparison. The backup timer now fires at 02:30 *local* (09:30 UTC).

## Phases (each tested, committed, deployed; ledger below)
1. **Alert controls**: health state in the database; consecutive-bad-checks before `health_down`; one policy for every alert path (including health); an **alert history** of every decision (sent / suppressed + reason / batched / muted); per-event routing (channels), mode (immediately, batched, daily digest, off) and cooldown; hourly cap per channel with one "N more suppressed" message; quiet hours with errors breaking through; per-site mute / maintenance window; clear units everywhere; DB session time zone pinned to UTC and an explicit display time zone setting.
2. **Site profile and public discovery**: one `site_profile` store per site (value, source synced/manual/skipped, refreshed-at); discovery from the public site (works for every site: language, title, description, robots.txt, sitemaps, platform, structured-data business name/address/phone, SSL); a setup guide listing what was found with Use / Type my own / Skip; manual values are never overwritten; features prefill from the profile (analysis, watch, audit, sitemaps, image SEO, Search Console property suggestion, Local SEO location, key-page watch suggestions).
3. **Plugin 1.1 with owner consent**: `/status` lists the plugin's routes and the data types the owner has approved (all **off** by default, including upgraded sites); new routes: site info, content inventory (cursor-paged, capped), SEO-plugin data (Yoast/Rank Math titles, descriptions, noindex), existing redirects (Redirection, Rank Math), WooCommerce store address, media-library counts, **version and update counts** (opt-in). Sitewright calls only routes the status lists; an optional route can never fail the base sync; "not allowed by the site owner" is a state, not an error. No users, customers, orders or admin e-mail are ever offered. Everything received is sanitised, size-capped text.
4. **Certificate and domain expiry**, **setup checklist per site**, **weekly digest**, version/update warnings surfaced as alerts. Certificate fetched through the SSRF guard (pinned IP, port 443); RDAP for domain expiry (new third-party destination, documented); recorded not thrown; alerts at 30/14/7 days as changes only; a site behind Cloudflare shows Cloudflare's edge certificate (said in the UI).
5. **Phone/address consistency** across audited pages.
6. **ACME certificate manager**: NOT started. Needs the owner's answers (below).

## ACME: questions for the owner (nothing is built until answered)
1. Which certificates: Sitewright's own, the business sites', or issue-only with the owner deploying?
2. Which challenge: HTTP-01 needs that server reachable publicly on port 80; DNS-01 needs an API token for the DNS host. Where is DNS hosted?
3. Where does the certificate go after renewal (which server, how is it reloaded)?
Expiry monitoring (phase 4) covers the alerting need whatever the answers.

## Non-goals and constraints
* The WordPress site never calls Sitewright (outbound-only, signed requests), as today.
* Secrets are never pasted in chat; the plugin secret is only written server-to-server.
* No new Composer dependencies. Everything received from a site is untrusted.
* Old plugins (1.0.x) keep working unchanged; new data types are off until the owner turns them on in WordPress.

## Progress ledger (update with every commit)
| # | Phase | Status | Commit | Tests |
|---|-------|--------|--------|-------|
| 0 | **Stay signed in (30 days)**: remember-me tokens (migration 015), devices panel, setting `session.remember_days` | done | see git log | PHP 557, JS 50, browser 17 |
| 1 | Alert controls: AlertPolicy (events/mute/channels/cooldown/quiet/mode/cap), alert history, batches and digest, pauses, presets, Alerts page, health state in the DB with a bad-checks streak (migration 016) | done | see git log | PHP 578, JS 52, browser 18 |
| 2 | Site profile (`site_profile`, migration 017), public discovery (`SiteProfile`), setup guide (Use / Type my own / Skip / Start over), prefills (address fields, location form, Search Console property), `sites.profile_days` | done in code, tests green on PHP 8.3 (see below); deploy and the full matrix pending | see git log | PHP 589, JS 56, browser 19 |
| 3 | Plugin 1.1 with owner consent | not started | | |
| 4 | Certificate/domain expiry, setup checklist, digest, update warnings | not started | | |
| 5 | Phone/address consistency | not started | | |
| 6 | ACME manager | blocked on owner answers | | |
