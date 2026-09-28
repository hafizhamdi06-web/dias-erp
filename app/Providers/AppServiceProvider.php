<?php

namespace App\Providers;

use App\Support\Acl;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('acl', fn () => new Acl());
        $this->app->alias('acl', Acl::class);
    }

    public function boot(): void
    {
        /*
         * DIPASANG DI boot(), BUKAN register(): `ValidationServiceProvider` bawaan Laravel
         * mendaftarkan kontrak ini sebagai singleton pada tahap register juga, dan urutannya
         * menimpa binding kita - terbukti saat diuji, timeout tetap 30. Di boot() semua
         * provider sudah selesai register, jadi binding ini yang dipakai.
         *
         * Pemeriksa "password pernah bocor" (`Password::uncompromised()`) memanggil
         * api.pwnedpasswords.com. Timeout bawaannya 30 DETIK - kalau server produksi tidak
         * bisa keluar internet, tombol Simpan di layar ganti password menggantung selama itu.
         * Dipendekkan jadi 3 detik.
         *
         * PENTING: pemeriksa ini **gagal secara diam-diam** (fail-open). Saat panggilan API
         * gagal, `NotPwnedVerifier::search()` menelan exception dan mengembalikan body kosong,
         * sehingga password dianggap TIDAK pernah bocor dan LOLOS tanpa pesan apa pun. Jadi
         * kalau server tidak punya akses keluar, aturan ini praktis mati - pastikan
         * api.pwnedpasswords.com bisa dijangkau dari produksi.
         */
        // `ValidationServiceProvider` adalah DEFERRED provider: ia baru di-register saat
        // kontrak ini pertama kali di-resolve, dan pada saat itu binding kita akan DITIMPA
        // (terbukti saat diuji: timeout tetap 30 walau binding dipasang di boot()).
        // Karena itu providernya dimuat dulu di sini, baru binding di bawah dipasang.
        $this->app->register(\Illuminate\Validation\ValidationServiceProvider::class);

        $this->app->bind(
            \Illuminate\Contracts\Validation\UncompromisedVerifier::class,
            fn ($app) => new \Illuminate\Validation\NotPwnedVerifier(
                $app[\Illuminate\Http\Client\Factory::class],
                3
            )
        );

        Paginator::useBootstrapFive();

        // @can_do('master/item', 'edit') ... @endcan_do
        Blade::if('can_do', function (string $path, string $ability = 'view') {
            return app('acl')->canRoute($path, $ability);
        });
    }
}
