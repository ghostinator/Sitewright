# Network & privacy

The browser only talks to the app's own origin (no CDNs, fonts, analytics or third-party scripts). All outbound traffic is made **by the PHP server**, exclusively through `core/SafeHttpClient.php`.

| # | Destination | When | What is sent | Disable |
|---|---|---|---|---|
| 1 | **Websites you enter** (SEO/Competitor analysis, Image SEO page + its images, Sitemap crawl and its `robots.txt`) | Only when a logged-in user submits that form | `GET` requests for the URLs involved; User-Agent `RankMathWebapp-SEO/1.0 (+RMW_CONTACT)`; your server's IP address becomes visible to those sites | Restrict with `RMW_FETCH_ALLOW_HOSTS`; or remove the feature by not granting accounts. Private/internal addresses are always refused. |
| 2 | **generativelanguage.googleapis.com** (Google Gemini) | Only if `RMW_GEMINI_API_KEY` is set, when a user runs *Generate* or *Rewrite* | The prompt: your keyword (≤200 chars, control chars removed), content type, tone — or the text you asked to rewrite (≤5000 chars). The API key is sent in the `x-goog-api-key` **header**, never in the URL. Response bodies are not logged. | Leave the key empty |
| 3 | **nominatim.openstreetmap.org** (OpenStreetMap) | Only if `RMW_ENABLE_GEOCODING=true` **and** a location is added without coordinates and no stored location has the same address; max 1 request/second | The address text (street, city, state, postal code, country) as the `q` parameter; identifying User-Agent | Leave `RMW_ENABLE_GEOCODING` unset/false (default) or enter coordinates yourself |

Nothing else: no update checks, telemetry, or phone-home. XML namespace URLs inside generated sitemaps and `https://schema.org` in JSON-LD are text, never fetched.

## Data stored

* Analysis history (URL, score, result JSON), generated content history, image and sitemap URLs, locations (name, address, phone, e-mail, coordinates), keyword metrics, redirect rules.
* **404 log**: URI, referer, user agent, IP address *as entered/imported by an administrator*. Entries older than `retention_days_404` (default 90 days) are purged on write and by `php bin/purge-404.php` (suitable for cron). Treat IP addresses as personal data under GDPR-style regimes; set retention accordingly.
* Accounts: username, e-mail, password hash. Sessions: server-side PHP sessions.
* Login throttling counters (hashed keys) in `rate_limits`.

## Outbound controls

* `RMW_FETCH_ALLOW_HOSTS`, `RMW_FETCH_BLOCK_HOSTS` — narrow where analysis/crawling may go (never bypasses the private-address block).
* `RMW_FETCH_DEV_ALLOW_CIDRS` — **development only**; disables the private-address block for the listed ranges.
* Egress firewall rules at the host level remain the strongest control; the application guard is defence in depth.
