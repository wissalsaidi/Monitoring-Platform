# Image PHP-FPM de l'application Laravel
FROM php:8.3-fpm-alpine

# mysql-client (mariadb-dump, mariadb) + plugin caching_sha2_password de MySQL 8 : sauvegardes (SCRUM-62)
RUN apk add --no-cache icu-libs libzip mysql-client mariadb-connector-c \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install pdo_mysql intl zip bcmath opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
