<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Autentikasi terhadap tabel legacy `auser`.
 *
 * Alur verifikasi:
 *   1. Ada hash modern di lv_user_auth  -> Hash::check(); rehash bila perlu.
 *   2. Belum ada                        -> cek MD5 lama (auser.UPASSWORD). Bila
 *                                          cocok, login DAN tulis hash bcrypt ke
 *                                          lv_user_auth ("upgrade saat login").
 *
 * Kolom auser.UPASSWORD TIDAK PERNAH diubah supaya login CI3 tetap jalan.
 */
class LegacyAuth
{
    /**
     * Coba login. Return User bila berhasil, null bila gagal.
     */
    public function attempt(string $ukode, string $password, string $ip = ''): ?User
    {
        $ukode = trim($ukode);
        if ($ukode === '' || $password === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('UKODE', $ukode)
            ->where('UACTIVE', 1)
            ->first();

        if ($user === null) {
            return null;
        }

        $secret = UserAuth::find($user->UID);

        if ($secret !== null && $secret->password_hash !== '') {
            // --- 1. Jalur modern ---------------------------------------
            if (! Hash::check($password, $secret->password_hash)) {
                return null;
            }

            if (Hash::needsRehash($secret->password_hash)) {
                $secret->password_hash = Hash::make($password);
            }
        } else {
            // --- 2. Jalur lama (MD5) + upgrade ------------------------
            $legacy = strtolower((string) $user->UPASSWORD);
            if (! hash_equals($legacy, md5($password))) {
                return null;
            }

            $secret = UserAuth::firstOrNew(['user_id' => $user->UID]);
            $secret->password_hash = Hash::make($password);
            $secret->must_change   = $secret->must_change ?? false;
        }

        $secret->last_login_at = now();
        $secret->last_login_ip = $ip ?: null;
        $secret->save();

        Auth::login($user);

        return $user;
    }

    /**
     * Verifikasi username+password TANPA login (sesi aktif TIDAK berubah) - dipakai utk gate
     * re-auth spt "buka kunci harga/diskon POS" (mis. supervisor ketik username+password
     * miliknya sendiri sementara kasir tetap login sbg dirinya). Alur cek password SAMA spt
     * attempt() (modern lalu fallback MD5 legacy), TAPI tidak upgrade hash/last_login & tidak
     * Auth::login() - murni pemeriksaan identitas, tidak menandai siapapun "login".
     */
    public function verify(string $ukode, string $password): ?User
    {
        $ukode = trim($ukode);
        if ($ukode === '' || $password === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('UKODE', $ukode)
            ->where('UACTIVE', 1)
            ->first();

        if ($user === null) {
            return null;
        }

        $secret = UserAuth::find($user->UID);

        if ($secret !== null && $secret->password_hash !== '') {
            if (! Hash::check($password, $secret->password_hash)) {
                return null;
            }
        } else {
            $legacy = strtolower((string) $user->UPASSWORD);
            if (! hash_equals($legacy, md5($password))) {
                return null;
            }
        }

        return $user;
    }
}
