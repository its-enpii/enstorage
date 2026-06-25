# syntax=docker/dockerfile:1.6
FROM php:8.4-fpm-alpine AS base

# System deps
RUN apk add --no-cache \
        bash \
        git \
        curl \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        icu-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        autoconf \
        g++ \
        make \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pgsql pdo_pgsql intl zip bcmath pcntl gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make $PHPIZE_DEPS \
    && rm -rf /tmp/*

# Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Step 1: install vendor from composer.lock only (cache-friendly).
# We DO use `--no-scripts` here (skips post-install scripts that may
# need a writable filesystem), but we explicitly generate the
# autoloader at the end so `vendor/autoload.php` exists for `artisan`.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
    && ls -la vendor/autoload.php

# ── Step 2: copy the rest of the application source.
COPY . .

# ── Step 3: refresh the autoloader against the actual source tree.
# `composer dump-autoload` is idempotent — it just regenerates the
# autoload files from `composer.json`'s `autoload` map. Required because
# Laravel registers new classes at runtime via service providers and
# the optimized classmap from step 1 may be stale.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && ls -la vendor/autoload.php

# Writable dirs for Laravel runtime
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Custom php.ini (upload limits for 1GB files)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000

CMD ["php-fpm", "-F"]