<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InvoiceMutasiPrintController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\JopPrintController;
use App\Http\Controllers\KasBankPrintController;
use App\Http\Controllers\KmbPrintController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\PbcPrintController;
use App\Http\Controllers\PbPrintController;
use App\Http\Controllers\PkbPrintController;
use App\Http\Controllers\ProduksiPrintController;
use App\Http\Controllers\PengeluaranLainPrintController;
use App\Http\Controllers\PengajuanDanaPrintController;
use App\Http\Controllers\PenyesuaianPrintController;
use App\Http\Controllers\PoPrintController;
use App\Http\Controllers\PosReceiptController;
use App\Http\Controllers\PrPrintController;
use App\Http\Controllers\SjPrintController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TmbPrintController;
use App\Livewire\Auth\ChangePassword;
use App\Livewire\Workspace;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Publik
|--------------------------------------------------------------------------
*/

Route::get('login', [LoginController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('login', [LoginController::class, 'login'])->middleware('guest');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Rute Terproteksi (harus login)
|--------------------------------------------------------------------------
| Seluruh aplikasi berjalan di dalam satu shell "Workspace" (sistem tab).
| Modul dibuka sebagai tab, bukan halaman terpisah.
| ?open=<segment_key> membuka tab tertentu saat pertama load.
*/

Route::middleware('auth')->group(function () {

    // Wajib ganti password sementara. SENGAJA didaftarkan sebelum rute lain & dilewati
    // middleware `EnsurePasswordChanged` supaya user tidak terkunci dalam lingkaran redirect.
    Route::get('ganti-password', ChangePassword::class)->name('password.change');

    Route::get('/', Workspace::class)->name('workspace');
    Route::get('dashboard', fn () => redirect()->route('workspace'))->name('dashboard');

    Route::get('sales/pos/receipt/{id}', [PosReceiptController::class, 'show'])->name('sales.pos.receipt');
    Route::get('inventory/pr/{id}/print', [PrPrintController::class, 'show'])->name('inventory.pr.print');
    Route::get('purchase/po/{id}/print', [PoPrintController::class, 'show'])->name('purchase.po.print');
    Route::get('purchase/pb/{id}/print', [PbPrintController::class, 'show'])->name('purchase.pb.print');
    Route::get('inventory/kmb/{id}/print', [KmbPrintController::class, 'show'])->name('inventory.kmb.print');
    Route::get('inventory/tmb/{id}/print', [TmbPrintController::class, 'show'])->name('inventory.tmb.print');
    Route::get('inventory/adjust/{id}/print', [PenyesuaianPrintController::class, 'show'])->name('inventory.adjust.print');
    Route::get('inventory/pengeluaran-lain/{id}/print', [PengeluaranLainPrintController::class, 'show'])->name('inventory.pengeluaran-lain.print');
    Route::get('sales/invoice/{id}/print', [InvoicePrintController::class, 'show'])->name('sales.invoice.print');
    Route::get('sales/invoice-mutasi/{id}/print', [InvoiceMutasiPrintController::class, 'show'])->name('sales.invoice-mutasi.print');
    Route::get('sales/sj/{id}/print', [SjPrintController::class, 'show'])->name('sales.sj.print');
    Route::get('purchase/pbc/{id}/print', [PbcPrintController::class, 'show'])->name('purchase.pbc.print');
    Route::get('purchase/pkb/{id}/print', [PkbPrintController::class, 'show'])->name('purchase.pkb.print');
    Route::get('pabrik/produksi/{id}/print', [ProduksiPrintController::class, 'show'])->name('pabrik.produksi.print');
    Route::get('pabrik/jop/{id}/print', [JopPrintController::class, 'show'])->name('pabrik.jop.print');
    Route::get('finance/pengajuan-dana/{id}/print', [PengajuanDanaPrintController::class, 'show'])->name('finance.pengajuan-dana.print');
    // Kas Masuk & Kas Keluar memakai blade YG SAMA (lihat docblock KasBankPrintController).
    // Bank Masuk/Keluar belum - dokumen Bank py field giro/transfer yg harus ikut tercetak.
    Route::get('finance/kas-masuk/{id}/print', [KasBankPrintController::class, 'kasMasuk'])->name('finance.kas-masuk.print');
    Route::get('finance/kas-keluar/{id}/print', [KasBankPrintController::class, 'kasKeluar'])->name('finance.kas-keluar.print');

    Route::get('reports/penjualan-per-barang', [ReportController::class, 'penjualanPerBarang'])->name('reports.penjualan-per-barang');
    Route::get('reports/penjualan-per-barang/excel', [ReportController::class, 'penjualanPerBarangExcel'])->name('reports.penjualan-per-barang.excel');
    Route::get('reports/ip-tindakan-produk', [ReportController::class, 'ipTindakanProduk'])->name('reports.ip-tindakan-produk');
    Route::get('reports/ip-tindakan-produk/excel', [ReportController::class, 'ipTindakanProdukExcel'])->name('reports.ip-tindakan-produk.excel');
    Route::get('reports/daftar-penjualan-tunai', [ReportController::class, 'daftarPenjualanTunai'])->name('reports.daftar-penjualan-tunai');
    Route::get('reports/daftar-penjualan-tunai/excel', [ReportController::class, 'daftarPenjualanTunaiExcel'])->name('reports.daftar-penjualan-tunai.excel');

    // Lookup JSON untuk <x-search-select> (auth saja, tanpa filter hak menu).
    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('wilayah/kota', [LookupController::class, 'wilayahKota'])->name('wilayah.kota');
        Route::get('wilayah/kecamatan', [LookupController::class, 'wilayahKecamatan'])->name('wilayah.kecamatan');
        Route::get('karyawan', [LookupController::class, 'karyawan'])->name('karyawan');
        Route::get('vendor', [LookupController::class, 'vendor'])->name('vendor');
        Route::get('termin', [LookupController::class, 'termin'])->name('termin');
        Route::get('kontak', [LookupController::class, 'kontak'])->name('kontak');
        Route::get('item-kode', [LookupController::class, 'itemKode'])->name('item-kode');
        Route::get('item-id', [LookupController::class, 'itemId'])->name('item-id');
        Route::get('item-serial', [LookupController::class, 'itemSerial'])->name('item-serial');
        Route::get('user', [LookupController::class, 'user'])->name('user');
        Route::get('lain/{tipe}', [LookupController::class, 'lain'])->name('lain');
        Route::get('coa/semua', [LookupController::class, 'coaSemua'])->name('coa.semua');
        Route::get('coa/rekening', [LookupController::class, 'coaRekening'])->name('coa.rekening');
        Route::get('coa/biaya', [LookupController::class, 'coaBiaya'])->name('coa.biaya');
        Route::get('bank', [LookupController::class, 'bank'])->name('bank');
        Route::get('kontak-keuangan', [LookupController::class, 'kontakKeuangan'])->name('kontak-keuangan');
    });
});
