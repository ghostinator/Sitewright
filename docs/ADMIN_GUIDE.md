# Sitewright administrator guide

For whoever installs, runs and secures Sitewright. Day-to-day use is in the [user guide](USER_GUIDE.md); every setting is in the [settings reference](SETTINGS_REFERENCE.md); the staging VM's specifics are in [STAGING_VM.md](STAGING_VM.md).

## 1. How it fits together

```
 you / team --(HTTPS, Tailscale or LAN)--> web server (nginx or Apache) --> PHP-FPM --> MariaDB / MySQL
                                                               worker (bin/worker.php, same user) ----^
 worker and web server --(outbound only)--> the sites you analyse, your WordPress sites, Postal, Gemini, Google
```

* Everything Sitewright connects to is **outbound**. Nothing needs to reach it from the internet, so it can stay on a private network.
* **Web requests** answer quickly and queue slow work. The **worker** (a systemd service) runs crawls, image checks, e-mail, alerts, WordPress syncs and Search Console imports. Without it those actions never finish.
* All outbound website fetches go through one guarded client (`SafeHttpClient`): http/https only, ports 80/443, private/loopback/link-local/metadata addresses refused, redirects re-checked, size and time limits.
* Requirements: PHP 8.1+ (tested on 8.1, 8.3, 8.4) with `pdo_mysql`, `curl`, `dom`, `mbstring`, `sodium`; MariaDB 10.11/11.8 or MySQL 8.4.

## 2. Install

1. Create the database and two accounts: a **runtime** account (SELECT/INSERT/UPDATE/DELETE) and a **migration** account (DDL). See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).
2. Put the code on the server; the web document root is `public/` only.
3. `php bin/install.php …` creates the schema, the config file (mode 0600) and the first admin. Nothing is created automatically.
4. Set `SW_BASE_URL` (https), `SW_ALLOWED_HOSTS`, HTTPS and `SW_HSTS=true` (or the config-file equivalents).
5. `php bin/gen-key.php` and put the result in the config as `app_key`. It encrypts secrets saved in Settings, site signing secrets and Google tokens. **Back it up separately from the database**; without it those secrets must be re-entered.
6. Install and start the worker and health timer:
   ```
   sudo cp deploy/sitewright-worker.service deploy/sitewright-health.{service,timer} /etc/systemd/system/
   sudo systemctl daemon-reload && sudo systemctl enable --now sitewright-worker sitewright-health.timer
   ```
7. Turn on two-step verification for your admin account (My Account), then optionally require it for all administrators (Settings → Security).

## 3. Configuration: where a value comes from

`environment variable` > `saved in Settings` > `config/config.php` > `built-in default`.

* Day to day, change things in the app under **Settings**. Secrets there are write-only and stored encrypted.
* These stay in the config file or environment and show read-only in the app: the database login, `app_key`, `base_url`, allowed hosts, trusted proxies, `fetch.allow_ports`, `fetch.dev_allow_cidrs`, `gemini.endpoint`, cookie, HSTS and debug settings.
* `sudo sw-set-secrets` (in `deploy/`) is the break-glass/bootstrap tool; it warns when a saved setting would override what it writes.
* Behind a reverse proxy set `SW_TRUSTED_PROXIES`; the proxy must preserve `Host`.
* Break-glass for any saved setting: `php bin/settings.php reset <key>` (or `reset-all`) removes it so the file value or built-in default applies again.
* Break-glass if an admin is locked out by "require two-step verification": set `SW_REQUIRE_MFA=false` on the server, or `php bin/user.php mfa-disable <user>`.

## 4. Operating it

| Task | Command |
|---|---|
| Add / list users, passwords, MFA | `php bin/user.php list \| create <user> <email> [--role=…] \| passwd <user> \| mfa-enable <user> \| mfa-disable <user>` |
| Read the audit log | `php bin/audit.php 100` |
| Purge old data | `php bin/purge-404.php`, `php bin/purge-audit.php` (the worker also purges hourly) |
| Back up / restore | `bin/db-backup.sh backup /var/backups/sitewright`; `SW_RESTORE_CONFIRM=yes bin/db-backup.sh restore <file>` |
| Nightly backup (02:30, keeps 30) | `sudo cp deploy/sitewright-backup.{service,timer} /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now sitewright-backup.timer`, then add `SW_BACKUP_DIR=/var/backups/sitewright` to `/etc/sitewright/worker.env` so the health check alerts when the newest backup is over 36 hours old |
| Rehearse a restore | `tests/docker/backup-drill.sh mariadb:11.8` |
| Set up file logging with rotation | `sudo deploy/setup-logging.sh [keep_days] [level]` (default 90 days, warn) |
| Connect Uptime Kuma (push monitor) | `sudo sw-set-secrets uptime` |
| Test alert channels | `php bin/alert-test.php` |
| Health check (cron/timer) | `php bin/healthcheck.php` (alerts only on a state change) |
| Run migrations | `php bin/migrate.php` with the migration account |
| Check the environment | `php bin/check-env.php` |
| Undo a bad saved setting without the web UI (for example a sign-in limit that locks everyone out) | `php bin/settings.php list \| reset <key> \| reset-all` |

### Upgrading (order matters)
1. Back up the database. 2. Copy the new code. 3. **Run migrations** (new code reads new columns; code without them answers every sign-in with HTTP 500). 4. Run the tests. 5. Reload PHP-FPM and restart the worker.

### Logging and alerts
JSON lines with a request id. Recommended for one VM: warnings and errors to a rotated, compressed file (`deploy/setup-logging.sh`) plus an Uptime Kuma push monitor fed by the health timer; Grafana Loki + Alloy if you later want search. E-mail alerts use your mail provider (Postal HTTP API or SMTP); Teams and Telegram are supported but not proven live. Details: [ALERTS_AND_LOGGING.md](ALERTS_AND_LOGGING.md). Which events send alerts is a setting.

### Monitoring: watchlist, audits, search opportunities, Core Web Vitals
The worker (every minute) starts due watchlist checks (at most `watch.max_per_tick`, one per site at a time, staggered), repeats audits every `audit.repeat_days` when set (one site per minute), and imports Search Console data daily. All of it is under **Settings → Monitoring** and **Integrations**. A watched page that is down or an audit of a dead site is *recorded*, never a failed job, so it cannot trigger `job_failed` alerts or the health check. Retention: scheduled analyses `watch.retention_days`, newest `audit.keep` audits per site (purged hourly by the worker). Search Console now also imports query+page rows (replaced each sync) for the opportunity finder; tie each connection to a site on the Analytics page, otherwise its data belongs to "not a site". **Core Web Vitals** are off by default; turning them on sends page addresses to Google, and in practice needs a free API key (an unkeyed request answered HTTP 429 when tested), stored encrypted and sent in a header. New alert events: `watch_down`, `watch_recovered`, `watch_regression`, `audit_new_issues`. Upgrading: run `php bin/migrate.php` (migrations 010-014) before the new code serves requests, then restart the worker.

### Site profile (setup guide)
Table `site_profile` (migration 017) holds, per site and item, what the site's public pages said (`found`), what the owner chose (`value`) and the state (`pending`, `used`, `manual`, `skipped`, `info`). The `site_profile` job reads the home page, `robots.txt` and, when robots.txt lists no sitemap, `/sitemap.xml` and `/wp-sitemap.xml`, all through the SSRF guard. It always finishes: a site that cannot be read is recorded on the site (`sites.profile_error`) and never counts as a failed job. The worker queues a read for sites never read or older than `sites.profile_days` (default 7; 0 = off). API: `GET /api/sites/{id}/profile` (any signed-in role), `POST /api/sites/{id}/profile` and `POST /api/sites/{id}/profile/refresh` (admin; every change is in the audit log as `site_profile_changed`). `GET /api/sites/options` carries only the used and typed values.

### Connected WordPress sites
Install the agent plugin (download it from the Sites page, version 1.1.0; 1.0.0 saved an empty secret and said "Saved." when the pasted value was not 64 hex characters); Sitewright calls the site with signed requests (HMAC-SHA256, timestamp and nonce, replay-refused). The plugin stores no IPs or user agents. Needs `app_key`. Tested against WordPress 6 (`tests/docker/wordpress-e2e.sh`).

**What the site owner approves (plugin 1.1).** Under Settings, Sitewright in WordPress there is one tick per data type: site details, page list, search titles and descriptions (Yoast or Rank Math), existing redirects (Redirection or Rank Math), shop address (WooCommerce), image counts, version and update counts. **All are off**, also after upgrading from 1.0.x. Sitewright asks the plugin which are ticked and calls only those; unticking one makes Sitewright delete what it stored. Nothing about users, e-mail addresses, customers, orders, page text or plugin names is offered. The Sites page's Setup guide shows each type as *Approved*, *Not approved*, *Plugin too old* (older than 1.1) or *Problem*. The heavier reads repeat every 6 hours (Settings, Crawling and jobs, *Refresh approved site details every*). Site details and the shop address feed the Setup guide and take the place of what the public page shows; the rest is stored for audits and checks (page list, search data, redirects, counts). Not yet used anywhere else: they are inputs for the certificate/update checks and the setup checklist.

### Google Search Console
Create an OAuth client (Web application), enable the Search Console API, add `https://YOUR-HOST/api/gsc/callback` as a redirect URI, then enter the client under Settings → Integrations. The sign-in redirect happens in your browser, so the host need not be public. Read-only scope; refresh token encrypted. **Not yet proven against Google** (tested with scripted responses).

## 5. Security model (what protects what)

| Area | Protection | Where it is tested |
|---|---|---|
| Outbound fetches | One guarded client, DNS pinning, per-hop checks, size/time caps | `tests/cases/02_*`, WordPress E2E |
| Sign-in | Throttled per account and IP, optional TOTP (replay-proof, recovery codes), new session id at each step | `04`, `11`, browser suite |
| Sessions | HttpOnly, SameSite, Secure over HTTPS, idle and absolute timeouts, fingerprint ends sessions on password/MFA change. "Keep me signed in" (`session.remember_days`, default 30, 0 = off): a separate HttpOnly cookie holds `selector.secret`; only the SHA-256 of the secret is stored; a wrong secret deletes the token; expiry slides (written at most twice a day) up to a 180-day ceiling; a password or MFA change, disabling or deleting the account, or signing out ends it; each person lists and forgets their devices on My Account | `09`, `13`, `29` |
| Requests | Same-origin check, CSRF token, JSON content type, per-route roles (viewer < editor < admin) walked by a test | `04`, `13` |
| Secrets | Encrypted at rest (XChaCha20-Poly1305, bound to the row), never in responses, logs or audit | `14`, `15`, `16`, `17` |
| Front end | No HTML-string sinks; CSP `script-src 'self'; style-src 'self'` | `tests/js`, browser suite |
| Audit | Security events recorded without secrets; retention purge | `11` |

Hardening you still do on the server: firewall egress for the web/worker user (block private ranges and the cloud metadata address, allow only what you use), HTTPS only, files not world-readable, regular backups, and an independent penetration test.

## 6. Testing

`tests/all.sh` runs everything locally (there is no hosted CI): PHP 8.1/8.3/8.4 against MariaDB 10.11/11.8 and MySQL 8.4, JS (jsdom), `.htaccess` behaviour on three Apache setups, the Playwright browser suite, the WordPress end-to-end, backup drills and a secret scan. `tests/all.sh quick` runs PHP 8.3 and JS only. Tests are hermetic: they remove `SW_*` variables and never read the machine's real config.

## 7. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| Jobs "waiting for a worker" | `systemctl status sitewright-worker`; `journalctl -u sitewright-worker`; the health check alerts when jobs wait over 5 minutes |
| Every sign-in is HTTP 500 after an update | Migrations were not run before the new code; run `php bin/migrate.php` |
| `mfa_required` on every page | "Require two-step verification" is on and the admin has none; use My Account, or the break-glass in section 3 |
| Site sync error | The message now says which: *does not match* (re-create the secret with **New secret**, paste only the 64 characters; a `SITEWRIGHT_SECRET` in wp-config.php wins over the settings page), *clocks differ* (UNIX time, so time zones are irrelevant), *no secret yet*, or an answer that did not come from the plugin (firewall/security plugin). `curl 'https://SITE/?rest_route=/sitewright/v1/status'` answering *Secret not set* means the site has no secret at all |
| Site sync "plugin not found" | Plugin inactive, or the REST API is blocked by a security plugin or firewall |
| E-mail "From address is not on a verified domain" | Verify the sender domain in Postal |
| "Secrets can only be saved after app_key is set" | Generate `app_key` (section 2, step 5) |
| Saved setting has no effect | A server environment variable overrides it (the row shows "Locked by server"), or `sw-set-secrets` wrote the file while a saved value exists |
| Apache 500 on a shared host | `.htaccess` needs only `AllowOverride FileInfo`; a symlinked sub-path with rewrite rules is not supported, use `Alias` |
| Docker "No space left on device" in tests | Free Docker disk (`docker system df`); remove stopped test containers |
