<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lv_user_pref` - preferensi tampilan/cetak per user.
 *
 * Tabel BARU ber-prefix `lv_` (aturan anti-tabrakan 3 framework). Sengaja TIDAK menambah
 * kolom ke `auser`: tabel itu dipakai bersama CI3 & CI4, dan berkas struktur production
 * (`2026-09-28_01_struktur.sql`) berjanji TIDAK mengubah satu pun tabel legacy.
 *
 * `user_id` mengacu ke `auser.UID` - TANPA foreign key, sama spt `lv_user_auth`/`lv_user_menu`
 * (auser dikelola aplikasi lain, tidak boleh dikunci dari sini).
 *
 * Dibuat utk kebutuhan pertama: pilihan ukuran struk POS. Kolom preferensi lain
 * ditambahkan ke tabel yang sama nanti, bukan bikin tabel baru lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_user_pref', function (Blueprint $t) {
            $t->unsignedInteger('user_id')->primary();

            /*
             * Ukuran struk POS: '58' = printer termal 58mm, 'a5' = setengah A4 / LX300.
             * NULL = ikut default aplikasi (`config('pos.struk_default')`), jadi satu baris
             * bisa dibuat lebih dulu untuk preferensi lain tanpa memaksa memilih ukuran struk.
             */
            $t->string('struk_pos', 10)->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_user_pref');
    }
};
