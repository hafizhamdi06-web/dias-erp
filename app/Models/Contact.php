<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * bkontak - master kontak legacy: dipakai untuk Pasien, Pelanggan, Supplier, Karyawan.
 * Dibedakan lewat KTIPE (-> bkontaktipe.KTPASIEN). Soft delete = KAKTIF=0.
 */
class Contact extends Model
{
    protected $table = 'bkontak';

    protected $primaryKey = 'KID';

    public $timestamps = false;

    protected $guarded = ['KID'];

    /** Tipe kontak = karyawan. */
    public const TIPE_KARYAWAN = 4;

    public function tipe()
    {
        return $this->belongsTo(ContactType::class, 'KTIPE', 'KTID');
    }

    public function scopeAktif($query)
    {
        return $query->where('KAKTIF', '<>', 0);
    }

    /** KTID tipe yang tergolong pasien (bkontaktipe.KTPASIEN=1). */
    public static function pasienTypeIds(): array
    {
        return ContactType::where('KTPASIEN', 1)->pluck('KTID')->all();
    }

    /**
     * Terapkan pencarian ke query builder pada bkontak (315rb+ baris).
     *
     * PENTING: KNAMA TIDAK BOLEH di-LIKE '%q%' (leading wildcard) - itu full table
     * scan dan bisa timeout (30 detik). Pakai FULLTEXT `idx_ft_knama` (BOOLEAN MODE,
     * tiap kata di-AND-kan sbg prefix match). Kolom lain (kode/id pasien/no member/
     * telp) TIDAK ter-index tapi aman dipakai LIKE 'q%' (prefix, tanpa leading %).
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $alias alias tabel bkontak di query (mis. 'k' utk 'bkontak as k')
     * @param list<string> $extraColumns kolom prefix-LIKE tambahan (mis. ['KNOKTP'])
     */
    public static function applySearch($query, string $q, string $alias = 'bkontak', array $extraColumns = []): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $words = array_values(array_filter(preg_split('/\s+/', $q)));
        $boolQuery = implode(' ', array_map(fn ($w) => '+' . addcslashes($w, '+-<>()~*"@') . '*', $words));
        $columns = array_merge(['KKODE', 'KIDPASIEN', 'KNOMEMBER', 'K1TELP1'], $extraColumns);

        $query->where(function ($w) use ($alias, $q, $boolQuery, $columns) {
            if ($boolQuery !== '') {
                $w->whereRaw("MATCH({$alias}.KNAMA) AGAINST(? IN BOOLEAN MODE)", [$boolQuery]);
            }
            foreach ($columns as $col) {
                $w->orWhere("{$alias}.{$col}", 'like', "{$q}%");
            }
        });
    }
}
