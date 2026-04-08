FROM php:8.4-fpm-bookworm AS base

ARG WWWUSER=1000
ARG WWWGROUP=1000

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${WWWGROUP} framecast \
    && useradd -u ${WWWUSER} -g framecast -ms /bin/bash framecast

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && chown -R framecast:framecast /var/www/html

USER framecast

CMD ["php-fpm"]
