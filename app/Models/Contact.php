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

    /** Batas kandidat yang diambil tahap pertama - jauh di atas 30 baris yang ditampilkan. */
    private const BATAS_KANDIDAT = 300;

    /**
     * Terapkan pencarian ke query builder pada bkontak (321rb+ baris).
     *
     * ## Kenapa DUA TAHAP, bukan satu WHERE
     *
     * Versi lama menyusun satu `WHERE MATCH(...) OR kolom LIKE 'q%'`. Itu **tidak bisa
     * memakai index FULLTEXT** - MySQL malah memindai `idx_bkontak_knama` demi
     * `ORDER BY KNAMA LIMIT 30` sambil menghitung MATCH **per baris**. Makin SEDIKIT
     * hasilnya makin lambat, karena LIMIT tidak pernah terpenuhi:
     *
     *     q='nmw'            7 ms      (30 hasil ketemu di awal abjad)
     *     q='Effendi'   16.543 ms
     *     q='petogogan' 69.723 ms      <- dilaporkan user sbg "diam saja"
     *
     * Sekarang: tahap 1 mengambil KID kandidat lewat query yang MASING-MASING bisa pakai
     * index-nya sendiri, tahap 2 menyaring query pemanggil dgn `whereIn(KID)` (primary key).
     * Kandidat diurutkan KNAMA lalu dipotong {@see BATAS_KANDIDAT}, jadi `ORDER BY KNAMA
     * LIMIT 30` di pemanggil tetap memberi 30 nama pertama yang benar.
     *
     * ## Kolom kode HANYA dicari kalau kata kuncinya mengandung ANGKA
     *
     * Bukan sekadar demi kecepatan - ini memperbaiki HASIL. Di data nyata
     * `KKODE LIKE 'nmw%'` cocok ke **204.777** baris dan `KIDPASIEN LIKE 'nmw%'` ke
     * **315.168** baris (hampir seluruh tabel), sehingga mencari "nmw" dulu mengembalikan
     * 30 kontak ACAK, bukan hasil pencarian. Kode/no member/telepon praktis selalu
     * mengandung angka, jadi kata kunci huruf-semua diperlakukan sbg pencarian NAMA saja.
     *
     *     q huruf-semua  ~0,1 detik   (FULLTEXT saja)
     *     q ber-angka    ~1,5 detik   (FULLTEXT + kolom kode)
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

        $query->whereIn("{$alias}.KID", static::kandidatId($q, $extraColumns));
    }

    /**
     * KID kandidat hasil pencarian - tiap sumber dijalankan TERPISAH supaya bisa memakai
     * index-nya sendiri, lalu digabung `UNION`. Setiap cabang dibatasi juga: tanpa itu,
     * satu cabang yang cocok ratusan ribu baris membuat penggabungan & pengurutannya berat.
     *
     * @param list<string> $extraColumns
     * @return list<int>
     */
    public static function kandidatId(string $q, array $extraColumns = []): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        /* Karakter operator BOOLEAN MODE DIBUANG, bukan di-escape. `addcslashes()` yg dipakai
         | versi lama TIDAK menetralkannya - MySQL tidak mengenal backslash sbg escape di
         | boolean mode, jadi mencari "NMW-NH07941" terbaca sbg `+NMW` DAN `-NH07941`
         | (tanda minus = KECUALIKAN), hasilnya semua nama ber-"NMW" ikut terbawa dan baris
         | yg dicari justru tenggelam. Dibuang jadi spasi -> "NMW NH07941" -> `+NMW* +NH07941*`. */
        $bersih = preg_replace('/[+\-<>()~*"@]+/', ' ', $q);
        $kata = array_values(array_filter(preg_split('/\s+/', $bersih)));
        $boolean = implode(' ', array_map(fn ($w) => '+' . $w . '*', $kata));

        $cabang = [];

        if ($boolean !== '') {
            $cabang[] = \Illuminate\Support\Facades\DB::table('bkontak')
                ->select('KID', 'KNAMA')
                ->whereRaw('MATCH(KNAMA) AGAINST(? IN BOOLEAN MODE)', [$boolean])
                ->limit(self::BATAS_KANDIDAT);
        }

        // Lihat docblock applySearch(): kolom kode hanya relevan kalau ada angkanya.
        if (preg_match('/\d/', $q)) {
            foreach (array_merge(['KKODE', 'KIDPASIEN', 'KNOMEMBER', 'K1TELP1'], $extraColumns) as $kolom) {
                $cabang[] = \Illuminate\Support\Facades\DB::table('bkontak')
                    ->select('KID', 'KNAMA')
                    ->where($kolom, 'like', "{$q}%")
                    ->limit(self::BATAS_KANDIDAT);
            }
        }

        if ($cabang === []) {
            return [];
        }

        $gabung = array_shift($cabang);
        foreach ($cabang as $c) {
            $gabung->union($c);
        }

        return \Illuminate\Support\Facades\DB::query()
            ->fromSub($gabung, 'c')
            ->orderBy('KNAMA')
            ->limit(self::BATAS_KANDIDAT)
            ->pluck('KID')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
