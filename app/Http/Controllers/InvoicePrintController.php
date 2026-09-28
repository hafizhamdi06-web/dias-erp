<?php

namespace App\Http\Controllers;

use App\Services\InvoicePenjualanWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "INVOICE" penjualan (IV, sumber Surat Jalan) - PDF SATU dokumen. Layout direplikasi
 * dari contoh cetakan lama user (`Invoice Penjualan BZ-IV26090001.pdf` = IPUID 6265).
 * Seluruh layout & perhitungan ada di `InvoicePrintBase` (dipakai bersama IVM); di sini
 * hanya rantai dokumen sumbernya.
 *
 * ## Kop surat PER CABANG - BUKAN dari `ainfo`
 * Beda besar dari cetakan dokumen stok (PB/KMB/TMB/PY/PL) yg semuanya tanpa kop. Dikonfirmasi
 * ke data: `ainfo.inama` = "NMW Clinic" dgn SEMUA alamat "-", sedangkan cetakan menampilkan
 * "PT. Royal Igyolini Indonesia" + alamat Bizpark. Sumbernya `bgudang` dari `IPUGUDANG`:
 * - `GNAMAPT` = badan hukum cabang (gudang 10 -> "PT. Royal Igyolini Indonesia", gudang 1 ->
 *   "PT. Igyolini Indonesia" - **beda PT per cabang, JANGAN di-hardcode**).
 * - `GALAMAT2` = alamat, dan **baris "Telpon : ..." SUDAH ada di dalamnya** (ada newline;
 *   `GTELP` gudang 10 justru kosong) -> dirender `nl2br()`, jangan digabung manual dgn GTELP.
 *
 * ## Kolom yg TIDAK BISA dipercaya - dihitung ulang dari baris
 * **`IPUSUBTOTAL` = 0 DAN `IPDSUBTOTAL`/`IPDTOTAL` = 0 di SELURUH data impor** (invoice 6265:
 * total 19.781.726,25 & pajak 1.960.351,25 terisi, subtotal 0). `SUM(qty*(harga-diskon))` =
 * 17.821.375 = PERSIS angka contoh. Pola SAMA `SDSUBTOTAL` di PO lama.
 *
 * ## Rantai sumber IV: SJ -> PBC
 * - **No SJ** = `IPDSUID` -> `fstoku.SUNOTRANSAKSI`. **BUKAN `IPDSJD`/`IPDSDID`** - keduanya
 *   NULL di data impor (walau `InvoicePenjualanWriter::create()` mengisinya utk invoice baru).
 * - **No PBC** = `fstoku` `SUSUMBER='PBC'` dgn **`SUNOSJAPOTIK` = id SJ**. PBC TIDAK punya FK
 *   SJ di kolom yg "kedengaran benar" (`SUPBUID`/`SUSOUID`/`SUNOSO`/`SUNOREFTRANSAKSI` NULL).
 *   Satu SJ bisa diterima >1 PBC; digabung koma.
 * - **Gudang yg ditagih** = `SJ.SUGUDANGTUJUAN` -> dipakai "Kepada Yth" & blok rekap.
 *
 * ## Beda SENGAJA dari cetakan lama (2, keduanya perbaikan)
 * 1. Kolom Nama di cetakan lama **TERPOTONG** kalau panjang ("NMW SELECTION DAILY SUNSCREI|").
 *    Di sini WRAP - nama item terpotong di dokumen tagihan kehilangan informasi, bukan gaya.
 * 2. Baris "No Surat Jalan :"/"No PBC :" lama diawali koma nyasar (artefak string-concat) -
 *    di sini digabung rapi.
 *
 * ## CATATAN DATA IMPOR
 * **3.716 dari 11.450 baris (32%) punya `IPDSUID` YATIM** (menunjuk `fstoku.SUID` yg tidak
 * ada; `IPDSUID` NULL: 0). Akibatnya 70 dari 208 invoice tidak punya gudang tujuan -> kolom
 * No SJ & No PBC kosong dan "Kepada Yth" jatuh ke nama kontak. Masalah DATA (SJ sumber tidak
 * ikut terimpor / id di-remap), BUKAN bug cetakan - begitu SJ-nya masuk dgn id yg sama,
 * ketiganya terisi sendiri tanpa ubah kode.
 */
class InvoicePrintController extends InvoicePrintBase
{
    protected function sumber(): string
    {
        return InvoicePenjualanWriter::SUMBER;
    }

    protected function aclPath(): string
    {
        return 'sales/invoice';
    }

    protected function labelKolom(): array
    {
        return ['No SJ', 'No PBC'];
    }

    protected function isiSumber(Collection $lines): void
    {
        $sjIds = $lines->pluck('sumberId')->filter()->unique()->values()->all();

        $sj = $sjIds === [] ? collect() : DB::table('fstoku as u')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUGUDANGTUJUAN')
            ->whereIn('u.SUID', $sjIds)
            ->get(['u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'g.GNAMA as tujuan'])
            ->keyBy('id');

        // Satu SJ bisa diterima lebih dari satu PBC - lihat docblock kelas.
        $pbcPerSj = [];
        if ($sjIds !== []) {
            foreach (DB::table('fstoku')
                ->where('SUSUMBER', 'PBC')->whereIn('SUNOSJAPOTIK', $sjIds)
                ->orderBy('SUID')
                ->get(['SUNOSJAPOTIK as sjId', 'SUNOTRANSAKSI as nomor']) as $p) {
                $pbcPerSj[(int) $p->sjId][] = $p->nomor;
            }
        }

        foreach ($lines as $l) {
            $s = $sj->get((int) $l->sumberId);
            $l->noSumber1 = $s->nomor ?? '';
            $l->noSumber2 = implode(', ', $pbcPerSj[(int) $l->sumberId] ?? []);
            $l->tujuan = $s->tujuan ?? null;
        }
    }
}
