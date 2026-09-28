<?php

namespace App\Http\Controllers;

use App\Services\KmbWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Kirim Mutasi Barang" - PDF SATU dokumen KMB. Pola sama `PbPrintController`
 * (dokumen 1 transaksi, mpdf A4, TANPA kop PT).
 *
 * Layout direplikasi dari contoh cetakan sistem lama yg diberikan user
 * (`Kirim Mutasi PG-KMB26090042.pdf`). Pemetaan dikonfirmasi lewat query ke KMB nyata:
 * - **"Tujuan :" = `bkontak.KNAMA`** (nama ORANG, mis. "Endang Yuliyanti") - label legacy yg
 *   menyesatkan: terdengar seperti gudang tujuan, padahal gudang tujuan ada di barisnya
 *   sendiri. Dipertahankan krn user minta sama dgn cetakan lama (pola SAMA `PbPrintController`
 *   yg "Tujuan"-nya justru nama vendor).
 * - "Gudang Asal :" = `bgudang.GNAMA` dari `SUCABANG`;
 *   "Gudang Tujuan :" = `bgudang.GNAMA` dari `SUGUDANGTUJUAN`;
 *   "Keterangan :" = `SUURAIAN`; "No Transaksi :" = `SUNOTRANSAKSI`; "Tanggal :" = `SUTANGGAL`.
 * - Tabel: "Item" = `bitem.IKODE`, "Nama Item" = `INAMA`, **"Qty Keluar" = `SDKELUAR`**
 *   (KMB = dokumen KELUAR, beda dari PB yg `SDMASUK`), "Satuan" = `bsatuan.SKODE`.
 * - Bawah: tanda tangan **"Dikirim Oleh" / "Diterima Oleh"** (beda dari PB yg "Bag Gudang /
 *   Penerima") + "Total Qty".
 *
 * **TIDAK ADA kolom No PO** (beda dari cetakan PB) - mutasi antar gudang tidak berasal dari PO.
 * Harga juga tidak dicetak.
 */
class KmbPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('inventory/kmb', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as ga', 'ga.GID', '=', 'u.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            ->where('u.SUID', $id)->where('u.SUSUMBER', KmbWriter::SUMBER)
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUURAIAN', 'u.SUSTATUS',
                'k.KNAMA as kontak', 'ga.GNAMA as gudangAsal', 'gt.GNAMA as gudangTujuan',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDKELUAR as qty', 's.SKODE as satuan',
            ]);

        return $pdf->preview('reports.kmb-print', [
            'title'    => 'Kirim Mutasi Barang ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
