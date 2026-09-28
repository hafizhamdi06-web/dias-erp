<?php

namespace App\Http\Controllers;

use App\Services\PbcWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Penerimaan Barang" untuk dokumen PBC (Penerimaan Barang Cabang) - PDF SATU dokumen.
 * Layout dari contoh cetakan lama user (`Penerimaan Barang Cabang MP-PBC26090001.pdf` =
 * `fstoku` SUSUMBER='PBC'). Tanpa kop.
 *
 * **Judulnya "Penerimaan Barang"**, SAMA dgn cetakan PB (pembelian) - bukan "Penerimaan
 * Barang Cabang". Struktur & CSS memang sekeluarga `pb-print`, bedanya **TIDAK ADA kolom
 * "No PO"** (PBC menerima dari Surat Jalan antar cabang, bukan dari Purchase Order), jadi
 * tabelnya 5 kolom: No | Item | Nama Item | Qty Masuk | Satuan.
 *
 * **"No Invoice :" SENGAJA DIKOSONGKAN.** Di cetakan PB kolom itu diisi `SUNOREF`, tapi utk
 * PBC `SUNOREF` isinya **nomor Permintaan Barang (RS)**, bukan nomor invoice - diverifikasi
 * ke SELURUH data: **1.838 dari 1.838 PBC** ber-`SUNOREF` berpola `%-RS%`, nol yg kosong.
 * Contoh cetakan user pun menampilkannya KOSONG. Mencetak nomor RS di bawah label "No
 * Invoice" jelas menyesatkan, jadi labelnya tetap ada (ikut contoh) tapi nilainya dibiarkan
 * kosong.
 *
 * "Tujuan :" = `bkontak.KNAMA` (di contoh "Fransiskus Emanuel L", nama ORANG) - label legacy
 * menyesatkan yg dipertahankan, pola sama cetakan PB/KMB/TMB. "Gudang :" = `SUCABANG`
 * (cabang PENERIMA). Qty dari `SDMASUK` (PBC = dokumen MASUK).
 */
class PbcPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('purchase/pbc', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUID', $id)->where('u.SUSUMBER', PbcWriter::SUMBER)
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
                'd.SDMASUK as qty', 's.SKODE as satuan',
            ]);

        return $pdf->preview('reports.pbc-print', [
            'title'    => 'Penerimaan Barang Cabang ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
