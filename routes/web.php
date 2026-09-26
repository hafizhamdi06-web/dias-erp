<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\PoPrintController;
use App\Http\Controllers\PosReceiptController;
use App\Http\Controllers\PrPrintController;
use App\Http\Controllers\ReportController;
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

    Route::get('/', Workspace::class)->name('workspace');
    Route::get('dashboard', fn () => redirect()->route('workspace'))->name('dashboard');

    Route::get('sales/pos/receipt/{id}', [PosReceiptController::class, 'show'])->name('sales.pos.receipt');
    Route::get('inventory/pr/{id}/print', [PrPrintController::class, 'show'])->name('inventory.pr.print');
    Route::get('purchase/po/{id}/print', [PoPrintController::class, 'show'])->name('purchase.po.print');

    Route::get('reports/penjualan-per-barang', [ReportController::class, 'penjualanPerBarang'])->name('reports.penjualan-per-barang');
    Route::get('reports/penjualan-per-barang/excel', [ReportController::class, 'penjualanPerBarangExcel'])->name('reports.penjualan-per-barang.excel');

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
