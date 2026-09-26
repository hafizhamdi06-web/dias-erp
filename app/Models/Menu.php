<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

/**
 * lv_menu - pohon menu aplikasi Laravel (AdminLTE sidebar + basis hak akses).
 */
class Menu extends Model
{
    protected $table = 'lv_menu';

    protected $fillable = [
        'parent_id', 'segment_key', 'title', 'route', 'icon',
        'menu_type', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'parent_id'  => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function isGroup(): bool
    {
        return $this->menu_type === 'group';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Semua menu aktif, terurut - siap dibangun jadi pohon.
     *
     * @return \Illuminate\Support\Collection<int,\App\Models\Menu>
     */
    public static function activeOrdered()
    {
        return static::active()
            ->orderByRaw('COALESCE(parent_id, 0)')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
