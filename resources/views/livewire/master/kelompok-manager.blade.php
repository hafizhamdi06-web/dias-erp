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
                <input type="text" class="form-control" placeholder="Cari kode…" wire:model.live.debounce.400ms="search">
            </div>
            @if (can_do('master/kelompok', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Kode</th><th>Kode Report</th>
                    <th class="text-center">Wajib Dokter</th><th class="text-center">Produk</th>
                    <th class="text-center">Urutan</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="kel-{{ $r->IK2ID }}">
                            <td>{{ $r->IK2KODE }}</td>
                            <td class="text-muted">{{ $r->IK2KODE_REPORT ?: '—' }}</td>
                            <td class="text-center">@if((int)$r->IKWAJIBDOKTER===1)<i class="fas fa-check text-success"></i>@endif</td>
                            <td class="text-center">@if((int)$r->IKPRODUK===1)<i class="fas fa-check text-success"></i>@endif</td>
                            <td class="text-center">{{ $r->IKURUTAN }}</td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/kelompok', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->IK2ID }})"><i class="fas fa-pen"></i></button>
                                @endif
                                @if (can_do('master/kelompok', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->IK2ID }})"
                                            data-confirm="Hapus kelompok {{ $r->IK2KODE }}?"><i class="fas fa-trash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Kelompok' : 'Kelompok Baru'">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="IK2KODE">
                            @error('IK2KODE') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kode Report</label>
                            <input type="text" class="form-control" wire:model="IK2KODE_REPORT">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Urutan</label>
                            <input type="number" class="form-control" wire:model="IKURUTAN">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="kel-dokter" wire:model="IKWAJIBDOKTER">
                                <label class="form-check-label" for="kel-dokter">Wajib Dokter</label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="kel-produk" wire:model="IKPRODUK">
                                <label class="form-check-label" for="kel-produk">Produk</label>
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
