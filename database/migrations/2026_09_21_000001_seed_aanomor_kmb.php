<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` utk dokumen "Kirim Mutasi Barang" (KMB) -
 * alur paralel PR jenis=0 (Permintaan Barang) yg TANPA verifikasi, RS -> KMB -> TMB.
 * Idempotent by NKODE, pola sama `2026_09_18_000001_seed_aanomor_pkb.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'KMB')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'KMB',
                'NKETERANGAN'     => 'Kirim Mutasi Barang',
                'NTABEL'          => 'fstoku',
                'NFLDTANGGAL'     => 'SUTANGGAL',
                'NFLDSUMBER'      => 'SUSUMBER',
                'NFLDNOTRANSAKSI' => 'SUNOTRANSAKSI',
                'NFLDURAIAN'      => 'SUURAIAN',
                'NFLDTOTALTRANS'  => 'SUTOTALTRANSAKSI',
                'NFLDKONTAK'      => 'SUKONTAK',
                'NFLDID'          => 'SUID',
                'NFA'             => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('aanomor')->where('NKODE', 'KMB')->delete();
    }
};
