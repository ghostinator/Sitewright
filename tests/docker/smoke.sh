#!/bin/sh
# Starts MariaDB + Apache (root on :8081, sub-directory /seo/ on :8082) + a fixture site, for browser smoke tests.
# Usage: tests/docker/smoke.sh up | down
cd "$(dirname "$0")/../.." || exit 1
NET=rmwnet
case "$1" in
up)
  docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
  docker ps --format '{{.Names}}' | grep -qx rmw-db || docker run -d --name rmw-db --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw mariadb:10.11 >/dev/null
  docker image inspect rmw-apache >/dev/null 2>&1 || docker build -q -t rmw-apache -f tests/docker/Dockerfile.apache tests/docker >/dev/null
  docker image inspect rmw-php:8.3 >/dev/null 2>&1 || docker build -q -t rmw-php:8.3 --build-arg PHP_VERSION=8.3 tests/docker >/dev/null
  for i in $(seq 1 40); do docker exec rmw-db mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
  docker run --rm --network $NET -v "$PWD":/app -w /app rmw-php:8.3 php -r '
    require "core/autoload.php"; use RankMathWebapp\Core\Installer;
    Installer::run(["create_db"=>true,"config_path"=>"/tmp/c.php","lock_path"=>"/tmp/l","db"=>["host"=>"rmw-db","port"=>"3306","name"=>"rmw_smoke","user"=>"root","pass"=>"test-root-pw","prefix"=>"rm_"],
      "admin"=>["username"=>"admin","email"=>"admin@example.test","password"=>"smoke-test-passphrase"]]); echo "installed\n";'
  docker rm -f rmw-fixture rmw-apache-root rmw-apache-sub >/dev/null 2>&1
  docker run -d --name rmw-fixture --network $NET -v "$PWD/tests/fixtures/site":/usr/share/nginx/html:ro nginx:alpine >/dev/null
  ENVS="-e RMW_DB_HOST=rmw-db -e RMW_DB_NAME=rmw_smoke -e RMW_DB_USER=root -e RMW_DB_PASS=test-root-pw -e RMW_ALLOWED_HOSTS=localhost -e RMW_FETCH_DEV_ALLOW_CIDRS=172.16.0.0/12,192.168.0.0/16,10.0.0.0/8 -e RMW_CONTACT=https://example.test/contact"
  docker run -d --name rmw-apache-root --network $NET -p 8081:80 $ENVS -v "$PWD":/var/www/app -v "$PWD/tests/docker/apache-root.conf":/etc/apache2/sites-enabled/000-default.conf rmw-apache >/dev/null
  docker run -d --name rmw-apache-sub --network $NET -p 8082:80 $ENVS -e RMW_BASE_URL=http://localhost:8082/seo -v "$PWD":/var/www/app -v "$PWD/tests/docker/apache-sub.conf":/etc/apache2/sites-enabled/000-default.conf rmw-apache sh -c 'ln -sfn /var/www/app/public /var/www/html/seo && apache2-foreground' >/dev/null
  sleep 3; echo "root: http://localhost:8081/   sub: http://localhost:8082/seo/   fixture: http://rmw-fixture/ (from inside the network)" ;;
down) docker rm -f rmw-fixture rmw-apache-root rmw-apache-sub >/dev/null 2>&1; echo down ;;
esac
