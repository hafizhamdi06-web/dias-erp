<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * lv_user_menu - hak akses per user per menu (view/add/edit/delete/print/approve).
 */
class UserMenu extends Model
{
    protected $table = 'lv_user_menu';

    public const ABILITIES = ['view', 'add', 'edit', 'delete', 'print', 'approve'];

    protected $fillable = [
        'user_id', 'menu_id',
        'can_view', 'can_add', 'can_edit', 'can_delete', 'can_print', 'can_approve',
    ];

    protected $casts = [
        'can_view'    => 'boolean',
        'can_add'     => 'boolean',
        'can_edit'    => 'boolean',
        'can_delete'  => 'boolean',
        'can_print'   => 'boolean',
        'can_approve' => 'boolean',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * Peta hak akses user: [menu_id => ['view'=>bool, 'add'=>bool, ...]].
     */
    public static function abilityMapForUser(int $userId): array
    {
        $map = [];

        foreach (static::where('user_id', $userId)->get() as $row) {
            $map[$row->menu_id] = [
                'view'    => $row->can_view,
                'add'     => $row->can_add,
                'edit'    => $row->can_edit,
                'delete'  => $row->can_delete,
                'print'   => $row->can_print,
                'approve' => $row->can_approve,
            ];
        }

        return $map;
    }
}
