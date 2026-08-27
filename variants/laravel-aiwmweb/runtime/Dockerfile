# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS assets
WORKDIR /build
COPY backend/package*.json ./
RUN if [ -f package-lock.json ]; then \
        npm ci --no-audit --no-fund; \
    else \
        npm install --ignore-scripts --no-audit --no-fund; \
    fi
COPY backend/ ./
ARG VITE_API_BASE_URL=http://localhost:8080
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
RUN npm run build

FROM php:8.4.24-fpm-trixie AS app
ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring pcntl pdo_mysql opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/ ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY --from=assets /build/public/build ./public/build
COPY runtime/php/production.ini /usr/local/etc/php/conf.d/99-aiwmweb-production.ini
COPY runtime/scripts/php-entrypoint.sh /usr/local/bin/aiwmweb-entrypoint
RUN chmod 0755 /usr/local/bin/aiwmweb-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["aiwmweb-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.31.4-alpine3.24 AS web
COPY backend/public /var/www/html/public
COPY --from=assets /build/public/build /var/www/html/public/build
COPY runtime/nginx/default.conf /etc/nginx/conf.d/default.conf
