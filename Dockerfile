# syntax=docker/dockerfile:1.7
#
# DIAS ERP (Laravel 12) — single-container image: nginx + php-fpm (supervisord).
# Tidak butuh Node/Vite: config/adminlte.php sudah laravel_asset_bundling=>false &
# mode 'local' dgn cdn_fallback, layout pakai public/vendor/adminlte (committed) +
# CDN Bootstrap/FontAwesome, JS di public/js/dias-helpers.js.
#
# Traefik (external) di depan container -> port 80 internal, tidak expose host.

# ---------- Stage 1: composer install ----------
FROM composer:2 AS vendor

WORKDIR /app

# Copy ONLY composer manifests dulu -> cache layer ini kalau deps tidak berubah.
COPY composer.json composer.lock ./

# Stage ini cuma resolve & unduh paket; ekstensi PHP yang sebenarnya ada di stage
# final, jadi cek platform dimatikan. --no-scripts: artisan belum ada di sini.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --ignore-platform-reqs

# ---------- Stage 2: runtime ----------
FROM php:8.2-fpm-alpine

# Library RUNTIME dipasang eksplisit (dipakai gd/intl/zip/mbstring saat jalan).
# Paket -dev + $PHPIZE_DEPS dipasang sbg virtual .build-deps dan dihapus di RUN
# yang SAMA - kalau cuma -dev yang terpasang lalu di-del, library runtime-nya ikut
# terhapus dan ekstensi gagal load ("Error loading shared library").
#   pdo_mysql -> MariaDB (DB produksi bersama), gd -> mpdf, zip/intl/bcmath/mbstring
#   -> Laravel & dependensi, opcache -> performa.
RUN apk add --no-cache \
        bash \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        nginx \
        oniguruma \
        supervisor \
        tzdata \
 && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        intl \
        bcmath \
        opcache \
 && apk del .build-deps

COPY --from=vendor /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Konfigurasi runtime.
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-dias.ini
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord/conf.d/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy source code.
COPY --chown=www-data:www-data . /var/www/html

# Copy vendor dari stage 1.
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor

# Generate autoloader (classmap teroptimasi) + jalankan post-autoload-dump
# (package:discover -> bootstrap/cache/packages.php). Tidak butuh .env.
# Sengaja TANPA --classmap-authoritative.
WORKDIR /var/www/html
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord/conf.d/supervisord.conf"]