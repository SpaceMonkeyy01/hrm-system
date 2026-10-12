# HRM System for Render (or any Docker host). See docs/DEPLOY-Render.md.
#
#   1. assets   builds the React front end (public/build)
#   2. vendor   installs PHP packages without dev tools
#   3. app      serversideup/php: nginx + PHP-FPM, runs migrations and caches config on every start
#
# Nothing private goes into the image: .dockerignore keeps out .env, the SQLite file, uploads and logs.

FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM serversideup/php:8.3-fpm-nginx AS app
USER root
RUN install-php-extensions intl pdo_pgsql gd
USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
# Folders Laravel writes to; .dockerignore leaves out their contents, so make sure they exist.
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache

# serversideup "Laravel automations": migrate --force, storage:link, config/route/view/event caches on start.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=12M \
    PHP_POST_MAX_SIZE=14M \
    SSL_MODE=off

# The image listens on 8080; render.yaml tells Render the same.
EXPOSE 8080
