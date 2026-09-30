<?php

namespace App\Http\Controllers;

use App\Services\KasBankWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak bukti Kas Masuk / Kas Keluar - PDF SATU dokumen `ctransaksiu` (prefix kolom CU/CD),
 * lihat docblock `KasBankWriter` utk struktur modulnya.
 *
 * **Layout MENIRU contoh cetakan sistem lama** yg dikirim user 2026-09-30
 * (`Bukti Kas Keluar BZ-KK26090002.pdf`) - SENGAJA jauh lebih polos drpd
 * `pengajuan-dana-print`: tanpa kop nama/alamat cabang, tanpa bingkai tabel (garis
 * horizontal saja), tanpa rekap per COA, tanpa judul kolom dwibahasa. **Jangan
 * "diseragamkan" dgn cetakan Petty Cash** - user minta persis contohnya.
 *
 * **SATU blade & SATU query utk kedua arah** - permintaan user: "untuk kas masuk di samakan
 * saja dengan kas keluar, yang beda debit kreditnya dan judul". Jadi di sini **TIDAK ADA
 * percabangan layout**: yg beda cuma `$judul` dan angkanya jatuh di kolom Debit atau Kredit -
 * dan itu terjadi SENDIRI karena kolom `CDDEBIT`/`CDKREDIT` dicetak APA ADANYA dari DB
 * (writer-lah yg menaruh angka di sisi yg benar: MASUK rekening=debit, KELUAR
 * rekening=kredit). JANGAN tambahkan `if ($masuk)` utk menukar kolom - itu justru merusaknya.
 * Label tanda tangan pun TETAP "Dikeluarkan/Dicatat/Disetujui" di kedua arah, sesuai contoh.
 *
 * ## Urutan baris: rekening kas PALING BAWAH
 * `ctransaksid` menyimpan rekening kas di `CDURUTAN=1`, tapi di contoh baris "Kas Kecil"
 * ada di **baris TERAKHIR** setelah semua akun biaya. Karena itu di-`ORDER BY (CDURUTAN=1),
 * CDURUTAN` - bukan sekadar `CDURUTAN`. Baris itu **IKUT DICETAK** (beda dari
 * `PengajuanDanaPrintController` yg mengecualikan baris urutan 1), supaya "Jumlah"
 * Debit = Kredit spt di contoh.
 *
 * ## Pemetaan field ke contoh
 * - "Uraian : KAS KELUAR" -> `CUURAIAN`.
 * - Nama di bawah tanggal ("Dede Suryaman") -> **`CUKONTAK` -> `bkontak.KNAMA`**, BUKAN
 *   nama user. Diverifikasi: "DEDE SURYAMAN" ada sbg kontak `KID=151457`
 *   (`KKODE='CIB-000022'`), sedangkan di `auser` tidak ada nama itu sama sekali.
 * - "Jakarta, 25-September-2026" -> kota cabang (`bgudang.GKOTA`) + `CUTANGGAL` format
 *   `d-F-Y` berbahasa Indonesia.
 *
 * **ASUMSI yg perlu dikonfirmasi user**: contohnya dari cabang **Bizpark**, yg `GKOTA`-nya
 * **NULL** di master, tapi cetakannya tetap berbunyi "Jakarta" - artinya sistem lama
 * kemungkinan besar MEMATOK "Jakarta". Di sini dipakai `GKOTA` dulu dan **jatuh ke
 * "Jakarta" kalau kosong** - hasilnya sama persis utk cabang di contoh, tapi tidak salah
 * mencetak "Jakarta" utk cabang Surabaya/Bali begitu `GKOTA` mereka diisi. Kalau user
 * memang mau selalu "Jakarta", tinggal buang `?:`-nya.
 *
 * Bank Masuk/Keluar BELUM disini - dokumen Bank punya field tambahan (`CUTIPE` Tunai/Giro/
 * Transfer, `CUBANK`, `CUNOGIRO`, `CUTGLTEMPO`) yg harus tampil di cetakannya.
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
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.CUCABANG')
            ->where('u.CUID', $id)->where('u.CUSUMBER', $sumber)
            ->first([
                'u.CUID', 'u.CUNOTRANSAKSI', 'u.CUTANGGAL', 'u.CUURAIAN', 'u.CUTOTALTRANS',
                'k.KNAMA as kontak', 'g.GKOTA as kota',
            ]);

        abort_if(! $h, 404);

        // Rekening kas (CDURUTAN=1) diturunkan ke PALING BAWAH - lihat docblock kelas.
        $lines = DB::table('ctransaksid as d')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->where('d.CDIDU', $id)
            ->orderByRaw('CASE WHEN d.CDURUTAN = 1 THEN 1 ELSE 0 END')
            ->orderBy('d.CDURUTAN')
            ->get([
                'd.CDURUTAN as urutan', 'd.CDCATATAN as keterangan',
                'd.CDDEBIT as debit', 'd.CDKREDIT as kredit',
                'c.CNOCOA as coa', 'c.CNAMA as coaNama',
            ]);

        return $pdf->preview('reports.kas-bank-print', [
            'title'          => $judul . ' ' . $h->CUNOTRANSAKSI,
            'judul'          => $judul,
            'h'              => $h,
            'lines'          => $lines,
            'totalDebit'     => (float) $lines->sum('debit'),
            'totalKredit'    => (float) $lines->sum('kredit'),
            'kota'           => trim((string) $h->kota) ?: 'Jakarta',
            'tanggalPanjang' => \Carbon\Carbon::parse($h->CUTANGGAL)->locale('id')->isoFormat('DD-MMMM-YYYY'),
        ], ['size' => 'A4', 'orientasi' => 'P', 'marginTop' => 18, 'marginLeft' => 15]);
    }
}
