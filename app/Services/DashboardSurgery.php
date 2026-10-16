<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Surgery (permintaan user 2026-10-16) - memantau transaksi SURGERY yg dibayar
 * cicil. Aturan bisnisnya di docblock `Surgery`.
 *
 * ## Rantai
 * Tiap nota surgery (`SUSURGERYDP <> 0`) menunjuk nota sebelumnya lewat `SUSURGERYDPIDU`.
 * Satu RANTAI = satu surgery: dari nota pertama s/d nota yg belum dilanjutkan (ujung).
 * Rantai disusun di PHP, bukan CTE rekursif - jumlah nota surgery kecil (puluhan/bulan) &
 * nota sebelumnya bisa di CABANG LAIN (VB6 mencari di semua cabang), jadi semua nota
 * surgery dimuat lalu disambung, baru disaring per cabang ujungnya.
 *
 * ## Angka (mengikuti logika VB6 `eFrmPOS2`, bukan rumus kas per metode bayar)
 *   sudah dibayar (kumulatif) di nota = SUTOTALTRANSAKSI - SUNILAIPIUTANG
 *   dibayar DI nota itu               = kumulatif - SUSURGERYDPPEMBAYARAN (bawaan nota sblm)
 *   nilai surgery                     = total nota UJUNG (bisa berubah kalau tindakan ditambah)
 *   sisa piutang                      = piutang nota ujung
 * Contoh nyata rantai BL-IP26090055 -> 0287 -> 0539 -> 0614: 9.883.502 + 9.566.380 +
 * 19.123.655 + 6.426.463 = 45.000.000 (pas dgn nilai surgery).
 */
class DashboardSurgery
{
    public const STATUS = ['berjalan' => 'Berjalan', 'selesai' => 'Selesai', 'cek' => 'Perlu dicek'];

    /**
     * Semua rantai surgery sesuai saringan.
     *
     * @param list<int> $cabang `[]` = tanpa batas (pemanggil sudah menerapkan hak cabang)
     * @param string    $status '' | berjalan | selesai | cek
     */
    public function rantai(array $cabang, string $dari, string $sampai, string $status = ''): Collection
    {
        $nota = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->whereRaw('COALESCE(u.SUSURGERYDP, 0) <> 0')
            ->get([
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUKONTAK as pasienId', 'k.KNAMA as pasien', 'u.SUCABANG as cabangId', 'g.GNAMA as cabang',
                'u.SUTOTALTRANSAKSI as total', 'u.SUNILAIPIUTANG as piutang',
                'u.SUSURGERYDPIDU as sebelumId', 'u.SUSURGERYDPPEMBAYARAN as bawaan',
            ])
            ->map(function ($n) {
                $n->total = (float) $n->total;
                $n->piutang = (float) $n->piutang;
                $n->bawaan = (float) $n->bawaan;
                $n->kumulatif = $n->total - $n->piutang;
                $n->dibayar = $n->kumulatif - $n->bawaan;

                return $n;
            })
            ->keyBy('id');

        // Ujung = nota yg tidak dilanjutkan nota mana pun.
        $punyaLanjutan = $nota->pluck('sebelumId')->filter()->map(fn ($v) => (int) $v)->flip();

        return $nota->reject(fn ($n) => $punyaLanjutan->has((int) $n->id))
            ->map(fn ($ujung) => $this->susun($ujung, $nota))
            ->filter(fn ($r) => $cabang === [] || in_array((int) $r->cabangId, $cabang, true))
            // Rantai masuk periode kalau ADA nota-nya di rentang tanggal itu.
            ->filter(fn ($r) => collect($r->nota)->contains(fn ($n) => substr((string) $n->tanggal, 0, 10) >= $dari
                && substr((string) $n->tanggal, 0, 10) <= $sampai))
            ->filter(fn ($r) => $status === '' || $r->status === $status)
            ->sortBy([['statusUrut', 'asc'], ['mulai', 'desc']])
            ->values();
    }

    /** Telusuri dari nota ujung ke belakang, lalu hitung ringkasannya. */
    private function susun(object $ujung, Collection $nota): object
    {
        $rantai = [$ujung];
        $kini = $ujung;
        $putus = false;
        // Batas 50 langkah - pengaman kalau data legacy punya rujukan melingkar.
        for ($i = 0; $i < 50 && $kini->sebelumId; $i++) {
            $sebelum = $nota->get((int) $kini->sebelumId);
            if (! $sebelum) {
                $putus = true; // nota sebelumnya batal / bukan surgery / di luar data
                break;
            }
            array_unshift($rantai, $sebelum);
            $kini = $sebelum;
        }
        $awal = $rantai[0];

        $cek = [];
        if ($ujung->total <= 0) {
            $cek[] = 'Nilai surgery 0';
        }
        if ($putus || ($awal->bawaan > 0 && ! $awal->sebelumId)) {
            $cek[] = 'Nota sebelumnya tidak ditemukan (sudah dibayar sblm ' . number_format($awal->bawaan, 0, ',', '.') . ')';
        }
        $jumlahBayar = array_sum(array_map(fn ($n) => $n->dibayar, $rantai));
        if (! $putus && abs($jumlahBayar - $ujung->kumulatif) > 1) {
            $cek[] = 'Jumlah cicilan tidak sama dgn total dibayar';
        }

        $status = $cek !== [] ? 'cek' : ($ujung->piutang > 0 ? 'berjalan' : 'selesai');

        return (object) [
            'id'          => (int) $ujung->id,
            'pasien'      => $ujung->pasien,
            'cabangId'    => (int) $ujung->cabangId,
            'cabang'      => $ujung->cabang,
            'notaAwal'    => $awal->nomor,
            'mulai'       => substr((string) $awal->tanggal, 0, 10),
            'notaAkhir'   => $ujung->nomor,
            'terakhir'    => substr((string) $ujung->tanggal, 0, 10),
            'jmlBayar'    => count($rantai),
            'nilai'       => $ujung->total,
            'dibayar'     => $ujung->kumulatif,
            'sisa'        => $ujung->piutang,
            'persen'      => $ujung->total > 0 ? min(100, $ujung->kumulatif / $ujung->total * 100) : 0,
            'status'      => $status,
            'statusUrut'  => ['berjalan' => 0, 'cek' => 1, 'selesai' => 2][$status],
            'cek'         => $cek,
            // Lama berjalan: dari nota pertama s/d hari ini (berjalan) / s/d pelunasan (selesai).
            'hari'        => (int) floor((strtotime($ujung->piutang > 0 ? date('Y-m-d') : substr((string) $ujung->tanggal, 0, 10))
                                - strtotime(substr((string) $awal->tanggal, 0, 10))) / 86400),
            'nota'        => $rantai,
        ];
    }

    /** Ringkasan kartu atas. */
    public function ringkas(Collection $rantai): object
    {
        $jalan = $rantai->where('status', 'berjalan');

        return (object) [
            'jumlah'      => $rantai->count(),
            'nilai'       => $rantai->sum('nilai'),
            'dibayar'     => $rantai->sum('dibayar'),
            'sisa'        => $rantai->sum('sisa'),
            'berjalan'    => $jalan->count(),
            'sisaJalan'   => $jalan->sum('sisa'),
            'selesai'     => $rantai->where('status', 'selesai')->count(),
            'cek'         => $rantai->where('status', 'cek')->count(),
        ];
    }

    /**
     * Item nota ujung + status alkes tindakan wajib alkes (aturan sama dgn Closing Harian IP:
     * AL tidak batal lewat `SDIDALKESNYA`). Alkes hanya relevan kalau nota itu SUDAH LUNAS
     * (hari operasi) - lihat `Surgery`.
     */
    public function item(int $suid): Collection
    {
        return DB::table('fstokd as d')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('fstoku as al', 'al.SUID', '=', 'd.SDIDALKESNYA')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'd.SDDOKTER')
            ->where('d.SDIDSU', $suid)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDKELUAR as qty', 'd.SDHARGA as harga',
                'd.SDDISKON as diskon', 'dr.KNAMA as dokter',
                DB::raw('COALESCE(i.IADAALKES, 0) as wajibAlkes'),
                DB::raw('CASE WHEN al.SUID IS NOT NULL AND al.SUSTATUS <> 9 THEN al.SUNOTRANSAKSI END as noAlkes'),
            ]);
    }
}
