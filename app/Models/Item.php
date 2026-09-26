<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * bitem - master item / barang / tindakan (POS).
 *
 * CATATAN: kolom tabel bitem semuanya HURUF BESAR (IID, IKODE, INAMA, ...).
 * SQL MySQL tidak peka huruf besar/kecil, tapi Eloquent membaca hasil
 * `SELECT *` dengan nama kolom sesuai definisi -> harus pakai UPPERCASE.
 *
 * Data tambahan di:
 *  - bitem2   (I2IDITEM)  : COA pendapatan, harga marketplace, SKU marketplace
 *  - bitemdaps (IDITEM)   : 7 komponen fee DAPS
 *
 * ICABANG = daftar GID cabang dibungkus "|", mis. "|1|3|18|".
 * ISTATUS: 0=Aktif, 1=Tidak Aktif, 2=Tidak Terpakai.
 * ITIPEITEM: 0=Stok, 1=Non Stok.
 */
class Item extends Model
{
    protected $table = 'bitem';

    protected $primaryKey = 'IID';

    public $timestamps = false;

    protected $guarded = ['IID'];

    /** FK yang harus NULL bila kosong (bukan 0). */
    public const NULLABLE_FK = [
        'IJENISITEM', 'IJENISITEMCOA', 'IKELOMPOKBARU', 'IKELOMPOK2020',
        'IKELOMPOK21', 'IKELOMPOK23', 'ICOA2021', 'IKOMISI2020', 'IJENISDIWEB',
    ];

    public function extra(): HasOne
    {
        return $this->hasOne(Item2::class, 'I2IDITEM', 'IID');
    }

    public function daps(): HasOne
    {
        return $this->hasOne(ItemDaps::class, 'IDITEM', 'IID');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'ISATUAN', 'SID');
    }

    public function scopeAktif($query)
    {
        return $query->where('ISTATUS', 0);
    }

    /** @return list<int> GID cabang dari string "|1|3|" */
    public function branchIds(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            explode('|', (string) $this->ICABANG)
        )));
    }

    public static function packBranchIds(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids ? '|' . implode('|', $ids) . '|' : '';
    }

    public static function statusLabel(int $status): string
    {
        return [0 => 'Aktif', 1 => 'Tidak Aktif', 2 => 'Tidak Terpakai'][$status] ?? '-';
    }
}
