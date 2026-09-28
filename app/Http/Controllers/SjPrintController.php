<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use App\Services\SjWriter;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Surat Jalan" - PDF SATU dokumen SJ. Layout direplikasi dari contoh cetakan lama user
 * (`Surat Jalan DE-SJ26090001.pdf` = `fstoku` SUID 1288618).
 *
 * ## Kop APOTEK - beda dari cetakan dokumen stok lain
 * PB/KMB/TMB/PY/PL sama sekali tanpa kop; SJ justru berkop identitas APOTEK (3 baris):
 * nama apotek, "Apoteker : ...", "SIPA : ...". Ketiganya **TIDAK ADA di database** (dicek:
 * tidak ada kolom IZIN/SIPA/APOTEK di skema; `ainfo`/`bnamapt`/`bgudang` tidak memuatnya) -
 * di sistem lama hardcode di template report. Diambil dari **`config/dokumen_print.php`
 * per `bnamapt.NPID`**, jalur sama cetakan PO: `SUCABANG` -> `bgudang.GPT` -> `NPID`.
 * Dikonfirmasi ke contoh: SJ dari gudang 20 "Depo" -> `GNAMAPT` "PT. Igyolini Indonesia" =
 * NPID 1, dan SIPA di config **cocok persis** dgn yg tercetak di contoh.
 * PT yg belum terdaftar di config -> kop dikosongkan, BUKAN ditebak.
 *
 * ## "No PO" pada contoh SEBENARNYA nomor Permintaan Barang
 * Label "No PO" tapi isinya `BA-RS26080047` - dirunut: `SJ.SUNOSO` -> `fperintahkirimbarangu`
 * (PKB `DE-PKB26090003`) -> `PKBUNORS` -> `fpermintaanbarangu.PBUNOTRANSAKSI` = `BA-RS26080047`.
 * Jadi yg tercetak adalah nomor **PR/RS**, bukan Purchase Order. Label menyesatkan ini
 * DIPERTAHANKAN sesuai contoh (pola sama "Tujuan :" di cetakan PB yg isinya nama vendor).
 *
 * ## Asal kolom lain (semua dikonfirmasi ke contoh)
 * "Tujuan :" = `bkontak.KNAMA` (di contoh "PT Exclusive Igyolini Healthcare"),
 * "Gudang Tujuan :" = `SUGUDANGTUJUAN`, "Gudang Sumber :" = `SUCABANG` (perhatikan: cetakan
 * KMB menamai kolom yg sama "Gudang Asal"), "Keterangan :" = `SUURAIAN`.
 * Tabel **5 kolom: No | Nama Item | Keluar | Satuan | Catatan** - **TIDAK ADA kolom kode
 * item** (beda dari cetakan KMB/TMB/PB yg punya kolom "Item"). Qty dari `SDKELUAR` (SJ =
 * dokumen KELUAR). Tanda tangan "Bag Apotik" / "Penerima" sebaris dgn "Total Qty".
 *
 * **DEFER**: No Batch (`SDSERIAL`) tidak dicetak - contoh cetakan tidak memuatnya, padahal
 * form SJ sudah mendukung pilih batch. Kalau nanti diminta, datanya sudah tersedia lewat
 * `SerialBatch::unpack()`.
 */
class SjPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('sales/sj', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as gs', 'gs.GID', '=', 'u.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            // "No PO" di contoh = nomor PR, lewat PKB - lihat docblock kelas.
            ->leftJoin('fperintahkirimbarangu as pkb', 'pkb.PKBUID', '=', 'u.SUNOSO')
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'pkb.PKBUNORS')
            ->where('u.SUID', $id)->where('u.SUSUMBER', SjWriter::SUMBER)
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUURAIAN', 'u.SUSTATUS',
                'k.KNAMA as kontak',
                'gs.GNAMA as gudangSumber', 'gs.GPT as ptId',
                'gt.GNAMA as gudangTujuan',
                'pr.PBUNOTRANSAKSI as noPr',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.INAMA as nama', 'd.SDKELUAR as qty',
                's.SKODE as satuan', 'd.SDCATATAN as catatan',
            ]);

        return $pdf->preview('reports.sj-print', [
            'title'    => 'Surat Jalan ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
            // Identitas apotek per badan hukum - kosong kalau PT belum terdaftar.
            'apotek'   => config('dokumen_print.pt.' . ((int) ($h->ptId ?? 0)), []),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
