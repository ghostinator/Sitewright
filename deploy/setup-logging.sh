#!/bin/bash
# One-time setup of file logging with rotation. Idempotent. Usage:  sudo deploy/setup-logging.sh [keep_days] [level]
#   keep_days  how many days of logs to keep (default 90); yesterday's stays readable, older days are compressed, then deleted
#   level      warn (default) = warnings and errors only; info also logs every request (about 1 line per click)
# Writes /var/log/sitewright/app.log (JSON lines), installs the logrotate rule, and sets log.file / log.level in config.php.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run with sudo"; exit 1; }
KEEP="${1:-90}"; LEVEL="${2:-warn}"
[[ "$KEEP" =~ ^[0-9]{1,4}$ ]] && [ "$KEEP" -ge 1 ] || { echo "keep_days must be a number from 1 to 9999"; exit 1; }
[[ "$LEVEL" =~ ^(debug|info|warn|error)$ ]] || { echo "level must be debug, info, warn or error"; exit 1; }
if [ -z "${SW_SETUP_TEST:-}" ] && ! command -v logrotate >/dev/null; then apt-get install -y logrotate >/dev/null || { echo "logrotate is not installed and could not be installed"; exit 1; }; fi
HERE="$(cd "$(dirname "$0")" && pwd)"
CFG="${SW_CFG:-/srv/sitewright/config/config.php}"
LOGDIR="${SW_LOGDIR:-/var/log/sitewright}"
install -d -m 0750 -o "${SW_LOG_OWNER:-www-data}" -g "${SW_LOG_GROUP:-adm}" "$LOGDIR"
sed -E "s/^([[:space:]]*rotate )[0-9]+/\1$KEEP/" "$HERE/sitewright.logrotate" > "${SW_LOGROTATE_DEST:-/etc/logrotate.d/sitewright}"
chmod 644 "${SW_LOGROTATE_DEST:-/etc/logrotate.d/sitewright}"
SW_CFG_FILE="$CFG" SW_LOGFILE="$LOGDIR/app.log" SW_LEVEL="$LEVEL" php -r '
  $f = getenv("SW_CFG_FILE"); $c = is_file($f) ? require $f : [];
  $c["log"] = ["file" => getenv("SW_LOGFILE"), "level" => getenv("SW_LEVEL")] + ($c["log"] ?? []);
  file_put_contents($f, "<?php\n// Staging deployment config. Keep private (0640 sitewright:www-data).\nreturn " . var_export($c, true) . ";\n");'
if [ -z "${SW_SETUP_TEST:-}" ]; then
  chown sitewright:www-data "$CFG"; chmod 640 "$CFG"
  systemctl reload php8.4-fpm 2>/dev/null || true; systemctl restart sitewright-worker 2>/dev/null || true
  logrotate --debug /etc/logrotate.d/sitewright >/dev/null 2>&1 || { echo "logrotate rejected the rule"; exit 1; }
fi
echo "Logging to $LOGDIR/app.log at level '$LEVEL', keeping $KEEP days. Saved Settings > Log detail overrides the level."
