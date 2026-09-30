<?php

/*
 * Membangkitkan database/production/2026-09-28_02_menu.sql dari isi `lv_menu`.
 *
 * TIDAK mengubah database apa pun - hanya MEMBACA lalu menulis file SQL.
 * Jalankan dari root project SETELAH `db:seed --class=MenuSeeder`, supaya isi file
 * selalu sama dgn MenuSeeder (sumber kebenarannya):
 *
 *   php artisan db:seed --class=MenuSeeder
 *   php artisan tinker --execute="require 'database/production/2026-09-28_02_menu.gen.php';"
 */

use Illuminate\Support\Facades\DB;

$menus = DB::table('lv_menu')->orderBy('id')->get();

$nilai = function ($v): string {
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_bool($v)) {
        return (string) (int) $v;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
};

$sql = <<<'HEAD'
-- =============================================================================
--  dias-laravel — DATA MENU (lv_menu)
--  Dibuat 2026-09-28. Jalankan SETELAH 2026-09-28_01_struktur.sql.
-- =============================================================================
--
--  CARA YANG DIANJURKAN BUKAN FILE INI, melainkan:
--
--      php artisan db:seed --class=MenuSeeder
--
--  MenuSeeder adalah sumber kebenaran daftar menu dan selalu ikut terbarui saat
--  ada menu baru. File SQL ini hanya salinan keadaannya, dipakai kalau di server
--  tidak memungkinkan menjalankan artisan. Dibangkitkan oleh
--  2026-09-28_02_menu.gen.php — jangan disunting tangan, jalankan ulang
--  pembangkitnya setelah menyeed.
--
--  AMAN DIULANG: dikunci `segment_key` yang unik — baris yang sudah ada akan
--  diperbarui judul/route/ikon/urutannya, bukan digandakan.
--
--  Pemeriksaan foreign key dimatikan sementara karena tabel ini menunjuk dirinya
--  sendiri (`parent_id`), dan ada baris yang induknya ber-ID lebih besar sehingga
--  urutan insert biasa akan ditolak.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;


HEAD;

foreach ($menus as $m) {
    $sql .= "INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)\n"
        . 'VALUES (' . implode(', ', [
            $nilai((int) $m->id),
            $nilai($m->parent_id === null ? null : (int) $m->parent_id),
            $nilai($m->segment_key),
            $nilai($m->title),
            $nilai($m->route),
            $nilai($m->icon),
            $nilai($m->menu_type),
            $nilai((int) $m->sort_order),
            $nilai((int) $m->is_active),
        ]) . ")\n"
        . "ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),\n"
        . "  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);\n\n";
}

$sql .= <<<'FOOT'

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  SESUDAH INI: beri hak akses lewat menu Administrasi > Hak Akses Menu.
--  Tabel `lv_user_menu` SENGAJA tidak diisi file ini — hak akses ditentukan
--  per user di tiap lingkungan, bukan disalin dari komputer pengembangan.
-- =============================================================================
FOOT;

$path = 'database/production/2026-09-28_02_menu.sql';
file_put_contents($path, $sql);
echo 'ditulis: ' . $path . ' (' . $menus->count() . ' menu, ' . number_format(strlen($sql)) . " byte)\n";
