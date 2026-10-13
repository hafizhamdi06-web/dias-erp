<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Sejak kapan COGS diambil dari `fstokd.SDBELI`
    |---------------------------------------------------------------------------
    |
    | Permintaan user 2026-10-13: mulai September 2026 kolom `SDBELI` diisi
    | `ICOGS x SDKELUAR` - yaitu COGS pada SAAT transaksi terjadi. Transaksi sejak
    | tanggal ini memakai `SDBELI`; sebelumnya tetap dihitung `SDKELUAR x bitem.ICOGS`.
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
    | ## CATATAN DATA (diukur 2026-10-13 di salinan lokal `data_pos_nmw_2023`)
    | Di salinan lokal ini isian `SDBELI` justru ada di **Agustus** (1.303 dari 2.045
    | baris IP), dan di mana pun terisi nilainya SAMA PERSIS `ICOGS x SDKELUAR`
    | (1.303 dari 1.303, nol selisih). September lokal cuma 21 baris dan `SDBELI`-nya
    | KOSONG semua. Salinan lokal memang ketinggalan dari server (`.env` menunjuk
    | 127.0.0.1, server punya DB sendiri), jadi batas di bawah MENGIKUTI KETERANGAN
    | USER, bukan data lokal. Kalau ternyata backfill-nya memang Agustus, ubah saja
    | tanggal di bawah ke '2026-08-01'.
    */

    'cogs_dari_sdbeli_sejak' => '2026-09-01',

];
