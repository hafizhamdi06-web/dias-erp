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
            // Data Harga Marketplace (permintaan user 2026-10-15) - cek & ubah bitem2.I2HARGAJUALMP.
            // Sort sama dgn Data Item POS; dibuat sesudahnya, jadi tampil di bawahnya.
            'master.harga-mp'  => ['Data Harga Marketplace', 'master/harga-mp', 'fas fa-store',          'link',  23, 'master'],
            'master.karyawan'  => ['Data Karyawan',     'master/karyawan',   'fas fa-user-nurse',         'link',  24, 'master'],
            'master.pasien'    => ['Data Pasien',       'master/pasien',     'fas fa-bed-pulse',          'link',  25, 'master'],
            'master.pelanggan' => ['Data Pelanggan',    'master/pelanggan',  'fas fa-address-card',       'link',  26, 'master'],
            'master.supplier'  => ['Data Supplier',     'master/supplier',   'fas fa-truck-field',        'link',  27, 'master'],
            'master.coa'       => ['Chart of Account',  'master/coa',        'fas fa-list-ol',            'link',  28, 'master'],

            // ---- Penjualan -----------------------------------------------
            'sales'            => ['Penjualan',         null,               'fas fa-cash-register',      'group', 30, null],
            // Dashboard Penjualan & COGS (permintaan user 2026-10-13) - lima tingkat
            // rincian PT > Cabang > COA > Item > Transaksi. Ditaruh paling atas di grup
            // Penjualan krn ini layar PANTAU, bukan entri: dibuka lebih dulu.
            'sales.dashboard'  => ['Dashboard Penjualan', 'sales/dashboard', 'fas fa-chart-line',        'link',  31, 'sales'],
            // Dashboard Surgery (permintaan user 2026-10-16) - pantau surgery yg dibayar cicil.
            // Sort sama dgn Dashboard Penjualan; dibuat sesudahnya, jadi tampil di bawahnya.
            'sales.surgery'    => ['Dashboard Surgery', 'sales/surgery',     'fas fa-user-doctor',        'link',  31, 'sales'],
            'sales.pos'        => ['Kasir / POS',       'sales/pos',         'fas fa-cash-register',      'link',  32, 'sales'],
            // Dulu hanya bisa dibuka dari tombol di dalam layar kasir; jadi menu sendiri atas
            // permintaan user 2026-09-28. Path `sales/pos-data` SENGAJA terpisah dari
            // `sales/pos` supaya hak aksesnya bisa diatur sendiri - mis. supervisor boleh
            // melihat & cetak ulang transaksi tanpa diberi akses layar kasir.
            'sales.pos-data'   => ['Data Transaksi POS', 'sales/pos-data',   'fas fa-table-list',         'link',  33, 'sales'],
            'sales.order'      => ['Sales Order',       'sales/order',       'fas fa-file-invoice',       'link',  34, 'sales'],
            // `sales.sj` PINDAH ke grup Inventory - lihat di bawah.
            'sales.invoice'    => ['Invoice Penjualan', 'sales/invoice',     'fas fa-file-invoice-dollar','link',  35, 'sales'],
            'sales.invoice-mutasi' => ['Invoice Penjualan Mutasi', 'sales/invoice-mutasi', 'fas fa-truck-arrow-right', 'link', 36, 'sales'],
            'sales.return'     => ['Retur Penjualan',   'sales/return',      'fas fa-rotate-left',        'link',  37, 'sales'],
            // Promo & Paket dipindah dari grup Master Data ke Penjualan (permintaan user
            // 2026-09-25). `segment_key` + `route` SENGAJA tetap `master.*`/`master/*`:
            // hak akses `lv_user_menu` terhubung lewat `menu_id` (aman), tapi path `master/promo`
            // & `master/paket` dipakai ~20 `can_do()`/`activity_log()` di komponen+blade -
            // menggantinya cuma demi kosmetik menu tidak sepadan dgn risikonya.
            'sales.alkes'      => ['Input Alkes Depo',  'sales/alkes',       'fas fa-syringe',            'link',  38, 'sales'],
            // Closing Harian IP (permintaan user 2026-10-15) - tindakan IP yg alkesnya belum
            // diinput. Sort sama dgn Input Alkes; dibuat sesudahnya, jadi tampil di bawahnya.
            'sales.closing-ip' => ['Closing Harian IP', 'sales/closing-ip',  'fas fa-clipboard-check',    'link',  38, 'sales'],
            'master.promo'     => ['Master Promo',      'master/promo',      'fas fa-tag',                'link',  39, 'sales'],
            'master.paket'     => ['Master Paket',      'master/paket',      'fas fa-boxes-packing',      'link',  40, 'sales'],

            // ---- Dashboard Penjualan Markom (permintaan user 2026-10-15) ----------
            // Grup sendiri di tingkat atas (sort 35, di antara Penjualan & Pembelian) - layar
            // PANTAU utk tim marketing, hak aksesnya diatur terpisah dari menu Penjualan.
            'markom'           => ['Dashboard Penjualan Markom', null, 'fas fa-bullhorn',              'group', 35, null],
            'markom.total'     => ['Penjualan Total',   'markom/total',      'fas fa-sack-dollar',        'link',  35, 'markom'],
            'markom.komparasi' => ['Penjualan Komparasi', 'markom/komparasi', 'fas fa-code-compare',      'link',  36, 'markom'],
            'markom.promo'     => ['Penjualan Promo',   'markom/promo',      'fas fa-tags',               'link',  37, 'markom'],

            // ---- Pembelian ----------------------------------------------
            'purchase'         => ['Pembelian',         null,               'fas fa-basket-shopping',    'group', 40, null],
            // `purchase.pkb` & `purchase.pbc` PINDAH ke grup Inventory - lihat di bawah.
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
            // PKB, Surat Jalan & PBC DIPINDAH ke sini (permintaan user 2026-10-10): PKB & PBC
            // dari grup Pembelian, SJ dari grup Penjualan. Memang tempat aslinya - di VB6
            // "Perintah Kirim Barang" (MID 598), "Surat Jalan Depo" (MID 728) dan
            // "Penerimaan Cabang" (MID 476) sama-sama di grup **Transaksi**, satu grup dgn
            // Permintaan Barang/KMB/TMB/Pengeluaran Lain, yg di sini jadi Inventory. Ketiganya
            // mutasi stok antar gudang sendiri, bukan jual-beli ke pihak luar. Urutannya
            // dijaga mengikuti alur dokumennya: PR -> PKB -> SJ -> PBC.
            //
            // `segment_key` + `route` SENGAJA tetap `purchase.*`/`sales.sj` (bukan
            // `inventory.*`), persis alasan Promo & Paket di grup Penjualan di atas: hak akses
            // `lv_user_menu` nyambung lewat `menu_id` (aman), tapi path lamanya dipakai
            // belasan `can_do()`/`activity_log()`, rute cetak `sales.sj.print`, dan kunci
            // registry Workspace - menggantinya cuma demi kosmetik menu tidak sepadan dgn
            // risiko hak akses hilang.
            'purchase.pkb'     => ['Perintah Kirim Barang (PKB)', 'purchase/pkb', 'fas fa-dolly-flatbed', 'link', 55, 'inventory'],
            'sales.sj'         => ['Surat Jalan',       'sales/sj',          'fas fa-truck-fast',         'link',  56, 'inventory'],
            'purchase.pbc'     => ['Penerimaan Barang Cabang', 'purchase/pbc', 'fas fa-box-open',         'link',  57, 'inventory'],
            'inventory.adjust' => ['Penyesuaian Stok',  'inventory/adjust',  'fas fa-scale-balanced',     'link',  58, 'inventory'],
            // Kembar Penyesuaian tapi keluar-saja (`fstoku` SUSUMBER='PL'), sengaja
            // bersebelahan di menu krn alur kerjanya mirip.
            'inventory.pengeluaran-lain' => ['Pengeluaran Lain', 'inventory/pengeluaran-lain', 'fas fa-arrow-right-from-bracket', 'link', 59, 'inventory'],
            'inventory.opname'  => ['Stok Opname',      'inventory/opname',  'fas fa-clipboard-check',    'link',  60, 'inventory'],
            'inventory.serial' => ['Data Histori Serial', 'inventory/serial', 'fas fa-barcode',          'link',  61, 'inventory'],

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
            'laporan.ip-tindakan-produk' => ['IP Tindakan/Produk Per Bulan', 'laporan/ip-tindakan-produk', 'fas fa-calendar-days', 'link', 83, 'laporan.penjualan'],
            'laporan.penjualan-tunai' => ['Daftar Penjualan Tunai', 'laporan/penjualan-tunai', 'fas fa-money-bill-wave', 'link', 84, 'laporan.penjualan'],
            'laporan.persediaan' => ['Persediaan',      null,               'fas fa-boxes-stacked',      'group', 85, 'laporan'],
            'laporan.stok-barang' => ['Daftar Stok Barang', 'laporan/stok-barang', 'fas fa-warehouse', 'link', 86, 'laporan.persediaan'],
            'laporan.stok-per-hari' => ['Stok Per Hari', 'laporan/stok-per-hari', 'fas fa-calendar-day', 'link', 87, 'laporan.persediaan'],
            'laporan.surat-jalan' => ['Daftar Surat Jalan Barang', 'laporan/surat-jalan', 'fas fa-truck-fast', 'link', 88, 'laporan.persediaan'],
            'laporan.stok-serial' => ['Daftar Stok Barang Serial', 'laporan/stok-serial', 'fas fa-barcode', 'link', 89, 'laporan.persediaan'],
            // Kedatangan pasien ikut grup POS (sumbernya transaksi IP), bukan Persediaan.
            'laporan.ip-kedatangan' => ['IP Kedatangan Pasien', 'laporan/ip-kedatangan', 'fas fa-user-check', 'link', 90, 'laporan.penjualan'],
            'laporan.ip-per-dokter' => ['IP Penjualan Per Dokter', 'laporan/ip-per-dokter', 'fas fa-user-doctor', 'link', 91, 'laporan.penjualan'],
            'laporan.dp-per-tanggal' => ['Jumlah DP Pertanggal', 'laporan/dp-per-tanggal', 'fas fa-piggy-bank', 'link', 92, 'laporan.penjualan'],

            // ---- Grup BARU "IV Penjualan" (permintaan user 2026-10-04) ----
            'laporan.iv'        => ['IV Penjualan',   null,  'fas fa-file-invoice', 'group', 93, 'laporan'],
            'laporan.iv.surat-jalan' => ['IP IV Surat Jalan', 'laporan/iv-surat-jalan', 'fas fa-truck-fast', 'link', 94, 'laporan.iv'],
            'laporan.iv.mutasi' => ['Daftar Invoice Mutasi', 'laporan/iv-mutasi', 'fas fa-right-left', 'link', 95, 'laporan.iv'],
            'laporan.iv.per-tanggal' => ['IP IV Per Tanggal', 'laporan/iv-per-tanggal', 'fas fa-calendar-day', 'link', 96, 'laporan.iv'],
            'laporan.iv.mutasi-detail' => ['Daftar IV Mutasi Detail', 'laporan/iv-mutasi-detail', 'fas fa-list-ul', 'link', 97, 'laporan.iv'],
            'laporan.iv.daftar-invoice' => ['IP IV Daftar Invoice', 'laporan/iv-daftar-invoice', 'fas fa-file-invoice', 'link', 98, 'laporan.iv'],
            'laporan.iv.daftar-mutasi' => ['IVM Daftar Invoice Mutasi', 'laporan/iv-daftar-mutasi', 'fas fa-file-invoice-dollar', 'link', 99, 'laporan.iv'],

            // ---- Panduan sistem (permintaan user 2026-10-16) -------------------
            // Halaman bantuan cara pakai, satu link per topik. Paling bawah di sidebar.
            'panduan'          => ['Panduan',           null,                'fas fa-book',               'group', 95, null],
            'panduan.pos'      => ['Kasir / POS',       'panduan/pos',       'fas fa-cash-register',      'link',  96, 'panduan'],
            'panduan.surgery'  => ['Transaksi Surgery', 'panduan/surgery',   'fas fa-user-doctor',        'link',  97, 'panduan'],
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
