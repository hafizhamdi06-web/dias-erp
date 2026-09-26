<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * lv_activity_log - jejak aktivitas user.
 */
class ActivityLog extends Model
{
    protected $table = 'lv_activity_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_label', 'action', 'module', 'entity_id', 'description', 'ip', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
