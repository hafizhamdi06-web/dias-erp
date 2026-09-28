<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    | User dengan UID atau UKODE berikut melewati semua pemeriksaan hak akses
    | dan melihat seluruh menu.
    */

    'super_user_ids'   => [1],

    'super_user_codes' => ['Admin', 'admin4', 'dev'],

    /*
    |--------------------------------------------------------------------------
    | Aturan password yang dibuat SENDIRI oleh user
    |--------------------------------------------------------------------------
    | Berlaku di layar "wajib ganti password" (`Auth\ChangePassword`), BUKAN untuk
    | password sementara yang dibuatkan admin.
    |
    | SENGAJA TIDAK mewajibkan simbol/huruf besar. Aturan komposisi seperti itu
    | justru menghasilkan pola yang mudah ditebak (`Password1!`, `P@ssw0rd`) yang
    | jadi tebakan PERTAMA program pembobol - NIST SP 800-63B menyarankan
    | menghindarinya. Panjang jauh lebih menentukan: 10 karakter huruf+angka
    | ~1.300x lebih luas ruang tebakannya daripada 8 karakter.
    */
    'password' => [
        'min' => 10,

        /*
        | Kata yang ditolak kalau TERKANDUNG di password (tidak peka huruf besar/kecil).
        | Username user sendiri otomatis ikut ditolak, tidak perlu didaftarkan di sini.
        */
        'kata_terlarang' => [
            'password', 'admin', 'qwerty', 'nmw', 'klinik', 'kasir',
            'petogogan', 'igyolini', 'rhein',
        ],
    ],

];
