# syntax=docker/dockerfile:1.6
FROM php:8.4-cli-alpine

# System deps (subset dari worker — reverb tidak butuh libpng/freetype/etc.)
# Yang dibutuhkan: pgsql + redis ext + composer + bash untuk ENTRYPOINT.
RUN apk add --no-cache \
        bash \
        libpq-dev \
        $PHPIZE_DEPS \
        autoconf g++ make linux-headers \
    && docker-php-ext-install pdo pgsql pdo_pgsql bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make $PHPIZE_DEPS \
    && rm -rf /tmp/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Step 1: install vendor dari composer.lock (cache-friendly).
# Pattern sama dengan backend/Dockerfile dan docker/worker.Dockerfile —
# install dari lockfile tanpa scripts, refresh autoloader di step 3
# terhadap source tree aktual. Tanpa step ini vendor/autoload.php gak ada
# dan `php artisan reverb:start` exit 255.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
    && ls -la vendor/autoload.php

# ── Step 2: copy source.
COPY . .

# ── Step 3: refresh autoloader agar classmap cover semua app classes.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && ls -la vendor/autoload.php

# Writable dirs untuk Laravel runtime (config cache, log).
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

# reverb:start binds ke REVERB_HOST:REVERB_PORT (di-set via .env host yang
# di-mount ke /var/www/html/.env:ro). Default Reverb = localhost:8080.
# Container port 8080; docker-compose maps host 8083 → container 8080.
CMD ["php", "artisan", "reverb:start", "--no-interaction"]
