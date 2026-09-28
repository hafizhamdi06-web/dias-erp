<?php

namespace App\Http\Middleware;

use App\Models\UserAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memaksa user mengganti password sementara sebelum boleh memakai aplikasi.
 *
 * `lv_user_auth.must_change` SUDAH ada sejak migrasi awal tetapi sebelum ini TIDAK PERNAH
 * ditegakkan (`resetPassword()` malah menulisnya `false`), sehingga password yang dibuatkan
 * admin jadi permanen. Middleware ini yang membuat kolom itu berarti - dipasang bersamaan
 * dengan tombol "Buat Password" (permintaan user 2026-09-28: password hasil reset bersifat
 * sementara, user diharuskan membuat password kuat).
 *
 * **Rute yang SENGAJA dilewati**, kalau tidak user akan terkunci dalam lingkaran:
 * - halaman ganti password itu sendiri (beserta POST-nya),
 * - logout - user harus tetap bisa keluar,
 * - endpoint Livewire (`livewire/update`), karena halaman ganti password memakai Livewire;
 *   memblokirnya membuat form di halaman itu tidak bisa disubmit.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        if ($request->routeIs('password.change') || $request->routeIs('logout')
            || $request->is('livewire/*')) {
            return $next($request);
        }

        $secret = UserAuth::find(Auth::id());

        if ($secret !== null && $secret->must_change) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
