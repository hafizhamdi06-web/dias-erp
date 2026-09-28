<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\PengajuanDanaWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Petty Cash" - PDF SATU dokumen Pengajuan Dana (PDN). Layout dari contoh cetakan lama
 * user (`Pengajuan dana PG-PDN26090001.pdf`). Tabel `ctransaksipu` + `ctransaksipd`
 * (prefix kolom CU / CD).
 *
 * **Judul cetakannya "Petty Cash"**, bukan "Pengajuan Dana" - nama modul & nama dokumennya
 * memang beda, ikut contoh.
 *
 * ## Cetakan paling kompleks sejauh ini - 5 blok
 * 1. **Kop BERULANG tiap halaman** (`<htmlpageheader>`, bukan sekali di atas): nama+alamat
 *    cabang, "Petty Cash", nomor transaksi, dan "Tanggal :". Contoh 2 halaman menampilkan
 *    blok ini di KEDUANYA - itu sebabnya dipakai page header, bukan konten biasa.
 * 2. **Tabel berbingkai** dgn judul kolom DWIBAHASA bertumpuk (Keterangan/Description,
 *    COA/Account No., Jumlah/Amount) + satu kolom tanggal TANPA judul.
 * 3. Baris **Terbilang** + total, menyatu di dalam bingkai tabel.
 * 4. Kotak tanda tangan 3 sel: Dibuat/Prepared - Diperiksa/Checked - Disetujui/Approved,
 *    masing2 dgn baris "Tgl/Date" (yg Dibuat sudah terisi tanggal dokumen).
 * 5. **Kotak rekap per COA**: KODE | KETERANGAN | DEBIT | KREDIT, lalu tanda tangan
 *    "Diperiksa Oleh / Accounting Manager/SPV" & "Dibukukan Oleh / Accounting".
 *
 * ## Baris mana yg dicetak
 * `ctransaksipd` **urutan 1 = rekening sumber dana** (kredit sejumlah total) - SENGAJA
 * DIKECUALIKAN, sesuai `PengajuanDanaWriter::lines()` dan sesuai contoh (rekap di contoh cuma
 * memuat 3 COA biaya, tanpa akun sumbernya). Yg dicetak hanya urutan > 1: tarikan dari Kas
 * Keluar, masing2 `CDCATATAN` (Keterangan), `CDNOCOA` -> `bcoa` (COA), `CDDEBIT` (Jumlah).
 *
 * Rekap DEBIT/KREDIT dihitung `SUM` atas baris yg SAMA - bukan dipatok 0 - supaya kalau suatu
 * saat ada baris kredit di luar akun sumber, angkanya tetap jujur.
 *
 * ## Yang perlu dikonfirmasi user
 * - **Kolom tanggal per baris** diisi `CUTANGGAL` (tanggal dokumen). Di contoh ke-43 barisnya
 *   bernilai SAMA PERSIS dgn tanggal dokumen (10/09/2026) walau uraiannya menyebut tanggal
 *   belanja yg berbeda-beda (31/08, 29/08, ...), jadi kolom itu jelas BUKAN tanggal belanja.
 *   Kandidat lain (tanggal dokumen Kas Keluar asal) tidak bisa dibedakan dari contoh ini.
 * - **Alamat cabang** diambil dari `bgudang` (`'NMW ' + GNAMA`, lalu `GALAMAT2`). Contoh
 *   menulis "Jl Petogogan 11 No 29 / Keb Baru - Jak Sel" sedangkan `GALAMAT2` gudang 1
 *   berbunyi "Jl. Petogogan 2 No. 29, Kebayoran Baru Jakarta Selatan 12160" - beda nomor
 *   jalan & format. Dipakai data master (bukan teks contoh) supaya cabang lain ikut benar.
 *
 * **CATATAN**: `ctransaksipu` `CUSUMBER='PDN'` **KOSONG (0 baris)** di DB ini - modul belum
 * pernah dipakai, jadi tidak ada dokumen nyata utk dicetak. Diverifikasi lewat dokumen uji
 * yg dibuat di dalam transaksi lalu di-rollback.
 */
class PengajuanDanaPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('finance/pengajuan-dana', 'print'), 403);

        $h = DB::table('ctransaksipu as u')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.CUCABANG')
            ->where('u.CUID', $id)->where('u.CUSUMBER', PengajuanDanaWriter::SUMBER)
            ->first([
                'u.CUID', 'u.CUNOTRANSAKSI', 'u.CUTANGGAL', 'u.CUURAIAN', 'u.CUSTATUS',
                'g.GNAMA as gudang', 'g.GALAMAT2 as gudangAlamat',
            ]);

        abort_if(! $h, 404);

        // Urutan 1 = akun sumber dana, dikecualikan - lihat docblock kelas.
        $lines = DB::table('ctransaksipd as d')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->where('d.CDIDU', $id)->where('d.CDURUTAN', '>', 1)
            ->orderBy('d.CDURUTAN')
            ->get([
                'd.CDCATATAN as keterangan', 'd.CDDEBIT as debit', 'd.CDKREDIT as kredit',
                'c.CNOCOA as coa', 'c.CNAMA as coaNama',
            ]);

        // Rekap per COA utk kotak paling bawah.
        $rekap = [];
        foreach ($lines as $l) {
            $kode = $l->coa ?: '-';
            if (! isset($rekap[$kode])) {
                $rekap[$kode] = ['kode' => $kode, 'nama' => $l->coaNama ?: '-', 'debit' => 0.0, 'kredit' => 0.0];
            }
            $rekap[$kode]['debit'] += (float) $l->debit;
            $rekap[$kode]['kredit'] += (float) $l->kredit;
        }

        return $pdf->preview('reports.pengajuan-dana-print', [
            'title' => 'Petty Cash ' . $h->CUNOTRANSAKSI,
            'h'     => $h,
            'lines' => $lines,
            'total' => $lines->sum('debit'),
            'rekap' => array_values($rekap),
        ], ['size' => 'A4', 'orientasi' => 'P', 'marginTop' => 28]);
    }
}
