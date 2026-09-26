<?php

namespace App\Livewire\Master;

use App\Models\Item;
use App\Models\Unit;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Satuan')]
class UnitManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $search = '';
    public bool $showModal = false;
    public ?int $editingId = null;

    public string $SKODE = '';
    public ?string $SNAMA = null;
    public bool $SSATUANDASAR = false;
    public ?int $SNILAI = 1;

    protected function rules(): array
    {
        return [
            'SKODE'        => ['required', 'string', 'max:25', Rule::unique('bsatuan', 'SKODE')->ignore($this->editingId, 'SID')],
            'SNAMA'        => ['nullable', 'string', 'max:150'],
            'SSATUANDASAR' => ['boolean'],
            'SNILAI'       => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'SKODE', 'SNAMA', 'SSATUANDASAR']);
        $this->SNILAI = 1;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $u = Unit::findOrFail($id);
        $this->editingId    = $u->SID;
        $this->SKODE        = $u->SKODE ?? '';
        $this->SNAMA        = $u->SNAMA;
        $this->SSATUANDASAR = (int) $u->SSATUANDASAR === 1;
        $this->SNILAI       = $u->SNILAI ?? 1;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'SKODE'        => $this->SKODE,
            'SNAMA'        => $this->SNAMA ?: null,
            'SSATUANDASAR' => $this->SSATUANDASAR ? 1 : 0,
            'SNILAI'       => $this->SNILAI ?? 1,
        ];

        if ($this->editingId) {
            Unit::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/satuan', $this->editingId, 'Ubah satuan ' . $this->SKODE);
        } else {
            $u = Unit::create($payload);
            activity_log('create', 'master/satuan', $u->SID, 'Tambah satuan ' . $this->SKODE);
        }

        session()->flash('status', 'Satuan disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        if (Item::where('isatuan', $id)->orWhere('isatuand', $id)->exists()) {
            session()->flash('error', 'Tidak bisa dihapus: masih dipakai di data item.');

            return;
        }

        Unit::whereKey($id)->delete();
        activity_log('delete', 'master/satuan', $id, 'Hapus satuan');
        session()->flash('status', 'Satuan dihapus.');
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = Unit::query()
            ->when($q !== '', fn ($b) => $b->where('SKODE', 'like', "%{$q}%")->orWhere('SNAMA', 'like', "%{$q}%"))
            ->orderBy('SKODE')
            ->paginate(20);

        return view('livewire.master.unit-manager', ['rows' => $rows]);
    }
}
