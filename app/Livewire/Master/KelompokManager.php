<?php

namespace App\Livewire\Master;

use App\Models\Item;
use App\Models\ItemGroup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Kelompok Item')]
class KelompokManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $search = '';
    public bool $showModal = false;
    public ?int $editingId = null;

    public string $IK2KODE = '';
    public ?string $IK2KODE_REPORT = null;
    public bool $IKWAJIBDOKTER = false;
    public bool $IKPRODUK = false;
    public ?int $IKURUTAN = null;

    protected function rules(): array
    {
        return [
            'IK2KODE'        => ['required', 'string', 'max:100', Rule::unique('bitemkelompok2020', 'IK2KODE')->ignore($this->editingId, 'IK2ID')],
            'IK2KODE_REPORT' => ['nullable', 'string', 'max:100'],
            'IKWAJIBDOKTER'  => ['boolean'],
            'IKPRODUK'       => ['boolean'],
            'IKURUTAN'       => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'IK2KODE', 'IK2KODE_REPORT', 'IKWAJIBDOKTER', 'IKPRODUK', 'IKURUTAN']);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $g = ItemGroup::findOrFail($id);
        $this->editingId      = $g->IK2ID;
        $this->IK2KODE        = $g->IK2KODE ?? '';
        $this->IK2KODE_REPORT = $g->IK2KODE_REPORT;
        $this->IKWAJIBDOKTER  = (int) $g->IKWAJIBDOKTER === 1;
        $this->IKPRODUK       = (int) $g->IKPRODUK === 1;
        $this->IKURUTAN       = $g->IKURUTAN;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'IK2KODE'        => $this->IK2KODE,
            'IK2KODE_REPORT' => $this->IK2KODE_REPORT ?: null,
            'IKWAJIBDOKTER'  => $this->IKWAJIBDOKTER ? 1 : 0,
            'IKPRODUK'       => $this->IKPRODUK ? 1 : 0,
            'IKURUTAN'       => $this->IKURUTAN,
        ];

        if ($this->editingId) {
            ItemGroup::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/kelompok', $this->editingId, 'Ubah kelompok ' . $this->IK2KODE);
        } else {
            $g = ItemGroup::create($payload);
            activity_log('create', 'master/kelompok', $g->IK2ID, 'Tambah kelompok ' . $this->IK2KODE);
        }

        session()->flash('status', 'Kelompok item disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        if (Item::where('ikelompok2020', $id)->orWhere('ikomisi2020', $id)->exists()) {
            session()->flash('error', 'Tidak bisa dihapus: masih dipakai di data item.');

            return;
        }

        ItemGroup::whereKey($id)->delete();
        activity_log('delete', 'master/kelompok', $id, 'Hapus kelompok item');
        session()->flash('status', 'Kelompok item dihapus.');
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = ItemGroup::query()
            ->when($q !== '', fn ($b) => $b->where('IK2KODE', 'like', "%{$q}%")
                ->orWhere('IK2KODE_REPORT', 'like', "%{$q}%"))
            ->orderBy('IK2KODE')
            ->paginate(20);

        return view('livewire.master.kelompok-manager', ['rows' => $rows]);
    }
}
