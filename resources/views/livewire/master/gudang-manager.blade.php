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
                <input type="text" class="form-control" placeholder="Cari kode / nama / inisial…"
                       wire:model.live.debounce.400ms="search">
            </div>
            @if (can_do('master/gudang', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Kode</th><th>Inisial</th><th>Nama</th><th>Divisi</th>
                        <th>Kota</th><th>Kontak di SJ</th>
                        <th class="text-center">Pakai Batch</th>
                        <th class="text-center">Default</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-end">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="gudang-{{ $r->GID }}">
                                <td class="fw-semibold">{{ $r->GKODE }}</td>
                                <td><span class="badge text-bg-secondary">{{ $r->GALAMAT1 ?: '—' }}</span></td>
                                <td>{{ $r->GNAMA ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->divisi ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->GKOTA ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->kontak_sj ?: '—' }}</td>
                                <td class="text-center">
                                    @if ((int) $r->GPAKAISERIAL === 1)
                                        <span class="badge text-bg-success"><i class="fas fa-barcode me-1"></i>Ya</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ((int) $r->GDEFAULT === 1) <i class="fas fa-check text-success"></i> @endif
                                </td>
                                <td class="text-center">
                                    @if ((int) $r->GAKTIF === 0)
                                        <span class="badge text-bg-secondary">Nonaktif</span>
                                    @else
                                        <i class="fas fa-check text-success"></i>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if (can_do('master/gudang', 'edit'))
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->GID }})">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Gudang' : 'Gudang Baru'">
            <form wire:submit="save">
                <div class="modal-body">
                    {{-- Layout mengikuti bFrmGudang (VB6): label kiri, input kanan, 3 kelompok --}}
                    <style>
                        .gd-field { display: flex; align-items: flex-start; gap: .75rem; margin-bottom: .5rem; }
                        .gd-field > label { flex: 0 0 120px; max-width: 120px; padding-top: .35rem; margin-bottom: 0; }
                        .gd-field > .gd-input { flex: 1 1 auto; min-width: 0; }
                    </style>

                    {{-- ---- Kelompok 1: identitas ---- --}}
                    <div class="gd-field">
                        <label class="form-label">Kode <span class="text-danger">*</span></label>
                        <div class="gd-input d-flex gap-3 align-items-start">
                            <div style="max-width: 220px; width: 100%">
                                <input type="text" class="form-control form-control-sm" wire:model="GKODE">
                                @error('GKODE') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-check mt-1">
                                <input type="checkbox" class="form-check-input" id="gd-default" wire:model="GDEFAULT">
                                <label class="form-check-label" for="gd-default">Default</label>
                            </div>
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <div class="gd-input">
                            <input type="text" class="form-control form-control-sm" wire:model="GNAMA">
                            @error('GNAMA') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Divisi</label>
                        <div class="gd-input">
                            <select class="form-select form-select-sm" wire:model="GDIVISI">
                                <option value="">— pilih —</option>
                                @foreach ($divisis as $d)
                                    <option value="{{ $d->DID }}">{{ $d->DNAMA ?: $d->DKODE }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Kontak</label>
                        <div class="gd-input">
                            <input type="text" class="form-control form-control-sm" wire:model="GKONTAK">
                        </div>
                    </div>

                    <hr class="my-3">

                    {{-- ---- Kelompok 2: alamat ---- --}}
                    <div class="gd-field">
                        <label class="form-label">Inisial</label>
                        <div class="gd-input" style="max-width: 220px">
                            <input type="text" class="form-control form-control-sm bg-body-secondary"
                                   value="{{ $GALAMAT1 }}" disabled>
                            <div class="form-text">Dipakai sebagai awalan nomor transaksi cabang ini — tidak bisa diubah.</div>
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Alamat</label>
                        <div class="gd-input">
                            <input type="text" class="form-control form-control-sm" wire:model="GALAMAT2">
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Kota</label>
                        <div class="gd-input"><input type="text" class="form-control form-control-sm" wire:model="GKOTA"></div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Provinsi</label>
                        <div class="gd-input"><input type="text" class="form-control form-control-sm" wire:model="GPROPINSI"></div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Negara</label>
                        <div class="gd-input"><input type="text" class="form-control form-control-sm" wire:model="GNEGARA"></div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Telepon</label>
                        <div class="gd-input d-flex gap-2 align-items-center">
                            <input type="text" class="form-control form-control-sm" style="max-width: 200px" wire:model="GTELP">
                            <label class="form-label mb-0 ms-2">Fax</label>
                            <input type="text" class="form-control form-control-sm" style="max-width: 200px" wire:model="GFAX">
                        </div>
                    </div>

                    <hr class="my-3">

                    {{-- ---- Kelompok 3: lain-lain ---- --}}
                    <div class="gd-field">
                        <label class="form-label">Keterangan</label>
                        <div class="gd-input">
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled>
                            <div class="form-text">Ada di form lama tapi tidak punya kolom di database — tidak tersimpan.</div>
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">Kontak di SJ</label>
                        <div class="gd-input">
                            <x-search-select model="GKONTAKSJ" :value="$GKONTAKSJ" :selected-text="$kontakSjLabel"
                                             :endpoint="route('lookup.kontak')" placeholder="cari kontak…" />
                        </div>
                    </div>
                    <div class="gd-field">
                        <label class="form-label">No Batch</label>
                        <div class="gd-input">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="gd-serial" wire:model="GPAKAISERIAL">
                                <label class="form-check-label" for="gd-serial">Pakai No Batch / Serial</label>
                            </div>
                            <div class="form-text">
                                Kalau dicentang, setiap transaksi keluar/masuk stok di gudang ini wajib mengisi
                                No Batch untuk item yang ber-serial.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">Batal</button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
