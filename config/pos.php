<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ukuran struk POS
    |--------------------------------------------------------------------------
    |
    | Dipakai kalau user BELUM punya preferensi sendiri di `lv_user_pref.struk_pos`.
    |
    |   'a5' = setengah A4 / LX300  (layout mengikuti CI3 `formulir-penjualan-tunai.php`)
    |   '58' = printer termal 58 mm
    |
    | Keadaan yang berjalan sekarang (info user 2026-09-30): HANYA user cabang Marketplace
    | yang mencetak 58 mm, sisanya setengah A4 - karena itu defaultnya `a5` dan user
    | Marketplace diberi preferensi `58` satu per satu lewat Administrasi User.
    |
    */
    'struk_default' => env('POS_STRUK_DEFAULT', 'a5'),

    /** Pilihan yang boleh - dipakai validasi & dropdown, jangan ditambah sembarangan. */
    'struk_pilihan' => [
        '58' => 'Struk termal 58 mm',
        'a5' => 'Setengah A4 / LX300',
    ],

];
