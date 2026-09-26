<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bgudang - cabang / gudang (legacy).
 */
class Branch extends Model
{
    protected $table = 'bgudang';

    protected $primaryKey = 'GID';

    public $timestamps = false;

    protected $guarded = ['GID'];

    public function scopeActive($query)
    {
        return $query->where('GAKTIF', '<>', 0);
    }

    /** @return \Illuminate\Support\Collection<int,\App\Models\Branch> */
    public static function options()
    {
        return static::active()->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA', 'GALAMAT1']);
    }
}
