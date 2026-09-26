<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * emasterpromod - baris detail kombinasi promo (khusus Promo::JENIS_PROMO[1] "Kombinasi 1").
 * Pemetaan kolom & makna dikonfirmasi LANGSUNG oleh user (bukan tebakan dari kode CI3) - lihat
 * memory proyek. Item1-4 (MPDKELITEM1-4) & Pilihan1-4 (MPDPILIHAN1-4, pipe-delimited) menyimpan
 * `bitem.IKODE` sbg teks, BUKAN `bitem.IID`.
 */
class PromoDetail extends Model
{
    protected $table = 'emasterpromod';

    protected $primaryKey = 'MPDID';

    public $timestamps = false;

    protected $guarded = ['MPDID'];

    public function promo()
    {
        return $this->belongsTo(Promo::class, 'MPDIDU', 'MPUID');
    }
}
