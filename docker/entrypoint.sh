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
#   3. Auto migrate + db:seed kondisional (env RUN_MIGRATIONS / RUN_SEED, default
#      false). migrate: tunggu KONEKSI DB siap (maks ~60 dtk, MariaDB di
#      `mariadb-net` bisa lambat saat boot server; tidak siap = exit 1), lalu
#      `migrate --force`. SENGAJA tanpa `--isolated`: cuma 1 container, dan lock
#      basi (container di-SIGKILL di tengah migrate) bikin migrate berikutnya
#      di-skip diam-diam dgn exit 0 selama ~1 jam.
#      db:seed polos (DatabaseSeeder: MenuSeeder + AdminAuthSeeder, KEDUANYA
#      idempoten - MenuSeeder by segment_key, AdminAuthSeeder skip kalau baris
#      lv_user_auth sudah ada). Gagal = exit != 0 = container gagal start.
#   4. chown SETELAH cache & migrate: artisan jalan sbg root, file hasilnya harus
#      bisa ditulis php-fpm (www-data).
#   5. Exec CMD (default: supervisord).

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

# --- Auto migrate / seed saat container start -----------------------------
# Default false supaya image aman dipakai tanpa flag. Set true di .env deploy
# (lihat .env.docker.example) kalau ingin migrate+seed otomatis tiap container
# start. Keduanya idempoten: migrate kedua = no-op, MenuSeeder updateOrCreate by
# segment_key, AdminAuthSeeder skip kalau password admin sudah ada (lihat
# database/seeders/AdminAuthSeeder.php docblock).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "entrypoint: menunggu DB siap (maks ~60 dtk)..."
    # Cek KONEKSI saja (getPdo), bukan `migrate:status` - yg terakhir juga gagal
    # kalau tabel lv_migrations belum ada (deploy pertama), jadi akan menunggu
    # 60 dtk percuma. Loop 30x @ 2 dtk; reboot server bareng MariaDB bisa butuh ~itu.
    db_ready=0
    for _ in $(seq 1 30); do
        if php artisan tinker --execute='DB::connection()->getPdo();' >/dev/null 2>&1; then
            db_ready=1
            break
        fi
        sleep 2
    done
    if [ "$db_ready" -ne 1 ]; then
        echo "entrypoint: DB tidak siap setelah 60 dtk (cek DB_HOST/kredensial/mariadb-net)" >&2
        exit 1
    fi
    echo "entrypoint: php artisan migrate --force"
    php artisan migrate --force
fi

if [ "${RUN_SEED:-false}" = "true" ]; then
    echo "entrypoint: php artisan db:seed --force"
    php artisan db:seed --force
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

echo "entrypoint: ready"

exec "$@"
