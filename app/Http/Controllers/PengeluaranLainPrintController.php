<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\PengeluaranLainWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Pengeluaran Lain" - PDF SATU dokumen PL. Sekeluarga dgn `PenyesuaianPrintController`
 * (struktur & CSS sama, tanpa kop PT), bedanya **cuma satu kolom qty: "Keluar"** dan satu
 * total ("Total Keluar") - sesuai sifat modulnya yg hanya mengeluarkan stok.
 *
 * **TIDAK ADA contoh cetakan legacy** utk dokumen ini (sama spt Penyesuaian). Keputusan
 * layout diwarisi dari cetakan Penyesuaian yg sudah disetujui user, supaya kedua dokumen
 * kembar ini konsisten:
 * - Blok info kiri punya baris **"Jenis :"** (`bjenispenyesuaian.JNAMA`) - PL ikut memakai
 *   tabel jenis PY, lihat docblock `PengeluaranLainWriter`.
 * - Kolom **Catatan** (`SDCATATAN`) ada - utk dokumen pengeluaran, alasan per baris justru
 *   yg paling sering dicari (di data nyata isinya "dipakai produksi").
 * - Tanda tangan **"Dibuat Oleh" / "Diketahui Oleh"**.
 *
 * "Tujuan :" = `bkontak.KNAMA` - label legacy yg dipakai SEMUA cetakan sekeluarga
 * (PB/KMB/TMB/PY) walau isinya nama orang/vendor. Dipertahankan demi konsistensi.
 */
class PengeluaranLainPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('inventory/pengeluaran-lain', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bjenispenyesuaian as j', 'j.JID', '=', 'u.SUJENISPENYESUAIAN')
            ->where('u.SUID', $id)->where('u.SUSUMBER', PengeluaranLainWriter::SUMBER)
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
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDKELUAR as keluar',
                's.SKODE as satuan', 'd.SDCATATAN as catatan',
            ]);

        return $pdf->preview('reports.pengeluaran-lain-print', [
            'title'       => 'Pengeluaran Lain ' . $h->SUNOTRANSAKSI,
            'h'           => $h,
            'lines'       => $lines,
            'totalKeluar' => $lines->sum('keluar'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
