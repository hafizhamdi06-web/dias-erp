<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Nama bank dari kolom `fstoku.SUBANKDEBIT` / `SUBANKKREDIT` / `SUBANKTRANSFER`.
 *
 * Kolom itu varchar berisi NAMA bank penerima ("BCA", "MANDIRI") - begitu CI3 & VB6
 * menyimpannya (pilihan bank CI3 ber-value teks nama). POS Laravel sempat menyimpan ID
 * `bbank.BID` (mis. "2"), sudah diperbaiki 2026-10-16 - nilai angka lama itu diterjemahkan
 * ke `bbank.BNAMA` di sini supaya struk & rekap per bank tetap benar.
 */
class BankNama
{
    /** @var array<string,string>|null BID => BNAMA */
    private ?array $peta = null;

    public function nama(?string $nilai): string
    {
        $v = trim((string) $nilai);
        if ($v === '') {
            return '';
        }
        if (ctype_digit($v)) {
            $this->peta ??= DB::table('bbank')->pluck('BNAMA', 'BID')->map(fn ($n) => (string) $n)->all();

            return strtoupper(trim($this->peta[$v] ?? $v));
        }

        return strtoupper($v);
    }
}
