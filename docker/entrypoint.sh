#!/usr/bin/env bash
# DIAS ERP container entrypoint.
#
#   1. Pastikan subdir storage ada (named volume bisa kosong saat pertama dibuat).
#      SESSION_DRIVER=file & CACHE_STORE=file (aturan anti-tabrakan CLAUDE.md),
#      storage/app/mpdf = tempDir mpdf (PdfReport).
#   2. Bangun cache config + event + route + view via `artisan optimize` - gabungan
#      4 cache dalam 1 call (lihat laravel.com/docs/12.x/deployment). HARUS saat
#      runtime krn env baru tersedia di sini (dari env_file docker-compose; TIDAK
#      ada file .env di image). Cache gagal = image rusak = container harus dianggap
#      unhealthy supaya rollback otomatis (TANPA `|| echo` non-fatal).
#   3. chown SETELAH cache dibangun: artisan jalan sbg root, file hasilnya harus
#      bisa ditulis php-fpm (www-data).
#   4. Exec CMD (default: supervisord).

set -euo pipefail

cd /var/www/html

mkdir -p \
    storage/app/mpdf \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

php artisan optimize
php artisan storage:link 2>/dev/null || true

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

echo "entrypoint: ready"

exec "$@"
