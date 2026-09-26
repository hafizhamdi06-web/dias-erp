<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title mb-0">Struktur Menu</span>
            @if (can_do('admin/menu', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create">
                    <i class="fas fa-plus me-1"></i> Menu Baru
                </button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:45%">Judul</th>
                        <th>Route</th>
                        <th>Tipe</th>
                        <th class="text-center">Urut</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tree as $node)
                        @include('livewire.admin.partials.menu-row', ['node' => $node, 'depth' => 0])
                    @endforeach
                    @if (empty($tree))
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada menu.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Menu' : 'Menu Baru'">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Parent</label>
                            <select class="form-select" wire:model="parent_id">
                                <option value="">— (menu utama) —</option>
                                @foreach ($parentOptions as $opt)
                                    <option value="{{ $opt->id }}">{{ $opt->title }}</option>
                                @endforeach
                            </select>
                            @error('parent_id') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tipe</label>
                            <select class="form-select" wire:model="menu_type">
                                <option value="link">Link (halaman)</option>
                                <option value="group">Group (hanya wadah)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="title">
                            @error('title') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kunci Segment <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="segment_key" placeholder="mis. master.item">
                            @error('segment_key') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Route (path)</label>
                            <input type="text" class="form-control" wire:model="route" placeholder="mis. master/item">
                            @error('route') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ikon (FontAwesome)</label>
                            <input type="text" class="form-control" wire:model="icon" placeholder="fas fa-box">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Urutan</label>
                            <input type="number" class="form-control" wire:model="sort_order">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="mm-active" wire:model="is_active">
                                <label class="form-check-label" for="mm-active">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                        Simpan
                    </button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
