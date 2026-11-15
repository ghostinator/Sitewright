#!/bin/sh
# Runs lint + test suite inside Docker against a disposable MariaDB with synthetic credentials.
# Usage: [DB_IMAGE=mysql:8.4] tests/docker/run.sh [php-version] [file-filter]   (default PHP 8.1, DB mariadb:10.11)
set -e
cd "$(dirname "$0")/../.."
V="${1:-8.1}"; shift || true
NET=swnet; DBI="${DB_IMAGE:-mariadb:10.11}"; DB="sw-db-$(echo "$DBI" | tr ':./' '---')"
CLI=mariadb; case "$DBI" in mysql*) CLI=mysql ;; esac
docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
if ! docker ps --format '{{.Names}}' | grep -qx $DB; then
  docker rm -f $DB >/dev/null 2>&1 || true
  docker run -d --name $DB --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw -e MARIADB_DATABASE=sw_test -e MYSQL_ROOT_PASSWORD=test-root-pw -e MYSQL_DATABASE=sw_test "$DBI" >/dev/null
fi
docker image inspect sw-php:$V >/dev/null 2>&1 || docker build -q -t sw-php:$V --build-arg PHP_VERSION=$V tests/docker >/dev/null
# wait for DB
for i in $(seq 1 40); do
  docker exec $DB $CLI -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1
done
echo "== php -l ($V)"
docker run --rm -v "$PWD":/app -w /app sw-php:$V sh -c 'find . -name "*.php" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors" || true; echo lint-done'
echo "== tests ($V)"
docker run --rm --network $NET -v "$PWD":/app -w /app \
  -e SW_TEST_DB_HOST=$DB -e SW_TEST_DB_USER=root -e SW_TEST_DB_PASS=test-root-pw \
  sw-php:$V php tests/run.php "$@"
