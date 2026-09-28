<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Administrasi User')]
class UserManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showModal = false;
    public ?int $editingId = null;

    // Field form
    public string $UKODE = '';
    public string $UNAMA = '';
    public ?string $UNAMALENGKAP = null;
    public ?int $UCABANG = null;
    public array $branchPilih = [];
    /** Pegawai (bkontak.KID) yg terhubung ke akun ini - dipakai sbg "kasir" otomatis di POS. */
    public ?int $UKID = null;
    public array $labels = [];
    public bool $UACTIVE = true;
    public ?string $password = null;
    public bool $legacy_login = false;

    // Modal reset password
    public bool $showPwModal = false;
    public ?int $pwUserId = null;
    public ?string $newPassword = null;
    public bool $pwLegacy = false;

    protected function rules(): array
    {
        return [
            'UKODE'         => ['required', 'string', 'max:25', Rule::unique('auser', 'UKODE')->ignore($this->editingId, 'UID')],
            'UNAMA'         => ['required', 'string', 'max:50'],
            'UNAMALENGKAP'  => ['nullable', 'string', 'max:100'],
            'UCABANG'       => ['nullable', 'integer'],
            'branchPilih'   => ['array'],
            'branchPilih.*' => ['integer'],
            'UKID'          => ['nullable', 'integer'],
            'UACTIVE'       => ['boolean'],
            'password'      => [$this->editingId ? 'nullable' : 'required', 'nullable', 'string', 'min:4', 'max:100'],
        ];
    }

    protected array $messages = [
        'UKODE.unique' => 'Username sudah dipakai user lain.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $u = User::findOrFail($id);

        $this->editingId    = $u->UID;
        $this->UKODE        = $u->UKODE;
        $this->UNAMA        = $u->UNAMA ?? '';
        $this->UNAMALENGKAP = $u->UNAMALENGKAP;
        $this->UCABANG      = $u->UCABANG;
        $this->branchPilih  = $u->branchIds();
        $this->UKID         = $u->UKID ? (int) $u->UKID : null;
        $this->labels['UKID'] = $this->UKID
            ? DB::table('bkontak')->where('KID', $this->UKID)->value('KNAMA')
            : null;
        $this->UACTIVE      = (int) $u->UACTIVE === 1;
        $this->password     = null;
        $this->legacy_login = false;
        $this->showModal    = true;
    }

    public function save(): void
    {
        $this->validate();

        $branchCsv = implode(',', array_map('intval', $this->branchPilih));

        $payload = [
            'UKODE'        => $this->UKODE,
            'UNAMA'        => $this->UNAMA,
            'UNAMALENGKAP' => $this->UNAMALENGKAP ?: null,
            'UCABANG'      => $this->UCABANG ?: null,
            'UCABANGPILIH' => $branchCsv ?: null,
            'UKID'         => $this->UKID ?: null,
            'UACTIVE'      => $this->UACTIVE ? 1 : 0,
        ];

        if ($this->editingId) {
            $user = User::find($this->editingId);
            $user->update($payload);
            activity_log('update', 'admin/user', $user->UID, 'Ubah user ' . $user->UKODE);
        } else {
            // UPASSWORD wajib ada di skema legacy; isi '-' bila tidak dipakai CI3.
            $payload['UPASSWORD'] = $this->legacy_login && $this->password
                ? md5($this->password)
                : '-';
            $user = User::create($payload);
            activity_log('create', 'admin/user', $user->UID, 'Tambah user ' . $user->UKODE);
        }

        if ($this->password) {
            // Password yang diketik admin saat tambah/ubah user juga dianggap SEMENTARA -
            // adminlah yang tahu isinya, jadi user wajib menggantinya saat login.
            $this->writePassword($user->UID, $this->password, true);

            if ($this->editingId && $this->legacy_login) {
                User::where('UID', $user->UID)->update(['UPASSWORD' => md5($this->password)]);
            }
        }

        session()->flash('status', $this->editingId ? 'User diperbarui.' : 'User ditambahkan.');
        $this->showModal = false;
        $this->resetForm();
    }

    public function selectAllBranches(): void
    {
        $this->branchPilih = Branch::options()->pluck('GID')->map(fn ($v) => (string) $v)->all();
    }

    public function clearBranches(): void
    {
        $this->branchPilih = [];
    }

    public function toggleActive(int $id): void
    {
        $u = User::findOrFail($id);
        $u->UACTIVE = (int) $u->UACTIVE === 1 ? 0 : 1;
        $u->save();

        activity_log('update', 'admin/user', $u->UID, ($u->UACTIVE ? 'Aktifkan' : 'Nonaktifkan') . ' user ' . $u->UKODE);
        session()->flash('status', 'Status user diubah.');
    }

    public function openResetPassword(int $id): void
    {
        $this->pwUserId    = $id;
        $this->newPassword = null;
        $this->pwLegacy    = false;
        $this->showPwModal = true;
    }

    /**
     * Buat password SEMENTARA: 2 angka + 4 huruf + 2 angka = 8 karakter (permintaan user
     * 2026-09-28). Password ini hanya untuk diserahkan ke user; begitu dipakai login, user
     * DIPAKSA menggantinya (lihat `resetPassword()` yg menyalakan `must_change`).
     *
     * **Karakter rancu sengaja dibuang** - angka `0`/`1` dan huruf `i`/`l`/`o`. Password ini
     * didikte lewat telepon/WhatsApp; salah baca `0` vs `O` atau `1` vs `l` memicu telepon
     * balik ke admin. Ruang tebakannya jadi 8^2 x 23^4 x 8^2 = ~1,1 miliar - lebih dari cukup
     * untuk password yang umurnya hanya sampai login pertama.
     *
     * `random_int()` (CSPRNG), BUKAN `rand()`/`mt_rand()` yang bisa ditebak.
     */
    public function buatPassword(): void
    {
        $angka = '23456789';
        $huruf = 'abcdefghjkmnpqrstuvwxyz';

        $ambil = function (string $dari, int $n): string {
            $hasil = '';
            for ($i = 0; $i < $n; $i++) {
                $hasil .= $dari[random_int(0, strlen($dari) - 1)];
            }

            return $hasil;
        };

        $this->newPassword = $ambil($angka, 2) . $ambil($huruf, 4) . $ambil($angka, 2);
        $this->resetErrorBag('newPassword');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'newPassword' => ['required', 'string', 'min:4', 'max:100'],
        ]);

        $user = User::findOrFail($this->pwUserId);
        // `true` = WAJIB GANTI saat login berikutnya. Password hasil reset admin selalu
        // dianggap sementara - tanpa ini, password 8 karakter itu jadi permanen.
        $this->writePassword($user->UID, $this->newPassword, true);

        if ($this->pwLegacy) {
            User::where('UID', $user->UID)->update(['UPASSWORD' => md5($this->newPassword)]);
        }

        activity_log('reset_password', 'admin/user', $user->UID, 'Reset password ' . $user->UKODE);
        session()->flash('status', 'Password user direset.');
        $this->showPwModal = false;
        $this->reset(['pwUserId', 'newPassword', 'pwLegacy']);
    }

    /**
     * @param bool $wajibGanti true utk password SEMENTARA (reset oleh admin) - user dipaksa
     *                         menggantinya saat login berikutnya oleh middleware
     *                         `EnsurePasswordChanged`.
     */
    private function writePassword(int $userId, string $plain, bool $wajibGanti = false): void
    {
        UserAuth::updateOrCreate(
            ['user_id' => $userId],
            ['password_hash' => Hash::make($plain), 'must_change' => $wajibGanti]
        );
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'UKODE', 'UNAMA', 'UNAMALENGKAP', 'UCABANG',
            'branchPilih', 'UKID', 'labels', 'password', 'legacy_login',
        ]);
        $this->UACTIVE = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $q = trim($this->search);

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('UKODE', 'like', "%{$q}%")
                        ->orWhere('UNAMA', 'like', "%{$q}%")
                        ->orWhere('UNAMALENGKAP', 'like', "%{$q}%");
                });
            })
            ->orderBy('UNAMA')
            ->paginate(20);

        $authIds = UserAuth::whereIn('user_id', collect($users->items())->pluck('UID'))
            ->pluck('user_id')
            ->all();

        return view('livewire.admin.user-manager', [
            'users'    => $users,
            'branches' => Branch::options(),
            'authIds'  => $authIds,
        ]);
    }
}
