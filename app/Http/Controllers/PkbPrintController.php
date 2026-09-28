<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Perintah Kirim Barang" - PDF SATU dokumen PKB. Layout dari contoh cetakan lama user
 * (`Perintah Kirim Barang DE-PKB26090001.pdf` = `fperintahkirimbarangu` PKBUID 28382).
 * Tabel `fperintahkirimbarangu` + `fperintahkirimbarangd` (prefix PKBU / PKBD), BUKAN `fstoku`.
 *
 * **Strukturnya BEDA dari cetakan dokumen stok lain**: tidak pakai blok "Tujuan/Gudang/
 * Keterangan" yg biasa. Blok kanan atas = identitas PKB (No PKB + Tanggal), lalu sub-judul
 * **"Data Permintaan :"** yg isinya identitas PR ASAL (No Transaksi / Tanggal / No Ref), dan
 * di kanannya Gudang + Tipe. Kop kiri atas "NMW CLINIC" = `bnamapt.NPNAMACLINIC`
 * (jalur `PKBUGUDANG` -> `bgudang.GPT` -> `NPID`, sama spt cetakan PO/SJ).
 *
 * ## Yang DIAMBIL dari PR, bukan dari PKB (dikonfirmasi ke data)
 * - **"Gudang :" = gudang PR (`PBUGUDANG`)**, BUKAN `PKBUGUDANG`. Di contoh tercetak "Bali"
 *   sedangkan `PKBUGUDANG` PKB itu = 20 "Depo". Masuk akal: yg dicetak adalah gudang PEMINTA.
 * - "No Transaksi :" & "Tanggal :" di blok Data Permintaan = `PBUNOTRANSAKSI`/`PBUTANGGAL`
 *   (di contoh `BL-RS26080044` / 31/08/2026), bukan nomor PKB-nya.
 * - "No Ref :" = `PBUNOREF` (kosong di contoh, dan memang '' di data).
 * - **"Tipe" = `PBUTIPEPERMINTAAN` -> `blain.LNAMA`** (`LTIPE='Jenis Permintaan'`).
 *   `PKBUTIPEPERMINTAAN` NULL di dokumen contoh, jadi sumbernya PR.
 *
 * ## Dua hal yg SENGAJA beda dari cetakan lama
 * 1. **"Tipe" dicetak UTUH "Depo Farmasi", contoh menampilkan "Farmasi".** Nilai `blain` LID
 *    2187 memang "Depo Farmasi" (satu2nya baris ber-"Farmasi"). Memangkas awalan "Depo "
 *    hanya utk mencocokkan contoh akan MERUSAK nilai lain di tabel yg sama - "Depo Ciledug"
 *    jadi "Ciledug", "Depo Research" jadi "Research". Jadi dicetak apa adanya.
 * 2. **Nilai "Total Qty" DICETAK.** Di cetakan lama labelnya ada tapi ANGKANYA KOSONG
 *    (contoh: 1 baris qty 30,00, tapi di sebelah "Total Qty" tidak ada angka) - jelas cacat
 *    report lama. Dokumen ini gunanya justru memerintahkan pengiriman sejumlah barang, jadi
 *    totalnya diisi.
 *
 * **"Diperintah oleh" dibiarkan KOSONG** mengikuti contoh - sumbernya TIDAK KETEMU: dua
 * kandidat yg masuk akal, `PKBUKARYAWAN` & `PKBUKONTAK`, DUA2NYA berisi "Indah Nurhayati"
 * di dokumen contoh sedangkan cetakannya kosong (dan `PKBUKONTAK` terisi di 1.103/1.103 PKB).
 * Menebak salah satunya berarti mencetak nama orang yg belum tentu benar di dokumen perintah
 * kerja, jadi labelnya saja yg ditampilkan.
 *
 * Footer halaman di contoh PKB **tanpa awalan "Halaman"** (beda dari cetakan SJ/PB/KMB/TMB) -
 * direplikasi apa adanya.
 */
class PkbPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('purchase/pkb', 'print'), 403);

        $h = DB::table('fperintahkirimbarangu as p')
            ->leftJoin('bgudang as gp', 'gp.GID', '=', 'p.PKBUGUDANG')
            // Blok "Data Permintaan" + Gudang + Tipe semuanya dari PR - lihat docblock.
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'p.PKBUNORS')
            ->leftJoin('bgudang as gr', 'gr.GID', '=', 'pr.PBUGUDANG')
            ->leftJoin('blain as l', 'l.LID', '=', 'pr.PBUTIPEPERMINTAAN')
            ->where('p.PKBUID', $id)
            ->first([
                'p.PKBUID', 'p.PKBUNOTRANSAKSI', 'p.PKBUTANGGAL', 'p.PKBUSTATUS',
                'gp.GPT as ptId',
                'pr.PBUNOTRANSAKSI as prNomor', 'pr.PBUTANGGAL as prTanggal',
                'pr.PBUNOREF as prNoRef',
                'gr.GNAMA as prGudang', 'l.LNAMA as prTipe',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fperintahkirimbarangd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.PKBDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.PKBDSATUAN')
            ->where('d.PKBDIDSU', $id)
            ->orderBy('d.PKBDURUTAN')
            ->get([
                'i.INAMA as nama', 'd.PKBDQTY as qty',
                's.SKODE as satuan', 'd.PKBDCATATAN as catatan',
            ]);

        return $pdf->preview('reports.pkb-print', [
            'title'    => 'Perintah Kirim Barang ' . $h->PKBUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
            'clinic'   => (string) (DB::table('bnamapt')->where('NPID', (int) ($h->ptId ?? 0))
                ->value('NPNAMACLINIC') ?? ''),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
