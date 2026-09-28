<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAuth;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Beri password Laravel (bcrypt di lv_user_auth) untuk user admin yang sudah ada
 * di `auser`, supaya bisa login tanpa tahu password MD5 lamanya.
 *
 *   php artisan db:seed --class=AdminAuthSeeder
 *
 * IDEMPOTEN: kalau `lv_user_auth` untuk user_id itu SUDAH ada, password TIDAK
 * di-reset (lewati + warn). Ini supaya `db:seed` polos aman dipanggil tiap
 * container start (lihat `docker/entrypoint.sh` + env RUN_SEED). Reset password
 * admin sekarang harus lewat UI User Manager atau `php artisan tinker`, BUKAN
 * re-run seeder.
 *
 * User lain tetap bisa login pakai password MD5 lama mereka - hash bcrypt-nya
 * dibuat otomatis saat login pertama ("upgrade saat login").
 */
class AdminAuthSeeder extends Seeder
{
    public function run(): void
    {
        // UKODE => password awal
        $admins = [
            'admin4' => 'admin2026',
        ];

        foreach ($admins as $ukode => $password) {
            $user = User::where('UKODE', $ukode)->first();

            if ($user === null) {
                $this->command?->warn("auser UKODE='{$ukode}' tidak ditemukan, dilewati.");
                continue;
            }

            // Sudah punya password Laravel? Jangan timpa - admin mungkin sudah
            // login & ganti password, atau baris ini sengaja dipasang manual.
            $existing = UserAuth::where('user_id', $user->UID)->first();
            if ($existing !== null) {
                $this->command?->warn("{$ukode} (UID {$user->UID}) sudah punya password Laravel, dilewati.");
                continue;
            }

            UserAuth::create([
                'user_id' => $user->UID,
                'password_hash' => Hash::make($password),
                'must_change' => false,
            ]);

            $this->command?->info("Password Laravel diset untuk {$ukode} (UID {$user->UID}).");
        }
    }
}
