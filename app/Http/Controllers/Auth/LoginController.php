<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LegacyAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /** Percobaan gagal yang diizinkan sebelum diblokir sementara. */
    private const MAKS_PERCOBAAN = 5;

    /** Lama blokir (detik) setelah batas percobaan tercapai. */
    private const JEDA_DETIK = 300;

    public function __construct(private LegacyAuth $auth)
    {
    }

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('workspace');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'ukode'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'ukode'    => 'username',
            'password' => 'password',
        ]);

        // Pembatasan percobaan login (2026-09-28). Sebelum ini TIDAK ADA sama sekali -
        // password sekuat apa pun bisa digerogoti tebakan berulang. Kunci per
        // username+IP, BUKAN IP saja, supaya satu kantor ber-IP sama tidak saling
        // mengunci; dan bukan username saja, supaya penyerang tidak bisa mengunci akun
        // orang lain dari luar.
        $kunci = Str::lower($data['ukode']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            $detik = RateLimiter::availableIn($kunci);
            activity_log('login_throttled', 'auth', null,
                'Login diblokir sementara untuk "' . $data['ukode'] . '"');

            throw ValidationException::withMessages([
                'ukode' => 'Terlalu banyak percobaan. Coba lagi dalam '
                    . ceil($detik / 60) . ' menit.',
            ]);
        }

        $user = $this->auth->attempt($data['ukode'], $data['password'], (string) $request->ip());

        if ($user === null) {
            RateLimiter::hit($kunci, self::JEDA_DETIK);
            activity_log('login_failed', 'auth', null, 'Gagal login untuk "' . $data['ukode'] . '"');

            throw ValidationException::withMessages([
                'ukode' => 'Username atau password salah, atau akun tidak aktif.',
            ]);
        }

        RateLimiter::clear($kunci);
        $request->session()->regenerate();
        app('acl')->refresh();
        activity_log('login', 'auth', $user->UID, 'Login berhasil');

        return redirect()->intended(route('workspace'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
