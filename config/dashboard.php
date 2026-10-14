<?php

return [
    /*
     * Banyaknya baris per halaman di tingkat PER TRANSAKSI Dashboard Penjualan.
     *
     * Dibuat bisa diatur, bukan angka mati di kode: berapa baris yg nyaman sangat
     * bergantung layar & kecepatan server di tempat pemakaian. Juga dipakai pengujian
     * utk mengecilkan halaman supaya pagernya benar-benar bisa dibuktikan - di salinan
     * data lokal satu item paling banyak 48 baris, jadi dgn 100 pagernya tak pernah
     * muncul sama sekali.
     */
    'per_halaman' => (int) env('DASHBOARD_PER_HALAMAN', 100),

    /*
     * Batas keras banyaknya baris yg dimuat ke berkas Excel.
     *
     * Ekspor sebulan penuh satu PT bisa puluhan ribu baris dan semuanya ditahan di memori
     * sekaligus. Kalau kena batas, berkasnya MENGAKU terpotong di kepala berkas - berkas yg
     * terpotong diam-diam jauh lebih berbahaya, user akan menjumlahkannya dan mengira itu
     * angka sebulan penuh.
     */
    'batas_ekspor' => (int) env('DASHBOARD_BATAS_EKSPOR', 20000),
];
