<?php

use App\Services\ActivityLogger;

if (! function_exists('activity_log')) {
    /**
     * Catat aktivitas user (shortcut untuk ActivityLogger::record()).
     */
    function activity_log(string $action, ?string $module = null, ?string $entityId = null, ?string $description = null): void
    {
        app(ActivityLogger::class)->record($action, $module, $entityId, $description);
    }
}

if (! function_exists('can_do')) {
    /**
     * Apakah user aktif punya hak $ability untuk path menu $path.
     */
    function can_do(string $path, string $ability = 'view'): bool
    {
        return app('acl')->canRoute($path, $ability);
    }
}

if (! function_exists('terbilang')) {
    /**
     * Angka -> kata Bahasa Indonesia, utk baris "Terbilang" di cetakan dokumen
     * (mis. PURCHASE ORDER). Desimal DIBUANG (dokumen legacy selalu bulat rupiah).
     * Contoh: 6500000 -> "Enam Juta Lima Ratus Ribu".
     */
    function terbilang(float $angka): string
    {
        $n = (int) floor(abs($angka));
        $minus = $angka < 0 ? 'Minus ' : '';

        $satuan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

        $baca = function (int $x) use (&$baca, $satuan): string {
            if ($x < 12) {
                return $satuan[$x];
            }
            if ($x < 20) {
                return $baca($x - 10) . ' Belas';
            }
            if ($x < 100) {
                return $baca(intdiv($x, 10)) . ' Puluh' . ($x % 10 ? ' ' . $baca($x % 10) : '');
            }
            if ($x < 200) {
                return 'Seratus' . ($x % 100 ? ' ' . $baca($x % 100) : '');
            }
            if ($x < 1000) {
                return $baca(intdiv($x, 100)) . ' Ratus' . ($x % 100 ? ' ' . $baca($x % 100) : '');
            }
            if ($x < 2000) {
                return 'Seribu' . ($x % 1000 ? ' ' . $baca($x % 1000) : '');
            }
            if ($x < 1000000) {
                return $baca(intdiv($x, 1000)) . ' Ribu' . ($x % 1000 ? ' ' . $baca($x % 1000) : '');
            }
            if ($x < 1000000000) {
                return $baca(intdiv($x, 1000000)) . ' Juta' . ($x % 1000000 ? ' ' . $baca($x % 1000000) : '');
            }
            if ($x < 1000000000000) {
                return $baca(intdiv($x, 1000000000)) . ' Milyar' . ($x % 1000000000 ? ' ' . $baca($x % 1000000000) : '');
            }

            return $baca(intdiv($x, 1000000000000)) . ' Triliun'
                . ($x % 1000000000000 ? ' ' . $baca($x % 1000000000000) : '');
        };

        return $n === 0 ? $minus . 'Nol' : $minus . trim(preg_replace('/\s+/', ' ', $baca($n)));
    }
}
