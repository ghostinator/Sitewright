#!/bin/sh
# Proves deploy/setup-logging.sh + deploy/sitewright.logrotate in a clean Debian container: the log directory and config are created, rotation keeps
# yesterday's log readable, compresses older days, deletes beyond the keep limit, and the Uptime Kuma push URL is stored privately and validated.
# Needs Docker and network (installs logrotate). Usage: tests/docker/logging-drill.sh
cd "$(dirname "$0")/../.." || exit 1
docker run --rm -v "$PWD":/src:ro php:8.3-cli bash -c '
set -e
apt-get update -qq >/dev/null && apt-get install -y -qq logrotate curl >/dev/null
fail() { echo "FAIL: $*"; exit 1; }
cp -r /src/deploy /tmp/deploy
printf "<?php\nreturn [\"db\" => [\"user\" => \"u\"]];\n" > /tmp/config.php
SW_SETUP_TEST=1 SW_CFG=/tmp/config.php /tmp/deploy/setup-logging.sh 3 warn >/dev/null
php -r "\$c = require \"/tmp/config.php\"; exit((\$c[\"log\"][\"file\"] === \"/var/log/sitewright/app.log\" && \$c[\"log\"][\"level\"] === \"warn\" && \$c[\"db\"][\"user\"] === \"u\") ? 0 : 1);" || fail "config not updated or other settings lost"
grep -q "^    rotate 3\$" /etc/logrotate.d/sitewright || fail "keep days not applied"
[ "$(stat -c %a /var/log/sitewright)" = 750 ] || fail "log directory mode"
SW_SETUP_TEST=1 SW_CFG=/tmp/config.php /tmp/deploy/setup-logging.sh 5 error >/dev/null; grep -q "^    rotate 5\$" /etc/logrotate.d/sitewright || fail "not idempotent / keep days not re-applied"
if SW_SETUP_TEST=1 SW_CFG=/tmp/config.php /tmp/deploy/setup-logging.sh abc warn >/dev/null 2>&1; then fail "bad keep_days accepted"; fi
if SW_SETUP_TEST=1 SW_CFG=/tmp/config.php /tmp/deploy/setup-logging.sh 5 chatty >/dev/null 2>&1; then fail "bad level accepted"; fi
logrotate --debug /etc/logrotate.d/sitewright >/dev/null 2>&1 || fail "logrotate rejects the rule"
SW_SETUP_TEST=1 SW_CFG=/tmp/config.php /tmp/deploy/setup-logging.sh 3 warn >/dev/null
for day in 1 2 3 4 5; do echo "{\"day\":$day}" >> /var/log/sitewright/app.log; logrotate -f -s /tmp/state /etc/logrotate.d/sitewright; done
echo "{\"day\":6}" >> /var/log/sitewright/app.log
[ -f /var/log/sitewright/app.log.1 ] || fail "yesterday missing"; grep -q "\"day\":5" /var/log/sitewright/app.log.1 || fail "yesterday not readable as plain text"
[ -f /var/log/sitewright/app.log.2.gz ] && zcat /var/log/sitewright/app.log.2.gz | grep -q "\"day\":4" || fail "older day not compressed"
[ -f /var/log/sitewright/app.log.3.gz ] || fail "third day missing"
[ ! -e /var/log/sitewright/app.log.4.gz ] && [ ! -e /var/log/sitewright/app.log.4 ] || fail "logs older than the keep limit were not deleted"
[ "$(stat -c "%a %U %G" /var/log/sitewright/app.log)" = "640 www-data adm" ] || fail "new log has wrong owner or mode: $(stat -c "%a %U %G" /var/log/sitewright/app.log)"
# Uptime Kuma push URL
export SW_SECRETS_TEST=1 SW_WORKER_ENV=/tmp/worker.env
printf "SW_OTHER=keep\n" > /tmp/worker.env
echo "http://192.168.0.50:3001/api/push/AbC123xyz?status=up&msg=OK&ping=" | /tmp/deploy/sw-set-secrets uptime >/dev/null || fail "valid push URL rejected"
grep -q "^SW_UPTIME_PUSH_URL=http://192.168.0.50:3001/api/push/AbC123xyz?status=up&msg=OK&ping=\$" /tmp/worker.env && grep -q "^SW_OTHER=keep\$" /tmp/worker.env || fail "push URL not stored or other variables lost"
[ "$(stat -c %a /tmp/worker.env)" = 600 ] || fail "worker.env must be private"
echo "http://192.168.0.51:3001/api/push/Other?status=up" | /tmp/deploy/sw-set-secrets uptime >/dev/null; [ "$(grep -c ^SW_UPTIME_PUSH_URL= /tmp/worker.env)" = 1 ] && grep -q Other /tmp/worker.env || fail "second run must replace, not append"
if echo "http://x/\$(reboot)" | /tmp/deploy/sw-set-secrets uptime >/dev/null 2>&1; then fail "hostile push URL accepted"; fi
echo "logging drill: PASS"
'
