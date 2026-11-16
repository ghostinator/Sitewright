#!/bin/sh
# Playwright suite against the Apache + MariaDB + worker smoke stack. Needs Docker. Usage: tests/browser/run.sh
cd "$(dirname "$0")/../.." || exit 1
tests/docker/smoke.sh up >/dev/null || exit 1
sleep 3
docker exec -d sw-apache-root sh -c 'while true; do php /var/www/app/bin/worker.php --once; sleep 1; done'
PW=mcr.microsoft.com/playwright:v1.49.1-noble
docker run --rm --network swnet -v "$PWD/tests/browser":/work -w /work -e BASE=http://sw-apache-root -e CI=1 $PW sh -c 'if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi && npx playwright test'
rc=$?
tests/docker/smoke.sh down >/dev/null
exit $rc
