FROM php:8.4-fpm-bookworm AS base

ARG WWWUSER=1000
ARG WWWGROUP=1000

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        chromium \
        curl \
        ffmpeg \
        fontconfig \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        nodejs \
        npm \
        unzip \
        wget \
        zip \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && rm -rf /var/lib/apt/lists/*

# ── Tier 2: Liberation fonts (metric-compatible open alternatives for Arial,
#    Times New Roman, Courier New) — available in Debian repos, no EULA download
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        fonts-liberation \
        fonts-urw-base35 \
    && rm -rf /var/lib/apt/lists/*

# ── Tier 1: Google Fonts (apt-available subset) ───────────────────────────────
# fonts-lato, fonts-open-sans available in Debian bookworm.
# Remaining Google Fonts should be bundled via a pre-built font archive stored
# in B2/Docker assets and extracted here in production:
#   ADD fonts-framecast.tar.gz /usr/local/share/fonts/framecast/
# See VISUAL_EDGE_PLAN.md → Font Library → Docker Bundling Strategy.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        fonts-lato \
        fonts-open-sans \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /usr/local/share/fonts/framecast \
    && fc-cache -f -v

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

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

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

RUN npm install --omit=dev --no-audit --no-fund

RUN if [ -d /var/www/html/resources/fonts ]; then \
        cp -R /var/www/html/resources/fonts/. /usr/local/share/fonts/framecast/; \
    fi \
    && fc-cache -f -v

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && chown -R framecast:framecast /var/www/html

USER framecast

CMD ["php-fpm"]
