<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\TmbWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Terima Mutasi Barang" - PDF SATU dokumen TMB. Kembaran `KmbPrintController`
 * (struktur & CSS sama, tanpa kop PT). Layout dari contoh cetakan lama user
 * (`Terima Mutasi Barang PG-TMB26090031.pdf`).
 *
 * Pemetaan dikonfirmasi lewat query ke TMB nyata:
 * - **"Tujuan :" = `bkontak.KNAMA` (nama ORANG)** - label legacy menyesatkan, pola SAMA
 *   `KmbPrintController`/`PbPrintController`. Dipertahankan sesuai cetakan lama.
 * - "Gudang :" = `bgudang.GNAMA` dari `SUCABANG` (gudang PENERIMA);
 *   "Keterangan :" = `SUURAIAN`; "No Transaksi :"/"Tanggal :" dari header.
 * - **TIDAK ada baris "Gudang Tujuan"** (beda dari cetakan KMB) - `SUGUDANGTUJUAN` memang
 *   NULL di semua TMB nyata (dokumen penerimaan, gudang tujuannya ya `SUCABANG` itu sendiri).
 *
 * **Kolom qty berlabel "Qty Keluar" TAPI isinya `SDMASUK`** - dikonfirmasi dari data: TMB
 * contoh `SDMASUK=5`, `SDKELUAR=0`, dan PDF aslinya menampilkan 5,00 di kolom "Qty Keluar".
 * Label itu jelas warisan salin-tempel dari laporan KMB; DIREPLIKASI apa adanya krn user
 * minta sama dgn cetakan lama. Sama halnya tanda tangan "Dikirim Oleh / Diterima Oleh".
 */
class TmbPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('inventory/tmb', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUID', $id)->where('u.SUSUMBER', TmbWriter::SUMBER)
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
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDMASUK as qty', 's.SKODE as satuan',
            ]);

        return $pdf->preview('reports.tmb-print', [
            'title'    => 'Terima Mutasi Barang ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
