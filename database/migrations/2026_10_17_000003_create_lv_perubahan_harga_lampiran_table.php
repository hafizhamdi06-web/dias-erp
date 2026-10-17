<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lv_perubahan_harga_lampiran` - bukti (surat/IOM kenaikan harga, gambar atau PDF) yg
 * dilampirkan ke dokumen Perubahan Harga UNTUK AUDIT (permintaan user 2026-10-17).
 *
 * File-nya di disk `local` (= `storage/app/private`, TIDAK bisa diakses lewat URL langsung),
 * dibuka lewat `PerubahanHargaLampiranController` yg memeriksa hak akses. `path` selalu
 * disusun server - nama file asli user hanya disimpan sbg `nama_asli` utk ditampilkan.
 *
 * `sha256` = sidik isi file saat diunggah: bukti bahwa file yg dibuka nanti masih sama dgn
 * yg dilampirkan (dan menolak file kembar di dokumen yg sama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_perubahan_harga_lampiran', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('suid')->index();
            $t->string('nama_asli', 255);
            $t->string('path', 255);
            $t->string('mime', 100);
            $t->unsignedInteger('ukuran');
            $t->char('sha256', 64);
            $t->unsignedInteger('user_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_perubahan_harga_lampiran');
    }
};
