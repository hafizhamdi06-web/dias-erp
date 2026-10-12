<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Gudang tujuan bawaan per Gudang Produksi
    |---------------------------------------------------------------------------
    |
    | Permintaan user 2026-10-12: "user tidak perlu rubah2 inputan gudang ini".
    | Saat Gudang Produksi dipilih, Gudang Jadi & Gudang Sample diisikan OTOMATIS -
    | tetap BISA diubah manual lewat kotak "Cari Gudang" di form.
    |
    | Ditaruh di config, BUKAN ditanam di kode, karena ini keputusan bisnis yg bisa
    | berubah (gudang baru, pindah PT) dan GID-nya beda antar basis data.
    |
    | Kunci  = GID gudang produksi.
    | `jadi`   = GID Gudang Jadi (SUGUDANGTUJUAN).
    | `sample` = GID Gudang Sample (SUGUDANGASAL), null kalau tidak dipakai.
    |
    | Gudang yg TIDAK terdaftar di sini jatuh ke aturan bawaan: **Gudang Jadi = Gudang
    | Produksi itu sendiri**, Gudang Sample dikosongkan (lihat `ProduksiForm`).
    |
    | ## Dasarnya
    | Dicek ke 41 baris `fstoku` SUSUMBER='PRO' yg ada:
    |   - 39 baris (semua ber-nomor `RP-`, Jun 2026) = RII Produksi (35) -> Bizpark (10)
    |     + RII Sample Bahan Jadi (36). Aturan di bawah cocok 100% dgn itu.
    |   - 2 baris sisanya (`PG-`, 22 Sep 2026, satu sudah batal) dari Petogogan ke Bizpark
    |     + RII Sample Bahan Baku. Tanggalnya pas saat modul Produksi ini dibangun, jadi
    |     itu sisa uji coba, BUKAN produksi nyata - sengaja tidak dijadikan acuan.
    |
    | VB6 `fFrmProduksi` sendiri TIDAK mengisi otomatis; ia MEMBATASI pilihan -
    | `CariLain txtGudangJadi, ..., IIf(xUserRII = True, " and gpt = 8 ", "")` - yaitu
    | hanya gudang milik PT. RII (`bnamapt` NPID 8: Bizpark, RII Bahan Baku, RII Produksi,
    | RII Sample Bahan Jadi, RII Sample Bahan Baku). Pengisian otomatis di sini LEBIH
    | JAUH dari VB6, dan itu memang yg diminta user.
    */

    'gudang_tujuan' => [
        // RII Produksi -> Gudang Jadi Bizpark, Sample RII Sample Bahan Jadi
        35 => ['jadi' => 10, 'sample' => 36],
    ],

];
