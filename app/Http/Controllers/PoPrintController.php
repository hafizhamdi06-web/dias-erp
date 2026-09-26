<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "PURCHASE ORDER" - PDF SATU dokumen PO. Pola sama `PrPrintController`
 * (dokumen transaksi, bukan laporan tabel; A4 mpdf).
 *
 * Layout & SUMBER TIAP FIELD direplikasi dari contoh cetakan sistem lama yg diberikan user
 * (`Order Pembelian GB-PO26090001.pdf`), dicocokkan ke `esalesorderu` SOUID=7009 -
 * **semua dikonfirmasi lewat query langsung, bukan tebakan**:
 * - Kop: `bnamapt` via `bgudang.GPT` dari `SOUCABANG` - `NPNAMA2` ("PT. Igyolini Indonesia"),
 *   `NPALAMAT`, `NPNAMACLINIC` ("NMW CLINIC", dicetak besar di kanan). Pola SAMA
 *   `PrPrintController` (per-cabang, BUKAN `ainfo` global).
 * - "Supplier" = `bkontak.KNAMA`; "Alamat" = **`K1ALAMAT` + `K1KOTA`** (slot 1, BUKAN K3 yg
 *   null utk vendor contoh; `K1KOTA` vendor contoh berisi "27819" dan di PDF asli memang
 *   tercetak di baris sendiri - direplikasi apa adanya). `SOUALAMAT` (snapshot alamat di
 *   header) SENGAJA TIDAK dipakai: di PO contoh isinya cuma baris kosong + "-".
 * - "No Transaksi"/"Tanggal" dari header; "Termin" = `btermin.TKODE` ("30");
 *   "Di Kirim Ke" = `bgudang.GNAMA` dari `SOUCABANG` ("Gudang Bahan Baku").
 * - Tabel: "Nama Item" = **`bitem.IPONAMA`** (nama versi supplier, mis. "Peel off mask
 *   Calendula 1000 gram" - BUKAN `INAMA` "MASK CALENDULA PO 1 KG"), fallback ke `INAMA`
 *   kalau kosong. "Kemasan" = `IPOKEMASAN`. "Satuan" = `bsatuan.SKODE`.
 *   **DUA kolom "Harga"**: kiri = `SODHARGA` (harga list), kanan = harga SETELAH diskon.
 * - **"Sub Total" per baris DIHITUNG (qty x harga-setelah-diskon), TIDAK membaca
 *   `SODSUBTOTAL`** - di data nyata kolom itu 0 untuk PO contoh (tidak pernah diisi sistem
 *   lama) padahal PDF menampilkan 2.000.000. Sub Total bawah = jumlah baris, dicocokkan
 *   dgn `SOUSUBTOTAL`.
 * - "Diinput Oleh" = `auser.UNAMA` dari `SOUCREATEU` + HP dari `bkontak.K1TELP1` via
 *   `auser.UKID`.
 *
 * **3 baris izin di kop (No Izin / apt. / SIPA) TIDAK ADA DI DATABASE MANAPUN** - dicek
 * `information_schema`: tidak ada kolom IZIN/SIPA/APOTEK di skema ini, dan `ainfo` pun tidak
 * memuatnya; di sistem lama teks itu hardcode di template report. Di sini ditaruh di
 * **`config/po_print.php` per NPID** (badan hukum), BUKAN hardcode di blade - krn SIPA
 * adalah identitas apoteker yg BEDA per PT; kalau PT-nya belum terdaftar di config, ketiga
 * baris itu tidak dicetak sama sekali (lebih baik kosong daripada mencetak SIPA milik PT lain).
 * Penanda tangan "Diketahui Oleh" (apoteker) + HP-nya juga dari config yg sama - di PO contoh
 * HP 0851 5637 9562 terbukti milik "Leo Arif Prasetyadi", apoteker yg sama dgn di kop.
 *
 * **"Jenis" (`esalesorderu.SOUJENIS`) BELUM JELAS PEMETAANNYA** - PO contoh `SOUJENIS=1`
 * dicetak "Produk OTC"; data nyata cuma punya nilai 0 (16 PO) & 1 (220 PO), dan form VB6 yg
 * kita punya TIDAK menyebut kolom itu sama sekali, jadi label utk 0 TIDAK diketahui.
 * Dipetakan di `config/po_print.php` (`jenis`), nilai tak dikenal dicetak "-" (BUKAN tebakan).
 * Form PO kita sendiri belum punya field Jenis, jadi PO baru akan kosong - perlu dikonfirmasi
 * ke user opsi Jenis-nya apa saja.
 */
class PoPrintController extends Controller
{
    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do('purchase/po', 'print'), 403);

        $h = DB::table('esalesorderu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SOUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SOUCABANG')
            ->leftJoin('btermin as t', 't.TID', '=', 'u.SOUTERMIN')
            ->leftJoin('auser as us', 'us.UID', '=', 'u.SOUCREATEU')
            ->leftJoin('bkontak as pk', 'pk.KID', '=', 'us.UKID')
            ->where('u.SOUID', $id)->where('u.SOUSUMBER', 'PO')
            ->first([
                'u.SOUID', 'u.SOUNOTRANSAKSI', 'u.SOUTANGGAL', 'u.SOUJENIS', 'u.SOUCATATAN',
                'u.SOUSUBTOTAL', 'u.SOUDISKON', 'u.SOUDISKONPERSEN', 'u.SOUTOTALPAJAK',
                'u.SOUTOTALTRANSAKSI', 'u.SOUSTATUS', 'u.SOUCABANG',
                'k.KNAMA as vendor', 'k.K1ALAMAT as vendorAlamat', 'k.K1KOTA as vendorKota',
                'g.GNAMA as gudang', 'g.GPT as ptId',
                't.TKODE as termin',
                'us.UNAMA as dibuatOleh', 'pk.K1TELP1 as dibuatHp',
            ]);

        abort_if(! $h, 404);

        $pt = $h->ptId
            ? DB::table('bnamapt')->where('NPID', $h->ptId)
                ->first(['NPID', 'NPNAMA2', 'NPALAMAT', 'NPNAMACLINIC'])
            : null;

        // Baris izin/apoteker khusus PT ini - lihat docblock kelas.
        $extra = config('po_print.pt.' . ($pt->NPID ?? 0), []);

        $lines = DB::table('esalesorderd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SODITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SODSATUAN')
            ->where('d.SODIDSOU', $id)
            ->orderBy('d.SODURUTAN')
            ->get([
                'd.SODORDER as qty', 'd.SODHARGA as harga', 'd.SODDISKON as diskonRp',
                'd.SODDISKONPERSEN as diskonPersen',
                'i.IPONAMA', 'i.INAMA', 'i.IPOKEMASAN', 's.SKODE as satuan',
            ])
            ->map(function ($r) {
                // Harga bersih = harga list - diskon Rp - diskon % (bertingkat, pola sama
                // `PurchaseOrderWriter::hitungDiskonBaris()`).
                $harga = (float) $r->harga;
                $bersih = $harga - (float) $r->diskonRp;
                $bersih -= $bersih * ((float) $r->diskonPersen / 100);

                return (object) [
                    'nama'         => $r->IPONAMA ?: ($r->INAMA ?? ''),
                    'kemasan'      => $r->IPOKEMASAN ?: '',
                    'qty'          => (float) $r->qty,
                    'satuan'       => $r->satuan ?: '',
                    'harga'        => $harga,
                    'diskonPersen' => (float) $r->diskonPersen,
                    'hargaBersih'  => $bersih,
                    'subTotal'     => (float) $r->qty * $bersih,
                ];
            });

        $subTotal = $lines->sum('subTotal');
        $diskon = (float) $h->SOUDISKON;
        $pajak = (float) $h->SOUTOTALPAJAK;

        return $pdf->preview('reports.po-print', [
            'title'    => 'Order Pembelian ' . $h->SOUNOTRANSAKSI,
            'h'        => $h,
            'pt'       => $pt,
            'extra'    => $extra,
            'jenis'    => config('po_print.jenis.' . (int) $h->SOUJENIS, '-'),
            'lines'    => $lines,
            'totalQty' => $lines->sum('qty'),
            'subTotal' => $subTotal,
            'diskon'   => $diskon,
            'pajak'    => $pajak,
            'total'    => $subTotal - $diskon + $pajak,
        ], ['size' => 'A4', 'orientasi' => 'P']);
    }
}
