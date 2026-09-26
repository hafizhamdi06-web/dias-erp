<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bsatuan - satuan barang.
 */
class Unit extends Model
{
    protected $table = 'bsatuan';

    protected $primaryKey = 'SID';

    public $timestamps = false;

    protected $guarded = ['SID'];

    /** @return \Illuminate\Support\Collection<int,\App\Models\Unit> */
    public static function options()
    {
        return static::orderBy('SKODE')->get(['SID', 'SKODE', 'SNAMA']);
    }
}
