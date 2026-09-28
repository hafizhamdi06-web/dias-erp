<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identitas apotek/apoteker untuk kop cetakan, per badan hukum (`bnamapt.NPID`)
    |--------------------------------------------------------------------------
    | Nama apotek, nomor izin, nama apoteker penanggung jawab & SIPA-nya TIDAK ADA
    | di database (dicek `information_schema`: tidak ada kolom IZIN/SIPA/APOTEK di
    | skema ini; `ainfo`, `bnamapt` & `bgudang` pun tidak memuatnya) - di sistem lama
    | teks ini hardcode di template report. Ditaruh di sini, BUKAN di blade, karena
    | isinya identitas apoteker yg BEDA per PT: mencetak SIPA milik PT lain jelas
    | tidak boleh.
    |
    | PT yg TIDAK terdaftar di sini -> baris2 itu (dan penanda tangan "Diketahui
    | Oleh" di cetakan PO) TIDAK dicetak - sengaja kosong daripada salah.
    |
    | Nilai NPID=1 disalin PERSIS dari contoh cetakan asli user:
    | - izin/apoteker/sipa/apoteker_hp: `Order Pembelian GB-PO26090001.pdf`
    |   (HP apoteker dikonfirmasi cocok dgn `bkontak` KID 285707 "Leo Arif Prasetyadi")
    | - apotek/apoteker_nama: `Surat Jalan DE-SJ26090001.pdf`
    |
    | `apoteker` vs `apoteker_nama` SENGAJA dua kunci: cetakan PO menulis namanya
    | apa adanya dgn gelar ("apt. Leo Arif Prasetyadi, S.Farm"), sedangkan cetakan
    | Surat Jalan memakai label sendiri "Apoteker : " lalu nama TANPA "apt."
    | ("Apoteker : Leo Arif Prasetyadi, S.Farm"). Dua2nya disalin persis dari contoh,
    | jadi tidak diturunkan satu dari yg lain.
    |
    | CATATAN: file ini semula `config/po_print.php`; diganti nama saat cetakan Surat
    | Jalan ikut memakainya (2026-09-27) supaya namanya tidak menyesatkan.
    */
    'pt' => [
        8 => [ // PT. Royal Igyolini Indonesia - badan hukum gudang 35 "RII Produksi"
            /*
             * Penanda tangan "Di Setujui Oleh" pada cetakan Job Order Produksi. TIDAK ADA
             * di database: `fproduksiu` tidak punya kolom approver sama sekali
             * (`PUKARYAWAN` NULL di kedua JOP lokal), dan nama bergelar ini tidak ada persis
             * di `bkontak` (yg terdekat "` TRI WAHYUNI.`" KID 21788, tanpa "Dra."/", Apt.").
             * Disalin dari contoh `Job Order Produksi RP-JOP26090001.pdf`.
             */
            'penyetuju_jop' => 'Dra. TRI WAHYUNI, Apt.',
        ],
        1 => [ // PT. Igyolini Indonesia
            'apotek'        => 'APOTEK RHEIN',
            'izin'          => '81202 1727 1234 0004',
            'apoteker'      => 'apt. Leo Arif Prasetyadi, S.Farm',
            'apoteker_nama' => 'Leo Arif Prasetyadi, S.Farm',
            'sipa'          => '2/B.19.1/31.74.07.1007.25.K-2.b,1/4/TM.09/e/2025',
            'apoteker_hp'   => '0851 5637 9562',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Label `esalesorderu.SOUJENIS` (cetakan PO)
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
