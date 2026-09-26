<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 280px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="Cari kode / nama…" wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="width:auto" wire:model.live="fAktif">
                    <option value="">Semua status</option>
                    <option value="1">Aktif</option>
                    <option value="0">Tidak aktif</option>
                </select>
                <select class="form-select form-select-sm" style="width:auto" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <div class="d-flex align-items-center gap-1">
                    <span class="text-muted small">Berlaku</span>
                    <input type="date" class="form-control form-control-sm" style="width:auto" wire:model.live="fTanggalDari" title="Berlaku dari">
                    <span class="text-muted small">&ndash;</span>
                    <input type="date" class="form-control form-control-sm" style="width:auto" wire:model.live="fTanggalSampai" title="Berlaku sampai">
                    @if ($fTanggalDari || $fTanggalSampai)
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="clearTanggalFilter" title="Hapus filter tanggal">
                            <i class="fas fa-xmark"></i>
                        </button>
                    @endif
                </div>
            </div>
            @if (can_do('master/promo', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Promo Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Berlaku</th>
                        <th>Jenis Pelanggan</th>
                        <th>Jenis Promo</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="promo-{{ $r->MPUID }}">
                            <td>{{ $r->MPUKODE }}</td>
                            <td class="text-muted">{{ $r->MPUNAMA ?: '—' }}</td>
                            <td class="text-muted small">
                                @if ($r->MPUTANGGAL1 || $r->MPUTANGGAL2)
                                    {{ $r->MPUTANGGAL1 ? \Illuminate\Support\Carbon::parse($r->MPUTANGGAL1)->format('d/m/Y') : '—' }}
                                    &ndash;
                                    {{ $r->MPUTANGGAL2 ? \Illuminate\Support\Carbon::parse($r->MPUTANGGAL2)->format('d/m/Y') : '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-muted small">{{ \App\Models\Promo::JENIS_PELANGGAN[(int) $r->MPUJENISPELANGGAN] ?? '—' }}</td>
                            <td class="text-muted small">{{ \App\Models\Promo::JENIS_PROMO[(int) $r->MPUJENISPROMO] ?? '—' }}</td>
                            <td class="text-center">
                                @if ((int) $r->MPUAKTIF === 1)
                                    <i class="fas fa-check text-success"></i>
                                @else
                                    <i class="fas fa-xmark text-danger"></i>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ((int) $r->MPUJENISPROMO === 1 && can_do('master/promo', 'edit'))
                                    <button class="btn btn-outline-info btn-sm" wire:click="openKombinasi({{ $r->MPUID }})" title="Kelola Kombinasi">
                                        <i class="fas fa-tags"></i>
                                    </button>
                                @endif
                                @if ((int) $r->MPUJENISPROMO === 0 && can_do('master/promo', 'edit'))
                                    <button class="btn btn-outline-info btn-sm" wire:click="openBiasa({{ $r->MPUID }})" title="Kelola Detail">
                                        <i class="fas fa-tags"></i>
                                    </button>
                                @endif
                                @if (can_do('master/promo', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->MPUID }})" title="Ubah">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                @endif
                                @if (can_do('master/promo', 'delete') && (int) $r->MPUAKTIF === 1)
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->MPUID }})"
                                            data-confirm="Nonaktifkan promo {{ $r->MPUKODE }}?" title="Nonaktifkan">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Promo' : 'Promo Baru'" size="lg">
            <form wire:submit="save">
                <div class="modal-body">
                    <div x-data="{ tab: 'detail' }" x-cloak>
                        <ul class="nav nav-tabs" role="tablist">
                            @foreach (['detail' => 'Detail', 'cabang' => 'Cabang', 'tipe' => 'Tipe Pelanggan'] as $key => $label)
                                <li class="nav-item">
                                    <button class="nav-link" :class="{ active: tab === '{{ $key }}' }"
                                            @click="tab = '{{ $key }}'" type="button">
                                        {{ $label }}
                                        @if ($key === 'cabang')
                                            @if (count($cabangPilih))
                                                <span class="badge text-bg-secondary ms-1">{{ count($cabangPilih) }}</span>
                                            @else
                                                <span class="badge text-bg-danger ms-1" title="Kosong = tidak aktif di cabang manapun">!</span>
                                            @endif
                                        @endif
                                        @if ($key === 'tipe' && count($tipePilih)) <span class="badge text-bg-secondary ms-1">{{ count($tipePilih) }}</span> @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content border border-top-0 p-3">

                            {{-- Detail --}}
                            <div x-show="tab === 'detail'">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Kode <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" wire:model="MPUKODE">
                                        @error('MPUKODE') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Nama</label>
                                        <input type="text" class="form-control" wire:model="MPUNAMA">
                                        @error('MPUNAMA') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Tanggal Berlaku Dari</label>
                                        <input type="date" class="form-control" wire:model="MPUTANGGAL1">
                                        @error('MPUTANGGAL1') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Tanggal Berlaku Sampai</label>
                                        <input type="date" class="form-control" wire:model="MPUTANGGAL2">
                                        @error('MPUTANGGAL2') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Jenis Pelanggan</label>
                                        <select class="form-select" wire:model="MPUJENISPELANGGAN">
                                            @foreach (\App\Models\Promo::JENIS_PELANGGAN as $val => $label)
                                                <option value="{{ $val }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Jenis Promo</label>
                                        <select class="form-select" wire:model="MPUJENISPROMO">
                                            @foreach (\App\Models\Promo::JENIS_PROMO as $val => $label)
                                                <option value="{{ $val }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @if (! in_array((int) $MPUJENISPROMO, [0, 1], true))
                                            <div class="form-text text-warning">
                                                <i class="fas fa-triangle-exclamation me-1"></i>Detail promo utk jenis ini belum didukung di sini - hubungi admin kalau perlu detail lengkap.
                                            </div>
                                        @endif
                                    </div>

                                    <div class="col-md-6 d-flex align-items-end">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="promo-aktif" wire:model="MPUAKTIF">
                                            <label class="form-check-label" for="promo-aktif">Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Cabang --}}
                            <div x-show="tab === 'cabang'">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small {{ count($cabangPilih) ? 'text-muted' : 'text-danger fw-semibold' }}">
                                        {{ count($cabangPilih) }} cabang dipilih
                                        @if (! count($cabangPilih))
                                            <i class="fas fa-triangle-exclamation ms-1"></i> kosong = promo TIDAK aktif di cabang manapun
                                        @endif
                                    </span>
                                    <span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="selectAllCabang">Pilih semua</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="clearCabangPilih">Kosongkan</button>
                                    </span>
                                </div>
                                <div class="border rounded p-2" style="max-height: 320px; overflow-y:auto">
                                    <div class="row">
                                        @foreach ($branches as $b)
                                            <div class="col-6 col-lg-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input"
                                                           id="pc-{{ $b->GID }}" value="{{ $b->GID }}"
                                                           wire:model="cabangPilih">
                                                    <label class="form-check-label small" for="pc-{{ $b->GID }}">
                                                        {{ $b->GNAMA }}
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Tipe Pelanggan --}}
                            <div x-show="tab === 'tipe'">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-muted">{{ count($tipePilih) }} tipe dipilih &middot; kosong = berlaku semua tipe pelanggan</span>
                                    <span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="selectAllTipe">Pilih semua</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="clearTipePilih">Kosongkan</button>
                                    </span>
                                </div>
                                <div class="border rounded p-2" style="max-height: 320px; overflow-y:auto">
                                    <div class="row">
                                        @foreach ($tipeList as $t)
                                            <div class="col-6 col-lg-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input"
                                                           id="pt-{{ $t->KTID }}" value="{{ $t->KTID }}"
                                                           wire:model="tipePilih">
                                                    <label class="form-check-label small" for="pt-{{ $t->KTID }}">
                                                        {{ $t->KTNAMA }}
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
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
