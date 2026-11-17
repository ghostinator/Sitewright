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
  -e "WORDPRESS_CONFIG_EXTRA=define('WP_HOME','http://sw-wp'); define('WP_SITEURL','http://sw-wp'); define('SITEWRIGHT_SECRET','$SECRET'); define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', false);" \
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
for i in $(seq 1 30); do docker run --rm --network $NET curlimages/curl -s -o /dev/null -w '%{http_code}' http://sw-wp/ 2>/dev/null | grep -q 200 && break; sleep 1; done
docker image inspect sw-php:8.3 >/dev/null 2>&1 || docker build -q -t sw-php:8.3 --build-arg PHP_VERSION=8.3 tests/docker >/dev/null
docker ps --format '{{.Names}}' | grep -qx sw-db-mariadb-10-11 || docker run -d --name sw-db-mariadb-10-11 --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw -e MARIADB_DATABASE=sw_test mariadb:10.11 >/dev/null
for i in $(seq 1 40); do docker exec sw-db-mariadb-10-11 mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
# a pasted secret must be exactly 64 hex characters: tidy quotes/spaces are accepted, anything else is refused instead of being silently shortened
got=$($CLI wp eval 'echo json_encode([Sitewright_Agent::normalise_secret(" \"" . strtoupper(str_repeat("ab", 32)) . "\" "), Sitewright_Agent::normalise_secret("not a secret"), Sitewright_Agent::normalise_secret(str_repeat("a", 63)), Sitewright_Agent::normalise_secret(str_repeat("a", 65)), Sitewright_Agent::normalise_secret("")]);' 2>&1)
want='["abababababababababababababababababababababababababababababababab",null,null,null,null]'
[ "$got" = "$want" ] || { echo "FAIL plugin secret validation: $got"; exit 1; }
echo "plugin secret validation: ok"
docker run --rm --network $NET -v "$PWD":/app -w /app -e WP_URL=http://sw-wp -e WP_SECRET=$SECRET \
  -e SW_TEST_DB_HOST=sw-db-mariadb-10-11 -e SW_TEST_DB_USER=root -e SW_TEST_DB_PASS=test-root-pw sw-php:8.3 php tests/e2e/wordpress.php
rc=$?
[ $rc -ne 0 ] && { echo "--- WordPress PHP log (tail)"; docker logs --tail 15 sw-wp 2>&1 | grep -iE "fatal|warning|error|sitewright" | head -10; }
exit $rc
