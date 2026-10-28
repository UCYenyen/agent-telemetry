# syntax=docker/dockerfile:1

# PHP dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# React / Vite build
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# Runtime
FROM php:8.4-cli

# tmux: drives the agent sessions on the host's tmux server (socket mounted in)
# procps: `ps` for discovering host agent processes (needs pid: host)
# util-linux: nsenter, used by the agy wrapper to run Antigravity on the host
RUN echo 'Acquire::Retries "10";' > /etc/apt/apt.conf.d/80retries \
    && apt-get update \
    && apt-get install -y --no-install-recommends tmux procps util-linux libpq-dev \
    && docker-php-ext-install pdo_pgsql pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/agy /usr/local/bin/agy

# The committed package caches list dev packages that are not installed here
RUN chmod +x /usr/local/bin/agy \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

EXPOSE 3116
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=3116"]
