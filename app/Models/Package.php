<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * epaketu - header master paket. Baris detail (item yg dibundel) ada di `epaketd`
 * (lihat PackageDetail) & pembatasan cabang di `epaketc` (dikelola langsung via
 * DB::table di PaketManager, sama pola spt emasterpromoc di Master Promo).
 *
 * TIDAK ADA layar Master Paket di CI3 sama sekali (dikonfirmasi via riset kode
 * menyeluruh) - fitur ini murni dikonsumsi read-only di POS legacy
 * (PJ_POS_HP::get_detail_paket()). Pemetaan kolom di bawah dikonfirmasi dari 2
 * sumber: (a) riset kode CI3 utk kolom yg BENAR2 dipakai di POS legacy, (b)
 * konfirmasi LANGSUNG dari user (AskUserQuestion) utk kolom yg nol referensi di
 * kode manapun - lihat memory proyek utk rincian per kolom.
 *
 * Kolom yg SENGAJA TIDAK dipakai di v1 (nol referensi + user pilih lewati):
 * PUITEM (tdk pernah dibaca dimanapun, tujuan aslinya tdk jelas), PFOTO, PUDKR
 * (user: "tidak terpakai"), PUBISASHARING (fitur sharing tampak mati, lihat
 * bsharingpaket/esharingpaket - nol referensi kode).
 */
class Package extends Model
{
    protected $table = 'epaketu';

    protected $primaryKey = 'PUID';

    public $timestamps = false;

    protected $guarded = ['PUID'];

    /**
     * PUJENISPELANGGAN - dikonfirmasi LANGSUNG oleh user (nilai 1="Member" adalah
     * satu2nya yg terkonfirmasi ada di kode POS legacy - `pos_2.js`: jenispasien==1
     * && kontaktipe!=12 -> ditolak; nilai lain tdk ada di kode, tapi user
     * mengonfirmasi arti lengkapnya sesuai daftar combo box klinik).
     */
    public const JENIS_PELANGGAN = [
        0 => 'Member dan Non Member',
        1 => 'Member',
        2 => 'Non Member',
        3 => 'Pasien Luar',
        4 => 'Distributor',
    ];

    public function details()
    {
        return $this->hasMany(PackageDetail::class, 'PDIDU', 'PUID');
    }

    /** Hitung ulang PUTOTALHARGA = jumlah PDSUBTOTAL semua baris detail paket ini. */
    public static function recalcTotal(int $packageId): void
    {
        $total = PackageDetail::where('PDIDU', $packageId)->sum('PDSUBTOTAL');
        static::whereKey($packageId)->update(['PUTOTALHARGA' => $total]);
    }
}
