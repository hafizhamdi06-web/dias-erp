<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\ProduksiWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Produksi" - PDF SATU dokumen PRO. Layout dari contoh cetakan lama user
 * (`Produksi RP-PRO26090001.pdf`). Tanpa kop (pola sama PB/KMB/TMB/PY/PL).
 *
 * **Ciri khas: DUA kolom qty dalam satu tabel** - `Qty Produk Jadi` (`SDMASUK`, barang jadi
 * yg masuk stok) & `Qty Bahan Baku` (`SDKELUAR`, bahan yg dikonsumsi) - dgn **dua Total Qty**
 * berdampingan di bawah. Satu baris hanya terisi salah satunya; di contoh, 302,00 produk jadi
 * dari 19.333,00 bahan baku. Ini sejajar cetakan Penyesuaian yg juga 2 kolom (Masuk/Keluar),
 * bedanya di sini label-nya spesifik manufaktur.
 *
 * Tanda tangan **hanya SATU: "Bag Produksi"** (dokumen internal, tidak ada serah terima).
 *
 * "Tujuan :" = `bkontak.KNAMA` (di contoh "Andi Andrian", nama ORANG) - label legacy
 * menyesatkan yg DIPERTAHANKAN, pola sama semua cetakan sekeluarga. "Gudang :" = `SUCABANG`
 * (di contoh "RII Produksi").
 *
 * **CATATAN**: dokumen contoh `RP-PRO26090001` TIDAK ADA di DB lokal (dicek di
 * `data_pos_nmw_2023`, `data_pos_nmw_2027` & `db_mp_pabrik_2026` - tidak ada di ketiganya;
 * PRO bulan September yg ada cuma `PG-PRO26090001/2`). Layout diverifikasi ke dokumen PRO
 * nyata yg bentuknya SAMA PERSIS (`RP-PRO26060028/26/38` - gudang "RII Produksi", kontak
 * "Andi Andrian", punya baris masuk DAN keluar).
 */
class ProduksiPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('pabrik/produksi', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUID', $id)->where('u.SUSUMBER', ProduksiWriter::SUMBER)
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUURAIAN', 'u.SUSTATUS',
                'k.KNAMA as kontak', 'g.GNAMA as gudang',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama',
                'd.SDMASUK as jadi', 'd.SDKELUAR as bahan', 's.SKODE as satuan',
            ]);

        return $pdf->preview('reports.produksi-print', [
            'title'      => 'Produksi ' . $h->SUNOTRANSAKSI,
            'h'          => $h,
            'lines'      => $lines,
            'totalJadi'  => $lines->sum('jadi'),
            'totalBahan' => $lines->sum('bahan'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
