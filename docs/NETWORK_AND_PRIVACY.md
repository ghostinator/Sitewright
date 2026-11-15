# Network & privacy

The browser only talks to the app's own origin (no CDNs, fonts, analytics or third-party scripts). All outbound traffic is made **by the PHP server**, exclusively through `core/SafeHttpClient.php`.

| # | Destination | When | What is sent | Disable |
|---|---|---|---|---|
| 1 | **Websites you enter** (SEO/Competitor analysis, Image SEO page + its images, Sitemap crawl and its `robots.txt`) | Only when a logged-in user submits that form | `GET` requests for the URLs involved; User-Agent `Sitewright-SEO/1.0 (+SW_CONTACT)`; your server's IP address becomes visible to those sites | Restrict with `SW_FETCH_ALLOW_HOSTS`; or remove the feature by not granting accounts. Private/internal addresses are always refused. |
| 2 | **generativelanguage.googleapis.com** (Google Gemini) | Only if `SW_GEMINI_API_KEY` is set, when a user runs *Generate* or *Rewrite* | The prompt: your keyword (≤200 chars, control chars removed), content type, tone — or the text you asked to rewrite (≤5000 chars). The API key is sent in the `x-goog-api-key` **header**, never in the URL. Response bodies are not logged. | Leave the key empty |
| 3 | **nominatim.openstreetmap.org** (OpenStreetMap) | Only if `SW_ENABLE_GEOCODING=true` **and** a location is added without coordinates and no stored location has the same address; max 1 request/second | The address text (street, city, state, postal code, country) as the `q` parameter; identifying User-Agent | Leave `SW_ENABLE_GEOCODING` unset/false (default) or enter coordinates yourself |

Nothing else: no update checks, telemetry, or phone-home. XML namespace URLs inside generated sitemaps and `https://schema.org` in JSON-LD are text, never fetched.

## Data stored

* Analysis history (URL, score, result JSON), generated content history, image and sitemap URLs, locations (name, address, phone, e-mail, coordinates), keyword metrics, redirect rules.
* **404 log**: URI, referer, user agent, IP address *as entered/imported by an administrator*. Entries older than `retention_days_404` (default 90 days) are purged on write and by `php bin/purge-404.php` (suitable for cron). Treat IP addresses as personal data under GDPR-style regimes; set retention accordingly.
* Accounts: username, e-mail, password hash. Sessions: server-side PHP sessions.
* Login throttling counters (hashed keys) in `rate_limits`.

## Outbound controls

* `SW_FETCH_ALLOW_HOSTS`, `SW_FETCH_BLOCK_HOSTS` — narrow where analysis/crawling may go (never bypasses the private-address block).
* `SW_FETCH_DEV_ALLOW_CIDRS` — **development only**; disables the private-address block for the listed ranges.
* Egress firewall rules at the host level remain the strongest control; the application guard is defence in depth.

## Privacy notice (what this application stores about people)

| Data | Whose | Where | Retention |
|---|---|---|---|
| Username, display name, password hash (bcrypt/argon via `password_hash`), optional TOTP secret and hashed recovery codes | Administrators | `users` table | Until the account is removed |
| Audit log: time, username, action, client IP | Administrators | `audit_log` table | `retention_days_audit` (default 365), purged by `php bin/purge-audit.php` |
| 404 log: URI, referer, user agent, IP | Site visitors, **as entered or imported by an administrator** (the app does not capture visitors itself) | `monitor_404` table | `retention_days_404` (default 90) |
| Session data | Administrators | PHP session files | Idle 30 min / absolute 8 h |
| Locations (business name, phone, address, email) | Businesses/people the administrator enters | `local_locations` table | Until deleted |

IP addresses and user agents are personal data under GDPR-style regimes. Name a controller, state the purpose (security monitoring, site maintenance), keep the retention values at the minimum you need, and publish this table (or your version of it) as your privacy notice. The application sets no tracking cookies and loads no third-party scripts; its only outbound destinations are listed above.
