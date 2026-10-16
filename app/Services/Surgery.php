<?php

namespace App\Services;

/**
 * Aturan transaksi SURGERY (dikonfirmasi user 2026-10-16) - SATU tempat, dipakai Closing
 * Harian IP & Dashboard Penjualan.
 *
 * Surgery = tindakan bernilai besar yg dibayar CICIL lewat beberapa nota IP berantai, SEMUA
 * dicentang SURGERY (`fstoku.SUSURGERYDP <> 0`). Tiap nota menarik nota sebelumnya (VB6
 * `eFrmPOS2.cmdNoIP_Click`, `SUSURGERYDPIDU`) dan MENYALIN seluruh item tindakannya; sisa
 * yg belum dibayar dicatat sbg piutang (`SUNILAIPIUTANG`).
 *
 *   - Pendapatan = uang yg dibayar di tiap nota -> rumus "uang diterima" Dashboard
 *     Penjualan sudah benar, TIDAK diubah.
 *   - Hari operasi SELALU nota pelunasan (piutang 0) = nota ASLI: tindakan, COGS & alkes
 *     hanya dihitung di sini.
 *   - Nota CICILAN (piutang > 0): itemnya salinan, tindakannya belum dikerjakan -> TIDAK
 *     ditagih alkes & COGS-nya TIDAK dihitung. Tanpa ini, satu operasi yg dicicil 3x
 *     tercatat 4x COGS-nya.
 *
 * Terukur 2026-10-16: 9 nota cicilan & 10 nota lunas; tak satu pun nota lunas punya nota
 * lanjutan - cocok dgn aturan "lunas = ujung rantai = hari operasi".
 */
class Surgery
{
    /** Kondisi SQL: nota `fstoku` ber-alias `$u` adalah CICILAN surgery. */
    public static function kondisiCicilan(string $u = 'u'): string
    {
        return "(COALESCE({$u}.SUSURGERYDP, 0) <> 0 AND COALESCE({$u}.SUNILAIPIUTANG, 0) > 0)";
    }
}
