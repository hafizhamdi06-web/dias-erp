<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * emasterpromou - header master promo (legacy). Detail kombinasi ada di `emasterpromod`
 * (belum digarap - lihat catatan di PromoManager).
 */
class Promo extends Model
{
    protected $table = 'emasterpromou';

    protected $primaryKey = 'MPUID';

    public $timestamps = false;

    protected $guarded = ['MPUID'];

    /** MPUJENISPELANGGAN - urutan combo box VB6 asli (0-indexed), JANGAN diubah urutannya. */
    public const JENIS_PELANGGAN = [
        0 => 'Member dan Non Member',
        1 => 'Member',
        2 => 'Non Member',
        3 => 'Pasien Luar',
        4 => 'Distributor',
    ];

    /** MPUJENISPROMO - urutan combo box VB6 asli (0-indexed), JANGAN diubah urutannya. */
    public const JENIS_PROMO = [
        0 => 'Biasa',
        1 => 'Kombinasi 1',
        2 => 'Kombinasi 2',
        3 => 'Per Jenis Item',
        4 => 'Kombinasi 3',
    ];
}
