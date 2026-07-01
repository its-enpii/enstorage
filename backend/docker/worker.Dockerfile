# syntax=docker/dockerfile:1.6
FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        bash \
        libpq-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        $PHPIZE_DEPS \
        autoconf g++ make linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pgsql pdo_pgsql intl zip bcmath pcntl gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make $PHPIZE_DEPS \
    && rm -rf /tmp/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Install supervisord for running queue worker
RUN apk add --no-cache supervisor

WORKDIR /var/www/html

# ── Step 1: install vendor from composer.lock only (cache-friendly).
# Same pattern as backend/Dockerfile — install deps from lockfile without
# running post-install scripts, then refresh the autoloader against the
# actual source tree in step 3. Without this step the worker container
# has no /var/www/html/vendor/autoload.php and `php artisan` exits 255
# on every restart, which is what was happening before this fix.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
    && ls -la vendor/autoload.php

# ── Step 2: copy the rest of the application source.
# Note: .dockerignore excludes `vendor` from the build context, but we
# already installed it in step 1 via composer, so this COPY does not
# need (and should not) re-include it.
COPY . .

# ── Step 3: refresh the autoloader so the classmap covers all app
# classes (service providers, jobs, etc.) that may have been added
# after composer.lock was generated.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && ls -la vendor/autoload.php

# Writable runtime dirs for Laravel (queue worker writes logs, cache,
# UploadFileJob writes thumbnails to storage/app/temp, etc.).
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/temp \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 9001

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
