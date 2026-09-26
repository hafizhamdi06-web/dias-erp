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
            @if (can_do('master/paket', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Paket Baru</button>
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
                        <th class="text-end">Total Harga</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="paket-{{ $r->PUID }}">
                            <td>{{ $r->PUKODE }}</td>
                            <td class="text-muted">{{ $r->PUNAMA ?: '—' }}</td>
                            <td class="text-muted small">
                                @if ($r->PUTANGGAL1 || $r->PUTANGGAL2)
                                    {{ $r->PUTANGGAL1 ? \Illuminate\Support\Carbon::parse($r->PUTANGGAL1)->format('d/m/Y') : '—' }}
                                    &ndash;
                                    {{ $r->PUTANGGAL2 ? \Illuminate\Support\Carbon::parse($r->PUTANGGAL2)->format('d/m/Y') : '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-muted small">{{ \App\Models\Package::JENIS_PELANGGAN[(int) $r->PUJENISPELANGGAN] ?? '—' }}</td>
                            <td class="text-end small">{{ number_format((float) $r->PUTOTALHARGA, 0, ',', '.') }}</td>
                            <td class="text-center">
                                @if ((int) $r->PUAKTIF === 1)
                                    <i class="fas fa-check text-success"></i>
                                @else
                                    <i class="fas fa-xmark text-danger"></i>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/paket', 'edit'))
                                    <button class="btn btn-outline-info btn-sm" wire:click="openDetail({{ $r->PUID }})" title="Kelola Item Paket">
                                        <i class="fas fa-boxes-packing"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->PUID }})" title="Ubah">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                @endif
                                @if (can_do('master/paket', 'delete') && (int) $r->PUAKTIF === 1)
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->PUID }})"
                                            data-confirm="Nonaktifkan paket {{ $r->PUKODE }}?" title="Nonaktifkan">
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
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Paket' : 'Paket Baru'" size="lg">
            <form wire:submit="save">
                <div class="modal-body">
                    <div x-data="{ tab: 'detail' }" x-cloak>
                        <ul class="nav nav-tabs" role="tablist">
                            @foreach (['detail' => 'Detail', 'cabang' => 'Cabang'] as $key => $label)
                                <li class="nav-item">
                                    <button class="nav-link" :class="{ active: tab === '{{ $key }}' }"
                                            @click="tab = '{{ $key }}'" type="button">
                                        {{ $label }}
                                        @if ($key === 'cabang')
                                            @if ($PUSEMUACABANG)
                                                <span class="badge text-bg-secondary ms-1">Semua</span>
                                            @elseif (count($cabangPilih))
                                                <span class="badge text-bg-secondary ms-1">{{ count($cabangPilih) }}</span>
                                            @else
                                                <span class="badge text-bg-danger ms-1" title="Kosong = tidak aktif di cabang manapun">!</span>
                                            @endif
                                        @endif
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
                                        <input type="text" class="form-control" wire:model="PUKODE">
                                        @error('PUKODE') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Nama</label>
                                        <input type="text" class="form-control" wire:model="PUNAMA">
                                        @error('PUNAMA') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Tanggal Berlaku Dari</label>
                                        <input type="date" class="form-control" wire:model="PUTANGGAL1">
                                        @error('PUTANGGAL1') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Tanggal Berlaku Sampai</label>
                                        <input type="date" class="form-control" wire:model="PUTANGGAL2">
                                        @error('PUTANGGAL2') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Jenis Pelanggan</label>
                                        <select class="form-select" wire:model="PUJENISPELANGGAN">
                                            @foreach (\App\Models\Package::JENIS_PELANGGAN as $val => $label)
                                                <option value="{{ $val }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Jumlah Kedatangan/Sesi</label>
                                        <input type="number" min="0" step="1" class="form-control" wire:model="PUJUMLAH">
                                        @error('PUJUMLAH') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Masa Berlaku (hari)</label>
                                        <input type="number" min="0" step="1" class="form-control" wire:model="PUUMUR">
                                        @error('PUUMUR') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-12">
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-aktif" wire:model="PUAKTIF">
                                                <label class="form-check-label" for="pu-aktif">Aktif</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-pasienbaru" wire:model="PUPASIENBARU">
                                                <label class="form-check-label" for="pu-pasienbaru">Pasien Baru Saja</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-konsulsaja" wire:model="PUKONSULSAJA">
                                                <label class="form-check-label" for="pu-konsulsaja">Konsul Saja</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-cetakheader" wire:model="PUCETAKHEADERSAJA">
                                                <label class="form-check-label" for="pu-cetakheader">Cetak Header Saja (struk)</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-berdua" wire:model="PUBERDUA">
                                                <label class="form-check-label" for="pu-berdua">Paket Berdua</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-kombinasi" wire:model="PUKOMBINASI">
                                                <label class="form-check-label" for="pu-kombinasi">Kombinasi</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-komisi" wire:model="PUDAPATKOMISI">
                                                <label class="form-check-label" for="pu-komisi">Dapat Komisi</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="pu-promo" wire:model="PUPROMO">
                                                <label class="form-check-label" for="pu-promo">Promo</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Max Pasien</label>
                                        <input type="number" min="0" step="1" class="form-control" wire:model="PUMAXPASIEN">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Min Transaksi per Invoice</label>
                                        <input type="number" min="0" step="0.01" class="form-control" wire:model="PUMINTRANSAKSIPERIV">
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="pu-pakaijam" wire:model.live="PUPAKAIJAM">
                                            <label class="form-check-label" for="pu-pakaijam">Pakai Jam (batasi jam berlaku)</label>
                                        </div>
                                    </div>
                                    @if ($PUPAKAIJAM)
                                        <div class="col-md-3">
                                            <label class="form-label small mb-1">Jam Dari</label>
                                            <input type="time" class="form-control" wire:model="PUJAM1">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small mb-1">Jam Sampai</label>
                                            <input type="time" class="form-control" wire:model="PUJAM2">
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Cabang --}}
                            <div x-show="tab === 'cabang'">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="pu-semuacabang" wire:model.live="PUSEMUACABANG">
                                    <label class="form-check-label" for="pu-semuacabang">Berlaku di semua cabang</label>
                                </div>
                                @if (! $PUSEMUACABANG)
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small {{ count($cabangPilih) ? 'text-muted' : 'text-danger fw-semibold' }}">
                                            {{ count($cabangPilih) }} cabang dipilih
                                            @if (! count($cabangPilih))
                                                <i class="fas fa-triangle-exclamation ms-1"></i> kosong = paket TIDAK aktif di cabang manapun
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
                                @else
                                    <div class="text-muted small">Paket ini berlaku di semua cabang - pilihan cabang di bawah diabaikan.</div>
                                @endif
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
