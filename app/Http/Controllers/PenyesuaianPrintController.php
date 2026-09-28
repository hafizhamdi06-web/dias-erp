<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\PenyesuaianWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Penyesuaian Barang" - PDF SATU dokumen PY. Sekeluarga dgn `KmbPrintController`/
 * `TmbPrintController` (struktur & CSS sama, tanpa kop PT).
 *
 * **TIDAK ADA contoh cetakan legacy** utk dokumen ini (user minta "seperti TMB/KMB, bedanya
 * ada masuk dan keluar"), jadi beberapa hal DIPUTUSKAN di sini - ditandai jelas supaya mudah
 * dikoreksi kalau ternyata ada format bakunya:
 * - Tabel py **2 kolom qty: "Masuk" (`SDMASUK`) & "Keluar" (`SDKELUAR`)** - inti perbedaan
 *   dgn KMB/TMB yg cuma 1 kolom. Di bawah ada **2 total: Total Masuk & Total Keluar**
 *   (KMB/TMB cuma "Total Qty").
 * - Ditambah kolom **Catatan** (`SDCATATAN`) - tidak ada di cetakan KMB/TMB, tapi utk dokumen
 *   penyesuaian alasan per baris justru yg paling sering dicari.
 * - Blok info kiri dapat baris **"Jenis :"** (`bjenispenyesuaian.JNAMA`) - identitas utama
 *   dokumen ini (Racikan / Penurunan Barang / Stok Opname / dst).
 * - Tanda tangan **"Dibuat Oleh" / "Diketahui Oleh"** (KMB: "Dikirim/Diterima Oleh" - tidak
 *   cocok utk penyesuaian yg tidak berpindah gudang).
 *
 * "Tujuan :" = `bkontak.KNAMA` - label legacy yg dipakai SEMUA cetakan sekeluarga
 * (PB/KMB/TMB) walau isinya nama orang/vendor. Dipertahankan demi konsistensi.
 */
class PenyesuaianPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('inventory/adjust', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bjenispenyesuaian as j', 'j.JID', '=', 'u.SUJENISPENYESUAIAN')
            ->where('u.SUID', $id)->where('u.SUSUMBER', PenyesuaianWriter::SUMBER)
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUURAIAN', 'u.SUSTATUS',
                'k.KNAMA as kontak', 'g.GNAMA as gudang', 'j.JNAMA as jenis',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDMASUK as masuk', 'd.SDKELUAR as keluar',
                's.SKODE as satuan', 'd.SDCATATAN as catatan',
            ]);

        return $pdf->preview('reports.penyesuaian-print', [
            'title'        => 'Penyesuaian Barang ' . $h->SUNOTRANSAKSI,
            'h'            => $h,
            'lines'        => $lines,
            'totalMasuk'   => $lines->sum('masuk'),
            'totalKeluar'  => $lines->sum('keluar'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
