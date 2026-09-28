<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Models\UserAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Layar "wajib ganti password" - muncul saat `lv_user_auth.must_change` menyala, yaitu setelah
 * admin membuatkan/mereset password (password sementara). Dipaksa oleh middleware
 * `EnsurePasswordChanged`.
 *
 * **Syarat password baru SENGAJA lebih ketat** dari validasi reset milik admin (`min:4`):
 * minimal 8 karakter, wajib ada huruf DAN angka, dan tidak boleh termasuk daftar password
 * yang pernah bocor (`uncompromised()`, dicek ke Have I Been Pwned lewat k-anonymity - yang
 * dikirim hanya 5 karakter pertama hash SHA-1, bukan passwordnya).
 * Alasannya: password admin memang sementara & langsung diganti di sini, sedangkan yang
 * dibuat user di layar ini berlaku seterusnya.
 *
 * **TIDAK menulis MD5 ke `auser.UPASSWORD`.** Reset oleh admin punya opsi itu supaya login
 * CI3 lama ikut jalan, tapi di sini sengaja tidak: MD5 tanpa garam bisa dibongkar cepat,
 * jadi menyalinnya justru melemahkan password kuat yang baru saja dibuat user. Akibat yang
 * diterima: sampai aplikasi lama benar-benar dihentikan, password CI3 user tetap yang lama.
 */
#[Layout('layouts.auth')]
#[Title('Ganti Password')]
class ChangePassword extends Component
{
    public string $passwordLama = '';
    public string $passwordBaru = '';
    public string $passwordBaruConfirmation = '';

    protected function rules(): array
    {
        return [
            'passwordLama' => ['required', 'string'],
            // `same:` dipakai, BUKAN `confirmed` - aturan `confirmed` mencari field bernama
            // `passwordBaru_confirmation` (snake), sedangkan properti Livewire di sini
            // camelCase, sehingga `confirmed` SELALU gagal walau isinya sama.
            'passwordBaru' => [
                'required', 'same:passwordBaruConfirmation',
                Password::min((int) config('acl.password.min', 10))->letters()->numbers()
                    ->uncompromised(),
                $this->tolakKataJelas(...),
            ],
        ];
    }

    /**
     * Tolak password yang memuat username-nya sendiri atau kata yang terlalu jelas untuk
     * lingkungan ini (`nmw`, `petogogan`, `kasir`, ...). Jauh lebih berguna daripada
     * mewajibkan simbol: yang dipilih orang biasanya justru nama sendiri/nama klinik.
     */
    public function tolakKataJelas(string $atribut, mixed $nilai, \Closure $gagal): void
    {
        $pw = mb_strtolower((string) $nilai);

        $terlarang = array_map('mb_strtolower', (array) config('acl.password.kata_terlarang', []));
        $ukode = mb_strtolower((string) (User::find(auth()->id())?->UKODE ?? ''));
        if ($ukode !== '' && mb_strlen($ukode) >= 3) {
            $terlarang[] = $ukode;
        }

        foreach ($terlarang as $kata) {
            if ($kata !== '' && str_contains($pw, $kata)) {
                $gagal('Password tidak boleh memuat kata "' . $kata . '". Pilih kata lain.');

                return;
            }
        }
    }

    protected array $messages = [
        'passwordLama.required' => 'Password lama wajib diisi.',
        'passwordBaru.required' => 'Password baru wajib diisi.',
        'passwordBaru.same' => 'Ulangi password tidak sama.',
    ];

    protected array $validationAttributes = [
        'passwordLama' => 'password lama',
        'passwordBaru' => 'password baru',
    ];

    public function simpan()
    {
        $this->validate();

        $uid = (int) auth()->id();
        $secret = UserAuth::find($uid);

        if ($secret === null || ! Hash::check($this->passwordLama, $secret->password_hash)) {
            $this->addError('passwordLama', 'Password lama salah.');

            return null;
        }

        if (Hash::check($this->passwordBaru, $secret->password_hash)) {
            $this->addError('passwordBaru', 'Password baru harus berbeda dari password lama.');

            return null;
        }

        $secret->password_hash = Hash::make($this->passwordBaru);
        $secret->must_change = false;
        $secret->save();

        activity_log('change_password', 'auth', $uid, 'Ganti password sendiri');
        session()->flash('status', 'Password berhasil diganti.');

        return $this->redirectRoute('workspace', navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.change-password', [
            'namaUser' => User::find(auth()->id())?->displayName() ?? '',
        ]);
    }
}
