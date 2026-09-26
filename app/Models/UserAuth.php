<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * lv_user_auth - hash password modern per user (auser.UID).
 */
class UserAuth extends Model
{
    protected $table = 'lv_user_auth';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id', 'password_hash', 'must_change', 'last_login_at', 'last_login_ip',
    ];

    protected $casts = [
        'must_change'   => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'UID');
    }
}
