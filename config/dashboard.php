<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Sejak kapan COGS diambil dari `fstokd.SDBELI`
    |---------------------------------------------------------------------------
    |
    | Permintaan user 2026-10-13: mulai **Agustus 2026** kolom `SDBELI` diisi
    | `ICOGS x SDKELUAR` - yaitu COGS pada SAAT transaksi terjadi. Transaksi sejak
    | tanggal ini memakai `SDBELI`; **Juli ke bawah** tetap dihitung
    | `SDKELUAR x bitem.ICOGS`.
    |
    | ## Kenapa ini penting (bukan sekadar sumber lain utk angka yg sama)
    | `bitem.ICOGS` adalah master data yg BERUBAH seiring waktu. Menghitung COGS
    | transaksi lama dgn ICOGS hari ini = menilai ulang masa lalu dgn harga sekarang.
    | `SDBELI` membekukan COGS saat transaksi dibuat, jadi laporan bulan lampau tidak
    | ikut bergeser tiap master diubah.
    |
    | ## Kenapa BATAS TANGGAL, bukan sekadar "pakai SDBELI kalau terisi"
    | `SDBELI` punya makna ASLI lain di modul pembelian (harga beli/HPP baris
    | penerimaan). Memakai "kalau terisi" berisiko diam-diam menarik angka ber-makna
    | lain di data lama. Batas tanggal membuat jangkauannya tegas.
    |
    | ## COCOK DGN DATA (diukur 2026-10-13 di `data_pos_nmw_2023`)
    | Baris IP ber-`SDBELI` terisi: Juni 0 dari 2.750, **Agustus 1.303 dari 2.045**,
    | September 0 dari 21. Dan di mana pun terisi, nilainya SAMA PERSIS
    | `ICOGS x SDKELUAR` - 1.303 dari 1.303, nol selisih. Jadi batas 1 Agustus di bawah
    | bukan cuma keterangan user, tapi juga yg terlihat di data.
    |
    | (Sempat disetel 2026-09-01 krn salah tangkap bulannya; dibetulkan user.)
    */

    'cogs_dari_sdbeli_sejak' => '2026-08-01',

];
