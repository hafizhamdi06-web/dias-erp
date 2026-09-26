<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bkontaktipe - tipe kontak. KTPASIEN=1 -> tipe pasien.
 */
class ContactType extends Model
{
    protected $table = 'bkontaktipe';

    protected $primaryKey = 'KTID';

    public $timestamps = false;

    protected $guarded = ['KTID'];
}
