<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAuth;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Pastikan akun admin Laravel ada & punya password (bcrypt di lv_user_auth).
 *
 *   - UKODE belum ada di `auser` -> dibuat (pola sama `Admin\UserManager::save()`:
 *     `UPASSWORD='-'` = tidak bisa login ke CI3, cabang = gudang default).
 *   - Sudah ada di `auser` -> baris `auser` TIDAK disentuh sama sekali.
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
                $user = User::create([
                    'UKODE'        => $ukode,
                    'UNAMA'        => $ukode,
                    'UCABANG'      => DB::table('bgudang')->where('GDEFAULT', 1)->value('GID'),
                    'UCABANGPILIH' => null, // kosong = tidak dibatasi cabang
                    'UACTIVE'      => 1,
                    'UPASSWORD'    => '-',  // tidak dipakai CI3
                ]);
                $this->command?->info("auser UKODE='{$ukode}' belum ada, dibuat (UID {$user->UID}).");
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
