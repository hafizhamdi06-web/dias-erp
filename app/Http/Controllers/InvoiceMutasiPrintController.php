<?php

namespace App\Http\Controllers;

use App\Services\InvoicePenjualanMutasiWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cetak "INVOICE" Penjualan Mutasi (IVM) - **layout SAMA PERSIS `InvoicePrintController`**
 * (permintaan user: "layout sama, beda penarikan saja"), seluruhnya di `InvoicePrintBase`.
 * Yang beda hanya rantai dokumen sumbernya: **IVM menarik dari TMB, bukan SJ**.
 *
 * IV & IVM berbagi tabel `einvoicepenjualanu`/`einvoicepenjualand`, dibedakan `IPUSUMBER`.
 *
 * ## Rantai sumber IVM: KMB -> TMB
 * Sejajar dgn IV (dokumen KELUAR dulu, lalu dokumen TERIMA):
 * - **No TMB** = `IPDSUID` -> `fstoku.SUNOTRANSAKSI` (`SUSUMBER='TMB'`) - dokumen terima.
 * - **No KMB** = `TMB.SUPRUID` -> `fstoku.SUID` - dokumen kirim yg jadi asal TMB itu.
 * - **Gudang yg ditagih** = **`TMB.SUCABANG`** (konvensi `TmbWriter`: cabang PEMBUAT TMB =
 *   cabang PENERIMA barang) -> dipakai "Kepada Yth" & blok rekap.
 * Diverifikasi ke data: `KM-IVM26060001` (penerbit Kalimalang) -> TMB `CP-TMB26060004` cabang
 * 2 Ciputat, `SUPRUID` -> KMB `KM-KMB26060001` yg `SUCABANG`-nya 4 = Kalimalang = persis
 * `IPUGUDANG` invoice. Rantainya konsisten.
 *
 * **`IPUGUDANGTUJUAN` TIDAK DIPAKAI** walau kolomnya ada & secara konsep paling tepat
 * ("cabang yg ditagih", diisi `InvoicePenjualanMutasiWriter::create()` utk dokumen baru):
 * **NULL di SELURUH 107 IVM hasil impor**. Ditelusuri dari TMB supaya dokumen lama & baru
 * sama2 tampil benar.
 *
 * ## CATATAN DATA IMPOR (lebih parah dari IV)
 * Dari 525 baris IVM: **265 menunjuk TMB (benar), 258 YATIM** (`IPDSUID` tidak ada di
 * `fstoku`), dan **2 baris menunjuk dokumen `IP`** (POS) - bukan TMB. Baris yatim & salah
 * sumber tetap dicetak dgn kolom dokumen kosong, tidak dibuang: nilainya harus tetap masuk
 * Sub Total. Masalah DATA, bukan bug cetakan.
 */
class InvoiceMutasiPrintController extends InvoicePrintBase
{
    protected function sumber(): string
    {
        return InvoicePenjualanMutasiWriter::SUMBER;
    }

    protected function aclPath(): string
    {
        return 'sales/invoice-mutasi';
    }

    protected function judulDokumen(): string
    {
        return 'Invoice Penjualan Mutasi';
    }

    protected function labelKolom(): array
    {
        return ['No KMB', 'No TMB'];
    }

    protected function isiSumber(Collection $lines): void
    {
        $tmbIds = $lines->pluck('sumberId')->filter()->unique()->values()->all();

        // TMB + cabang penerima + KMB asalnya lewat SUPRUID - lihat docblock kelas.
        $tmb = $tmbIds === [] ? collect() : DB::table('fstoku as t')
            ->leftJoin('bgudang as g', 'g.GID', '=', 't.SUCABANG')
            ->leftJoin('fstoku as kmb', 'kmb.SUID', '=', 't.SUPRUID')
            ->whereIn('t.SUID', $tmbIds)
            ->get([
                't.SUID as id', 't.SUNOTRANSAKSI as nomor', 't.SUSUMBER as sumber',
                'g.GNAMA as tujuan', 'kmb.SUNOTRANSAKSI as noKmb',
            ])
            ->keyBy('id');

        foreach ($lines as $l) {
            $t = $tmb->get((int) $l->sumberId);
            $l->noSumber1 = $t->noKmb ?? '';
            $l->noSumber2 = $t->nomor ?? '';
            $l->tujuan = $t->tujuan ?? null;
        }
    }
}
