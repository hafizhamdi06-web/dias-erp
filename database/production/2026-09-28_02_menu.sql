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
--  ada menu baru. File SQL ini hanya salinan keadaan per 2026-09-28, dipakai
--  kalau di server tidak memungkinkan menjalankan artisan.
--
--  AMAN DIULANG: dikunci `segment_key` yang unik — baris yang sudah ada akan
--  diperbarui judul/route/ikon/urutannya, bukan digandakan.
--
--  Pemeriksaan foreign key dimatikan sementara karena tabel ini menunjuk dirinya
--  sendiri (`parent_id`), dan ada satu baris yang induknya ber-ID lebih besar
--  sehingga urutan insert biasa akan ditolak.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (1, NULL, 'admin', 'Administrasi', NULL, 'fas fa-screwdriver-wrench', 'group', 10, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (2, 1, 'admin.menu', 'Menu', 'admin/menu', 'fas fa-sitemap', 'link', 11, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (3, 1, 'admin.user', 'User', 'admin/user', 'fas fa-user', 'link', 12, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (4, 1, 'admin.user-access', 'Hak Akses Menu', 'admin/user-access', 'fas fa-user-lock', 'link', 13, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (5, 1, 'admin.activity', 'Log Aktivitas', 'admin/activity', 'fas fa-clock-rotate-left', 'link', 14, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (6, NULL, 'master', 'Master Data', NULL, 'fas fa-database', 'group', 20, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (7, 6, 'master.kelompok', 'Kelompok Item', 'master/kelompok', 'fas fa-layer-group', 'link', 21, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (8, 6, 'master.satuan', 'Satuan', 'master/satuan', 'fas fa-ruler', 'link', 22, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (9, 6, 'master.item', 'Data Item POS', 'master/item', 'fas fa-box', 'link', 23, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (10, 6, 'master.pasien', 'Data Pasien', 'master/pasien', 'fas fa-bed-pulse', 'link', 25, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (11, 6, 'master.pelanggan', 'Data Pelanggan', 'master/pelanggan', 'fas fa-address-card', 'link', 26, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (12, 6, 'master.supplier', 'Data Supplier', 'master/supplier', 'fas fa-truck-field', 'link', 27, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (13, 6, 'master.coa', 'Chart of Account', 'master/coa', 'fas fa-list-ol', 'link', 28, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (14, NULL, 'sales', 'Penjualan', NULL, 'fas fa-cash-register', 'group', 30, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (15, 14, 'sales.pos', 'Kasir / POS', 'sales/pos', 'fas fa-cash-register', 'link', 31, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (16, 14, 'sales.order', 'Sales Order', 'sales/order', 'fas fa-file-invoice', 'link', 32, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (17, 14, 'sales.invoice', 'Invoice Penjualan', 'sales/invoice', 'fas fa-file-invoice-dollar', 'link', 34, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (18, 14, 'sales.return', 'Retur Penjualan', 'sales/return', 'fas fa-rotate-left', 'link', 36, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (19, NULL, 'purchase', 'Pembelian', NULL, 'fas fa-basket-shopping', 'group', 40, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (20, 19, 'purchase.po', 'Purchase Order', 'purchase/po', 'fas fa-file-signature', 'link', 43, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (21, 19, 'purchase.receipt', 'Penerimaan Barang', 'purchase/receipt', 'fas fa-dolly', 'link', 44, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (22, 19, 'purchase.invoice', 'Invoice Pembelian', 'purchase/invoice', 'fas fa-receipt', 'link', 45, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (23, 19, 'purchase.return', 'Retur Pembelian', 'purchase/return', 'fas fa-rotate-left', 'link', 46, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (24, NULL, 'inventory', 'Inventory', NULL, 'fas fa-warehouse', 'group', 50, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (25, 24, 'inventory.stock', 'Kartu Stok', 'inventory/stock', 'fas fa-boxes-stacked', 'link', 52, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (27, 24, 'inventory.adjust', 'Penyesuaian Stok', 'inventory/adjust', 'fas fa-scale-balanced', 'link', 55, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (28, 24, 'inventory.opname', 'Stok Opname', 'inventory/opname', 'fas fa-clipboard-check', 'link', 57, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (29, NULL, 'finance', 'Finance & Accounting', NULL, 'fas fa-coins', 'group', 60, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (31, 29, 'finance.journal', 'Jurnal Umum', 'finance/journal', 'fas fa-book', 'link', 66, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (32, 29, 'finance.ar', 'Piutang (AR)', 'finance/ar', 'fas fa-hand-holding-dollar', 'link', 67, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (33, 29, 'finance.ap', 'Hutang (AP)', 'finance/ap', 'fas fa-file-invoice-dollar', 'link', 68, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (34, 29, 'finance.report', 'Laporan Keuangan', 'finance/report', 'fas fa-chart-pie', 'link', 69, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (36, 6, 'master.karyawan', 'Data Karyawan', 'master/karyawan', 'fas fa-user-nurse', 'link', 24, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (38, 14, 'master.promo', 'Master Promo', 'master/promo', 'fas fa-tag', 'link', 38, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (39, 14, 'master.paket', 'Master Paket', 'master/paket', 'fas fa-boxes-packing', 'link', 39, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (41, 19, 'purchase.pkb', 'Perintah Kirim Barang (PKB)', 'purchase/pkb', 'fas fa-dolly-flatbed', 'link', 41, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (43, 19, 'purchase.pbc', 'Penerimaan Barang Cabang', 'purchase/pbc', 'fas fa-box-open', 'link', 42, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (44, 14, 'sales.sj', 'Surat Jalan', 'sales/sj', 'fas fa-truck-fast', 'link', 33, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (45, 24, 'inventory.pr', 'Permintaan Barang', 'inventory/pr', 'fas fa-clipboard-list', 'link', 51, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (46, 24, 'inventory.kmb', 'Kirim Mutasi Barang', 'inventory/kmb', 'fas fa-truck-ramp-box', 'link', 53, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (47, 24, 'inventory.tmb', 'Terima Mutasi Barang', 'inventory/tmb', 'fas fa-dolly', 'link', 54, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (48, NULL, 'pabrik', 'Pabrik', NULL, 'fas fa-industry', 'group', 70, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (49, 48, 'pabrik.jop', 'Job Order Produksi', 'pabrik/jop', 'fas fa-clipboard-list', 'link', 71, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (50, 48, 'pabrik.produksi', 'Produksi', 'pabrik/produksi', 'fas fa-industry', 'link', 72, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (51, 29, 'finance.kas-masuk', 'Kas Masuk', 'finance/kas-masuk', 'fas fa-money-bill-trend-up', 'link', 61, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (52, 29, 'finance.kas-keluar', 'Kas Keluar', 'finance/kas-keluar', 'fas fa-money-bill-transfer', 'link', 62, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (53, 29, 'finance.bank-masuk', 'Bank Masuk', 'finance/bank-masuk', 'fas fa-building-columns', 'link', 63, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (54, 29, 'finance.bank-keluar', 'Bank Keluar', 'finance/bank-keluar', 'fas fa-building-columns', 'link', 64, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (55, 29, 'finance.pengajuan-dana', 'Pengajuan Dana', 'finance/pengajuan-dana', 'fas fa-hand-holding-dollar', 'link', 65, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (56, NULL, 'laporan', 'Laporan', NULL, 'fas fa-chart-column', 'group', 80, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (57, 58, 'laporan.penjualan-per-barang', 'IP Per Barang', 'laporan/penjualan-per-barang', 'fas fa-box', 'link', 82, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (58, 56, 'laporan.penjualan', 'POS', NULL, 'fas fa-cash-register', 'group', 81, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (59, 14, 'sales.invoice-mutasi', 'Invoice Penjualan Mutasi', 'sales/invoice-mutasi', 'fas fa-truck-arrow-right', 'link', 35, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (60, 6, 'master.gudang', 'Data Gudang', 'master/gudang', 'fas fa-warehouse', 'link', 20, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (61, 24, 'inventory.serial', 'Data Histori Serial', 'inventory/serial', 'fas fa-barcode', 'link', 58, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (62, 14, 'sales.alkes', 'Input Alkes Depo', 'sales/alkes', 'fas fa-syringe', 'link', 37, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (63, 24, 'inventory.pengeluaran-lain', 'Pengeluaran Lain', 'inventory/pengeluaran-lain', 'fas fa-arrow-right-from-bracket', 'link', 56, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO `lv_menu` (id, parent_id, segment_key, title, route, icon, menu_type, sort_order, is_active)
VALUES (64, 58, 'laporan.ip-tindakan-produk', 'IP Tindakan/Produk Per Bulan', 'laporan/ip-tindakan-produk', 'fas fa-calendar-days', 'link', 83, 1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), title=VALUES(title), route=VALUES(route),
  icon=VALUES(icon), menu_type=VALUES(menu_type), sort_order=VALUES(sort_order), is_active=VALUES(is_active);


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  SESUDAH INI: beri hak akses lewat menu Administrasi > Hak Akses Menu.
--  Tabel `lv_user_menu` SENGAJA tidak diisi file ini — hak akses ditentukan
--  per user di tiap lingkungan, bukan disalin dari komputer pengembangan.
-- =============================================================================