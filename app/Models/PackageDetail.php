<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * epaketd - baris detail paket (item yg dibundel dalam 1 paket, epaketu).
 *
 * Pemetaan dikonfirmasi via riset kode CI3 (PJ_POS_HP::get_detail_paket(),
 * pos_2.js) + konfirmasi user utk kolom yg nol referensi di kode - lihat
 * memory proyek & docblock Package.
 *
 * PDITEM = FK `bitem.IID` (integer, BEDA dari pola "Pilihan" milik Promo yg
 * simpan IKODE teks). PDPILIHAN = daftar KODE item alternatif dipisah `|`
 * (pola sama spt Promo's "Pilihan"), MESKI PDITEM sendiri simpan ID.
 *
 * PDQTY = qty utk kedatangan/sesi PERTAMA, PDQTYTINDAKAN = qty utk kedatangan
 * ke-2 dst (dikonfirmasi `pos_2.js`: kedatangan==0 pakai qtydetil, selain itu
 * pakai qtydetiltindakan) - kunci utama nanti utk fitur "tarik paket" di POS.
 *
 * Kolom yg SENGAJA TIDAK dipakai di v1 (nol referensi kode + user: "tidak
 * dipakai"): PDALKESIKUT, PDPAKAIJAM, PDJAM1, PDJAM2.
 */
class PackageDetail extends Model
{
    protected $table = 'epaketd';

    protected $primaryKey = 'PDID';

    public $timestamps = false;

    protected $guarded = ['PDID'];

    public function package()
    {
        return $this->belongsTo(Package::class, 'PDIDU', 'PUID');
    }
}
