#!/bin/sh
# Runs everything locally (this project deliberately has no hosted CI). Needs Docker and Node. Takes ~10 minutes.
#   tests/all.sh            full matrix
#   tests/all.sh quick      PHP 8.3 + MariaDB 10.11 and JS only
cd "$(dirname "$0")/.." || exit 1
fail=0
step() { echo; echo "=================== $*"; }
run()  { "$@" || { echo "!!! FAILED: $*"; fail=1; }; }

step "PHP tests"
if [ "$1" = quick ]; then
  run tests/docker/run.sh 8.3
else
  run tests/docker/run.sh 8.1
  run tests/docker/run.sh 8.3
  run tests/docker/run.sh 8.4
  run env DB_IMAGE=mariadb:11.8 tests/docker/run.sh 8.4
  run env DB_IMAGE=mysql:8.4 tests/docker/run.sh 8.3
fi

step "JS tests"
( cd tests/js && npm ci --silent && node --test ) || { echo "!!! FAILED: JS tests"; fail=1; }

if [ "$1" != quick ]; then
  step "Apache variants (.htaccess behaviour)"
  tests/docker/smoke.sh up && sleep 4 && run tests/docker/apache-check.sh
  [ -x tests/browser/run.sh ] && { step "Browser suite"; run tests/browser/run.sh; }
  tests/docker/smoke.sh down >/dev/null
  step "Backup / restore drills"
  run tests/docker/backup-drill.sh mariadb:10.11
  run tests/docker/backup-drill.sh mariadb:11.8
  run tests/docker/backup-drill.sh mysql:8.4
  step "Secret scan (working tree)"
  run docker run --rm -v "$PWD:/repo" zricethezav/gitleaks:v8.18.4 detect --no-git --source /repo --redact
fi

echo; [ $fail = 0 ] && echo "ALL GREEN" || echo "SOMETHING FAILED"
exit $fail
