<?php

namespace App\Livewire\Admin;

use App\Models\Menu;
use App\Models\UserMenu;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Administrasi Menu')]
class MenuManager extends Component
{
    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public bool $showModal = false;
    public ?int $editingId = null;

    // Field form
    public ?int $parent_id = null;
    public string $segment_key = '';
    public string $title = '';
    public ?string $route = null;
    public ?string $icon = null;
    public string $menu_type = 'link';
    public int $sort_order = 0;
    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'parent_id'   => ['nullable', 'integer', 'exists:lv_menu,id'],
            'segment_key' => ['required', 'string', 'max:100', Rule::unique('lv_menu', 'segment_key')->ignore($this->editingId)],
            'title'       => ['required', 'string', 'max:100'],
            'route'       => ['nullable', 'string', 'max:191'],
            'icon'        => ['nullable', 'string', 'max:50'],
            'menu_type'   => ['required', 'in:group,link'],
            'sort_order'  => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active'   => ['boolean'],
        ];
    }

    protected array $messages = [
        'segment_key.unique' => 'Kunci segment sudah dipakai menu lain.',
    ];

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $menu = Menu::findOrFail($id);

        $this->editingId   = $menu->id;
        $this->parent_id   = $menu->parent_id;
        $this->segment_key = $menu->segment_key;
        $this->title       = $menu->title;
        $this->route       = $menu->route;
        $this->icon        = $menu->icon;
        $this->menu_type   = $menu->menu_type;
        $this->sort_order  = $menu->sort_order;
        $this->is_active   = $menu->is_active;
        $this->showModal   = true;
    }

    public function save(): void
    {
        $this->validate();

        // Guard siklus: parent tidak boleh diri sendiri atau turunannya.
        if ($this->editingId && $this->parent_id) {
            if ($this->parent_id === $this->editingId || in_array($this->parent_id, $this->descendantIds($this->editingId), true)) {
                $this->addError('parent_id', 'Parent tidak boleh menu ini sendiri atau turunannya.');

                return;
            }
        }

        $payload = [
            'parent_id'   => $this->parent_id ?: null,
            'segment_key' => $this->segment_key,
            'title'       => $this->title,
            'route'       => $this->route ?: null,
            'icon'        => $this->icon ?: null,
            'menu_type'   => $this->menu_type,
            'sort_order'  => $this->sort_order,
            'is_active'   => $this->is_active,
        ];

        if ($this->editingId) {
            Menu::find($this->editingId)->update($payload);
            activity_log('update', 'admin/menu', $this->editingId, 'Ubah menu ' . $this->title);
            session()->flash('status', 'Menu diperbarui.');
        } else {
            $menu = Menu::create($payload);
            activity_log('create', 'admin/menu', $menu->id, 'Tambah menu ' . $this->title);
            session()->flash('status', 'Menu ditambahkan.');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $menu = Menu::findOrFail($id);

        if (Menu::where('parent_id', $id)->exists()) {
            session()->flash('error', 'Tidak bisa dihapus: masih punya sub-menu.');

            return;
        }

        if (UserMenu::where('menu_id', $id)->exists()) {
            session()->flash('error', 'Tidak bisa dihapus: masih dipakai di hak akses user.');

            return;
        }

        $title = $menu->title;
        $menu->delete();
        activity_log('delete', 'admin/menu', $id, 'Hapus menu ' . $title);
        session()->flash('status', 'Menu dihapus.');
    }

    private function descendantIds(int $rootId): array
    {
        $all = Menu::select('id', 'parent_id')->get();
        $out = [];
        $stack = [$rootId];

        while ($stack) {
            $cur = array_pop($stack);
            foreach ($all->where('parent_id', $cur) as $child) {
                $out[] = $child->id;
                $stack[] = $child->id;
            }
        }

        return $out;
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'parent_id', 'segment_key', 'title',
            'route', 'icon', 'menu_type', 'sort_order', 'is_active',
        ]);
        $this->menu_type = 'link';
        $this->is_active = true;
        $this->sort_order = 0;
        $this->resetErrorBag();
    }

    public function render()
    {
        $menus = Menu::orderByRaw('COALESCE(parent_id, 0)')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $tree = $this->buildTree($menus, null);

        $parentOptions = $menus->whereNull('parent_id')
            ->concat($menus->where('menu_type', 'group'))
            ->unique('id')
            ->sortBy('title')
            ->values();

        return view('livewire.admin.menu-manager', [
            'tree'          => $tree,
            'parentOptions' => $parentOptions,
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
