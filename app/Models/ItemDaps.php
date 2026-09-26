<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bitemdaps - 7 komponen fee DAPS per item (1:1 dengan bitem lewat IDITEM).
 */
class ItemDaps extends Model
{
    protected $table = 'bitemdaps';

    protected $primaryKey = 'IDID';

    public $timestamps = false;

    protected $guarded = ['IDID'];

    public const FEE_COLUMNS = [
        'IDDAPSFEEDOKTER', 'IDDAPSFEEPERAWAT', 'IDDAPSALKES',
        'IDDAPSFACILITY', 'IDDAPSEQUIPMENT', 'IDDAPSJASAKLINIK', 'IDDAPSSALESCOM',
    ];
}
