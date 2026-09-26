<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="position-relative" style="min-width: 320px">
                @if ($selectedUser)
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold">User:</span>
                        <span class="badge text-bg-primary fs-6">
                            {{ $selectedUser->UKODE }} — {{ $selectedUser->UNAMALENGKAP ?: $selectedUser->UNAMA }}
                        </span>
                        <button class="btn btn-outline-secondary btn-sm" wire:click="clearUser">
                            <i class="fas fa-rotate me-1"></i> Ganti
                        </button>
                    </div>
                @else
                    <label class="form-label small fw-semibold mb-1">Cari user</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="username / nama…"
                               wire:model.live.debounce.350ms="userSearch" autocomplete="off">
                    </div>

                    @if (strlen(trim($userSearch)) > 0)
                        <div class="list-group position-absolute w-100 shadow-sm"
                             style="z-index: 1050; max-height: 260px; overflow-y: auto">
                            @forelse ($searchResult as $u)
                                <button type="button" class="list-group-item list-group-item-action"
                                        wire:key="su-{{ $u->UID }}" wire:click="pick({{ $u->UID }})">
                                    <span class="fw-semibold">{{ $u->UKODE }}</span>
                                    <span class="text-muted">— {{ $u->UNAMALENGKAP ?: $u->UNAMA }}</span>
                                </button>
                            @empty
                                <div class="list-group-item text-muted small">Tidak ada user cocok.</div>
                            @endforelse
                        </div>
                    @endif
                @endif
            </div>
            @if ($userId)
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" wire:click="grantAll">Centang semua</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="revokeAll">Kosongkan</button>
                    @if (can_do('admin/user-access', 'edit'))
                        <button class="btn btn-primary btn-sm" wire:click="save">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            <i class="fas fa-save me-1"></i> Simpan
                        </button>
                    @endif
                </div>
            @endif
        </div>

        <div class="card-body p-0">
            @if (! $userId)
                <p class="text-muted text-center py-5 mb-0">Pilih user untuk mengatur hak akses menu.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 260px">Menu</th>
                                @foreach ($abilities as $ab)
                                    <th class="text-center text-capitalize" style="width: 70px">{{ $ab }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tree as $node)
                                @include('livewire.admin.partials.access-row', ['node' => $node, 'depth' => 0, 'abilities' => $abilities])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
