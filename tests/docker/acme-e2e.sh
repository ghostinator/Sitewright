#!/bin/sh
# Sitewright's ACME client and certificate service against Pebble (Let's Encrypt's own test server) with its test DNS server, in Docker.
# Real signatures, orders, DNS-01 validation, CSRs and certificate download; nothing leaves your machine. Needs to pull the two images once.
# Usage: tests/docker/acme-e2e.sh   (leaves nothing running)
cd "$(dirname "$0")/../.." || exit 1
NET=swnet; CA=$(mktemp -d "$PWD/.acme-ca.XXXXXX")
docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
trap 'docker rm -f sw-pebble sw-challtestsrv >/dev/null 2>&1; rm -rf "$CA"' EXIT
docker rm -f sw-pebble sw-challtestsrv >/dev/null 2>&1
docker create --name sw-pebble-ca ghcr.io/letsencrypt/pebble:latest >/dev/null && docker cp sw-pebble-ca:/test/certs/pebble.minica.pem "$CA/minica.pem" >/dev/null && docker rm sw-pebble-ca >/dev/null
docker run -d --name sw-challtestsrv --network $NET ghcr.io/letsencrypt/pebble-challtestsrv:latest -http01 "" -https01 "" -tlsalpn01 "" -doh "" >/dev/null
docker run -d --name sw-pebble --network $NET --network-alias pebble -e PEBBLE_VA_NOSLEEP=1 -e PEBBLE_VA_ALWAYS_VALID=0 \
  ghcr.io/letsencrypt/pebble:latest -config /test/config/pebble-config.json -dnsserver sw-challtestsrv:8053 >/dev/null
docker image inspect sw-php:8.3 >/dev/null 2>&1 || docker build -q -t sw-php:8.3 --build-arg PHP_VERSION=8.3 tests/docker >/dev/null
docker ps --format '{{.Names}}' | grep -qx sw-db-mariadb-10-11 || docker run -d --name sw-db-mariadb-10-11 --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw -e MARIADB_DATABASE=sw_test mariadb:10.11 >/dev/null
for i in $(seq 1 40); do docker exec sw-db-mariadb-10-11 mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
for i in $(seq 1 30); do docker run --rm --network $NET -v "$CA":/ca:ro sw-php:8.3 php -r 'exit(@file_get_contents("https://pebble:14000/dir", false, stream_context_create(["ssl" => ["cafile" => "/ca/minica.pem"]])) ? 0 : 1);' && break; sleep 1; done
docker run --rm --network $NET -v "$PWD":/app -v "$CA":/ca:ro -w /app -e PEBBLE_DIR=https://pebble:14000/dir -e CHALLTESTSRV=http://sw-challtestsrv:8055 -e PEBBLE_CA=/ca/minica.pem \
  -e SW_TEST_DB_HOST=sw-db-mariadb-10-11 -e SW_TEST_DB_USER=root -e SW_TEST_DB_PASS=test-root-pw sw-php:8.3 php tests/e2e/acme.php
rc=$?
[ $rc -ne 0 ] && { echo "--- pebble log (tail)"; docker logs --tail 15 sw-pebble 2>&1 | cut -c1-220; }
exit $rc
