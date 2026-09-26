<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` utk dokumen "Penerimaan Barang Cabang"
 * (PBC) - tahap terakhir alur PR/RS -> PKB -> SJ -> PBC. Idempotent by NKODE, pola sama
 * `2026_09_18_000001_seed_aanomor_pkb.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'PBC')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'PBC',
                'NKETERANGAN'     => 'Penerimaan Barang',
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
        DB::table('aanomor')->where('NKODE', 'PBC')->delete();
    }
};
