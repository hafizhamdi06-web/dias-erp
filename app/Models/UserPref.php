<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * lv_user_pref - preferensi tampilan/cetak per user (`auser.UID`).
 *
 * Pola sama `UserAuth`: primary key `user_id`, bukan auto-increment. Preferensi baru
 * ditambahkan sebagai KOLOM di tabel ini, jangan bikin tabel baru lagi.
 */
class UserPref extends Model
{
    protected $table = 'lv_user_pref';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['user_id', 'struk_pos'];
}
