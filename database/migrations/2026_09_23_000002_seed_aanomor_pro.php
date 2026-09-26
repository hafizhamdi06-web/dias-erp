<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` utk dokumen "Produksi" (PRO) - tabel
 * `fstoku` (SAMA infra SJ/PBC/KMB/TMB, eksekusi stok nyata). Idempotent by NKODE, pola
 * sama modul2 sblmnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'PRO')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'PRO',
                'NKETERANGAN'     => 'Produksi',
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
        DB::table('aanomor')->where('NKODE', 'PRO')->delete();
    }
};
