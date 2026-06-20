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

COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 9001

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
