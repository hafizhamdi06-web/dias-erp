<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Kueri Closing Harian IP - SATU tempat, dipakai layar (`Livewire\Sales\ClosingIp`) DAN
 * ekspor Excel (`ReportController::closingIpExcel()`), supaya isi berkas tidak bisa
 * menyimpang dari yg tampil di layar. Aturan "tindakan wajib alkes" dijelaskan di docblock
 * `ClosingIp`.
 *
 * Hak cabang diterapkan DI SINI, bukan di pemanggil: cabang yg diminta dicek terhadap
 * `visibleBranchIds()` user login - nilai `cabang` bisa datang dari request Livewire maupun
 * URL ekspor, dua-duanya bisa diketik manual.
 */
class ClosingHarianIp
{
    /** Kolom baris yg ditampilkan/diekspor. */
    public const KOLOM = [
        'u.SUID as ipId', 'u.SUNOTRANSAKSI as noIp', 'u.SUTANGGAL as tanggal',
        'k.KNAMA as pasien', 'i.IKODE as kode', 'i.INAMA as tindakan',
        'd.SDID as sdid', 'd.SDKELUAR as qty', 'dr.KNAMA as dokter',
        'd.SDDARIPAKET as dariPaket', 'd.SDKEDATANGAN as kedatangan',
        'u.SUCABANG as cabangId', 'g.GNAMA as cabang',
    ];

    /**
     * @param array{dari?:string,sampai?:string,cabang?:int|string|null,search?:string} $saring
     *        `cabang` kosong/0 = semua cabang yg boleh dilihat user.
     */
    public function query(array $saring)
    {
        $dari = (string) ($saring['dari'] ?? '');
        $sampai = (string) ($saring['sampai'] ?? '');
        $q = trim((string) ($saring['search'] ?? ''));
        $boleh = auth()->user()->visibleBranchIds();
        $pilih = (int) ($saring['cabang'] ?? 0);
        if ($pilih && $boleh !== [] && ! in_array($pilih, $boleh, true)) {
            $pilih = 0;
        }

        return DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('fstoku as al', 'al.SUID', '=', 'd.SDIDALKESNYA')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'd.SDDOKTER')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', AlkesWriter::STATUS_BATAL)
            ->when($pilih, fn ($b) => $b->where('u.SUCABANG', $pilih),
                fn ($b) => $b->when($boleh !== [], fn ($w) => $w->whereIn('u.SUCABANG', $boleh)))
            ->where('i.IADAALKES', 1)
            ->whereIn('i.IJENISITEM', AlkesWriter::JENIS_TINDAKAN)
            ->where('i.IRESEP', 0)
            ->where('d.SDKELUAR', '>', 0)
            ->whereRaw('NOT (COALESCE(d.SDDARIPAKET, 0) <> 0 AND COALESCE(d.SDKEDATANGAN, 0) = 0)')
            ->where(fn ($b) => $b->whereNull('al.SUID')->orWhere('al.SUSTATUS', AlkesWriter::STATUS_BATAL))
            ->when($dari !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $dari))
            ->when($sampai !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $sampai))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('i.INAMA', 'like', "%{$q}%")));
    }

    /** Urutan baris yg sama di layar & ekspor. */
    public function urut($query)
    {
        return $query->orderBy('u.SUTANGGAL')->orderBy('u.SUNOTRANSAKSI')->orderBy('d.SDURUTAN');
    }
}
