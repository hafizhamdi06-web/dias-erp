<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman HTML tidak boleh disimpan browser.
 *
 * Penanda versi `?v=` pada CSS/JS (lihat helper `aset()`) HANYA berguna kalau HTML-nya
 * sendiri diambil ulang. Kalau halamannya ikut tersimpan, browser terus membaca HTML lama
 * lengkap dgn `?v=` lama, jadi berkas barunya tidak pernah diminta - user terpaksa membuka
 * jendela samaran dulu (dilaporkan user 2026-10-14).
 *
 * Alasan kedua, sama pentingnya: ini aplikasi ber-login. Tanpa `no-store`, menekan tombol
 * BACK sesudah logout masih bisa memunculkan halaman berisi data pasien dari sesi
 * sebelumnya, karena diambil dari cache browser.
 *
 * HANYA untuk HTML. Respons lain - PDF, Excel, unduhan, gambar - dibiarkan apa adanya,
 * termasuk header `Content-Disposition`-nya.
 */
class HalamanJanganDisimpan
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $tipe = (string) $response->headers->get('Content-Type', '');

        // Respons streaming/unduhan tidak disentuh sama sekali.
        if ($tipe !== '' && ! str_contains(strtolower($tipe), 'text/html')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
