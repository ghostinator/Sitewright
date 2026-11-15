# Deployment guide

## Layout

```
/srv/sitewright/            <- NOT web-accessible
├── core/ modules/ config/ database/ bin/ docs/ tests/ storage/
└── public/               <- the ONLY web-accessible directory (document root)
```

## 1. Database (least privilege)

```sql
CREATE DATABASE sitewright CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Runtime account (what the web app uses): no DDL, no TRUNCATE, no FILE.
CREATE USER 'sw_app'@'127.0.0.1' IDENTIFIED BY '<long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON sitewright.* TO 'sw_app'@'127.0.0.1';
-- Install/upgrade account (used only by bin/install.php and bin/migrate.php, e.g. via SW_DB_USER/SW_DB_PASS):
CREATE USER 'sw_migrate'@'127.0.0.1' IDENTIFIED BY '<another long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON sitewright.* TO 'sw_migrate'@'127.0.0.1';
```

Install as `sw_migrate`, then edit `config/config.php` (or the environment) to use `sw_app`. Never use `root`; never `GRANT ALL`.

## 2. Install

```bash
export SW_DB_PASS='<sw_migrate password>' SW_ADMIN_PASSWORD='<min 12 chars>'
php bin/install.php --db-user=sw_migrate --db-name=sitewright --admin-user=admin --admin-email=you@example.com
chmod 600 config/config.php
```

## 3. Apache

```apache
<VirtualHost *:443>
    ServerName seo.example.com
    DocumentRoot /srv/sitewright/public
    # Certificate configuration (SSLEngine, SSLCertificateFile, ...) omitted.
    SSLEngine on
    <Directory /srv/sitewright/public>
        Options -Indexes
        # public/.htaccess carries the rewrite and header rules.
        AllowOverride All
        Require all granted
    </Directory>
    # Or put everything in config/config.php instead of SetEnv.
    SetEnv SW_BASE_URL https://seo.example.com
</VirtualHost>
```

Required modules: `rewrite`, `headers`. Environment variables for PHP can be set with `PassEnv`/`SetEnv`, PHP-FPM `env[...]`, or simply `config/config.php`.

**Sub-path** (e.g. `https://example.com/seo/`): `Alias /seo /srv/sitewright/public` with the same `<Directory>` block, and `SW_BASE_URL=https://example.com/seo`. Tested with Apache 2.4 (`tests/docker/smoke.sh`). If your host maps the sub-path differently and rewrites misroute, add `RewriteBase /seo/` inside that `<Directory>`. Do not deploy the whole repository inside the web root.

## 4. nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name seo.example.com;
    root /srv/sitewright/public;
    index index.php;

    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
    # add_header Strict-Transport-Security "max-age=31536000" always;   # once HTTPS is final

    location ~ /\. { deny all; }
    location /api/ { try_files $uri /api.php$is_args$args; }
    location = /api.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/api.php; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location ~ \.php$ { return 404; }              # only the two front controllers are executable
    location / { try_files $uri /index.php$is_args$args; }
}
```

## 5. Hardening checklist

* Behind a reverse proxy or load balancer: preserve the `Host` header, and set `SW_TRUSTED_PROXIES` to the proxy addresses (e.g. `10.0.0.0/24`). The app then takes the client address from `X-Forwarded-For`, reading right to left and skipping trusted hops, so login throttling, rate limits and the audit log see real clients. Without it every user shares the proxy's IP (the 30-per-15-minute IP login limit becomes a global lockout). `X-Forwarded-Host`/`-Proto` are deliberately not used: HTTPS is decided by `SW_BASE_URL`, and the same-origin check needs the proxy to pass `Host` through unchanged.
* HTTPS only; set `SW_BASE_URL` (https) and `SW_ALLOWED_HOSTS`; enable `SW_HSTS=true`.
* `display_errors=Off` in PHP (the app also forces it off unless `SW_DEBUG`); `expose_php=Off`.
* `config/config.php` mode `0600`, owned by the PHP user; keep backups encrypted.
* Block outbound access from the PHP host to internal networks and cloud metadata endpoints at the firewall as a second layer behind the application's SSRF guard.
* **MFA (TOTP)**: turn it on for every administrator: `php bin/user.php mfa-enable <username>` prints the secret and an `otpauth://` URI (paste into any authenticator app), asks for one valid code to prove it works, then prints 8 single-use recovery codes (store them offline). Enabling or disabling MFA ends that user's sessions. Sign-in then takes two steps; wrong codes are throttled per account and per IP, and a code cannot be reused. Lost device: use a recovery code, or an operator runs `php bin/user.php mfa-disable <username>` and re-enables.
* **Audit log**: sign-ins, failures, MFA steps, logouts, imports, deletions and CLI account changes go to the `audit_log` table (never passwords, keys or prompt text; unknown usernames are stored as `(unknown)`). Read with `php bin/audit.php 100`; schedule `php bin/purge-audit.php` daily (`retention_days_audit`, default 365). Optionally give the runtime DB account only INSERT and SELECT on that table (it also needs DELETE for the purge job, so run the purge as the migration account if you do).
* Schedule `php bin/purge-404.php` and `php bin/purge-audit.php` daily.
* **Backups**: `SW_DB_NAME=... SW_DB_USER=... SW_DB_PASS=... bin/db-backup.sh backup /var/backups/sitewright` (consistent `mysqldump --single-transaction`, gzip, mode 0600, keeps the newest 14). Restore into an empty database with `SW_RESTORE_CONFIRM=yes bin/db-backup.sh restore <file>`. The dump holds password hashes and TOTP secrets: encrypt it and keep it off the web server. Rehearse a restore with `tests/docker/backup-drill.sh [db-image]` (drops everything and checks the counts), and do the same against your real backups quarterly.
* Do not run the web server or PHP as root; no recursive `chmod 755/777` — files need only read access for the PHP user, `storage/` write access for the installer.
* Rotate any API key that was ever committed (and that you own). The CI workflow scans the working tree with gitleaks; scan history yourself when you adopt the repository.

## 6. Upgrades

**Migrate in the same step as the code update, before reloading PHP-FPM / Apache.** Release 004 (MFA + audit) reads `users.totp_secret`; new code against an un-migrated database fails every sign-in with HTTP 500. Sequence: back up (`bin/db-backup.sh backup`), update the code, `php bin/migrate.php` (migration-capable DB account), run the tests, then reload PHP. `git pull && php bin/migrate.php` (run with the migration-capable DB account). Migrations are tracked in `schema_migrations`; a v1.0 database is detected and adopted automatically. Migration `003` drops the never-used `projects`, `schema`, `settings` and `analytics_pages` tables.
