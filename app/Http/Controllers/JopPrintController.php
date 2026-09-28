<?php

namespace App\Http\Controllers;

use App\Services\JopWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Job Order Produksi" - PDF SATU dokumen JOP. Layout dari contoh cetakan lama user
 * (`Job Order Produksi RP-JOP26090001.pdf`). Tanpa kop.
 *
 * Tabel `fproduksiu` + `fproduksid` (prefix PU / PD) dgn `PUSUMBER='JOP'` - BUKAN
 * `fstoku`/`fstokd` spt Produksi (PRO). JOP = RENCANA kerja, PRO = eksekusinya.
 *
 * **Tabel 5 kolom: No | Item | Nama Item | Qty Masuk | Qty Keluar** (`PDMASUK`/`PDKELUAR`),
 * dgn **dua Total Qty** berdampingan. Mirip cetakan Produksi, TAPI:
 * - label kolomnya "Qty Masuk"/"Qty Keluar" (Produksi: "Qty Produk Jadi"/"Qty Bahan Baku"),
 * - **TIDAK ADA kolom Satuan** (Produksi punya),
 * - tanda tangannya DUA dan **BERNAMA** (Produksi cuma satu, kosong).
 *
 * ## Tanda tangan bernama - satu dari data, satu dari config
 * - **"Di Buat Oleh" = `bkontak.KNAMA` dari `PUKONTAK`** - di contoh "( Andi Andrian )",
 *   sama persis dgn isi "Tujuan :" di blok atas.
 * - **"Di Setujui Oleh" = config `dokumen_print.pt.<NPID>.penyetuju_jop`**. Namanya TIDAK ADA
 *   di database: `fproduksiu` tidak punya kolom approver sama sekali (`PUKARYAWAN` NULL di
 *   kedua JOP lokal) dan "Dra. TRI WAHYUNI, Apt." tidak ada persis di `bkontak` (terdekat
 *   "` TRI WAHYUNI.`" KID 21788, tanpa gelar) - jelas hardcode di report lama, pola SAMA
 *   apoteker/SIPA di cetakan PO & SJ. NPID didapat lewat `PUCABANG` -> `bgudang.GPT`;
 *   gudang 35 "RII Produksi" -> **NPID 8 "PT. Royal Igyolini Indonesia"**.
 *   PT yg belum terdaftar -> kurungnya dicetak KOSONG, bukan diisi nama PT lain.
 *
 * "Tujuan :" = `bkontak.KNAMA` (nama ORANG) - label legacy menyesatkan yg dipertahankan,
 * pola sama semua cetakan sekeluarga. "Gudang :" = `PUCABANG`.
 *
 * **CATATAN**: dokumen contoh `RP-JOP26090001` TIDAK ADA di DB lokal - hanya ada 2 JOP
 * (`PG-JOP26090001/2`, gudang Petogogan). Sama spt cetakan Produksi yg contohnya (`RP-PRO...`)
 * juga tidak ada: dokumen ber-prefix RP (RII Produksi) tampaknya belum ikut terimpor ke
 * salinan lokal ini.
 */
class JopPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('pabrik/jop', 'print'), 403);

        $h = DB::table('fproduksiu as p')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'p.PUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'p.PUCABANG')
            ->where('p.PUID', $id)->where('p.PUSUMBER', JopWriter::SUMBER)
            ->first([
                'p.PUID', 'p.PUNOTRANSAKSI', 'p.PUTANGGAL', 'p.PUURAIAN', 'p.PUSTATUS',
                'k.KNAMA as kontak', 'g.GNAMA as gudang', 'g.GPT as ptId',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fproduksid as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.PDITEM')
            ->where('d.PDIDSU', $id)
            ->orderBy('d.PDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama',
                'd.PDMASUK as masuk', 'd.PDKELUAR as keluar',
            ]);

        return $pdf->preview('reports.jop-print', [
            'title'       => 'Job Order Produksi ' . $h->PUNOTRANSAKSI,
            'h'           => $h,
            'lines'       => $lines,
            'totalMasuk'  => $lines->sum('masuk'),
            'totalKeluar' => $lines->sum('keluar'),
            // Kosong kalau PT-nya belum terdaftar - lihat docblock kelas.
            'penyetuju'   => (string) config('dokumen_print.pt.' . ((int) ($h->ptId ?? 0)) . '.penyetuju_jop', ''),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
