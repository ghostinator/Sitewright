<?php
/**
 * Copy to config/config.php (git-ignored, keep it chmod 0600) or use the SW_* environment variables.
 * Environment variables override this file. Never commit real values.
 */
return [
    'db' => [
        'host' => '127.0.0.1',   // SW_DB_HOST
        'port' => 3306,          // SW_DB_PORT
        'name' => 'sitewright_webapp', // SW_DB_NAME
        'user' => '',            // SW_DB_USER  (runtime account: SELECT, INSERT, UPDATE, DELETE only)
        'pass' => '',            // SW_DB_PASS
        'prefix' => 'sw_',       // SW_DB_PREFIX  (letters, digits, underscore; max 16)
    ],

    // Public URL of the app incl. sub-path, e.g. https://seo.example.com or https://example.com/seo
    'base_url' => '',            // SW_BASE_URL
    // Hostnames accepted in the Host header (DNS-rebinding protection). Default: localhost only.
    'allowed_hosts' => [],       // SW_ALLOWED_HOSTS (comma separated)

    'debug' => false,            // SW_DEBUG  (never enable on a public server)
    'contact' => '',             // SW_CONTACT: URL/e-mail shown in the User-Agent of outbound requests

    // Content AI (optional). Leave empty to use clearly-labelled templates only.
    'gemini' => [
        'api_key' => '',         // SW_GEMINI_API_KEY
        'model' => 'gemini-3.6-flash', // SW_GEMINI_MODEL
    ],

    // Local SEO geocoding via OpenStreetMap Nominatim. OFF by default: enabling sends addresses to a third party.
    'enable_geocoding' => false, // SW_ENABLE_GEOCODING

    'retention_days_404' => 90,  // 404 log entries older than this are purged (0 = keep forever)
    // SW_TRUSTED_PROXIES: comma-separated IPs/CIDRs of YOUR reverse proxies. Only requests arriving from these have X-Forwarded-For
    // honoured (rate limits, login throttle and the audit log then see the real client). Empty = proxy headers ignored.
    // 0.0.0.0/0 and ::/0 are refused. The proxy must also preserve the Host header.
    'trusted_proxies' => [],
    'retention_days_audit' => 365, // audit_log rows older than this are removed by bin/purge-audit.php
    'hsts' => false,             // SW_HSTS: send Strict-Transport-Security when served over HTTPS

    'fetch' => [
        'allow_hosts' => [],     // SW_FETCH_ALLOW_HOSTS: if set, analysis/crawl only these hosts (IP checks still apply)
        'block_hosts' => [],     // SW_FETCH_BLOCK_HOSTS
        'dev_allow_cidrs' => [], // SW_FETCH_DEV_ALLOW_CIDRS: DEV ONLY - exempt ranges (e.g. 127.0.0.0/8) from the SSRF guard
    ],
];
