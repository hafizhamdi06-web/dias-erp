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

        $q = trim($this->userSearch);

        $results = collect();
        if (! $this->userId && $q !== '') {
            $results = User::query()
                ->where('UACTIVE', 1)
                ->where(function ($w) use ($q) {
                    $w->where('UKODE', 'like', "%{$q}%")
                        ->orWhere('UNAMA', 'like', "%{$q}%")
                        ->orWhere('UNAMALENGKAP', 'like', "%{$q}%");
                })
                ->orderBy('UNAMA')
                ->limit(25)
                ->get(['UID', 'UKODE', 'UNAMA', 'UNAMALENGKAP']);
        }

        return view('livewire.admin.user-access', [
            'tree'         => $this->buildTree($menus, null),
            'searchResult' => $results,
            'selectedUser' => $this->userId ? User::find($this->userId) : null,
            'abilities'    => self::ABILITIES,
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
