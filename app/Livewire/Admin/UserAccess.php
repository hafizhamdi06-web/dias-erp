<?php

namespace App\Livewire\Admin;

use App\Models\Menu;
use App\Models\User;
use App\Models\UserMenu;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Hak Akses Menu')]
class UserAccess extends Component
{
    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public const ABILITIES = ['view', 'add', 'edit', 'delete', 'print', 'approve'];

    #[Url(as: 'user')]
    public ?int $userId = null;

    /** Pencarian user (username / nama). */
    public string $userSearch = '';

    /** grid[menu_id][ability] = bool */
    public array $grid = [];

    /* ---- Salin hak akses ------------------------------------------------
     | Dibuat 2026-09-28. Latar: hak akses disetel per user per menu, sedangkan
     | ada 306 user aktif x 58 menu - mustahil disetel satu per satu sebelum
     | cutover. Dua arah:
     |   1. `salinDari()`  - tarik hak akses user lain ke grid (BELUM disimpan,
     |                       admin bisa periksa dulu lalu klik Simpan).
     |   2. `terapkanKe()` - tulis hak akses user ini ke BANYAK user sekaligus.
     | Ini penyelesaian sementara sampai konsep Role dibuat setelah cutover.
     */
    public bool $showSalinModal = false;
    public string $salinSearch = '';

    public bool $showTerapkanModal = false;
    public string $terapkanSearch = '';
    /** @var list<int> UID tujuan yang dicentang; disimpan terpisah supaya tidak
     *                 hilang saat kata pencarian diganti. */
    public array $terapkanTargets = [];

    public function updatedUserId(): void
    {
        $this->loadGrid();
    }

    public function mount(): void
    {
        if ($this->userId) {
            $this->loadGrid();
        }
    }

    /** Pilih user dari hasil pencarian. */
    public function pick(int $id): void
    {
        $this->userId = $id;
        $this->userSearch = '';
        $this->loadGrid();
    }

    /** Ganti user (kembali ke mode pencarian). */
    public function clearUser(): void
    {
        $this->userId = null;
        $this->grid = [];
        $this->userSearch = '';
    }

    private function loadGrid(): void
    {
        $this->grid = [];

        if (! $this->userId) {
            return;
        }

        $rows = UserMenu::where('user_id', $this->userId)->get()->keyBy('menu_id');

        foreach (Menu::where('menu_type', 'link')->pluck('id') as $menuId) {
            $row = $rows->get($menuId);
            foreach (self::ABILITIES as $ab) {
                $this->grid[$menuId][$ab] = $row ? (bool) $row->{'can_' . $ab} : false;
            }
        }
    }

    /** Centang ability apa pun -> view otomatis ikut. */
    public function updatedGrid($value, $key): void
    {
        [$menuId, $ability] = explode('.', $key);

        if ($value && $ability !== 'view') {
            $this->grid[$menuId]['view'] = true;
        }
    }

    public function grantAll(): void
    {
        foreach ($this->grid as $menuId => $_) {
            foreach (self::ABILITIES as $ab) {
                $this->grid[$menuId][$ab] = true;
            }
        }
    }

    public function revokeAll(): void
    {
        foreach ($this->grid as $menuId => $_) {
            foreach (self::ABILITIES as $ab) {
                $this->grid[$menuId][$ab] = false;
            }
        }
    }

    /* ================= SALIN DARI USER LAIN ================= */

    public function bukaSalin(): void
    {
        $this->salinSearch = '';
        $this->showSalinModal = true;
    }

    /**
     * Tarik hak akses `$sumberId` ke grid user yang sedang dibuka.
     * **TIDAK langsung menyimpan** - admin bisa memeriksa/menyesuaikan dulu lalu klik Simpan.
     * Grid ditimpa penuh (bukan digabung) supaya hasilnya benar-benar sama dgn sumbernya.
     */
    public function salinDari(int $sumberId): void
    {
        abort_unless(can_do('admin/user-access', 'edit'), 403);

        if (! $this->userId || $sumberId === $this->userId) {
            $this->showSalinModal = false;

            return;
        }

        $rows = UserMenu::where('user_id', $sumberId)->get()->keyBy('menu_id');

        foreach (array_keys($this->grid) as $menuId) {
            $row = $rows->get($menuId);
            foreach (self::ABILITIES as $ab) {
                $this->grid[$menuId][$ab] = $row ? (bool) $row->{'can_' . $ab} : false;
            }
        }

        $sumber = User::find($sumberId);
        $this->showSalinModal = false;
        session()->flash('status', 'Hak akses ' . ($sumber->UKODE ?? $sumberId)
            . ' dimuat ke layar. Periksa dulu, lalu klik Simpan.');
    }

    /* ================= TERAPKAN KE BANYAK USER ================= */

    public function bukaTerapkan(): void
    {
        $this->terapkanSearch = '';
        $this->terapkanTargets = [];
        $this->showTerapkanModal = true;
    }

    public function toggleTarget(int $id): void
    {
        $this->terapkanTargets = in_array($id, $this->terapkanTargets, true)
            ? array_values(array_diff($this->terapkanTargets, [$id]))
            : [...$this->terapkanTargets, $id];
    }

    /** Centang seluruh hasil pencarian sekaligus - inti dari fitur ini. */
    public function pilihSemuaHasil(): void
    {
        $ids = $this->cariUser($this->terapkanSearch, 200)
            ->pluck('UID')->map(fn ($v) => (int) $v)
            ->reject(fn ($v) => $v === (int) $this->userId)
            ->all();

        $this->terapkanTargets = array_values(array_unique([...$this->terapkanTargets, ...$ids]));
    }

    /**
     * Tulis hak akses user yang sedang dibuka ke semua user tujuan.
     *
     * **MENGGANTI, bukan menggabung** - hak akses lama user tujuan dihapus lebih dulu.
     * "Salin" yang menggabung akan menghasilkan hak akses yang tidak bisa ditebak isinya,
     * dan tidak bisa dipakai untuk MENCABUT akses. Dibungkus satu transaksi supaya kalau
     * gagal di tengah tidak ada user yang hak aksesnya separuh jadi.
     *
     * Yang ditulis adalah isi GRID di layar (bukan yang tersimpan di DB), supaya
     * penyesuaian yang belum sempat disimpan tidak diam-diam terbawa berbeda.
     */
    public function terapkanKe(): void
    {
        abort_unless(can_do('admin/user-access', 'edit'), 403);

        $targets = array_values(array_filter(
            array_map('intval', $this->terapkanTargets),
            fn ($id) => $id > 0 && $id !== (int) $this->userId
        ));

        if (! $this->userId || $targets === []) {
            $this->dispatch('toast', message: 'Pilih dulu user tujuannya.', type: 'error');

            return;
        }

        $baris = [];
        foreach ($this->grid as $menuId => $abilities) {
            if (! collect($abilities)->contains(true)) {
                continue;
            }
            $baris[] = [
                'menu_id'     => (int) $menuId,
                'can_view'    => true,
                'can_add'     => (bool) ($abilities['add'] ?? false),
                'can_edit'    => (bool) ($abilities['edit'] ?? false),
                'can_delete'  => (bool) ($abilities['delete'] ?? false),
                'can_print'   => (bool) ($abilities['print'] ?? false),
                'can_approve' => (bool) ($abilities['approve'] ?? false),
            ];
        }

        DB::transaction(function () use ($targets, $baris) {
            UserMenu::whereIn('user_id', $targets)->delete();

            $now = now();
            $isi = [];
            foreach ($targets as $uid) {
                foreach ($baris as $b) {
                    $isi[] = $b + ['user_id' => $uid, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            foreach (array_chunk($isi, 500) as $bagian) {
                UserMenu::insert($bagian);
            }
        });

        $sumber = User::find($this->userId);
        $nama = User::whereIn('UID', $targets)->pluck('UKODE')->implode(', ');
        activity_log('user_access_copy', 'admin/user-access', $this->userId,
            'Salin hak akses ' . ($sumber->UKODE ?? $this->userId) . ' ke ' . count($targets)
            . ' user: ' . $nama);

        $this->showTerapkanModal = false;
        $this->terapkanTargets = [];
        $this->dispatch('toast',
            message: 'Hak akses disalin ke ' . count($targets) . ' user.', type: 'success');
    }

    /** Pencarian user aktif - dipakai bersama oleh ketiga kotak pencarian di layar ini. */
    private function cariUser(string $q, int $limit = 25)
    {
        $q = trim($q);

        if ($q === '') {
            return collect();
        }

        return User::query()
            ->where('UACTIVE', 1)
            ->where(function ($w) use ($q) {
                $w->where('UKODE', 'like', "%{$q}%")
                    ->orWhere('UNAMA', 'like', "%{$q}%")
                    ->orWhere('UNAMALENGKAP', 'like', "%{$q}%");
            })
            ->orderBy('UNAMA')
            ->limit($limit)
            ->get(['UID', 'UKODE', 'UNAMA', 'UNAMALENGKAP']);
    }

    public function save(): void
    {
        if (! $this->userId) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->grid as $menuId => $abilities) {
                $any = collect($abilities)->contains(true);

                if (! $any) {
                    UserMenu::where('user_id', $this->userId)->where('menu_id', $menuId)->delete();

                    continue;
                }

                UserMenu::updateOrCreate(
                    ['user_id' => $this->userId, 'menu_id' => $menuId],
                    [
                        'can_view'    => true, // ada minimal satu ability -> view wajib
                        'can_add'     => (bool) ($abilities['add'] ?? false),
                        'can_edit'    => (bool) ($abilities['edit'] ?? false),
                        'can_delete'  => (bool) ($abilities['delete'] ?? false),
                        'can_print'   => (bool) ($abilities['print'] ?? false),
                        'can_approve' => (bool) ($abilities['approve'] ?? false),
                    ]
                );
            }
        });

        $user = User::find($this->userId);
        activity_log('user_access', 'admin/user-access', $this->userId, 'Ubah hak akses menu ' . ($user->UKODE ?? $this->userId));
        session()->flash('status', 'Hak akses disimpan.');
    }

    public function render()
    {
        $menus = Menu::orderByRaw('COALESCE(parent_id, 0)')->orderBy('sort_order')->orderBy('id')->get();

        $results = ! $this->userId ? $this->cariUser($this->userSearch) : collect();

        return view('livewire.admin.user-access', [
            'tree'         => $this->buildTree($menus, null),
            'searchResult' => $results,
            'selectedUser' => $this->userId ? User::find($this->userId) : null,
            'abilities'    => self::ABILITIES,
            // Sumber/tujuan penyalinan - user yang sedang dibuka dikecualikan.
            'salinResult'  => $this->showSalinModal
                ? $this->cariUser($this->salinSearch)->reject(fn ($u) => (int) $u->UID === (int) $this->userId)
                : collect(),
            'terapkanResult' => $this->showTerapkanModal
                ? $this->cariUser($this->terapkanSearch, 200)->reject(fn ($u) => (int) $u->UID === (int) $this->userId)
                : collect(),
        ]);
    }

    private function buildTree($menus, $parentId): array
    {
        $nodes = [];
        foreach ($menus->where('parent_id', $parentId) as $menu) {
            $nodes[] = [
                'menu'     => $menu,
                'children' => $this->buildTree($menus, $menu->id),
            ];
        }

        return $nodes;
    }
}
