<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lv_perubahan_harga_log` - jejak TERAPKAN / KEMBALIKAN dokumen Perubahan Harga.
 *
 * Dokumennya sendiri disimpan di tabel legacy `gperubahanhargau` / `gperubahanhargad`
 * (struktur VB6 `fFrmPerubahanHarga`), yg TIDAK punya kolom "kapan diterapkan / oleh siapa"
 * dan tidak boleh diubah (aturan proyek: tabel legacy tidak di-ALTER). Jadi riwayatnya di
 * sini.
 *
 * `rincian` (JSON) = keadaan per item SAAT aksi dijalankan: harga sebelum & sesudah, dan
 * `ITANGGALRUBAHHARGA` sebelumnya - inilah yg dipakai "Kembalikan" untuk memulihkan tanggal.
 * Item yg DILEWATI (harganya sudah diubah pihak lain) ikut dicatat beserta alasannya.
 *
 * Tanpa foreign key ke `gperubahanhargau` (pola semua tabel `lv_`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_perubahan_harga_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('suid')->index();
            $t->string('aksi', 20);
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('jumlah_item')->default(0);
            $t->unsignedInteger('jumlah_dilewati')->default(0);
            $t->longText('rincian')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_perubahan_harga_log');
    }
};
