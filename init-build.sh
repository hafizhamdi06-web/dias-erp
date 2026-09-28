#!/usr/bin/env bash
#
# init-build.sh — Deploy DIAS ERP di server (Docker Compose + Traefik).
#
# Dipanggil manual ATAU tiap 5 menit via cron (`--if-changed`). Idempotent. Aman
# dipanggil ulang.
#
# Prasyarat di server:
#   - docker + docker compose plugin
#   - network `traefik-net` dan `mariadb-net` SUDAH ADA (dibuat stack lain;
#     script TIDAK membuatnya). Cek: `docker network ls`
#   - File .env (template: .env.docker.example), diedit sesuai server (DB_HOST,
#     APP_KEY, dll). Kalau belum, script menyalin template dan exit 1.
#   - Repo adalah clone dari `origin` branch main. SSH alias `github-personal`
#     (atau yang dipakai `origin`) HARUS bisa dipakai user cron dengan deploy
#     key TANPA passphrase (ssh-agent tidak tersedia di cron). Override
#     lewat env DEPLOY_BRANCH kalau mau uji di branch lain.
#
# Usage:
#   ./init-build.sh                  # build + up + tunggu healthy (default TIDAK migrate)
#   ./init-build.sh --migrate        # + run `php artisan migrate --force` setelah healthy
#   ./init-build.sh --seed           # + run `php artisan db:seed --force` (DatabaseSeeder:
#                                    #   MenuSeeder + AdminAuthSeeder, KEDUANYA idempoten)
#   ./init-build.sh --rebuild        # `--no-cache` build (paksa rebuild semua layer)
#   ./init-build.sh --pull           # `docker compose pull` untuk base image terbaru
#   ./init-build.sh --if-changed     # MODE CRON: pull origin/main, skip kalau tidak berubah,
#                                    # kalau berubah -> build + up + tunggu healthy;
#                                    # gagal di titik mana pun -> rollback ke image revisi.
#   ./init-build.sh --help
#
# Crontab (tiap 5 menit, log di-append):
#   */5 * * * * /srv/dias-erp/init-build.sh --if-changed >> /var/log/dias-erp-deploy.log 2>&1
#
# Tentang --migrate/--seed manual:
#   Redundan kalau RUN_MIGRATIONS=true / RUN_SEED=true di .env - entrypoint.sh
#   sudah menjalankan migrate + db:seed otomatis tiap container start. Flag
#   manual ini untuk one-off (mis. deploy pertama tanpa flag di .env, atau
#   mau paksa migrate/seed tanpa rebuild image).
#
#   DB PRODUKSI shared dgn CI3/CI4 (aturan CLAUDE.md). Migration hanya membuat
#   tabel `lv_*` (prefix aman); tabel existing harus sudah ada di DB dan nama
#   service MariaDB harus benar sebelum dijalankan.
#
# Tentang rollback:
#   SEBELUM build, image :latest di-retag ke :rollback. Gagal di langkah
#   manapun (build / up / healthy) -> :rollback di-tag kembali ke :latest +
#   `compose up -d --no-build --force-recreate` + tunggu healthy. Pada mode
#   --if-changed, SHA commit yang gagal dicatat di `.deploy/failed-sha` dan
#   `git reset --hard` ke SHA lama supaya isi repo sinkron dgn image yang jalan;
#   cron berikutnya SKIP SHA itu sampai ada commit baru di main.
#   SHA yang sukses di-deploy dicatat di `.deploy/deployed-sha` - itulah yang
#   dibandingkan dgn origin/main (bukan HEAD), jadi deploy yang terputus di
#   tengah (reboot/OOM) otomatis dicoba ulang di cron berikutnya.

# --- Bungkus seluruh script dalam main() ---------------------------------
# WAJIB: `git pull` di mode --if-changed bisa mengubah file script ini saat
# sedang dieksekusi, dan bash membaca script bertahap -> bisa jalan setengah
# versi lama/setengah baru. Dengan main(), seluruh script diparse di awal
# dan `set -e`-nya seragam.
#
# PATH eksplisit karena PATH cron sangat minim.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

main() {
    set -euo pipefail

    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    cd "$SCRIPT_DIR"

    DO_MIGRATE=0
    DO_SEED=0
    DO_REBUILD=0
    DO_PULL=0
    IF_CHANGED=0
    # Diisi di mode --if-changed; kosong di mode manual. Diinisialisasi supaya
    # `set -u` tidak mematikan script saat rollback() dipanggil di mode manual.
    OLD=""
    NEW=""

    while [ $# -gt 0 ]; do
        case "$1" in
            --migrate)    DO_MIGRATE=1 ;;
            --seed)        DO_SEED=1 ;;
            --rebuild)     DO_REBUILD=1 ;;
            --pull)        DO_PULL=1 ;;
            --if-changed)  IF_CHANGED=1 ;;
            -h|--help)
                # Cetak blok komentar header (baris 2 s/d baris non-komentar pertama).
                awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
                exit 0
                ;;
            *)
                log "FATAL: unknown flag: $1"
                exit 64
                ;;
        esac
        shift
    done

    # Lock: build/up > 5 menit tidak ditumpuk dengan cron berikutnya. Di mode
    # --if-changed, yg kedua langsung keluar 0 (cron tidak spam). Di mode manual,
    # yg kedua gagal jelas (biar operator tahu).
    mkdir -p .deploy
    exec 9>.deploy/lock
    if ! flock -n 9; then
        if [ "$IF_CHANGED" -eq 1 ]; then
            exit 0
        fi
        log "FATAL: deploy lain sedang berjalan (lock aktif di .deploy/lock)."
        exit 1
    fi

    # --- 1. Pre-flight: docker, compose, file .env ----------------------
    command -v docker >/dev/null 2>&1 || {
        log "FATAL: docker tidak ditemukan di PATH."
        exit 1
    }

    if ! docker compose version >/dev/null 2>&1; then
        log "FATAL: docker compose (plugin v2) tidak ditemukan."
        log "       Install: https://docs.docker.com/compose/install/"
        exit 1
    fi

    if [ ! -f .env ]; then
        if [ -f .env.docker.example ]; then
            cp .env.docker.example .env
            log "=================================================================="
            log ".env belum ada -> disalin dari .env.docker.example."
            log "EDIT dulu: APP_KEY, DB_HOST (nama service MariaDB di mariadb-net),"
            log "TRAEFIK_HOST, DB_PASSWORD, dll. Lalu jalankan ulang script ini."
            log "=================================================================="
            exit 1
        else
            log "FATAL: .env TIDAK ADA dan .env.docker.example juga tidak ada."
            exit 1
        fi
    fi

    # --- 2. Pre-flight: external networks --------------------------------
    for NET in traefik-net mariadb-net; do
        if ! docker network inspect "$NET" >/dev/null 2>&1; then
            log "FATAL: network external '$NET' TIDAK ADA."
            log "       Network ini milik stack lain (Traefik / DB). Pastikan"
            log "       sudah dibuat di server, atau ganti nama di docker-compose.yaml"
            log "       sesuai kenyataan: docker network ls"
            exit 1
        fi
    done

    # Baca nilai dari .env TANPA `source` (.env boleh berisi ${...}, spasi, kutip).
    env_get() {
        local val
        val=$(grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- || true)
        val="${val%\"}"; val="${val#\"}"
        printf '%s' "$val"
    }

    PROJECT_NAME="$(env_get COMPOSE_PROJECT_NAME)"
    PROJECT_NAME="${PROJECT_NAME:-dias-erp}"
    TRAEFIK_HOST="$(env_get TRAEFIK_HOST)"
    if [ -z "$TRAEFIK_HOST" ]; then
        log "FATAL: TRAEFIK_HOST kosong di .env (dipakai label Traefik Host())."
        exit 1
    fi

    # --- APP_DEBUG wajib false di produksi (lihat laravel.com/docs/12.x/deployment).
    # APP_DEBUG=true di produksi = bocornya exception + path ke publik. Tolak deploy.
    APP_DEBUG_VAL="$(env_get APP_DEBUG)"
    if [ "$APP_DEBUG_VAL" = "true" ]; then
        log "FATAL: APP_DEBUG=true di .env. Wajib false di produksi."
        log "       Bahaya: exception detail + path server terekspos publik."
        exit 1
    fi

    DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"

    # --- 3. Generate APP_KEY kalau kosong --------------------------------
    if [ -z "$(env_get APP_KEY)" ]; then
        command -v openssl >/dev/null 2>&1 || {
            log "FATAL: APP_KEY kosong & openssl tidak ada. Isi APP_KEY manual."
            exit 1
        }
        GENERATED_KEY="base64:$(openssl rand -base64 32)"
        if grep -qE '^APP_KEY=' .env; then
            # perl -pi: portable GNU/BSD. Key base64 bisa mengandung '/' dan '+'
            # -> dilewatkan via env, bukan disisipkan ke regex.
            NEW_KEY="$GENERATED_KEY" perl -pi -e 's/^APP_KEY=.*/APP_KEY=$ENV{NEW_KEY}/' .env
        else
            printf '\nAPP_KEY=%s\n' "$GENERATED_KEY" >> .env
        fi
        log "APP_KEY di-generate & ditulis ke .env."
    fi

    # --- 4. Mode --if-changed: fetch + compare + skip ---------------------
    if [ "$IF_CHANGED" -eq 1 ]; then
        if ! command -v git >/dev/null 2>&1; then
            log "FATAL: --if-changed butuh git di PATH."
            exit 1
        fi

        git fetch --quiet "origin" "$DEPLOY_BRANCH"

        # Pembanding = SHA yang TERAKHIR SUKSES di-deploy (.deploy/deployed-sha),
        # BUKAN HEAD. Merge terjadi sebelum build, jadi kalau script mati di
        # tengah (reboot, OOM saat build) HEAD sudah maju tapi container masih
        # versi lama - membandingkan dgn HEAD akan skip commit itu selamanya.
        # File belum ada (deploy pertama via cron) -> anggap HEAD yang jalan.
        OLD="$(cat .deploy/deployed-sha 2>/dev/null || true)"
        OLD="${OLD:-$(git rev-parse HEAD)}"
        NEW="$(git rev-parse "origin/$DEPLOY_BRANCH")"

        # Mode skip SENGAJA diam (tidak log) - cron jalan 288x/hari.
        if [ "$OLD" = "$NEW" ]; then
            exit 0
        fi

        # Sudah pernah gagal di SHA ini? Tunggu commit baru saja (diam juga).
        FAILED_SHA="$(cat .deploy/failed-sha 2>/dev/null || true)"
        if [ -n "$FAILED_SHA" ] && [ "$NEW" = "$FAILED_SHA" ]; then
            exit 0
        fi

        log "ada commit baru $OLD..$NEW, merge..."
        if ! git merge --ff-only "origin/$DEPLOY_BRANCH"; then
            log "FATAL: ada perubahan lokal di server (merge --ff-only gagal)."
            log "       Server cabang lokal tidak boleh divergen dari origin/$DEPLOY_BRANCH."
            exit 1
        fi

        # Peringatan migrasi (DB shared, migrasi TIDAK otomatis).
        MIGR_CHANGED="$(git diff --name-only "$OLD" "$NEW" -- database/migrations || true)"
        if [ -n "$MIGR_CHANGED" ]; then
            log "PERINGATAN: ada perubahan di database/migrations antara $OLD dan $NEW:"
            printf '  %s\n' $MIGR_CHANGED
            log "DB shared dengan CI3/CI4 - jalankan manual:"
            log "  ./init-build.sh --migrate"
            # Tidak exit - perubahan kode lain di PR yang sama tetap berguna,
            # operator bisa migrate nanti. Hanya log.
        fi
    fi

    # --- 5. Pull image non-build (optional) ------------------------------
    # hafizhamdi/dias-erp:latest dibangun lokal (tidak ada di registry) -> --ignore-buildable.
    # Base image PHP tetap ditarik terbaru oleh `build --pull` di bawah.
    if [ "$DO_PULL" -eq 1 ]; then
        log "docker compose pull --ignore-buildable"
        docker compose pull --ignore-buildable
    fi

    # --- 6. Tag image :latest yang sedang jalan jadi :rollback ----------
    # Kalau :latest sudah ada dan container sebelumnya sehat, :rollback = versi
    # terakhir yang terbukti jalan. Deploy pertama (belum ada :latest) -> :rollback
    # tidak ada, rollback() cukup log FATAL (lihat di bawah).
    if docker image inspect "hafizhamdi/dias-erp:latest" >/dev/null 2>&1; then
        # Hapus :rollback dari run sebelumnya kalau masih ada (deploy sukses
        # sebelumnya membersihkannya, tapi defensive).
        docker image rm "hafizhamdi/dias-erp:rollback" >/dev/null 2>&1 || true
        docker tag "hafizhamdi/dias-erp:latest" "hafizhamdi/dias-erp:rollback"
        log ":latest di-tag ke :rollback (jagaan)."
    else
        log "PERINGATAN: :latest belum ada (deploy pertama?). Tidak ada rollback image."
    fi

    # --- 7. Build ---------------------------------------------------------
    BUILD_FLAGS=(--pull)
    if [ "$DO_REBUILD" -eq 1 ]; then
        BUILD_FLAGS+=(--no-cache)
    fi

    log "docker compose build ${BUILD_FLAGS[*]}"
    if ! docker compose build "${BUILD_FLAGS[@]}"; then
        rollback "build gagal" "$OLD"
    fi

    # --- 8. Up -----------------------------------------------------------
    log "docker compose up -d"
    if ! docker compose up -d; then
        rollback "up gagal" "$OLD"
    fi

    # --- 9. Tunggu HEALTHY (gantikan logika 'running' lama) --------------
    log "menunggu container HEALTHY..."
    if ! wait_healthy; then
        log "FATAL: container tidak healthy dalam 120s."
        log "       docker compose logs --tail=50 app:"
        docker compose logs --tail=50 app || true
        rollback "healthcheck gagal" "$OLD"
    fi

    # --- 10. Optional: migrate & seed (HANYA setelah healthy) ------------
    if [ "$DO_MIGRATE" -eq 1 ]; then
        log "php artisan migrate --force"
        docker compose exec -T app php artisan migrate --force
    fi

    if [ "$DO_SEED" -eq 1 ]; then
        log "php artisan db:seed --force"
        # db:seed polos = DatabaseSeeder (MenuSeeder + AdminAuthSeeder, KEDUANYA
        # idempoten - lihat docker/entrypoint.sh RUN_SEED). Konsisten dgn alur
        # auto-seed di container start.
        docker compose exec -T app php artisan db:seed --force
    fi

    # --- 11. Bersihkan :rollback + dangling layers -----------------------
    # Sukses, jadi :rollback sudah tidak perlu.
    docker image rm "hafizhamdi/dias-erp:rollback" >/dev/null 2>&1 || true
    docker image prune -f >/dev/null 2>&1 || true

    # --- 12. Catat SHA yang sukses di-deploy + hapus failed-sha ----------
    # Ditulis di mode manual JUGA, supaya cron berikutnya tahu versi yang jalan.
    if command -v git >/dev/null 2>&1 && git rev-parse HEAD >/dev/null 2>&1; then
        git rev-parse HEAD > .deploy/deployed-sha
    fi
    rm -f .deploy/failed-sha

    # --- 13. Smoke check + banner -----------------------------------------
    log "smoke check: php artisan about"
    docker compose exec -T app php artisan about || true

    log "status container:"
    docker compose ps

    # Banner akhir + crontab example SELALU tampil di mode manual. Mode
    # --if-changed tidak pakai banner (cron skip tidak akan print apa-apa).
    if [ "$IF_CHANGED" -eq 0 ]; then
        cat <<EOF

==================================================================
 DEPLOY SELESAI
==================================================================
 Host       : https://${TRAEFIK_HOST}
 Container  : $(docker compose ps --format '{{.Name}}' app 2>/dev/null || echo '?') (port internal 80)
 Storage    : named volume 'storage' (SESSION_DRIVER/CACHE_STORE=file,
              storage/app/mpdf utk PdfReport)

 CRONTAB CONTOH (tiap 5 menit, log di-append):
   */5 * * * * ${SCRIPT_DIR}/init-build.sh --if-changed >> /var/log/dias-erp-deploy.log 2>&1

 TEST DARI SERVER:
   # ext PHP terpasang
   docker compose exec app php -m | grep -E 'gd|pdo_mysql|mbstring|zip|intl|bcmath|opcache'

   # koneksi MariaDB via mariadb-net
   docker compose exec app php artisan tinker --execute="DB::select('select 1'); print_r(DB::connection()->getDatabaseName());"

   # halaman login termuat (cek status HTTP)
   curl -sI "https://${TRAEFIK_HOST}/login" | head -n 1

   # header keamanan + sembunyi nya X-Powered-By (docs Laravel 12 deployment)
   curl -sI "https://${TRAEFIK_HOST}/login" | grep -iE 'X-Frame|X-Content|X-Powered'

   # storage/app/mpdf (tempDir PdfReport) bisa ditulis php-fpm
EOF
    fi

    if [ "$IF_CHANGED" -eq 1 ]; then
        log "deploy ${OLD:-?}..${NEW:-?} sukses."
    fi
}

# ---- helpers ----------------------------------------------------------

# Log dengan timestamp ISO. Semua output script lewat sini (cron + manual).
log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*"
}

# Tunggu container HEALTHY maks 120 detik. Loop setiap 2 detik. Kalau
# status 'unhealthy' SEBELUM 120s, langsung return 1 (gagal, bukan timeout).
# Return 0 kalau healthy, 1 kalau unhealthy/timeout.
wait_healthy() {
    local deadline=$((SECONDS + 120))
    local status="" cid=""
    while [ "$SECONDS" -lt "$deadline" ]; do
        # ID container diambil dari compose, JANGAN menebak nama: docker-compose.yaml
        # pakai `container_name: dias-erp`, bukan pola `<project>-app`. ID dibaca
        # ulang tiap iterasi krn container bisa di-recreate (restart loop).
        cid="$(docker compose ps -q app 2>/dev/null || true)"
        status=""
        if [ -n "$cid" ]; then
            status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$cid" 2>/dev/null || true)"
        fi
        case "$status" in
            healthy)   return 0 ;;
            unhealthy)
                log "container status=unhealthy, iterasi log."
                docker compose logs --tail=30 app 2>&1 || true
                return 1
                ;;
            *) sleep 2 ;;
        esac
    done
    log "timeout 120s, status terakhir: ${status:-unknown}"
    return 1
}

# Rollback ke image :rollback dan (kalau mode --if-changed & OLD diberikan)
# `git reset --hard OLD` + catat NEW ke .deploy/failed-sha.
#
# Argumen:
#   $1 = alasan (string pendek untuk log)
#   $2 = OLD sha (string kosong kalau mode manual / tidak ada OLD)
rollback() {
    local reason="$1"
    local old_sha="$2"

    log "ROLLBACK ($reason)..."

    if docker image inspect "hafizhamdi/dias-erp:rollback" >/dev/null 2>&1; then
        docker tag "hafizhamdi/dias-erp:rollback" "hafizhamdi/dias-erp:latest"
        log ":rollback -> :latest. up --no-build --force-recreate..."
        # --force-recreate supaya container di-recreate dgn image lama (jika
        # hanya tag yg berubah tanpa recreate, container tetap jalan dgn image
        # baru yg gagal).
        if docker compose up -d --no-build --force-recreate; then
            wait_healthy || log "WARN: rollback sendiri tidak healthy setelah 120s. Cek manual."
        else
            log "WARN: 'docker compose up -d --no-build --force-recreate' gagal."
        fi
        # Bekukan :rollback supaya tidak ditimpa deploy berikutnya sampai
        # dihapus eksplisit (deploy sukses nanti membersihkannya).
    else
        log "FATAL: deploy pertama (?) atau image :rollback tidak ada. Tidak ada yang bisa dikembalikan."
    fi

    if [ -n "$old_sha" ] && [ "$IF_CHANGED" -eq 1 ]; then
        NEW_SHA="$(git rev-parse HEAD)"
        log "git reset --hard $old_sha (HEAD sekarang = $NEW_SHA, akan dicatat di .deploy/failed-sha)"
        git reset --hard "$old_sha"
        printf '%s\n' "$NEW_SHA" > .deploy/failed-sha
    fi

    log "rollback selesai. Exit 1 supaya cron / wrapper tau ada kegagalan."
    exit 1
}

main "$@"
