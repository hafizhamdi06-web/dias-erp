-- =============================================================================
--  dias-laravel — UPDATE STRUKTUR DATABASE PRODUKSI
--  Dibuat 2026-09-28. Jalankan SATU KALI pada database produksi (data_pos_nmw_2023).
-- =============================================================================
--
--  APA YANG DILAKUKAN FILE INI
--    1. Membuat 5 tabel BARU milik aplikasi Laravel, semuanya ber-prefix `lv_`.
--    2. Menambah 6 baris konfigurasi nomor transaksi ke tabel legacy `aanomor`.
--    3. Menandai 9 migrasi sebagai sudah dijalankan, supaya `php artisan migrate`
--       di produksi TIDAK mencoba menjalankannya ulang.
--
--  APA YANG **TIDAK** DILAKUKAN — penting
--    * TIDAK ada satu pun tabel legacy yang strukturnya diubah. Tidak ada ALTER
--      TABLE, tidak ada DROP, tidak ada perubahan kolom/index/trigger.
--    * TIDAK menyentuh tabel `migrations` (milik CI3) maupun `a4_migrations`
--      (milik CI4). Aplikasi Laravel sengaja memakai tabel sendiri,
--      `lv_migrations`, supaya tidak bentrok — lihat config/database.php.
--    * TIDAK menyentuh `auser`. Password lama (MD5 di `auser.UPASSWORD`) dibiarkan
--      apa adanya supaya CI3/CI4 tetap bisa login; hash bcrypt untuk Laravel
--      ditulis ke `lv_user_auth` saat user login pertama kali.
--
--  AMAN DIULANG
--    Semua perintah idempoten (CREATE TABLE IF NOT EXISTS / INSERT ... WHERE NOT
--    EXISTS). Menjalankan file ini dua kali tidak merusak apa pun dan tidak
--    menggandakan baris.
--
--  SEBELUM MENJALANKAN
--    Backup dulu. Walau file ini hanya menambah, backup tetap wajib untuk
--    perubahan apa pun di database produksi.
--
--  SESUDAH MENJALANKAN — masih ada 2 langkah, lihat catatan di bagian akhir file.
-- =============================================================================


-- =============================================================================
--  BAGIAN 1 — TABEL BARU (prefix lv_)
-- =============================================================================

-- Catatan pencatat migrasi Laravel. Sengaja BUKAN bernama `migrations`:
-- nama itu sudah dipakai CI3 di database yang sama.
CREATE TABLE IF NOT EXISTS `lv_migrations` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch`     int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Hash password modern per user. `user_id` mengacu ke `auser.UID`.
-- Sengaja TANPA foreign key ke `auser` supaya tidak mengikat tabel legacy.
CREATE TABLE IF NOT EXISTS `lv_user_auth` (
  `user_id`       int(10) unsigned NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change`   tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at`    timestamp NULL DEFAULT NULL,
  `updated_at`    timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Struktur menu aplikasi. `segment_key` unik — dipakai seeder sebagai kunci
-- idempoten, dan dipakai kode untuk memetakan menu ke komponen layar.
CREATE TABLE IF NOT EXISTS `lv_menu` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id`   bigint(20) unsigned DEFAULT NULL,
  `segment_key` varchar(100) NOT NULL,
  `title`       varchar(100) NOT NULL,
  `route`       varchar(191) DEFAULT NULL,
  `icon`        varchar(50) DEFAULT NULL,
  `menu_type`   enum('group','link') NOT NULL DEFAULT 'link',
  `sort_order`  int(11) NOT NULL DEFAULT 0,
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `created_at`  timestamp NULL DEFAULT NULL,
  `updated_at`  timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lv_menu_segment_key_unique` (`segment_key`),
  KEY `lv_menu_parent_id_sort_order_index` (`parent_id`,`sort_order`),
  CONSTRAINT `lv_menu_parent_id_foreign`
    FOREIGN KEY (`parent_id`) REFERENCES `lv_menu` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Hak akses per user per menu — 6 kewenangan.
-- PENTING: hak akses terhubung lewat `menu_id`, BUKAN lewat path/route. Jadi
-- mengganti `route` sebuah menu tidak menghilangkan hak akses yang sudah diberikan.
CREATE TABLE IF NOT EXISTS `lv_user_menu` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned NOT NULL,
  `menu_id`     bigint(20) unsigned NOT NULL,
  `can_view`    tinyint(1) NOT NULL DEFAULT 0,
  `can_add`     tinyint(1) NOT NULL DEFAULT 0,
  `can_edit`    tinyint(1) NOT NULL DEFAULT 0,
  `can_delete`  tinyint(1) NOT NULL DEFAULT 0,
  `can_print`   tinyint(1) NOT NULL DEFAULT 0,
  `can_approve` tinyint(1) NOT NULL DEFAULT 0,
  `created_at`  timestamp NULL DEFAULT NULL,
  `updated_at`  timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lv_user_menu_user_id_menu_id_unique` (`user_id`,`menu_id`),
  KEY `lv_user_menu_user_id_index` (`user_id`),
  KEY `lv_user_menu_menu_id_foreign` (`menu_id`),
  CONSTRAINT `lv_user_menu_menu_id_foreign`
    FOREIGN KEY (`menu_id`) REFERENCES `lv_menu` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Jejak aktivitas user (login, buat/ubah/batal dokumen, dll).
CREATE TABLE IF NOT EXISTS `lv_activity_log` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned DEFAULT NULL,
  `user_label`  varchar(100) DEFAULT NULL,
  `action`      varchar(50) NOT NULL,
  `module`      varchar(50) DEFAULT NULL,
  `entity_id`   varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip`          varchar(45) DEFAULT NULL,
  `created_at`  timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lv_activity_log_module_action_index` (`module`,`action`),
  KEY `lv_activity_log_user_id_index` (`user_id`),
  KEY `lv_activity_log_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferensi tampilan/cetak per user. Kebutuhan pertama: ukuran struk POS
-- ('58' = termal 58mm, 'a5' = setengah A4/LX300). NULL = ikut default aplikasi
-- (`config('pos.struk_default')`). Preferensi baru ditambah sbg KOLOM di tabel ini.
CREATE TABLE IF NOT EXISTS `lv_user_pref` (
  `user_id`    int(10) unsigned NOT NULL,
  `struk_pos`  varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  BAGIAN 2 — KONFIGURASI NOMOR TRANSAKSI (tabel legacy `aanomor`)
-- =============================================================================
--  Enam dokumen baru perlu terdaftar di `aanomor`. Pola kolom NFLD* meniru baris
--  yang sudah ada (mis. `RS` = Permintaan Barang), disesuaikan ke tabel/kolom
--  masing-masing dokumen.
--
--  Semua INSERT di bawah memakai pola "hanya kalau NKODE belum ada", jadi baris
--  yang SUDAH ADA di produksi TIDAK akan disentuh atau digandakan. Ini penting:
--  beberapa kode (mis. TMB) mungkin sudah lebih dulu ada di produksi.
-- =============================================================================

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'PKB', 'Perintah Kirim Barang', 'fperintahkirimbarangu',
       'PKBUTANGGAL', 'PKBUSUMBER', 'PKBUNOTRANSAKSI', 'PKBUURAIAN',
       '', 'PKBUKONTAK', 'PKBUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'PKB');

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'PBC', 'Penerimaan Barang', 'fstoku',
       'SUTANGGAL', 'SUSUMBER', 'SUNOTRANSAKSI', 'SUURAIAN',
       'SUTOTALTRANSAKSI', 'SUKONTAK', 'SUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'PBC');

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'KMB', 'Kirim Mutasi Barang', 'fstoku',
       'SUTANGGAL', 'SUSUMBER', 'SUNOTRANSAKSI', 'SUURAIAN',
       'SUTOTALTRANSAKSI', 'SUKONTAK', 'SUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'KMB');

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'TMB', 'Terima Mutasi Barang', 'fstoku',
       'SUTANGGAL', 'SUSUMBER', 'SUNOTRANSAKSI', 'SUURAIAN',
       'SUTOTALTRANSAKSI', 'SUKONTAK', 'SUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'TMB');

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'JOP', 'Job Order Produksi', 'fproduksiu',
       'PUTANGGAL', 'PUSUMBER', 'PUNOTRANSAKSI', 'PUURAIAN',
       'PUTOTALTRANSAKSI', 'PUKONTAK', 'PUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'JOP');

INSERT INTO `aanomor`
  (NKODE, NKETERANGAN, NTABEL, NFLDTANGGAL, NFLDSUMBER, NFLDNOTRANSAKSI,
   NFLDURAIAN, NFLDTOTALTRANS, NFLDKONTAK, NFLDID, NFA)
SELECT 'PRO', 'Produksi', 'fstoku',
       'SUTANGGAL', 'SUSUMBER', 'SUNOTRANSAKSI', 'SUURAIAN',
       'SUTOTALTRANSAKSI', 'SUKONTAK', 'SUID', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `aanomor` WHERE NKODE = 'PRO');


-- =============================================================================
--  BAGIAN 3 — TANDAI MIGRASI SUDAH DIJALANKAN
-- =============================================================================
--  Struktur di atas sudah dibuat manual lewat file ini, jadi 9 migrasi Laravel
--  dicatat sebagai selesai. Tanpa bagian ini, `php artisan migrate` di produksi
--  akan mencoba membuat ulang tabel yang sudah ada dan gagal.
-- =============================================================================

INSERT INTO `lv_migrations` (migration, batch)
SELECT m.migration, 1 FROM (
  SELECT '2026_09_09_000001_create_lv_user_auth_table'   AS migration UNION ALL
  SELECT '2026_09_09_000002_create_lv_menu_tables'                    UNION ALL
  SELECT '2026_09_09_000003_create_lv_activity_log_table'             UNION ALL
  SELECT '2026_09_18_000001_seed_aanomor_pkb'                         UNION ALL
  SELECT '2026_09_18_000002_seed_aanomor_pbc'                         UNION ALL
  SELECT '2026_09_21_000001_seed_aanomor_kmb'                         UNION ALL
  SELECT '2026_09_21_000002_seed_aanomor_tmb'                         UNION ALL
  SELECT '2026_09_23_000001_seed_aanomor_jop'                         UNION ALL
  SELECT '2026_09_23_000002_seed_aanomor_pro'                         UNION ALL
  SELECT '2026_09_30_000001_create_lv_user_pref_table'
) m
WHERE NOT EXISTS (
  SELECT 1 FROM `lv_migrations` x WHERE x.migration = m.migration
);


-- =============================================================================
--  LANGKAH SESUDAH FILE INI
-- =============================================================================
--
--  1. ISI DATA MENU. Tabel `lv_menu` masih kosong; tanpa isinya, sidebar aplikasi
--     tidak menampilkan apa pun. Cara yang dianjurkan, dijalankan dari folder
--     aplikasi di server:
--
--         php artisan db:seed --class=MenuSeeder
--
--     Seeder ini idempoten (dikunci `segment_key`) dan merupakan sumber kebenaran
--     daftar menu, jadi aman dijalankan ulang setiap kali ada menu baru.
--     Kalau di server tidak memungkinkan menjalankan artisan, pakai file
--     `2026-09-28_02_menu.sql` sebagai gantinya.
--
--  2. BERI HAK AKSES MENU. Setelah menu terisi, buka aplikasi sebagai super user
--     lalu atur lewat menu Administrasi > Hak Akses Menu. User super
--     (config/acl.php, bawaannya UID 1) melewati pemeriksaan hak akses, jadi
--     bisa dipakai untuk mengatur user lain.
--
--  CATATAN
--     Tabel `failed_jobs` ada di database pengembangan tetapi TIDAK disertakan di
--     sini: itu tabel bawaan Laravel untuk antrian, sedangkan aplikasi ini memakai
--     QUEUE_CONNECTION=sync (tanpa antrian), sehingga tabelnya tidak terpakai.
--     Tabel sesi & cache juga tidak diperlukan — keduanya memakai driver file.
-- =============================================================================
