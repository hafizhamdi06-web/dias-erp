<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="input-group input-group-sm" style="max-width: 320px">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" class="form-control" placeholder="Cari username / nama…"
                       wire:model.live.debounce.400ms="search">
            </div>
            @if (can_do('admin/user', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create">
                    <i class="fas fa-user-plus me-1"></i> User Baru
                </button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama</th>
                        <th>Cabang</th>
                        <th class="text-center">Password Laravel</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr wire:key="user-{{ $u->UID }}">
                            <td>{{ $u->UKODE }}</td>
                            <td>{{ $u->UNAMALENGKAP ?: $u->UNAMA }}</td>
                            <td class="text-muted small" style="max-width: 200px; white-space: normal; word-break: break-word;">{{ $u->UCABANGPILIH ?: '—' }}</td>
                            <td class="text-center">
                                @if (in_array($u->UID, $authIds))
                                    <span class="badge text-bg-success">ada</span>
                                @else
                                    <span class="badge text-bg-secondary" title="Akan dibuat saat login pertama (MD5 lama)">belum</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ((int) $u->UACTIVE === 1)
                                    <i class="fas fa-check text-success"></i>
                                @else
                                    <i class="fas fa-xmark text-danger"></i>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if (can_do('admin/user', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $u->UID }})" title="Ubah">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="btn btn-outline-primary btn-sm" wire:click="openResetPassword({{ $u->UID }})" title="Reset password">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <button class="btn btn-outline-{{ (int) $u->UACTIVE === 1 ? 'danger' : 'success' }} btn-sm"
                                            wire:click="toggleActive({{ $u->UID }})"
                                            data-confirm="Ubah status aktif user {{ $u->UKODE }}?"
                                            title="{{ (int) $u->UACTIVE === 1 ? 'Nonaktifkan' : 'Aktifkan' }}">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada user.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $users->links() }}
        </div>
    </div>

    {{-- Modal tambah/ubah user --}}
    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah User' : 'User Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="UKODE">
                            @error('UKODE') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nama (singkat) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="UNAMA">
                            @error('UNAMA') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-control" wire:model="UNAMALENGKAP">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Karyawan</label>
                            <x-search-select model="UKID" :value="$UKID" :selected-text="$labels['UKID'] ?? null"
                                             :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" />
                            <div class="form-text">Dipakai sbg nama kasir otomatis di POS.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Cabang Utama</label>
                            <select class="form-select" wire:model="UCABANG">
                                <option value="">—</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Cabang yang Boleh Diakses</span>
                                <span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="selectAllBranches">Pilih semua</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="clearBranches">Kosongkan</button>
                                </span>
                            </label>
                            <div class="border rounded p-2" style="max-height: 140px; overflow-y:auto">
                                <div class="row">
                                    @foreach ($branches as $b)
                                        <div class="col-6 col-lg-4">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input"
                                                       id="ub-{{ $b->GID }}" value="{{ $b->GID }}"
                                                       wire:model="branchPilih">
                                                <label class="form-check-label small" for="ub-{{ $b->GID }}">
                                                    {{ $b->GNAMA }}
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Password {{ $editingId ? '(kosongkan bila tidak diubah)' : '' }}
                                @if (! $editingId) <span class="text-danger">*</span> @endif
                            </label>
                            <input type="text" class="form-control" wire:model="password" autocomplete="off">
                            @error('password') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="u-legacy" wire:model="legacy_login">
                                <label class="form-check-label" for="u-legacy">
                                    Bisa login CI3 juga (tulis MD5 ke UPASSWORD)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="u-active" wire:model="UACTIVE">
                                <label class="form-check-label" for="u-active">Aktif</label>
                            </div>
                        </div>

                        {{-- Ukuran struk POS per user (2026-09-30). Kosong = ikut default
                             aplikasi, barisnya tidak disimpan sama sekali. --}}
                        <div class="col-md-4">
                            <label class="form-label" for="u-struk">Struk POS</label>
                            <select class="form-select @error('struk_pos') is-invalid @enderror"
                                    id="u-struk" wire:model="struk_pos">
                                <option value="">
                                    Ikut default ({{ config('pos.struk_pilihan')[config('pos.struk_default')] ?? config('pos.struk_default') }})
                                </option>
                                @foreach (config('pos.struk_pilihan', []) as $kode => $label)
                                    <option value="{{ $kode }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('struk_pos') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Dipakai saat user ini mencetak struk dari POS.</div>
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

    {{-- Modal reset password --}}
    @if ($showPwModal)
        <x-lw-modal :show="$showPwModal" title="Reset Password" close="$set('showPwModal', false)">
            <form wire:submit="resetPassword">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" wire:model="newPassword"
                                   autocomplete="off" placeholder="ketik sendiri atau klik Buat Password">
                            <button type="button" class="btn btn-outline-secondary" wire:click="buatPassword">
                                <i class="fas fa-wand-magic-sparkles me-1"></i> Buat Password
                            </button>
                        </div>
                        @error('newPassword') <div class="text-danger small">{{ $message }}</div> @enderror
                        <div class="form-text">
                            Password ini <strong>sementara</strong>. Begitu dipakai login, user wajib
                            menggantinya sendiri dengan password yang kuat.
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="pw-legacy" wire:model="pwLegacy">
                        <label class="form-check-label" for="pw-legacy">
                            Update juga UPASSWORD (MD5) untuk login CI3
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showPwModal', false)">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
