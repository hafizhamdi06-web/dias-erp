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
                        <button class="btn btn-outline-primary btn-sm" wire:click="bukaSalin">
                            <i class="fas fa-clone me-1"></i> Salin dari user lain
                        </button>
                        <button class="btn btn-outline-primary btn-sm" wire:click="bukaTerapkan">
                            <i class="fas fa-users me-1"></i> Terapkan ke user lain
                        </button>
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

    {{-- ============ SALIN DARI USER LAIN ============ --}}
    {{-- WAJIB dibungkus @if - BUKAN sekadar gaya. `x-lw-modal` memakai `wire:ignore.self`,
         jadi Livewire TIDAK memperbarui atribut elemen modal itu sendiri. Kalau elemennya
         selalu ada di halaman, kelas `show d-block` tidak pernah ikut terpasang saat
         $show berubah -> modal tidak pernah muncul walau state-nya sudah true.
         Dgn @if, elemennya dibuat BARU saat dibutuhkan sehingga sudah membawa kelas itu.
         Semua modal lain di app ini memakai pola yg sama. --}}
    @if ($showSalinModal)
    <x-lw-modal :show="$showSalinModal" title="Salin Hak Akses dari User Lain"
                close="$set('showSalinModal', false)">
        <div class="modal-body">
            <p class="text-muted small">
                Hak akses user yang dipilih akan <strong>dimuat ke layar</strong> dan menimpa
                centangan yang sekarang. Belum tersimpan — periksa dulu, lalu klik <em>Simpan</em>.
            </p>
            <input type="text" class="form-control form-control-sm mb-2" autocomplete="off"
                   placeholder="cari username / nama…" wire:model.live.debounce.300ms="salinSearch">

            <div class="list-group" style="max-height: 320px; overflow-y: auto">
                @forelse ($salinResult as $u)
                    <button type="button" class="list-group-item list-group-item-action py-1"
                            wire:click="salinDari({{ $u->UID }})">
                        <span class="font-monospace small">{{ $u->UKODE }}</span>
                        — {{ $u->UNAMALENGKAP ?: $u->UNAMA }}
                    </button>
                @empty
                    <div class="text-muted small px-1 py-2">
                        {{ trim($salinSearch) === '' ? 'Ketik untuk mencari user.' : 'Tidak ada hasil.' }}
                    </div>
                @endforelse
            </div>
        </div>
    </x-lw-modal>
    @endif

    {{-- ============ TERAPKAN KE BANYAK USER ============ --}}
    @if ($showTerapkanModal)
    <x-lw-modal :show="$showTerapkanModal" title="Terapkan Hak Akses ke User Lain"
                close="$set('showTerapkanModal', false)">
        <div class="modal-body">
            <div class="alert alert-warning py-2 small">
                <i class="fas fa-triangle-exclamation me-1"></i>
                Hak akses user tujuan akan <strong>diganti seluruhnya</strong> mengikuti centangan
                di layar ini — bukan digabung. Yang sebelumnya mereka punya dan tidak ada di sini
                akan hilang.
            </div>

            <input type="text" class="form-control form-control-sm mb-2" autocomplete="off"
                   placeholder="cari username / nama…" wire:model.live.debounce.300ms="terapkanSearch">

            <div class="d-flex justify-content-between align-items-center mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        wire:click="pilihSemuaHasil" @disabled(count($terapkanResult) === 0)>
                    Pilih semua hasil ({{ count($terapkanResult) }})
                </button>
                <span class="small {{ count($terapkanTargets) ? 'fw-semibold' : 'text-muted' }}">
                    {{ count($terapkanTargets) }} user dipilih
                </span>
            </div>

            <div class="list-group" style="max-height: 300px; overflow-y: auto">
                @forelse ($terapkanResult as $u)
                    <label class="list-group-item list-group-item-action py-1 d-flex gap-2 align-items-center">
                        <input type="checkbox" class="form-check-input m-0"
                               wire:click="toggleTarget({{ $u->UID }})"
                               @checked(in_array((int) $u->UID, $terapkanTargets, true))>
                        <span>
                            <span class="font-monospace small">{{ $u->UKODE }}</span>
                            — {{ $u->UNAMALENGKAP ?: $u->UNAMA }}
                        </span>
                    </label>
                @empty
                    <div class="text-muted small px-1 py-2">
                        {{ trim($terapkanSearch) === '' ? 'Ketik untuk mencari user.' : 'Tidak ada hasil.' }}
                    </div>
                @endforelse
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" wire:click="$set('showTerapkanModal', false)">Batal</button>
            <button type="button" class="btn btn-primary" wire:click="terapkanKe"
                    data-confirm="Ganti hak akses {{ count($terapkanTargets) }} user mengikuti layar ini?"
                    @disabled(count($terapkanTargets) === 0)>
                <span wire:loading wire:target="terapkanKe" class="spinner-border spinner-border-sm me-1"></span>
                Terapkan ke {{ count($terapkanTargets) }} user
            </button>
        </div>
    </x-lw-modal>
    @endif
</div>
