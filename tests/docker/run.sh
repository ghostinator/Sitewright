#!/bin/sh
# Runs lint + test suite inside Docker against a disposable MariaDB with synthetic credentials.
# Usage: tests/docker/run.sh [php-version]   (default 8.1)
set -e
cd "$(dirname "$0")/../.."
V="${1:-8.1}"; shift || true
NET=rmwnet; DB=rmw-db
docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
if ! docker ps --format '{{.Names}}' | grep -qx $DB; then
  docker rm -f $DB >/dev/null 2>&1 || true
  docker run -d --name $DB --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw -e MARIADB_DATABASE=rmw_test mariadb:10.11 >/dev/null
fi
docker image inspect rmw-php:$V >/dev/null 2>&1 || docker build -q -t rmw-php:$V --build-arg PHP_VERSION=$V tests/docker >/dev/null
# wait for DB
for i in $(seq 1 40); do
  docker exec $DB mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1
done
echo "== php -l ($V)"
docker run --rm -v "$PWD":/app -w /app rmw-php:$V sh -c 'find . -name "*.php" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors" || true; echo lint-done'
echo "== tests ($V)"
docker run --rm --network $NET -v "$PWD":/app -w /app \
  -e RMW_TEST_DB_HOST=$DB -e RMW_TEST_DB_USER=root -e RMW_TEST_DB_PASS=test-root-pw \
  rmw-php:$V php tests/run.php "$@"
