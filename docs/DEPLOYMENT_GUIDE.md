# Deployment guide

## Layout

```
/srv/rankmath/            <- NOT web-accessible
├── core/ modules/ config/ database/ bin/ docs/ tests/ storage/
└── public/               <- the ONLY web-accessible directory (document root)
```

## 1. Database (least privilege)

```sql
CREATE DATABASE rankmath CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Runtime account (what the web app uses): no DDL, no TRUNCATE, no FILE.
CREATE USER 'rm_app'@'127.0.0.1' IDENTIFIED BY '<long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON rankmath.* TO 'rm_app'@'127.0.0.1';
-- Install/upgrade account (used only by bin/install.php and bin/migrate.php, e.g. via RMW_DB_USER/RMW_DB_PASS):
CREATE USER 'rm_migrate'@'127.0.0.1' IDENTIFIED BY '<another long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON rankmath.* TO 'rm_migrate'@'127.0.0.1';
```

Install as `rm_migrate`, then edit `config/config.php` (or the environment) to use `rm_app`. Never use `root`; never `GRANT ALL`.

## 2. Install

```bash
export RMW_DB_PASS='<rm_migrate password>' RMW_ADMIN_PASSWORD='<min 12 chars>'
php bin/install.php --db-user=rm_migrate --db-name=rankmath --admin-user=admin --admin-email=you@example.com
chmod 600 config/config.php
```

## 3. Apache

```apache
<VirtualHost *:443>
    ServerName seo.example.com
    DocumentRoot /srv/rankmath/public
    SSLEngine on  # certificate configuration omitted
    <Directory /srv/rankmath/public>
        Options -Indexes
        AllowOverride All          # public/.htaccess carries the rewrite + header rules
        Require all granted
    </Directory>
    SetEnv RMW_BASE_URL https://seo.example.com   # or put everything in config/config.php
</VirtualHost>
```

Required modules: `rewrite`, `headers`. Environment variables for PHP can be set with `PassEnv`/`SetEnv`, PHP-FPM `env[...]`, or simply `config/config.php`.

**Sub-path** (e.g. `https://example.com/seo/`): `Alias /seo /srv/rankmath/public` with the same `<Directory>` block, and `RMW_BASE_URL=https://example.com/seo`. Tested with Apache 2.4 (`tests/docker/smoke.sh`). If your host maps the sub-path differently and rewrites misroute, add `RewriteBase /seo/` inside that `<Directory>`. Do not deploy the whole repository inside the web root.

## 4. nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name seo.example.com;
    root /srv/rankmath/public;
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

* HTTPS only; set `RMW_BASE_URL` (https) and `RMW_ALLOWED_HOSTS`; enable `RMW_HSTS=true`.
* `display_errors=Off` in PHP (the app also forces it off unless `RMW_DEBUG`); `expose_php=Off`.
* `config/config.php` mode `0600`, owned by the PHP user; keep backups encrypted.
* Block outbound access from the PHP host to internal networks and cloud metadata endpoints at the firewall as a second layer behind the application's SSRF guard.
* Schedule `php bin/purge-404.php` daily if you use the 404 log.
* Backups: `mysqldump --defaults-extra-file=/root/.my.cnf rankmath | gzip > backup.sql.gz` (keep credentials in the option file, not on the command line).
* Do not run the web server or PHP as root; no recursive `chmod 755/777` — files need only read access for the PHP user, `storage/` write access for the installer.
* Rotate any API key that was ever committed. Scan history with gitleaks/trufflehog.

## 6. Upgrades

`git pull && php bin/migrate.php` (run with the migration-capable DB account). Migrations are tracked in `schema_migrations`; a v1.0 database is detected and adopted automatically. Migration `003` drops the never-used `projects`, `schema`, `settings` and `analytics_pages` tables.
