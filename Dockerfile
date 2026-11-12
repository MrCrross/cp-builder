# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

# ---------------------------------------------------------------------------
# PHP base: extensions shared by composer / wayfinder generation stages
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-trixie AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# curl, mbstring, xml, opcache уже встроены в официальный образ php:8.5-trixie
RUN docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pdo_pgsql \
        pcntl \
        zip

# ---------------------------------------------------------------------------
# Composer stage: production PHP dependencies + wayfinder-генерация
# (vite-плагин wayfinder вызывает `php artisan wayfinder:generate` на build)
# ---------------------------------------------------------------------------
FROM php-base AS composer

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan wayfinder:generate --with-form --no-interaction

# ---------------------------------------------------------------------------
# Dev-vite stage: php-cli + node (wayfinder-плагин вызывает `php artisan`
# во время build/dev, поэтому node-стейджу нужен PHP)
# ---------------------------------------------------------------------------
FROM composer AS dev-vite

COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# ---------------------------------------------------------------------------
# Frontend build stage: npm production build (assets baked into runtime image)
# ---------------------------------------------------------------------------
FROM dev-vite AS frontend

RUN npm ci --no-audit --no-fund
RUN npm run build

# ---------------------------------------------------------------------------
# Runtime: PHP-FPM (production, no Node, no dev Composer dependencies)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libjpeg-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Re-encode загрузок (§9.4) требует JPEG/WebP в GD, не только PNG.
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        pdo_pgsql \
        pcntl \
        zip

WORKDIR /var/www/html

# Application code (vendor и node_modules исключены через .dockerignore)
COPY . .

# Production dependencies from the composer stage
COPY --from=composer /var/www/html/vendor ./vendor

# Compiled frontend assets from the frontend stage
COPY --from=frontend /var/www/html/public/build ./public/build

# Runtime directories (storage живёт на named volume; см. compose.yaml)
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# XML/YML-импорт (план §13.1): выгрузки до 512 МБ (YML-эталон — 197 МБ).
# Разбор потоковый — memory_limit не трогаем.
RUN echo 'upload_max_filesize=512M\npost_max_size=528M' \
    > /usr/local/etc/php/conf.d/cp-builder-uploads.ini

# Symlink for serving public uploads (storage/app/public) via Caddy
RUN php artisan storage:link --no-interaction || true

# Package manifest для read-only runtime (bootstrap/cache маскируется tmpfs,
# поэтому manifest готовим на этапе сборки; содержимое env-независимо)
RUN php artisan package:discover --no-interaction

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
