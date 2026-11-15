#!/bin/sh
# Asserts the .htaccess behaviour on every Apache variant started by smoke.sh (run `tests/docker/smoke.sh up` first).
fail=0
chk() { # name url expected-status [body-grep]
  code=$(curl -s -o /tmp/sw-ap.$$ -w '%{http_code}' "$2"); ok=1
  [ "$code" = "$3" ] || ok=0
  [ -z "$4" ] || grep -q "$4" /tmp/sw-ap.$$ || ok=0
  [ $ok = 1 ] && echo "ok   $1 ($code)" || { echo "FAIL $1: got $code, wanted $3 $4"; fail=1; }
}
for v in "root:8081:" "alias:8082:/seo" "FileInfo-only:8083:"; do
  n=${v%%:*}; r=${v#*:}; port=${r%%:*}; pre=${r#*:}; B="http://localhost:$port$pre"
  chk "$n: shell"            "$B/"                    200 "app-config"
  chk "$n: api health"       "$B/api/health"          200 '"success":true'
  chk "$n: unknown view"     "$B/not-a-view"          404
  chk "$n: asset served"     "$B/assets/js/app.js"    200 "Sitewright"
  chk "$n: no dir listing"   "$B/assets/"             404
  chk "$n: no dir listing 2" "$B/assets/js"           404
  chk "$n: dotfile denied"   "$B/.htaccess"           403
  chk "$n: backup denied"    "$B/config.sql"          403
  chk "$n: README denied"    "$B/readme.md"           403
done
rm -f /tmp/sw-ap.$$; exit $fail
