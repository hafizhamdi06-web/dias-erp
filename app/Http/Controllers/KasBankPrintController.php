<?php

namespace App\Http\Controllers;

use App\Services\KasBankWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak bukti Kas Masuk / Kas Keluar - PDF SATU dokumen `ctransaksiu` (prefix kolom CU/CD),
 * lihat docblock `KasBankWriter` utk struktur modulnya.
 *
 * **SATU blade & SATU query utk kedua arah** - permintaan user 2026-09-30: "untuk kas masuk
 * di samakan saja dengan kas keluar, yang beda debit kreditnya dan judul". Jadi di sini
 * TIDAK ADA percabangan layout: yg beda cuma `$judul` dan angkanya jatuh di kolom Debit atau
 * Kredit - dan itu terjadi SENDIRI karena kolom `CDDEBIT`/`CDKREDIT` dicetak APA ADANYA dari
 * DB (writer-lah yg menaruh angka di sisi yg benar: MASUK rekening=debit, KELUAR
 * rekening=kredit). JANGAN tambahkan `if ($masuk)` utk menukar kolom - itu justru merusaknya.
 *
 * **Baris urutan 1 (rekening kas) IKUT DICETAK** - beda dari
 * `PengajuanDanaPrintController` yg mengecualikannya. Alasannya: ini bukti jurnal, harus
 * berimbang Debit = Kredit di mata pemeriksa. Rekap bawah juga memuat semua baris, jadi
 * total Debit dan Kredit-nya sama - itu sengaja, bukan duplikasi angka.
 *
 * Layout mengikuti rumah gaya `pengajuan-dana-print` (kop berulang, judul kolom DWIBAHASA,
 * terbilang menyatu di bingkai, kotak tanda tangan) supaya cetakan finance seragam. Judul
 * kolom Inggris memakai `<br>`, BUKAN `display:block` - mpdf tidak menghormati itu pada
 * elemen inline (lihat komentar di blade-nya).
 *
 * **CATATAN**: `ctransaksiu` `CUSUMBER` 'KM'/'KK' masih SANGAT sedikit isinya di DB ini
 * (modul baru), jadi verifikasi memakai dokumen sintetis di dalam transaksi yg di-rollback,
 * bukan data histori nyata.
 *
 * Bank Masuk/Keluar BELUM disini - dokumen Bank punya field tambahan (`CUTIPE` Tunai/Giro/
 * Transfer, `CUBANK`, `CUNOGIRO`, `CUTGLTEMPO`) yg harus tampil di cetakannya, jadi tidak
 * bisa sekadar ikut layout ini tanpa blok tambahan.
 */
class KasBankPrintController extends Controller
{
    public function kasMasuk(int $id, PdfReport $pdf)
    {
        return $this->cetak(KasBankWriter::KAS_MASUK, 'finance/kas-masuk', 'Bukti Kas Masuk', $id, $pdf);
    }

    public function kasKeluar(int $id, PdfReport $pdf)
    {
        return $this->cetak(KasBankWriter::KAS_KELUAR, 'finance/kas-keluar', 'Bukti Kas Keluar', $id, $pdf);
    }

    private function cetak(string $sumber, string $ability, string $judul, int $id, PdfReport $pdf)
    {
        abort_unless(can_do($ability, 'print'), 403);

        $h = DB::table('ctransaksiu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.CUKONTAK')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'u.CUREKKAS')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.CUCABANG')
            ->leftJoin('auser as p', 'p.UID', '=', 'u.CUCREATEU')
            ->where('u.CUID', $id)->where('u.CUSUMBER', $sumber)
            ->first([
                'u.CUID', 'u.CUNOTRANSAKSI', 'u.CUTANGGAL', 'u.CUURAIAN', 'u.CUTOTALTRANS',
                'k.KNAMA as kontak',
                'c.CNOCOA as rekKode', 'c.CNAMA as rekNama',
                'g.GNAMA as gudang', 'g.GALAMAT2 as gudangAlamat',
                'p.UNAMA as dibuatOleh',
            ]);

        abort_if(! $h, 404);

        // SEMUA baris, urutan 1 (rekening kas) IKUT - lihat docblock kelas.
        $lines = DB::table('ctransaksid as d')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->where('d.CDIDU', $id)
            ->orderBy('d.CDURUTAN')
            ->get([
                'd.CDURUTAN as urutan', 'd.CDCATATAN as keterangan',
                'd.CDDEBIT as debit', 'd.CDKREDIT as kredit',
                'c.CNOCOA as coa', 'c.CNAMA as coaNama',
            ]);

        // Rekap per COA - dijumlahkan supaya akun yg dipakai 2x tidak muncul 2 baris.
        $rekap = [];
        foreach ($lines as $l) {
            $kode = $l->coa ?: '-';
            $rekap[$kode] ??= ['kode' => $kode, 'nama' => $l->coaNama ?: '-', 'debit' => 0.0, 'kredit' => 0.0];
            $rekap[$kode]['debit'] += (float) $l->debit;
            $rekap[$kode]['kredit'] += (float) $l->kredit;
        }

        return $pdf->preview('reports.kas-bank-print', [
            'title'      => $judul . ' ' . $h->CUNOTRANSAKSI,
            'judul'      => $judul,
            'h'          => $h,
            'lines'      => $lines,
            'totalDebit' => (float) $lines->sum('debit'),
            'totalKredit' => (float) $lines->sum('kredit'),
            'rekap'      => array_values($rekap),
        ], ['size' => 'A4', 'orientasi' => 'P', 'marginTop' => 30]);
    }
}
