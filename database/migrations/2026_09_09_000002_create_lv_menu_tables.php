<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu & hak akses per user untuk aplikasi Laravel.
 *
 * Tabel baru sendiri (prefix lv_) supaya tidak bentrok dengan menu CI3
 * (amenu/ausermenu) maupun CI4 (a4menu/a4usermenu) di DB yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_menu', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('segment_key', 100)->unique();   // kunci idempoten utk seeder
            $table->string('title', 100);
            $table->string('route', 191)->nullable();        // path relatif, mis. "master/item"
            $table->string('icon', 50)->nullable();          // kelas FontAwesome, mis. "fas fa-box"
            $table->enum('menu_type', ['group', 'link'])->default('link');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
            $table->foreign('parent_id')->references('id')->on('lv_menu')->nullOnDelete();
        });

        Schema::create('lv_user_menu', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');   // auser.UID
            $table->unsignedBigInteger('menu_id');
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_print')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'menu_id']);
            $table->index('user_id');
            $table->foreign('menu_id')->references('id')->on('lv_menu')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_user_menu');
        Schema::dropIfExists('lv_menu');
    }
};
