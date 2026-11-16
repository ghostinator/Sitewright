#!/bin/sh
# Backup/restore drill: migrate a scratch DB, add data, back up, DROP everything, restore, compare row counts + schema fingerprint.
# Usage: tests/docker/backup-drill.sh [db-image]   (default mariadb:10.11)
set -e
cd "$(dirname "$0")/../.."
IMG="${1:-mariadb:10.11}"; N=sw-drill-$$
trap 'docker rm -f $N >/dev/null 2>&1 || true' EXIT
docker run -d --name $N -e MARIADB_ROOT_PASSWORD=pw -e MYSQL_ROOT_PASSWORD=pw -e MARIADB_DATABASE=drill -e MYSQL_DATABASE=drill "$IMG" >/dev/null
CLI=mariadb; case "$IMG" in mysql*) CLI=mysql ;; esac
for i in $(seq 1 60); do docker exec $N $CLI -h127.0.0.1 -uroot -ppw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
docker exec $N sh -c "$CLI -uroot -ppw drill" < /dev/null
for f in database/migrations/*.sql; do sed 's/{{prefix}}/sw_/g' "$f" | docker exec -i $N $CLI -uroot -ppw drill; done
docker exec $N $CLI -uroot -ppw drill -e "INSERT INTO sw_users (username,email,password) VALUES ('a','a@x.test','h'),('b','b@x.test','h'); INSERT INTO sw_audit_log (username,action) VALUES ('a','x'),('b','y'),('c','z');"
snap() { docker exec $N $CLI -uroot -ppw -N drill -e "SELECT 'users',COUNT(*) FROM sw_users UNION ALL SELECT 'audit',COUNT(*) FROM sw_audit_log UNION ALL SELECT 'tables',COUNT(*) FROM information_schema.tables WHERE table_schema='drill'"; }
BEFORE=$(snap)
docker cp bin/db-backup.sh $N:/tmp/db-backup.sh
docker exec -e SW_DB_NAME=drill -e SW_DB_USER=root -e SW_DB_PASS=pw -e SW_DB_HOST=127.0.0.1 $N bash /tmp/db-backup.sh backup /tmp/bk > /tmp/sw-drill-out.$$
FILE=$(cat /tmp/sw-drill-out.$$); rm -f /tmp/sw-drill-out.$$
docker exec $N sh -c "stat -c '%a' $FILE" | grep -qx 600 || { echo "backup file is not mode 0600"; exit 1; }
docker exec $N $CLI -uroot -ppw -e 'DROP DATABASE drill; CREATE DATABASE drill'
docker exec -e SW_DB_NAME=drill -e SW_DB_USER=root -e SW_DB_PASS=pw -e SW_DB_HOST=127.0.0.1 $N bash /tmp/db-backup.sh restore "$FILE" 2>&1 | grep -q Refusing && echo "restore without confirmation refused: ok"
docker exec -e SW_RESTORE_CONFIRM=yes -e SW_DB_NAME=drill -e SW_DB_USER=root -e SW_DB_PASS=pw -e SW_DB_HOST=127.0.0.1 $N bash /tmp/db-backup.sh restore "$FILE"
AFTER=$(snap)
[ "$BEFORE" = "$AFTER" ] && { echo "DRILL PASS ($IMG)"; echo "$AFTER"; } || { echo "DRILL FAIL"; echo "--before"; echo "$BEFORE"; echo "--after"; echo "$AFTER"; exit 1; }
