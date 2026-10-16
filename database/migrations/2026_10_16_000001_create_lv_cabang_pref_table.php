<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lv_cabang_pref` - pengaturan per CABANG milik Laravel (pasangan `lv_user_pref`).
 *
 * Tabel BARU ber-prefix `lv_` - sengaja TIDAK menambah kolom ke `bgudang` (tabel legacy
 * dipakai bersama VB6/CI3/CI4, aturan proyek: tabel legacy tidak diubah).
 *
 * `gid` = `bgudang.GID`, kontak = `bkontak.KID` - TANPA foreign key, sama spt tabel `lv_`
 * lain (tabel legacy dikelola aplikasi lain, tidak boleh dikunci dari sini).
 *
 * Kebutuhan pertama (permintaan user 2026-10-16): Farmasi & Farmasi Asisten DEFAULT POS
 * per cabang - kasir cukup klik "Isi default cabang". Pengaturan cabang lain ditambahkan
 * ke tabel yg sama nanti, bukan tabel baru lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_cabang_pref', function (Blueprint $t) {
            $t->unsignedInteger('gid')->primary();
            $t->unsignedInteger('farmasi_kid')->nullable();
            $t->unsignedInteger('farmasi_asisten_kid')->nullable();
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_cabang_pref');
    }
};
