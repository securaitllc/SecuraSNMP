# ---- PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Ignore platform reqs here: the composer image lacks ext-gd/ext-zip that
# phpspreadsheet declares; the runtime stage installs them.
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- Frontend assets ----
FROM node:22 AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts
COPY . .
# icons.css is generated (gitignored) and normally produced by the postinstall
# script, which --ignore-scripts skips. Generate it explicitly before the build
# so a clean checkout (CI) has it — otherwise vite can't resolve ./icons.css.
RUN npm run build:icons && npm run build

# ---- Runtime ----
# php-fpm (not -cli) so the web tier is a concurrent worker pool behind nginx —
# the previous `php artisan serve` was single-threaded and serialized every
# concurrent request. The fpm image still ships the `php` CLI the pollers use.
FROM php:8.3-fpm
RUN apt-get update && apt-get install -y --no-install-recommends iputils-ping traceroute snmp snmptrapd \
    nginx libzip-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/*
# pdo_mysql for the DB; zip + gd are required by phpspreadsheet (xlsx export).
RUN docker-php-ext-install pdo_mysql zip gd
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/snmptrapd.conf /etc/snmp/snmptrapd.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-nodus.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh docker/trap-handler.sh
EXPOSE 8000
EXPOSE 162/udp
ENTRYPOINT ["/entrypoint.sh"]
