<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <div class="input-group input-group-sm" style="max-width: 280px">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" class="form-control" placeholder="Cari…" wire:model.live.debounce.400ms="search">
            </div>
            @if (can_do('master/satuan', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Kode</th><th>Nama</th>
                    <th class="text-center">Satuan Dasar</th><th class="text-center">Nilai Konversi</th>
                    <th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="unit-{{ $r->SID }}">
                            <td>{{ $r->SKODE }}</td>
                            <td>{{ $r->SNAMA ?: '—' }}</td>
                            <td class="text-center">@if((int)$r->SSATUANDASAR===1)<i class="fas fa-check text-success"></i>@endif</td>
                            <td class="text-center">{{ $r->SNILAI }}</td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/satuan', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->SID }})"><i class="fas fa-pen"></i></button>
                                @endif
                                @if (can_do('master/satuan', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->SID }})"
                                            data-confirm="Hapus satuan {{ $r->SKODE }}?"><i class="fas fa-trash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Satuan' : 'Satuan Baru'">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="SKODE">
                            @error('SKODE') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Nama</label>
                            <input type="text" class="form-control" wire:model="SNAMA">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nilai Konversi</label>
                            <input type="number" class="form-control" wire:model="SNILAI">
                        </div>
                        <div class="col-md-7 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="unit-dasar" wire:model="SSATUANDASAR">
                                <label class="form-check-label" for="unit-dasar">Satuan Dasar</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
