<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LegacyAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
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

        $user = $this->auth->attempt($data['ukode'], $data['password'], (string) $request->ip());

        if ($user === null) {
            activity_log('login_failed', 'auth', null, 'Gagal login untuk "' . $data['ukode'] . '"');

            throw ValidationException::withMessages([
                'ukode' => 'Username atau password salah, atau akun tidak aktif.',
            ]);
        }

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
