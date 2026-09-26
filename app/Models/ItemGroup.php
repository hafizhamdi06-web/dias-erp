<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bitemkelompok2020 - kelompok item (dipakai POS untuk pengelompokan & komisi).
 */
class ItemGroup extends Model
{
    protected $table = 'bitemkelompok2020';

    protected $primaryKey = 'IK2ID';

    public $timestamps = false;

    protected $guarded = ['IK2ID'];

    /** @return \Illuminate\Support\Collection<int,\App\Models\ItemGroup> */
    public static function options()
    {
        return static::orderBy('IK2KODE')->get(['IK2ID', 'IK2KODE', 'IK2KODE_REPORT']);
    }
}
