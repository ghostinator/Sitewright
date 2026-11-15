<?php
/**
 * Copy to config/config.php (git-ignored, keep it chmod 0600) or use the RMW_* environment variables.
 * Environment variables override this file. Never commit real values.
 */
return [
    'db' => [
        'host' => '127.0.0.1',   // RMW_DB_HOST
        'port' => 3306,          // RMW_DB_PORT
        'name' => 'rankmath_webapp', // RMW_DB_NAME
        'user' => '',            // RMW_DB_USER  (runtime account: SELECT, INSERT, UPDATE, DELETE only)
        'pass' => '',            // RMW_DB_PASS
        'prefix' => 'rm_',       // RMW_DB_PREFIX  (letters, digits, underscore; max 16)
    ],

    // Public URL of the app incl. sub-path, e.g. https://seo.example.com or https://example.com/seo
    'base_url' => '',            // RMW_BASE_URL
    // Hostnames accepted in the Host header (DNS-rebinding protection). Default: localhost only.
    'allowed_hosts' => [],       // RMW_ALLOWED_HOSTS (comma separated)

    'debug' => false,            // RMW_DEBUG  (never enable on a public server)
    'contact' => '',             // RMW_CONTACT: URL/e-mail shown in the User-Agent of outbound requests

    // Content AI (optional). Leave empty to use clearly-labelled templates only.
    'gemini' => [
        'api_key' => '',         // RMW_GEMINI_API_KEY
        'model' => 'gemini-3.6-flash', // RMW_GEMINI_MODEL
    ],

    // Local SEO geocoding via OpenStreetMap Nominatim. OFF by default: enabling sends addresses to a third party.
    'enable_geocoding' => false, // RMW_ENABLE_GEOCODING

    'retention_days_404' => 90,  // 404 log entries older than this are purged (0 = keep forever)
    'hsts' => false,             // RMW_HSTS: send Strict-Transport-Security when served over HTTPS

    'fetch' => [
        'allow_hosts' => [],     // RMW_FETCH_ALLOW_HOSTS: if set, analysis/crawl only these hosts (IP checks still apply)
        'block_hosts' => [],     // RMW_FETCH_BLOCK_HOSTS
        'dev_allow_cidrs' => [], // RMW_FETCH_DEV_ALLOW_CIDRS: DEV ONLY - exempt ranges (e.g. 127.0.0.0/8) from the SSRF guard
    ],
];
