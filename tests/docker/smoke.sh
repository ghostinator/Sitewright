#!/bin/sh
# Starts MariaDB + Apache (root :8081, sub-directory /seo/ via Alias :8082, FileInfo-only host :8083) + a fixture site.
# Usage: tests/docker/smoke.sh up | down
cd "$(dirname "$0")/../.." || exit 1
NET=swnet
case "$1" in
up)
  docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
  docker ps --format '{{.Names}}' | grep -qx sw-db || docker run -d --name sw-db --network $NET -e MARIADB_ROOT_PASSWORD=test-root-pw mariadb:10.11 >/dev/null
  docker image inspect sw-apache >/dev/null 2>&1 || docker build -q -t sw-apache -f tests/docker/Dockerfile.apache tests/docker >/dev/null
  docker image inspect sw-php:8.3 >/dev/null 2>&1 || docker build -q -t sw-php:8.3 --build-arg PHP_VERSION=8.3 tests/docker >/dev/null
  for i in $(seq 1 40); do docker exec sw-db mariadb -uroot -ptest-root-pw -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
  docker run --rm --network $NET -v "$PWD":/app -w /app sw-php:8.3 php -r '
    require "core/autoload.php"; use Sitewright\Core\Installer;
    Installer::run(["create_db"=>true,"config_path"=>"/tmp/c.php","lock_path"=>"/tmp/l","db"=>["host"=>"sw-db","port"=>"3306","name"=>"sw_smoke","user"=>"root","pass"=>"test-root-pw","prefix"=>"sw_"],
      "admin"=>["username"=>"admin","email"=>"admin@example.test","password"=>"smoke-test-passphrase"]]); echo "installed\n";'
  docker rm -f sw-fixture sw-apache-root sw-apache-sub sw-apache-fi >/dev/null 2>&1
  docker run -d --name sw-fixture --network $NET -v "$PWD/tests/fixtures/site":/usr/share/nginx/html:ro nginx:alpine >/dev/null
  ENVS="-e SW_DB_HOST=sw-db -e SW_DB_NAME=sw_smoke -e SW_DB_USER=root -e SW_DB_PASS=test-root-pw -e SW_ALLOWED_HOSTS=localhost -e SW_FETCH_DEV_ALLOW_CIDRS=172.16.0.0/12,192.168.0.0/16,10.0.0.0/8 -e SW_CONTACT=https://example.test/contact"
  docker run -d --name sw-apache-root --network $NET -p 8081:80 $ENVS -v "$PWD":/var/www/app -v "$PWD/tests/docker/apache-root.conf":/etc/apache2/sites-enabled/000-default.conf sw-apache >/dev/null
  docker run -d --name sw-apache-sub --network $NET -p 8082:80 $ENVS -e SW_BASE_URL=http://localhost:8082/seo -v "$PWD":/var/www/app -v "$PWD/tests/docker/apache-sub.conf":/etc/apache2/sites-enabled/000-default.conf sw-apache sh -c 'ln -sfn /var/www/app/public /var/www/html/seo && apache2-foreground' >/dev/null
  docker run -d --name sw-apache-fi --network $NET -p 8083:80 $ENVS -v "$PWD":/var/www/app -v "$PWD/tests/docker/apache-fileinfo.conf":/etc/apache2/sites-enabled/000-default.conf sw-apache sh -c 'sed -ri "s!AllowOverride All!AllowOverride None!g" /etc/apache2/apache2.conf; apache2-foreground' >/dev/null
  sleep 3; echo "root: http://localhost:8081/   sub: http://localhost:8082/seo/   fixture: http://sw-fixture/ (from inside the network)" ;;
down) docker rm -f sw-fixture sw-apache-root sw-apache-sub sw-apache-fi >/dev/null 2>&1; echo down ;;
esac
