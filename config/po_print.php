<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Baris tambahan kop surat cetakan PO, per badan hukum (`bnamapt.NPID`)
    |--------------------------------------------------------------------------
    | Nomor izin, nama apoteker penanggung jawab & SIPA-nya TIDAK ADA di database
    | (dicek `information_schema`: tidak ada kolom IZIN/SIPA/APOTEK di skema ini,
    | `ainfo` pun tidak memuatnya) - di sistem lama teks ini hardcode di template
    | report. Ditaruh di sini, BUKAN di blade, karena isinya identitas apoteker yg
    | BEDA per PT: mencetak SIPA milik PT lain jelas tidak boleh.
    |
    | PT yg TIDAK terdaftar di sini -> ketiga baris itu & penanda tangan "Diketahui
    | Oleh" TIDAK dicetak (sengaja kosong daripada salah).
    |
    | Nilai NPID=1 di bawah disalin PERSIS dari contoh cetakan asli user
    | (`Order Pembelian GB-PO26090001.pdf`); HP apoteker dikonfirmasi cocok dgn
    | `bkontak` KID 285707 "Leo Arif Prasetyadi".
    */
    'pt' => [
        1 => [ // PT. Igyolini Indonesia
            'izin'         => '81202 1727 1234 0004',
            'apoteker'     => 'apt. Leo Arif Prasetyadi, S.Farm',
            'sipa'         => '2/B.19.1/31.74.07.1007.25.K-2.b,1/4/TM.09/e/2025',
            'apoteker_hp'  => '0851 5637 9562',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Label `esalesorderu.SOUJENIS`
    |--------------------------------------------------------------------------
    | Di data nyata hanya ada nilai 0 (16 PO) dan 1 (220 PO). Contoh cetakan user
    | ber-`SOUJENIS=1` tercetak "Produk OTC" -> itu yg dipastikan. Label untuk 0
    | BELUM DIKETAHUI (form VB6 yg kita punya tidak menyebut kolom ini sama sekali),
    | jadi sengaja TIDAK ditebak - nilai tak dikenal dicetak "-".
    */
    'jenis' => [
        1 => 'Produk OTC',
    ],
];
