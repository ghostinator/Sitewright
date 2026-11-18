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

### Certificates, domains, updates and the weekly summary
Table `site_expiry` (migration 019): per site, `cert` and `domain` rows with the expiry date, the state (`ok`, `problem`, `error`) and `alerted`, the tightest warning step already sent (30, 14, 7, 3, 1 or 0 for expired). The worker queues a `site_expiry` job per site every `sites.expiry_hours` (24); the job never fails (a problem is recorded as the row's state) and is left out of the failed-jobs health count, like the other site jobs. Certificates are read directly from the site (port 443, through the address guard). Domain dates come from RDAP: the list of registries from IANA (cached a week in `kv`) and then the registry of the domain; turn this off with `sites.rdap_enabled`. `updates_waiting` and `php_outdated` alerts come from the *versions* data the site owner approves in the plugin (the PHP support dates are the table `SiteRemote::PHP_SECURITY_UNTIL`; update it each year). The weekly summary (`digest.weekly_day`, `digest.weekly_time`) is sent once per ISO week, also when the worker was down at the time.

### Certificates (Let's Encrypt, DNS validation)
**What it is for.** Names whose traffic ends at a server of yours that serves HTTPS itself: Sitewright's own address, a mail host, an internal service reached over Tailscale. A site behind a Cloudflare Tunnel or Cloudflare's proxy (for example livelaughpueblo.co) needs no certificate on its own server. DNS validation proves ownership with a TXT record, so the server never has to be reachable from the internet, and wildcards (`*.example.com`) work.

**How it works.** Sitewright (tables from migration 020) holds the ACME account, the DNS tokens and each certificate's private key, all encrypted with `app_key`. A `cert_issue` job asks Let's Encrypt for the certificate, adds the TXT record(s) through your DNS provider, waits (`acme.dns_wait_seconds`), answers the challenge, collects the certificate and removes the records. It checks the result (names covered, key matches) before storing it. Renewal starts `acme.renew_days` (30) before expiry, or earlier than a third of a short-lived certificate's life; a failed attempt is retried after 30 min, 1 h, 2 h … up to a day (6 h when the service says "rate limited") while the current certificate keeps being served. The job never fails the queue or the health check; failures are `cert_issue_failed` alerts. **Test first:** the default `acme.environment` is `staging` (certificates browsers do not trust, no real limits). Switching to `production` makes every certificate again with the real service.

**Set up.**
1. *DNS accounts → Add DNS account.* Choose the provider that hosts the **name servers** of the domain (check at your registrar; a domain registered at one company can have its DNS at another). Give the key only the permission to edit DNS records. Press *Test*, with a domain, to prove records can really be added and removed. When you later add a certificate, Sitewright checks every name against the domains the account manages and says so (with a "did you mean…" suggestion) before anything is requested; a name that does not exist yet under a managed domain is fine.

   | Provider | Create | Permissions | Notes |
   |---|---|---|---|
   | **Cloudflare** *(tried live)* | My Profile → API Tokens → *Edit zone DNS* template | Zone: Read, DNS: Edit, limited to the zones you need | One token sees the zones of one Cloudflare account; domains in another account need a second DNS account |
   | **Spaceship** *(tried live)* | API Manager → API key + secret | `domains:read`, `dnsrecords:read`, `dnsrecords:write` | Only offers domains whose name servers are Spaceship's own; a domain pointing at other name servers is listed as "hosted elsewhere" |
   | **Amazon Route 53** | IAM user with an access key | `route53:ListHostedZones`, `route53:ListResourceRecordSets`, `route53:ChangeResourceRecordSets`, `route53:GetChange` (limit to your hosted zones) | Public hosted zones only. Waits until Route 53 reports the change in sync |
   | **DigitalOcean** | API → Tokens, custom scope | read and write on *domain* | Records use the minimum 30 s TTL |
   | **Porkbun** | Account → API Access: key + secret, **and switch API access on for each domain** | | Porkbun accepts records for a domain that is not delegated to it and only warns; Sitewright treats that as a failure and removes the record |
   | **deSEC** | Token Management | default token | One record set per name: Sitewright adds and removes only its own value |
   | **Gandi** | Organization → Sharing → personal access token | LiveDNS | Same record-set handling as deSEC |
   | **Linode (Akamai)** | Personal access token | Domains: Read/Write | Only "master" domains |
   | **Vultr** | API key | | If the key has an access control list, allow this server's address |

   **What has been tried:** Cloudflare and Spaceship against real accounts (issuing, wildcard, fetching, cleanup). The other seven were written from each provider's documentation and tested against stateful fakes of their APIs, including the wildcard case and removal; Route 53's request signing is checked against AWS's published test vector. Press *Test* with a real domain before relying on one, and tell me about any provider that behaves differently. Not offered yet: GoDaddy (its API cannot remove a single record), Namecheap (its API replaces a domain's entire record list on every change, which is unsafe), Google Cloud DNS and Azure DNS (need OAuth service-account sign-in).
2. *Certificates → Add certificate:* a name, one or more domain names (a name and its wildcard may share one certificate), the DNS account.
3. *Add server* under the certificate: you get a token once and a config snippet. On the server: copy `deploy/sw-cert-pull` to `/usr/local/sbin/` (mode 755), save the snippet as `/etc/sitewright-cert/<name>.conf` (mode 600), run `sudo sw-cert-pull /etc/sitewright-cert/<name>.conf`, then enable the timer (`deploy/sw-cert-pull@.service` and `.timer` into `/etc/systemd/system/`, `systemctl enable --now sw-cert-pull@<name>.timer`). Point the web server at `DEST/fullchain.pem` and `DEST/privkey.pem` (nginx: `ssl_certificate`, `ssl_certificate_key`).

**Security model.** Sitewright never holds a login to a server; servers pull. Each server has its own token (only a hash is stored) and may be limited to addresses; failed attempts are throttled and every refusal looks identical. The script needs https, refuses a certificate that is not the one announced, whose key does not match, or that expires within a day, keeps the previous pair and puts it back if the reload command fails, and reports what it did (shown per server; a server 3 days behind raises `cert_target_stale`). The private key is only sent to a server that proves it holds the token. Revoke a server by deleting it. The address list and the throttle use the client address the app sees: behind another proxy or load balancer, set `trusted_proxies` so that is the real server address (on the staging VM nginx talks to PHP directly, so it already is).

**Living with certbot.** `sw-cert-pull` writes only under its own `DEST` and never touches `/etc/letsencrypt`. A server that already uses certbot keeps doing so until you change its web server configuration to use `DEST`. Running certbot and Sitewright for the same name does not break either (Sitewright's DNS validation does not use port 80), but it is pointless: choose one per server and name.

**Limits.** Let's Encrypt allows 50 certificates a week per registered domain and 5 duplicates a week; Sitewright makes a new key at each renewal. Certificates for a name Let's Encrypt cannot validate (a `.ts.net` Tailscale name) are not possible; use a name in a zone you control. DNS tokens for several Cloudflare accounts are separate DNS accounts.

### Adding many sites at once, audit clean-up, registrar expiry (all added 2026-11-18)
**Add sites from a DNS account** (Sites page, bottom): pick a DNS account, *Find domains*, tick the ones you want, *Add the selected sites*. A background job (`site_import`) visits each domain over https (with and without `www`) and adds only those that serve a web page: parked domains, mail-only domains, domains that answer with an error, that block automated visitors (HTTP 401/403/429) or that redirect to a different domain are skipped and listed with the reason. Imported sites have no plugin; they get a Setup guide, certificate and domain-expiry checks and can be watched and audited like any other. A site that already exists (same host, with or without `www`) is never added twice.

**Site audit.** Links that ask the site to *do* something (Jetpack `?share=`, WooCommerce `?add-to-cart=`, `wc-ajax`, `replytocom`, `_wpnonce`, `/wp-login.php`, `/wp-admin`) are no longer followed or reported (they are not pages and made the crawl slow and noisy); the report says how many were left out. A page that fails to load, or answers 5xx or 429, while the crawl is busy is fetched once more before it is called broken. The report starts with **Fix once, fixes many**: a link found on three or more pages is listed once with how many pages it is on, where a redirect ends up (change the link, usually in a menu or the theme) and, for a missing page, the closest live page with a one-click 301. When the site owner shares the *Page list* in the plugin, every published page is crawled, so a published page that nothing links to is reported (*No page links to it*).

**Domain expiry from a registrar.** When the registry has no public lookup (for example `.co`), Sitewright asks a connected registrar account: today Spaceship (its key needs `domains:read`). The domain row then says *Registered as example.co at Spaceship (from your Spaceship account)*. A domain registered elsewhere still shows "no public RDAP service".

**Image text written back to WordPress (plugin 1.2.0).** In *Image SEO*, choose a site in the header and analyse a page; each image has boxes for alt text and title, *Suggest* (from the file name only), *Save* (kept in Sitewright) and *Apply to the site*. Applying needs, **on the site itself**, the owner to have ticked *Change image text* (Settings, Sitewright); it is a separate tick from the read-only choices and is off by default. Sitewright asks the plugin first on every apply. Only non-empty text of the images you chose is sent, only alt text and titles of images already in the media library, up to 50 per request; the plugin cannot upload, delete or change anything else. Every change is logged (table `media_changes`, migration 021) with the previous text and listed under the results with **Undo**.

### Connected WordPress sites
Install the agent plugin (download it from the Sites page, version 1.2.0; 1.0.0 saved an empty secret and said "Saved." when the pasted value was not 64 hex characters); Sitewright calls the site with signed requests (HMAC-SHA256, timestamp and nonce, replay-refused). The plugin stores no IPs or user agents. Needs `app_key`. Tested against WordPress 6 (`tests/docker/wordpress-e2e.sh`).

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
