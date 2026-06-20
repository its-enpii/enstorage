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

# Install composer deps first (cache layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    || composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copy rest of app
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Custom php.ini (upload limits for 1GB files)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000

CMD ["php-fpm", "-F"]
