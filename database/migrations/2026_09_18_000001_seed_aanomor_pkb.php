<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` (config nomor transaksi, dipakai lintas
 * CI3/CI4/Laravel) untuk dokumen baru "Perintah Kirim Barang" (PKB). TIDAK mengubah baris
 * manapun yang sudah ada (khususnya `RS`=Permintaan Barang) - idempotent by NKODE, cuma
 * insert kalau kode 'PKB' belum ada. Nilai kolom NFLD* meniru pola baris `RS` yang sudah
 * ada (lihat App\Services\PurchaseRequestWriter), disesuaikan ke kolom PKBU*.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'PKB')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'PKB',
                'NKETERANGAN'     => 'Perintah Kirim Barang',
                'NTABEL'          => 'fperintahkirimbarangu',
                'NFLDTANGGAL'     => 'PKBUTANGGAL',
                'NFLDSUMBER'      => 'PKBUSUMBER',
                'NFLDNOTRANSAKSI' => 'PKBUNOTRANSAKSI',
                'NFLDURAIAN'      => 'PKBUURAIAN',
                'NFLDTOTALTRANS'  => '',
                'NFLDKONTAK'      => 'PKBUKONTAK',
                'NFLDID'          => 'PKBUID',
                'NFA'             => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('aanomor')->where('NKODE', 'PKB')->delete();
    }
};
