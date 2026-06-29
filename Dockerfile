FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm ci && npm run build

FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        rsync \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/php/entrypoint.sh /usr/local/bin/bangdeliv-entrypoint
COPY docker/php/php.ini /usr/local/etc/php/conf.d/bangdeliv.ini

RUN mkdir -p \
        /opt/bangdeliv-seed-public \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && (composer install \
            --no-dev \
            --no-interaction \
            --no-progress \
            --prefer-dist \
            --optimize-autoloader \
        || composer install \
            --no-dev \
            --no-interaction \
            --no-progress \
            --prefer-source \
            --optimize-autoloader) \
    && if [ -d storage/app/public ]; then cp -a storage/app/public/. /opt/bangdeliv-seed-public/; fi \
    && chmod +x /usr/local/bin/bangdeliv-entrypoint \
    && chown -R www-data:www-data bootstrap/cache storage

ENTRYPOINT ["bangdeliv-entrypoint"]
CMD ["php-fpm"]
