<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

/**
 * Kerangka menu ERP - idempoten, dikunci lewat kolom `segment_key`.
 * Jalankan ulang aman: baris yang sudah ada di-update, yang belum dibuat.
 *
 *   php artisan db:seed --class=MenuSeeder
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // [segment_key => [title, route|null, icon, menu_type, sort, parent_segment|null]]
        $items = [
            // ---- Administrasi ------------------------------------------------
            'admin'            => ['Administrasi',      null,               'fas fa-screwdriver-wrench', 'group', 10, null],
            'admin.menu'       => ['Menu',              'admin/menu',        'fas fa-sitemap',            'link',  11, 'admin'],
            'admin.user'       => ['User',              'admin/user',        'fas fa-user',               'link',  12, 'admin'],
            'admin.user-access'=> ['Hak Akses Menu',    'admin/user-access', 'fas fa-user-lock',          'link',  13, 'admin'],
            'admin.activity'   => ['Log Aktivitas',     'admin/activity',    'fas fa-clock-rotate-left',  'link',  14, 'admin'],

            // ---- Master Data -----------------------------------------------
            'master'           => ['Master Data',       null,               'fas fa-database',            'group', 20, null],
            'master.gudang'    => ['Data Gudang',       'master/gudang',     'fas fa-warehouse',          'link',  20, 'master'],
            'master.kelompok'  => ['Kelompok Item',     'master/kelompok',   'fas fa-layer-group',        'link',  21, 'master'],
            'master.satuan'    => ['Satuan',            'master/satuan',     'fas fa-ruler',              'link',  22, 'master'],
            'master.item'      => ['Data Item POS',     'master/item',       'fas fa-box',                'link',  23, 'master'],
            'master.karyawan'  => ['Data Karyawan',     'master/karyawan',   'fas fa-user-nurse',         'link',  24, 'master'],
            'master.pasien'    => ['Data Pasien',       'master/pasien',     'fas fa-bed-pulse',          'link',  25, 'master'],
            'master.pelanggan' => ['Data Pelanggan',    'master/pelanggan',  'fas fa-address-card',       'link',  26, 'master'],
            'master.supplier'  => ['Data Supplier',     'master/supplier',   'fas fa-truck-field',        'link',  27, 'master'],
            'master.coa'       => ['Chart of Account',  'master/coa',        'fas fa-list-ol',            'link',  28, 'master'],

            // ---- Penjualan -----------------------------------------------
            'sales'            => ['Penjualan',         null,               'fas fa-cash-register',      'group', 30, null],
            'sales.pos'        => ['Kasir / POS',       'sales/pos',         'fas fa-cash-register',      'link',  31, 'sales'],
            'sales.order'      => ['Sales Order',       'sales/order',       'fas fa-file-invoice',       'link',  32, 'sales'],
            'sales.sj'         => ['Surat Jalan',       'sales/sj',          'fas fa-truck-fast',         'link',  33, 'sales'],
            'sales.invoice'    => ['Invoice Penjualan', 'sales/invoice',     'fas fa-file-invoice-dollar','link',  34, 'sales'],
            'sales.invoice-mutasi' => ['Invoice Penjualan Mutasi', 'sales/invoice-mutasi', 'fas fa-truck-arrow-right', 'link', 35, 'sales'],
            'sales.return'     => ['Retur Penjualan',   'sales/return',      'fas fa-rotate-left',        'link',  36, 'sales'],
            // Promo & Paket dipindah dari grup Master Data ke Penjualan (permintaan user
            // 2026-09-25). `segment_key` + `route` SENGAJA tetap `master.*`/`master/*`:
            // hak akses `lv_user_menu` terhubung lewat `menu_id` (aman), tapi path `master/promo`
            // & `master/paket` dipakai ~20 `can_do()`/`activity_log()` di komponen+blade -
            // menggantinya cuma demi kosmetik menu tidak sepadan dgn risikonya.
            'sales.alkes'      => ['Input Alkes Depo',  'sales/alkes',       'fas fa-syringe',            'link',  37, 'sales'],
            'master.promo'     => ['Master Promo',      'master/promo',      'fas fa-tag',                'link',  38, 'sales'],
            'master.paket'     => ['Master Paket',      'master/paket',      'fas fa-boxes-packing',      'link',  39, 'sales'],

            // ---- Pembelian ----------------------------------------------
            'purchase'         => ['Pembelian',         null,               'fas fa-basket-shopping',    'group', 40, null],
            'purchase.pkb'     => ['Perintah Kirim Barang (PKB)', 'purchase/pkb', 'fas fa-dolly-flatbed',  'link',  41, 'purchase'],
            'purchase.pbc'     => ['Penerimaan Barang Cabang', 'purchase/pbc', 'fas fa-box-open',         'link',  42, 'purchase'],
            'purchase.po'      => ['Purchase Order',    'purchase/po',       'fas fa-file-signature',     'link',  43, 'purchase'],
            'purchase.receipt' => ['Penerimaan Barang', 'purchase/receipt',  'fas fa-dolly',              'link',  44, 'purchase'],
            'purchase.invoice' => ['Invoice Pembelian', 'purchase/invoice',  'fas fa-receipt',            'link',  45, 'purchase'],
            'purchase.return'  => ['Retur Pembelian',   'purchase/return',   'fas fa-rotate-left',        'link',  46, 'purchase'],

            // ---- Inventory --------------------------------------------
            'inventory'        => ['Inventory',         null,               'fas fa-warehouse',          'group', 50, null],
            'inventory.pr'     => ['Permintaan Barang', 'inventory/pr',      'fas fa-clipboard-list',     'link',  51, 'inventory'],
            'inventory.stock'  => ['Kartu Stok',        'inventory/stock',   'fas fa-boxes-stacked',      'link',  52, 'inventory'],
            'inventory.kmb'    => ['Kirim Mutasi Barang', 'inventory/kmb',   'fas fa-truck-ramp-box',     'link',  53, 'inventory'],
            'inventory.tmb'    => ['Terima Mutasi Barang', 'inventory/tmb',  'fas fa-dolly',               'link',  54, 'inventory'],
            'inventory.adjust' => ['Penyesuaian Stok',  'inventory/adjust',  'fas fa-scale-balanced',     'link',  55, 'inventory'],
            'inventory.opname'  => ['Stok Opname',      'inventory/opname',  'fas fa-clipboard-check',    'link',  56, 'inventory'],
            'inventory.serial' => ['Data Histori Serial', 'inventory/serial', 'fas fa-barcode',          'link',  57, 'inventory'],

            // ---- Finance / Accounting -------------------------------
            'finance'            => ['Finance & Accounting', null,             'fas fa-coins',              'group', 60, null],
            'finance.kas-masuk'  => ['Kas Masuk',        'finance/kas-masuk',  'fas fa-money-bill-trend-up','link',  61, 'finance'],
            'finance.kas-keluar' => ['Kas Keluar',       'finance/kas-keluar', 'fas fa-money-bill-transfer','link',  62, 'finance'],
            'finance.bank-masuk' => ['Bank Masuk',       'finance/bank-masuk', 'fas fa-building-columns',   'link',  63, 'finance'],
            'finance.bank-keluar'=> ['Bank Keluar',      'finance/bank-keluar','fas fa-building-columns',   'link',  64, 'finance'],
            'finance.pengajuan-dana' => ['Pengajuan Dana', 'finance/pengajuan-dana', 'fas fa-hand-holding-dollar', 'link', 65, 'finance'],
            'finance.journal'  => ['Jurnal Umum',       'finance/journal',   'fas fa-book',               'link',  66, 'finance'],
            'finance.ar'       => ['Piutang (AR)',      'finance/ar',        'fas fa-hand-holding-dollar','link',  67, 'finance'],
            'finance.ap'       => ['Hutang (AP)',       'finance/ap',        'fas fa-file-invoice-dollar','link',  68, 'finance'],
            'finance.report'   => ['Laporan Keuangan',  'finance/report',    'fas fa-chart-pie',          'link',  69, 'finance'],

            // ---- Pabrik (manufaktur internal) ------------------------
            'pabrik'           => ['Pabrik',            null,               'fas fa-industry',           'group', 70, null],
            'pabrik.jop'       => ['Job Order Produksi', 'pabrik/jop',       'fas fa-clipboard-list',     'link',  71, 'pabrik'],
            'pabrik.produksi'  => ['Produksi',          'pabrik/produksi',   'fas fa-industry',           'link',  72, 'pabrik'],

            // ---- Laporan (sub-grup per modul transaksi) ----------------
            'laporan'          => ['Laporan',           null,               'fas fa-chart-column',       'group', 80, null],
            'laporan.penjualan' => ['POS',              null,               'fas fa-cash-register',      'group', 81, 'laporan'],
            'laporan.penjualan-per-barang' => ['IP Per Barang', 'laporan/penjualan-per-barang', 'fas fa-box', 'link', 82, 'laporan.penjualan'],
        ];

        $idBySegment = [];

        foreach ($items as $segment => [$title, $route, $icon, $type, $sort, $parentSegment]) {
            $menu = Menu::updateOrCreate(
                ['segment_key' => $segment],
                [
                    'title'      => $title,
                    'route'      => $route,
                    'icon'       => $icon,
                    'menu_type'  => $type,
                    'sort_order' => $sort,
                    'parent_id'  => $parentSegment ? ($idBySegment[$parentSegment] ?? null) : null,
                    'is_active'  => true,
                ]
            );

            $idBySegment[$segment] = $menu->id;
        }
    }
}
