<?php

namespace App\Http\Middleware;

use App\Models\UserAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
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
 * - SELURUH endpoint Livewire, karena halaman ganti password memakai Livewire.
 *
 * ## Prefiks Livewire TIDAK BOLEH ditulis tangan - pakai `EndpointResolver::prefix()`
 * Sampai 2026-10-01 pengecualiannya ditulis `$request->is('livewire/*')`. **Itu TIDAK PERNAH
 * cocok**: Livewire 4 mengacak prefiksnya jadi `/livewire-<hash8>` (lihat
 * `EndpointResolver::prefix()`), mis. `/livewire-800c6d86/update`. Akibatnya, utk user
 * `must_change` - satu-satunya user yg melihat layar itu - **`livewire.js` ikut dialihkan 302**
 * sehingga JS-nya tidak pernah termuat dan tombol "Simpan Password Baru" MATI TOTAL tanpa
 * pesan apa pun (dilaporkan user: "tidak respon"). Endpoint `update`-nya pun ikut dialihkan.
 *
 * **JANGAN menambalnya dgn menyalin hash-nya**: hash itu diturunkan dari `APP_KEY`, jadi
 * NILAINYA BERBEDA di tiap server - hash lokal akan salah di produksi. Satu-satunya cara benar
 * adalah menanyakan ke Livewire lewat `EndpointResolver::prefix()`.
 *
 * Jebakan ini TIDAK terlihat oleh `Livewire::test()` (memanggil komponen langsung, melewati
 * middleware HTTP) - itu sebabnya uji komponennya lulus padahal layarnya mati. Harus diuji
 * lewat middleware-nya sendiri.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        if ($request->routeIs('password.change') || $request->routeIs('logout')
            || $request->is($this->polaLivewire())) {
            return $next($request);
        }

        $secret = UserAuth::find(Auth::id());

        if ($secret !== null && $secret->must_change) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }

    /**
     * Pola path SELURUH endpoint Livewire (update, livewire.js, source map, unggah berkas,
     * modul js/css komponen) - ditanyakan ke Livewire, bukan ditulis tangan. Lihat docblock
     * kelas kenapa ini penting.
     *
     * `livewire/*` tetap disertakan sbg jaring pengaman kalau suatu saat versi Livewire-nya
     * kembali memakai prefiks polos.
     *
     * @return list<string>
     */
    private function polaLivewire(): array
    {
        $pola = ['livewire/*'];

        if (class_exists(EndpointResolver::class)) {
            $pola[] = trim(EndpointResolver::prefix(), '/') . '/*';
        }

        return $pola;
    }
}
