# Livewire dev/test/benchmark image.
#   docker build -f docker/Dockerfile --build-arg PHP_VERSION=8.4 -t livewire-dev .
# Targets: `base` (unit tests, benchmarks, composer, node) and `browser` (adds Chromium for Dusk).
ARG PHP_VERSION=8.4
FROM composer:2 AS composer
FROM node:22-bookworm-slim AS node

FROM php:${PHP_VERSION}-cli-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates libzip-dev libicu-dev libonig-dev libsqlite3-dev libxml2-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip pdo_sqlite mbstring pcntl gd \
    && rm -rf /var/lib/apt/lists/*

# Node 22 + npm from the official node image.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Benchmarks need stable numbers: no memory limit, opcache on for CLI is opt-in via env.
RUN { echo "memory_limit=-1"; echo "opcache.enable_cli=\${OPCACHE_CLI:-0}"; } > /usr/local/etc/php/conf.d/livewire.ini \
    && docker-php-ext-enable opcache 2>/dev/null || true

# Run as the host user so vendor/, node_modules/ and .phpunit.cache stay owned by you.
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} dev 2>/dev/null || true \
    && useradd -m -u ${UID} -g ${GID} dev 2>/dev/null || true
ENV COMPOSER_HOME=/home/dev/.composer COMPOSER_ALLOW_SUPERUSER=0
USER ${UID}:${GID}
WORKDIR /app

FROM base AS browser
USER root
RUN apt-get update && apt-get install -y --no-install-recommends chromium chromium-driver \
    && rm -rf /var/lib/apt/lists/*
ENV CHROME_BIN=/usr/bin/chromium
USER ${UID}:${GID}

# Profiling: xdebug (pinned, built from the official PECL channel) is installed but OFF unless XDEBUG_MODE is set.
FROM base AS profile
USER root
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install xdebug-3.4.1 excimer-1.2.6 \
    && docker-php-ext-enable xdebug excimer \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && { echo "xdebug.mode=\${XDEBUG_MODE:-off}"; echo "xdebug.output_dir=/app/perf/results/xdebug"; echo "xdebug.start_with_request=yes"; echo "xdebug.profiler_output_name=cachegrind.out.%p"; } > /usr/local/etc/php/conf.d/zz-xdebug-livewire.ini
USER ${UID}:${GID}
