<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 1 baris baru ke tabel legacy `aanomor` utk dokumen "Job Order Produksi" (JOP) -
 * tabel `fproduksiu` (BUKAN `fstoku`, dokumen rencana tanpa trigger stok). Idempotent by
 * NKODE, pola sama modul2 sblmnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('aanomor')->where('NKODE', 'JOP')->exists();

        if (! $exists) {
            DB::table('aanomor')->insert([
                'NKODE'           => 'JOP',
                'NKETERANGAN'     => 'Job Order Produksi',
                'NTABEL'          => 'fproduksiu',
                'NFLDTANGGAL'     => 'PUTANGGAL',
                'NFLDSUMBER'      => 'PUSUMBER',
                'NFLDNOTRANSAKSI' => 'PUNOTRANSAKSI',
                'NFLDURAIAN'      => 'PUURAIAN',
                'NFLDTOTALTRANS'  => 'PUTOTALTRANSAKSI',
                'NFLDKONTAK'      => 'PUKONTAK',
                'NFLDID'          => 'PUID',
                'NFA'             => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('aanomor')->where('NKODE', 'JOP')->delete();
    }
};
