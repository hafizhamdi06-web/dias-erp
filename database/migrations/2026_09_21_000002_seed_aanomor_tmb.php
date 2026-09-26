<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` utk dokumen "Terima Mutasi Barang" (TMB) -
 * tahap terakhir alur paralel PR jenis=0, RS -> KMB -> TMB. Idempotent by NKODE, pola
 * sama `2026_09_18_000002_seed_aanomor_pbc.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'TMB')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'TMB',
                'NKETERANGAN'     => 'Terima Mutasi Barang',
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
        DB::table('aanomor')->where('NKODE', 'TMB')->delete();
    }
};
