<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lv_activity_log - jejak aktivitas user aplikasi Laravel (login, CRUD, dll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();      // auser.UID
            $table->string('user_label', 100)->nullable();       // snapshot nama/UKODE
            $table->string('action', 50);                        // login, create, update, delete, ...
            $table->string('module', 50)->nullable();            // admin/menu, admin/user, ...
            $table->string('entity_id', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['module', 'action']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_activity_log');
    }
};
