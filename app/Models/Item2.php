<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bitem2 - data tambahan item (1:1 dengan bitem lewat I2IDITEM).
 */
class Item2 extends Model
{
    protected $table = 'bitem2';

    protected $primaryKey = 'I2ID';

    public $timestamps = false;

    protected $guarded = ['I2ID'];
}
