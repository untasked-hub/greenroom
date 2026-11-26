# syntax=docker/dockerfile:1
# Multi-stage Laravel 13 + Vue/Inertia image for Untasked (Fly ~512MB).
# Place vendor-dev and test first so legacy docker build --target test skips vendor & node/Vite entirely.
FROM composer:2 AS vendor-dev
WORKDIR /app
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs \
  || composer update --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage \
  && composer dump-autoload --optimize --no-interaction

# Test stage: Pest + pcov. Platform builds --target test during release_create.
FROM php:8.4-cli-alpine AS test
RUN apk add --no-cache postgresql-libs icu-libs libzip sqlite-libs \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev sqlite-dev \
  && docker-php-ext-install -j$(nproc) pdo_pgsql pdo_sqlite intl zip \
  && pecl install pcov \
  && docker-php-ext-enable pcov \
  && apk del .build-deps
WORKDIR /app
COPY --from=vendor-dev /app /app
# Minimal Vite manifest so Inertia/Blade views render during Pest tests without building Node assets in --target test.
RUN mkdir -p public/build storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && printf '{"resources/css/app.css":{"file":"assets/app.css","src":"resources/css/app.css","isEntry":true},"resources/js/app.ts":{"file":"assets/app.js","src":"resources/js/app.ts","isEntry":true}}' > public/build/manifest.json \
  && chmod -R 777 storage bootstrap/cache \
  && touch .env
ENV APP_ENV=testing \
    APP_KEY=base64:dW50YXNrZWQtcGhwdW5pdC10ZXN0LWtleS14eHh4eHg= \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory:
CMD ["php", "-d", "pcov.enabled=1", "vendor/bin/pest", "--colors=never", "--coverage", "--min=80", "--coverage-text"]

FROM composer:2 AS vendor
WORKDIR /app
# Writable dirs required before any artisan / package:discover scripts.
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs \
  || composer update --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage \
  && composer dump-autoload --optimize --no-interaction

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --prefer-offline || npm install
COPY . .
COPY --from=vendor /app/vendor ./vendor
# Fail the image build when Vite fails — an empty manifest ships a blank browser shell.
RUN npm run build

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-libs icu-libs libzip wget \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
  && docker-php-ext-install -j$(nproc) pdo_pgsql intl zip opcache \
  && apk del .build-deps
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
  && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && chmod -R 777 storage bootstrap/cache
# Decks and recordings are uploaded through PHP; the default 2M limit rejects them.
RUN printf 'upload_max_filesize=256M\npost_max_size=260M\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/greenroom-uploads.ini
ENV APP_ENV=production \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=5 CMD wget -qO- http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/entrypoint.sh"]
