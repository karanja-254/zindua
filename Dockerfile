# WitnessVault — Laravel 12 backend container for Render
# Multi-stage: Composer vendor, Vite frontend assets, then PHP-FPM + Nginx.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

FROM node:20-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
RUN mkdir -p public \
    && npm ci \
    && npm run build

FROM php:8.3-fpm-alpine AS app

# System deps + PHP extensions required by Laravel + AWS/R2 + Redis.
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        ffmpeg \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite \
        sqlite-dev \
        postgresql-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        pdo_sqlite \
        zip \
        gd \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

# Application source + pre-resolved vendor directory.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=node_builder /app/public/build /var/www/html/public/build

# Regenerate the optimized autoloader now that the full source tree is present.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/html/public/build \
    && chmod -R 775 storage bootstrap/cache \
    && chmod -R 755 /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
