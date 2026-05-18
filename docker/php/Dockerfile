FROM php:8.4-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    intl \
    zip \
    opcache \
    dom \
    xml \
    mbstring \
    curl

RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS dev

RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

FROM base AS prod

COPY docker/php/conf.d/opcache.prod.ini /usr/local/etc/php/conf.d/99-opcache.ini

COPY composer.json symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data
