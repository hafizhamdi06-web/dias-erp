<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bagian BERSAMA cetakan Invoice Penjualan (IV, sumber SJ) & Invoice Penjualan Mutasi
 * (IVM, sumber TMB). User minta "layout sama, beda penarikan saja" - jadi seluruh layout,
 * kop, perhitungan, terbilang & kotak rekap tinggal DI SINI, dan tiap turunan cuma
 * menentukan rantai dokumen sumbernya.
 *
 * IV & IVM memakai **TABEL YANG SAMA** (`einvoicepenjualanu`/`einvoicepenjualand`), dibedakan
 * `IPUSUMBER`. Lihat docblock `InvoicePrintController` utk temuan datanya (kop per cabang,
 * kolom subtotal selalu 0, rantai tipe pendapatan) - berlaku sama utk kedua modul.
 *
 * Yang ditentukan turunan:
 * - `sumber()` / `aclPath()` / `judulDokumen()`
 * - `labelKolom()`  : 2 judul kolom dokumen sumber (IV: No SJ/No PBC, IVM: No KMB/No TMB)
 * - `isiSumber()`   : mengisi `noSumber1`, `noSumber2` & `tujuan` tiap baris
 */
abstract class InvoicePrintBase extends Controller
{
    abstract protected function sumber(): string;

    abstract protected function aclPath(): string;

    /** @return array{0:string,1:string} judul kolom dokumen sumber & penerima */
    abstract protected function labelKolom(): array;

    /**
     * Isi per baris: `noSumber1` (dokumen keluar), `noSumber2` (dokumen terima),
     * `tujuan` (nama gudang yg ditagih, utk "Kepada Yth" & blok rekap).
     */
    abstract protected function isiSumber(Collection $lines): void;

    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do($this->aclPath(), 'print'), 403);

        $h = DB::table('einvoicepenjualanu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.IPUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.IPUGUDANG')
            ->leftJoin('btermin as t', 't.TID', '=', 'u.IPUTERMIN')
            ->where('u.IPUID', $id)->where('u.IPUSUMBER', $this->sumber())
            ->first([
                'u.IPUID', 'u.IPUNOTRANSAKSI', 'u.IPUTANGGAL', 'u.IPUTGLJATUHTEMPO',
                'u.IPUALAMAT', 'u.IPUATTENTION', 'u.IPUURAIAN', 'u.IPUCATATAN',
                'u.IPUJENISPAJAK', 'u.IPUDISKON', 'u.IPUDISKONPERSEN', 'u.IPUBIAYAONGKIR',
                'k.KNAMA as kontak',
                'g.GNAMA as gudang', 'g.GNAMAPT as pt', 'g.GALAMAT2 as ptAlamat', 'g.GKOTA as ptKota',
                't.TTEMPO as termin',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('einvoicepenjualand as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.IPDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.IPDSATUAN')
            // Rantai tipe pendapatan utk kotak rekap (aturan user 2026-09-27):
            // bitem -> bitem2 -> bcoatipe_pendapatan. LEFT JOIN supaya baris tanpa tipe
            // TETAP terhitung (kalau hilang, jumlah rekap tidak sama dgn Sub Total).
            ->leftJoin('bitem2 as i2', 'i2.I2IDITEM', '=', 'i.IID')
            ->leftJoin('bcoatipe_pendapatan as ct', 'ct.CTID', '=', 'i2.I2COAPENDAPATAN')
            ->where('d.IPDIDIPU', $id)
            ->orderBy('d.IPDURUTAN')
            ->get([
                'd.IPDITEM', 'i.INAMA as nama', 'd.IPDKELUAR as qty', 's.SKODE as kemas',
                'd.IPDHARGA as harga', 'd.IPDDISKON as disc', 'd.IPDSUID as sumberId',
                'ct.CTNAMA as tipePendapatan',
            ]);

        // Rantai dokumen sumber - satu2nya yg beda antara IV & IVM.
        $this->isiSumber($lines);

        foreach ($lines as $l) {
            $l->subtotal = ((float) $l->harga - (float) $l->disc) * (float) $l->qty;
        }

        // SEMUA angka dihitung ulang dari baris - kolom subtotal di DB selalu 0.
        $subtotal = (float) $lines->sum('subtotal');
        $diskon = (float) ($h->IPUDISKONPERSEN > 0
            ? round($subtotal * (float) $h->IPUDISKONPERSEN / 100, 2)
            : (float) $h->IPUDISKON);
        $dpp = $subtotal - $diskon;
        $pajak = (int) $h->IPUJENISPAJAK === 1 ? round($dpp * 0.11, 2) : 0.0;
        $ongkir = (float) $h->IPUBIAYAONGKIR;
        $total = $dpp + $pajak + $ongkir;

        // "Kepada Yth" = "NMW " + nama gudang yg ditagih. Bisa lebih dari satu.
        $tujuanList = $lines->pluck('tujuan')->filter()->unique()->values()
            ->map(fn ($n) => 'NMW ' . $n)->all();

        // Kotak rekap: gudang tujuan -> tipe pendapatan.
        $rekap = [];
        foreach ($lines as $l) {
            $gt = $l->tujuan ?: '-';
            $tp = $l->tipePendapatan ?: '-';
            if (! isset($rekap[$gt][$tp])) {
                // Satuan = satuan baris PERTAMA grup (urut IPDURUTAN).
                $rekap[$gt][$tp] = ['tipe' => $tp, 'qty' => 0.0, 'satuan' => $l->kemas, 'jumlah' => 0.0];
            }
            $rekap[$gt][$tp]['qty'] += (float) $l->qty;
            $rekap[$gt][$tp]['jumlah'] += (float) $l->subtotal;
        }

        [$kol1, $kol2] = $this->labelKolom();

        return $pdf->preview('reports.invoice-print', [
            'title'     => $this->judulDokumen() . ' ' . $h->IPUNOTRANSAKSI,
            'h'         => $h,
            'lines'     => $lines,
            'kolom1'    => $kol1,
            'kolom2'    => $kol2,
            'ref1List'  => $lines->pluck('noSumber1')->filter()->unique()->values()->all(),
            'ref2List'  => $lines->pluck('noSumber2')->filter()->unique()->values()->all(),
            'kepadaYth' => $tujuanList !== [] ? implode(', ', $tujuanList) : ($h->kontak ?: '-'),
            'rekap'     => $rekap,
            'subtotal'  => $subtotal,
            'diskon'    => $diskon,
            'pajak'     => $pajak,
            'ongkir'    => $ongkir,
            'total'     => $total,
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }

    protected function judulDokumen(): string
    {
        return 'Invoice Penjualan';
    }
}
