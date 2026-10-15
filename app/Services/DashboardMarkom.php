<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka untuk Dashboard Penjualan Markom (permintaan user 2026-10-15) - tiga sub menu:
 * Penjualan Total, Penjualan Komparasi, Penjualan Promo.
 *
 * ## Definisi
 *   Nilai Penjualan -> `DashboardPenjualan::ekspresiPenjualan()` - DIPAKAI ULANG, bukan
 *                      disalin, supaya angka Markom selalu sama dgn Dashboard Penjualan.
 *   Jumlah Pasien   -> 1 pasien per HARI = 1: `COUNT(DISTINCT kontak#tanggal)`, hanya
 *                      baris yg bernilai jual (qty x (harga - diskon) > 0). Konvensi yg
 *                      sama dgn laporan IP Tindakan Produk (`ReportController`).
 *
 * Basis sama dgn Dashboard Penjualan: `fstoku.SUSUMBER='IP'`, `SUSTATUS<>9`, baris kepala
 * alkes dibuang (`SDKEPALAALKES=0`).
 *
 * Catatan pasien per cabang: pasien yg datang ke DUA cabang di hari yg sama dihitung 1 di
 * tiap cabang, jadi jumlah baris per cabang bisa sedikit melebihi angka TOTAL - total
 * dihitung dari seluruh data, bukan menjumlah baris.
 */
class DashboardMarkom
{
    /**
     * @param list<int> $cabang `[]` = tanpa batas cabang (pemanggil sudah menerapkan hak
     *                          akses cabang SEBELUM memanggil - lihat `MarkomFilter`).
     */
    private function dasar(string $dari, string $sampai, array $cabang)
    {
        return DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bmerchant as mc', 'mc.MCKODE', '=', 'u.SUMERCHANTJENIS')
            ->whereRaw('COALESCE(d.SDKEPALAALKES, 0) = 0')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->whereBetween('u.SUTANGGAL', [$dari, $sampai . ' 23:59:59'])
            ->when($cabang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $cabang));
    }

    /** Kolom Penjualan + Pasien + Transaksi - sama di semua sub menu. */
    private function ukuran(): array
    {
        $jual = app(DashboardPenjualan::class)->ekspresiPenjualan();

        return [
            DB::raw("SUM({$jual}) as penjualan"),
            DB::raw("COUNT(DISTINCT CASE WHEN d.SDKELUAR * (d.SDHARGA - d.SDDISKON) > 0
                        THEN CONCAT(u.SUKONTAK, '#', DATE(u.SUTANGGAL)) END) as pasien"),
            DB::raw('COUNT(DISTINCT u.SUID) as transaksi'),
        ];
    }

    private function angka(object $r): object
    {
        $r->penjualan = (float) ($r->penjualan ?? 0);
        $r->pasien = (int) ($r->pasien ?? 0);
        $r->transaksi = (int) ($r->transaksi ?? 0);

        return $r;
    }

    /** Satu baris total seluruh saringan. */
    public function total(string $dari, string $sampai, array $cabang): object
    {
        return $this->angka($this->dasar($dari, $sampai, $cabang)->first($this->ukuran()));
    }

    /** Per cabang, diurutkan dari penjualan terbesar. Kunci koleksi = GID. */
    public function perCabang(string $dari, string $sampai, array $cabang): Collection
    {
        return $this->dasar($dari, $sampai, $cabang)
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->groupBy('u.SUCABANG', 'g.GNAMA')
            ->orderByDesc('penjualan')
            ->get(array_merge([DB::raw('u.SUCABANG as id'), DB::raw('g.GNAMA as nama')], $this->ukuran()))
            ->map(fn ($r) => $this->angka($r))
            ->keyBy('id');
    }

    /**
     * Per promo. Jalur promo sama dgn `DashboardPenjualan::infoTransaksi()`:
     * `fstokd.SDIDPROMO -> emasterpromod.MPDID`, naik ke `emasterpromou` (nama promo ada di
     * induknya). Baris tanpa promo tidak ikut.
     *
     * `diskon` = qty x `SDDISKON` - potongan harga yg diberikan promo itu.
     */
    public function perPromo(string $dari, string $sampai, array $cabang): Collection
    {
        return $this->dasarPromo($dari, $sampai, $cabang)
            ->groupBy('pmu.MPUID', 'pmu.MPUKODE', 'pmu.MPUNAMA')
            ->orderByDesc('penjualan')
            ->get(array_merge([
                DB::raw('pmu.MPUID as id'),
                DB::raw('pmu.MPUKODE as kode'),
                DB::raw('pmu.MPUNAMA as nama'),
                DB::raw('SUM(d.SDKELUAR) as qty'),
                DB::raw('SUM(d.SDKELUAR * d.SDDISKON) as diskon'),
            ], $this->ukuran()))
            ->map(fn ($r) => $this->angkaPromo($r));
    }

    /** Produk/tindakan di dalam satu promo - isi tombol Detail. */
    public function itemPromo(int $promo, string $dari, string $sampai, array $cabang): Collection
    {
        return $this->dasarPromo($dari, $sampai, $cabang)
            ->where('pmu.MPUID', $promo)
            ->groupBy('d.SDITEM', 'i.IKODE', 'i.INAMA')
            ->orderByDesc('penjualan')
            ->get(array_merge([
                DB::raw('d.SDITEM as id'),
                DB::raw('i.IKODE as kode'),
                DB::raw('i.INAMA as nama'),
                DB::raw('SUM(d.SDKELUAR) as qty'),
                DB::raw('SUM(d.SDKELUAR * d.SDDISKON) as diskon'),
            ], $this->ukuran()))
            ->map(fn ($r) => $this->angkaPromo($r));
    }

    /**
     * Gabungkan hasil dua periode per `id` - baris yg hanya ada di salah satu periode tetap
     * tampil dgn 0 di periode lainnya. Tiap `$kolom` jadi `{kolom}A` & `{kolom}B`.
     * Diurutkan dari `{kolomPertama}A` terbesar.
     */
    public function bandingkan(Collection $a, Collection $b, array $kolom): Collection
    {
        $a = $a->keyBy('id');
        $b = $b->keyBy('id');

        return $a->keys()->merge($b->keys())->unique()->map(function ($id) use ($a, $b, $kolom) {
            $r = (object) [
                'id'   => $id,
                'kode' => $a[$id]->kode ?? $b[$id]->kode ?? null,
                'nama' => $a[$id]->nama ?? $b[$id]->nama ?? null,
            ];
            foreach ($kolom as $k) {
                $r->{$k . 'A'} = $a[$id]->{$k} ?? 0;
                $r->{$k . 'B'} = $b[$id]->{$k} ?? 0;
            }

            return $r;
        })->sortByDesc($kolom[0] . 'A')->values();
    }

    /**
     * Selisih A - B & persen pertumbuhan terhadap B, siap tampil. B = 0 -> persen '—'
     * (tidak bermakna), bukan galat bagi-nol.
     *
     * @return array{selisih:string,persen:string,kelas:string}
     */
    public static function tumbuh(float $a, float $b): array
    {
        $s = $a - $b;
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $panah = $s > 0 ? '▲' : ($s < 0 ? '▼' : '');

        return [
            'selisih' => ($s > 0 ? '+' : '') . $rp($s),
            'persen'  => trim($panah . ' ' . ($b != 0 ? number_format($s / abs($b) * 100, 1, ',', '.') . '%' : '—')),
            'kelas'   => $s > 0 ? 'text-success' : ($s < 0 ? 'text-danger' : 'text-muted'),
        ];
    }

    private function dasarPromo(string $dari, string $sampai, array $cabang)
    {
        return $this->dasar($dari, $sampai, $cabang)
            ->join('emasterpromod as pmd', 'pmd.MPDID', '=', 'd.SDIDPROMO')
            ->join('emasterpromou as pmu', 'pmu.MPUID', '=', 'pmd.MPDIDU');
    }

    private function angkaPromo(object $r): object
    {
        $r->qty = (float) $r->qty;
        $r->diskon = (float) $r->diskon;

        return $this->angka($r);
    }
}
