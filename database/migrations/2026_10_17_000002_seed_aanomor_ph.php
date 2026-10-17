<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed `aanomor` utk dokumen "Perubahan Harga" (PH) - tabel `gperubahanhargau`. VB6
 * `fFrmPerubahanHarga` memanggil `pNoTransaksi("PH", ...)` tapi baris `aanomor`-nya tidak
 * pernah ada di DB ini (form itu belum pernah dipakai: tabelnya 0 baris). Idempotent by
 * NKODE, pola sama seed PKB/PBC/KMB/TMB/JOP/PRO.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('aanomor')->where('NKODE', 'PH')->exists()) {
            return;
        }

        DB::table('aanomor')->insert([
            'NKODE'           => 'PH',
            'NKETERANGAN'     => 'Perubahan Harga',
            'NTABEL'          => 'gperubahanhargau',
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

    public function down(): void
    {
        DB::table('aanomor')->where('NKODE', 'PH')->delete();
    }
};
