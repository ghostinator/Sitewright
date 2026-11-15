#!/usr/bin/env bash
# Logical backup / restore of the application database (mysqldump; consistent snapshot, no table locks).
#   SW_DB_HOST SW_DB_PORT SW_DB_NAME SW_DB_USER SW_DB_PASS   same variables as the application (the password is passed via MYSQL_PWD,
#                                                            never on the command line)
#   bin/db-backup.sh backup [dir]        writes dir/sitewright-YYYYmmdd-HHMMSS.sql.gz (mode 0600), default ./backups, keeps SW_BACKUP_KEEP (14)
#   bin/db-backup.sh restore <file.gz>   loads a backup into SW_DB_NAME (refuses unless SW_RESTORE_CONFIRM=yes)
# The backup contains password hashes, TOTP secrets and the audit log: store it encrypted and off the web server.
# The dump tool needs SELECT, SHOW VIEW, TRIGGER (and LOCK TABLES is not used); restore needs a DDL-capable account.
set -euo pipefail
: "${SW_DB_NAME:?set SW_DB_NAME}" "${SW_DB_USER:?set SW_DB_USER}"
HOST="${SW_DB_HOST:-127.0.0.1}"; PORT="${SW_DB_PORT:-3306}"; export MYSQL_PWD="${SW_DB_PASS:-}"
DUMP="$(command -v mariadb-dump || command -v mysqldump)"; CLI="$(command -v mariadb || command -v mysql)"
umask 077
case "${1:-}" in
  backup)
    dir="${2:-./backups}"; mkdir -p "$dir"
    out="$dir/sitewright-$(date +%Y%m%d-%H%M%S).sql.gz"
    "$DUMP" -h "$HOST" -P "$PORT" -u "$SW_DB_USER" --single-transaction --no-tablespaces --routines=false --default-character-set=utf8mb4 "$SW_DB_NAME" | gzip -9 > "$out.partial"
    gzip -t "$out.partial" && mv "$out.partial" "$out"
    ls -1t "$dir"/sitewright-*.sql.gz 2>/dev/null | tail -n +$(( ${SW_BACKUP_KEEP:-14} + 1 )) | xargs -r rm -f
    echo "$out"
    ;;
  restore)
    f="${2:?usage: restore <file.gz>}"
    [ "${SW_RESTORE_CONFIRM:-}" = "yes" ] || { echo "Refusing: this replaces tables in $SW_DB_NAME. Re-run with SW_RESTORE_CONFIRM=yes" >&2; exit 2; }
    gzip -t "$f"
    gunzip -c "$f" | "$CLI" -h "$HOST" -P "$PORT" -u "$SW_DB_USER" --default-character-set=utf8mb4 "$SW_DB_NAME"
    echo "restored $f into $SW_DB_NAME"
    ;;
  *) echo "usage: $0 backup [dir] | restore <file.gz>" >&2; exit 2 ;;
esac
