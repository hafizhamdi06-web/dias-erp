<?php

namespace App\Http\Controllers;

use App\Services\PbWriter;
use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "Penerimaan Barang" - PDF SATU dokumen PB. Pola sama `PoPrintController`/
 * `PrPrintController` (dokumen 1 transaksi, mpdf A4).
 *
 * Layout direplikasi dari contoh cetakan sistem lama yg diberikan user
 * (`Penerimaan BarangRB-PB26080001.pdf` = `fstoku` SUID 1261469), header dicocokkan lewat
 * query langsung:
 * - **"Tujuan :" = `bkontak.KNAMA` (VENDOR/supplier)** - label legacy yg menyesatkan
 *   (terdengar seperti gudang tujuan, isinya justru nama supplier "PT ROI SURYA PRIMA
 *   FARMA"). Dipertahankan apa adanya krn user minta sama dgn cetakan lama.
 * - "Gudang :" = `bgudang.GNAMA` dari `SUCABANG`; "Keterangan :" = `SUURAIAN`;
 *   "No Transaksi :" = `SUNOTRANSAKSI`; "Tanggal :" = `SUTANGGAL`;
 *   "No Invoice :" = `SUNOREF`.
 * - Tabel: "Item" = `bitem.IKODE`, "Nama Item" = `bitem.INAMA` (dikonfirmasi dari item 4843
 *   di contoh: IKODE "ROI PARANOX SPF 50 PPY", INAMA "PARANOX MOISTURIZING SUNSCREEN ...").
 *   "Qty Masuk" = `SDMASUK`, "Satuan" = `bsatuan.SKODE`, "No PO" ditelusuri
 *   `SDSODID` -> `esalesorderd.SODIDSOU` -> `esalesorderu.SOUNOTRANSAKSI`.
 * - Bawah: tanda tangan "Bag Gudang" & "Penerima" (label statis, tidak ada data
 *   penandatangan di DB) + "Total Qty".
 *
 * **TIDAK ADA kop perusahaan** di cetakan ini (beda dari PO yg berkop `bnamapt`) - contoh
 * asli memang cuma berjudul "Penerimaan Barang", tanpa nama/alamat PT.
 *
 * **Harga TIDAK dicetak** - konsisten dgn form PB yg kolom Harga-nya disembunyikan
 * (nilainya tetap tersimpan di `SDHARGA` utk HPP nanti).
 */
class PbPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('purchase/receipt', 'print'), 403);

        $h = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUID', $id)->where('u.SUSUMBER', PbWriter::SUMBER)
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUNOREF', 'u.SUURAIAN',
                'u.SUSTATUS', 'k.KNAMA as vendor', 'g.GNAMA as gudang',
            ]);

        abort_if(! $h, 404);

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->leftJoin('esalesorderd as sod', 'sod.SODID', '=', 'd.SDSODID')
            ->leftJoin('esalesorderu as sou', 'sou.SOUID', '=', 'sod.SODIDSOU')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE as kode', 'i.INAMA as nama', 'd.SDMASUK as qty',
                's.SKODE as satuan', 'sou.SOUNOTRANSAKSI as noPo',
            ]);

        return $pdf->preview('reports.pb-print', [
            'title'    => 'Penerimaan Barang ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
