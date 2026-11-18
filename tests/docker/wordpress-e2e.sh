#!/bin/sh
# Real WordPress + MariaDB in Docker with the Sitewright agent installed as a mu-plugin, driven by Sitewright's own sync code.
# Usage: tests/docker/wordpress-e2e.sh   (leaves nothing running)
cd "$(dirname "$0")/../.." || exit 1
NET=swnet; SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
trap 'docker rm -f sw-wp sw-wp-db >/dev/null 2>&1' EXIT
docker rm -f sw-wp sw-wp-db >/dev/null 2>&1
docker run -d --name sw-wp-db --network $NET -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:10.11 >/dev/null
docker run -d --name sw-wp --network $NET -e WORDPRESS_DB_HOST=sw-wp-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e "WORDPRESS_CONFIG_EXTRA=define('WP_HOME','http://sw-wp'); define('WP_SITEURL','http://sw-wp'); define('SITEWRIGHT_SECRET','$SECRET'); define('WP_DEBUG', true); define('WPSEO_VERSION', 'e2e'); define('WP_DEBUG_DISPLAY', false);" \
  -v "$PWD/agent/wordpress/sitewright-agent.php":/var/www/html/wp-content/mu-plugins/sitewright-agent.php:ro wordpress:6-php8.3-apache >/dev/null
echo "waiting for the database and WordPress..."
for i in $(seq 1 60); do docker exec sw-wp-db mariadb -h127.0.0.1 -uwp -pwp -e 'select 1' wp >/dev/null 2>&1 && break; sleep 1; done
for i in $(seq 1 60); do docker exec sw-wp test -f /var/www/html/wp-config.php && break; sleep 1; done
CLI="docker run --rm --volumes-from sw-wp --network $NET -e WORDPRESS_DB_HOST=sw-wp-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -u 33:33 wordpress:cli"
for i in 1 2 3 4 5 6; do
  out=$($CLI wp core install --url=http://sw-wp --title=E2E --admin_user=admin --admin_password='e2e-admin-pass-123' --admin_email=a@example.test --skip-email 2>&1) && break
  [ $i = 6 ] && { echo "wp core install failed: $out"; exit 2; }; sleep 5
done
$CLI wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1
$CLI wp post create --post_type=page --post_title='New page' --post_name=new-page --post_status=publish >/dev/null 2>&1
$CLI wp post create --post_type=page --post_title='Ok' --post_name=ok --post_status=publish >/dev/null 2>&1
# content the optional routes will be asked about (the consent switches are flipped by the PHP driver)
HELLO=$($CLI wp post create --post_title='Hello <em>world</em>' --post_name=hello --post_status=publish --porcelain 2>&1)
$CLI wp post meta update "$HELLO" _yoast_wpseo_title 'Hello | E2E' >/dev/null 2>&1
$CLI wp post meta update "$HELLO" _yoast_wpseo_metadesc 'Say hello' >/dev/null 2>&1
TPL=$($CLI wp post create --post_title='Templated' --post_name=templated --post_status=publish --porcelain 2>&1)
$CLI wp post meta update "$TPL" _yoast_wpseo_title '%%title%% %%sep%% %%sitename%%' >/dev/null 2>&1
HID=$($CLI wp post create --post_title='Hidden' --post_name=hidden --post_status=publish --porcelain 2>&1)
$CLI wp post meta update "$HID" _yoast_wpseo_meta-robots-noindex 1 >/dev/null 2>&1
$CLI wp post create --post_title='Locked' --post_name=locked --post_status=publish --post_password=pw >/dev/null 2>&1
$CLI wp post create --post_title='Draft only' --post_name=draft-only --post_status=draft >/dev/null 2>&1
$CLI wp option update woocommerce_store_address '1 Test St' >/dev/null 2>&1
$CLI wp option update woocommerce_store_city Pueblo >/dev/null 2>&1
$CLI wp option update woocommerce_store_postcode 81008 >/dev/null 2>&1
$CLI wp option update woocommerce_default_country US:CO >/dev/null 2>&1
$CLI wp eval 'global $wpdb; $wpdb->query("CREATE TABLE wp_redirection_items (id INT AUTO_INCREMENT PRIMARY KEY, url VARCHAR(2000), match_url VARCHAR(2000), regex TINYINT, action_type VARCHAR(20), action_code INT, action_data LONGTEXT, match_type VARCHAR(20), status VARCHAR(20))"); $wpdb->query("INSERT INTO wp_redirection_items (url, regex, action_type, action_code, action_data, match_type, status) VALUES (\"/old-r/\", 0, \"url\", 301, \"/new-r/\", \"url\", \"enabled\"), (\"/rx/(.*)\", 1, \"url\", 301, \"/y/\", \"url\", \"enabled\"), (\"/off/\", 0, \"url\", 301, \"/z/\", \"url\", \"disabled\")"); echo $wpdb->last_error;' 2>&1 | grep -v '^$' | head -3
# the consent form's own handler (real WordPress, as an administrator): a bogus type is ignored, only what is ticked turns on
got=$($CLI wp --user=admin eval '$_POST = $_REQUEST = ["sitewright_consent_form" => "1", "sitewright_consent" => ["media", "bogus", "versions"], "_wpnonce" => wp_create_nonce("sitewright_consent_save")]; ob_start(); Sitewright_Agent::settings_page(); $o = ob_get_clean(); echo json_encode(array_keys(array_filter(Sitewright_Agent::consent()))) . (strpos($o, "Saved") !== false ? " saved" : " NOT-saved");' 2>&1)
[ "$got" = '["media","versions"] saved' ] || { echo "FAIL consent form: $got"; exit 1; }
$CLI wp option delete sitewright_consent >/dev/null 2>&1
echo "consent form: ok"
for i in $(seq 1 30); do docker run --rm --network $NET curlimages/curl -s -o /dev/null -w '%{http_code}' http://sw-wp/ 2>/dev/null | grep -q 200 && break; sleep 1; done
docker image inspect sw-php:8.3 >/dev/null 2>&1 || docker build -q -t sw-php:8.3 --build-arg PHP_VERSION=8.3 tests/docker >/dev/null
docker ps --format '{{.Names}}' | grep -qx sw-db-mariadb-10-11 || docker run -d --name sw-db-mariadb-10-11 --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw -e MARIADB_DATABASE=sw_test mariadb:10.11 >/dev/null
for i in $(seq 1 40); do docker exec sw-db-mariadb-10-11 mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
# a pasted secret must be exactly 64 hex characters: tidy quotes/spaces are accepted, anything else is refused instead of being silently shortened
got=$($CLI wp eval 'echo json_encode([Sitewright_Agent::normalise_secret(" \"" . strtoupper(str_repeat("ab", 32)) . "\" "), Sitewright_Agent::normalise_secret("not a secret"), Sitewright_Agent::normalise_secret(str_repeat("a", 63)), Sitewright_Agent::normalise_secret(str_repeat("a", 65)), Sitewright_Agent::normalise_secret("")]);' 2>&1)
want='["abababababababababababababababababababababababababababababababab",null,null,null,null]'
[ "$got" = "$want" ] || { echo "FAIL plugin secret validation: $got"; exit 1; }
echo "plugin secret validation: ok"
docker run --rm --network $NET -v "$PWD":/app -w /app -e WP_URL=http://sw-wp -e WP_SECRET=$SECRET -e WP_DB_HOST=sw-wp-db \
  -e SW_TEST_DB_HOST=sw-db-mariadb-10-11 -e SW_TEST_DB_USER=root -e SW_TEST_DB_PASS=test-root-pw sw-php:8.3 php tests/e2e/wordpress.php
rc=$?
[ $rc -ne 0 ] && { echo "--- WordPress PHP log (tail)"; docker logs --tail 15 sw-wp 2>&1 | grep -iE "fatal|warning|error|sitewright" | head -10; }
exit $rc
