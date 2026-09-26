<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lv_user_auth - hash password modern untuk aplikasi Laravel.
 *
 * Tabel `auser` legacy (kolom UPASSWORD = MD5 mentah) TIDAK diubah supaya CI3 & CI4
 * tetap jalan. Saat user login pertama kali lewat Laravel, hash bcrypt-nya ditulis
 * ke sini ("upgrade saat login").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_user_auth', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary(); // auser.UID
            $table->string('password_hash', 255);
            $table->boolean('must_change')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_user_auth');
    }
};
