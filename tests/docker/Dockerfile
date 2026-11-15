# Test image: official PHP CLI + pdo_mysql. Build args: PHP_VERSION (8.1 proves the minimum supported version).
ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli
RUN docker-php-ext-install pdo_mysql >/dev/null
