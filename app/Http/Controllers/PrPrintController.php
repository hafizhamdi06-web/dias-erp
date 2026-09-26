<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\PurchaseRequestWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Surat Permintaan Pelanggan" - dokumen PDF SATU transaksi Permintaan Barang (PR),
 * BUKAN laporan tabel banyak baris (beda dari `ReportController`) - pola sama
 * `PosReceiptController` (cetak SATU dokumen transaksi), tapi PDF A4 penuh (mpdf) krn
 * layoutnya kompleks (blok info 2 kolom + tabel + blok tanda tangan), bukan struk thermal.
 *
 * Layout & LABEL kolom (termasuk yg terkesan salah nama) DIREPLIKASI PERSIS dari contoh
 * PDF cetakan sistem LAMA yg diberikan user (`Surat_Permintaan_Pelanggan CP-RS26080001.pdf`,
 * dicocokkan ke `fpermintaanbarangu`/`d` PBUID=40875 by nomor transaksi - SEMUA field
 * dikonfirmasi cocok persis via query langsung, bukan tebakan):
 * - "No PO" = `PBUNOTRANSAKSI` (nomor PR itu SENDIRI, BUKAN referensi ke PO manapun -
 *   label legacy yg menyesatkan, TETAP dipertahankan krn user minta match PDF asli persis).
 * - "Tipe" = `blain.LKODE` (kode PENDEK, mis. "Farmasi") - BUKAN `LNAMA` ("Depo Farmasi",
 *   itu dipakai kolom "Gudang / Supplier Tujuan" via `bgudang.GNAMA` PBUGUDANGSUMBER,
 *   kebetulan sering sama teksnya krn `blain.LGUDANGNAMA` biasa disalin dari situ).
 * - "Dari Cabang" = `bgudang.GNAMA` dari `PBUGUDANG` (cabang PEMINTA - field yg di form
 *   Laravel dilabeli "Depo / Farmasi", label BEDA di cetakan vs di form, sama-sama kolom
 *   `PBUGUDANG`).
 * - Kolom tabel "Nama Item" **ternyata literal `bitem.IKODE`** (kode item, BUKAN nama
 *   penuh `INAMA`) - dikonfirmasi PERSIS dari PDF contoh ("CA80"/"CA10" = kode, bukan nama
 *   "CERADAN ADVANCE CR 80 GR" dst) - label kolom legacy yg TIDAK match isinya, tetap
 *   direplikasi apa adanya krn itu yg diminta ("seperti file pdf tersebut").
 *   **GOTCHA DATA ditemukan sekaligus (2026-09-24)**: 2 item CONTOH di PDF (CA80/CA10,
 *   IID 3812/3638) TERNYATA py `IKODE`/`INAMA` TERTUKAR di DB (`IKODE`="CERADAN ADVANCE CR
 *   80 GR" = nama panjang, `INAMA`="CA80" = kode pendek - KEBALIKAN dari konvensi normal
 *   IKODE=kode/INAMA=nama yg dipakai KONSISTEN di 97% item lain, mis. IID=14 "FW ACNE 100
 *   ML"/"NMW ACNE FOAMING CLEANSER 100ML"). Dicek: **87 dari 2.764 item aktif (~3%)**
 *   punya pola tertukar serupa (`LENGTH(IKODE)>LENGTH(INAMA)` & `INAMA` pendek) - masalah
 *   KUALITAS DATA legacy (kemungkinan import lama), BUKAN bug kode - kode ini TETAP pilih
 *   `IKODE` (benar utk mayoritas item) apa adanya, TIDAK dikompensasi/di-swap manual di sini
 *   (tidak ada cara membedakan item mana yg genuinely tertukar vs tidak tanpa aturan
 *   heuristik berisiko salah).
 * - "Real Stok" = `PBDSTOKREAL` (stok SISTEM tersimpan saat PR dibuat, BUKAN `PBDSTOK`
 *   yg manual - lihat gotcha 2 kolom stok di docblock `PrForm`).
 * - Blok tanda tangan "Dibuat/Diketahui/Disetujui Oleh" HANYA label statis + garis kosong
 *   `( )` (pola legacy, TIDAK ada data nama penandatangan tersimpan di DB manapun - PR
 *   TIDAK py kolom pencatat siapa yg cetak/tanda tangan fisik).
 * - **Nama perusahaan direvisi (2026-09-24, setelah user tunjukkan cetakan asli via PDF
 *   yg ternyata beda per cabang)**: `CONCAT(bnamapt.NPNAMACLINIC, ' ', bnamapt.NPNAMA2)`
 *   via `bgudang.GPT` dari CABANG "Dari Cabang" (`PBUGUDANG`, BUKAN `ainfo` global lagi -
 *   `PdfReport::companyInfo()` TIDAK dipakai di controller ini) - contoh: cabang Ciputat
 *   (GID=2) → `NPNAMACLINIC`="NMW CLINIC" + `NPNAMA2`="PT. Exclusive Igyolini Healthcare"
 *   = "NMW CLINIC PT. Exclusive Igyolini Healthcare", PERSIS cocok PDF contoh user.
 *   `NPNAMACLINIC` SELALU "NMW CLINIC" utk semua cabang (dicek), `NPNAMA2` BEDA per PT
 *   (badan hukum berbeda per cabang/franchise, dicek 10 cabang aktif - PT. Igyolini
 *   Indonesia/PT. Exclusive Igyolini Healthcare/PT. Rhein NMW Indonesia/dst). `bgudang.
 *   GNAMAPT` SEBENARNYA sudah py teks jadi yg SAMA (`NPNAMA2` didenormalisasi ke sana) -
 *   TAPI user eksplisit minta join `bnamapt` via `bgudang.GPT`, dipakai persis itu (bukan
 *   `GNAMAPT` langsung) - kalau nanti `bnamapt` diedit tanpa sinkron ulang `GNAMAPT`,
 *   join ini yg akan selalu akurat.
 */
class PrPrintController extends Controller
{
    public function show(int $id)
    {
        // Route BIASA (bukan Livewire) - satu2nya penjaga hak akses NYATA utk endpoint ini
        // (tombol List/Form cuma UI, bisa dilewati kalau URL diketik langsung - 2026-09-24,
        // permintaan user "sesuaikan dengan hak akses").
        abort_unless(can_do('inventory/pr', 'print'), 403);

        $writer = app(PurchaseRequestWriter::class);
        $h = $writer->header($id);
        abort_if(! $h, 404);

        $karyawan = $h->PBUKONTAK ? DB::table('bkontak')->where('KID', $h->PBUKONTAK)->value('KNAMA') : null;
        $dariCabang = $h->PBUGUDANG ? DB::table('bgudang')->where('GID', $h->PBUGUDANG)->value('GNAMA') : null;
        $gudangTujuan = $h->PBUGUDANGSUMBER ? DB::table('bgudang')->where('GID', $h->PBUGUDANGSUMBER)->value('GNAMA') : null;
        $tipe = $h->PBUTIPEPERMINTAAN ? DB::table('blain')->where('LID', $h->PBUTIPEPERMINTAAN)->value('LKODE') : null;

        $namaPt = $h->PBUGUDANG
            ? DB::table('bgudang as g')
                ->leftJoin('bnamapt as p', 'p.NPID', '=', 'g.GPT')
                ->where('g.GID', $h->PBUGUDANG)
                ->selectRaw("TRIM(CONCAT(COALESCE(p.NPNAMACLINIC,''), ' ', COALESCE(p.NPNAMA2,''))) as nama")
                ->value('nama')
            : null;

        $lines = $writer->lines($id);

        return app(PdfReport::class)->preview('reports.pr-print', [
            'title'        => 'Surat Permintaan Pelanggan',
            'company'      => ['nama' => $namaPt ?: 'NMW Clinic'],
            'nomor'        => $h->PBUNOTRANSAKSI,
            'tanggal'      => $h->PBUTANGGAL,
            'karyawan'     => $karyawan,
            'dariCabang'   => $dariCabang,
            'tipe'         => $tipe,
            'gudangTujuan' => $gudangTujuan,
            'noRef'        => $h->PBUNOREF,
            'lines'        => $lines,
            'totalQty'     => collect($lines)->sum('PBDQTY'),
        ]);
    }
}
