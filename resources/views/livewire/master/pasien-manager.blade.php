<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 260px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="kode / nama / ID / KTP / telp"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 150px" wire:model.live="fKategori">
                    <option value="">Semua tipe</option>
                    <option value="tunai">Tunai</option>
                    <option value="member">Member</option>
                </select>
                <select class="form-select form-select-sm" style="max-width: 170px" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <select class="form-select form-select-sm" style="max-width: 140px" wire:model.live="fAktif">
                    <option value="1">Aktif saja</option>
                    <option value="">Semua status</option>
                </select>
            </div>
            @if (can_do('master/pasien', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-user-plus me-1"></i> Pasien Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>ID Pasien</th><th>Kode</th><th>Nama</th><th>Tipe</th><th>Cabang</th><th>Telp</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="psn-{{ $r->KID }}">
                            <td class="text-muted small">{{ $r->KIDPASIEN ?: '—' }}</td>
                            <td>{{ $r->KKODE }}</td>
                            <td>{{ $r->KNAMA }}</td>
                            <td class="text-muted small">{{ $r->kategori ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->K1TELP1 ?: '—' }}</td>
                            <td class="text-center">
                                <span class="badge {{ (int) $r->KAKTIF !== 0 ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ (int) $r->KAKTIF !== 0 ? 'Aktif' : 'Non-Aktif' }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/pasien', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->KID }})"><i class="fas fa-pen"></i></button>
                                @endif
                                @if (can_do('master/pasien', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->KID }})"
                                            data-confirm="Nonaktifkan pasien {{ $r->KNAMA }}?"><i class="fas fa-user-slash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada pasien.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Pasien' : 'Pasien Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="kode">
                            @error('kode') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ID Pasien</label>
                            <input type="text" class="form-control form-control-sm" wire:model="idpasien">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="nama">
                            @error('nama') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Kategori</label>
                            <select class="form-select form-select-sm" wire:model="kategori">
                                @foreach ($kategoris as $k)
                                    <option value="{{ $k->KTID }}">{{ $k->KTNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">No. Member</label>
                            <input type="text" class="form-control form-control-sm" wire:model="nomember">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">No. KTP</label>
                            <input type="text" class="form-control form-control-sm" wire:model="noktp">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cabang</label>
                            <select class="form-select form-select-sm" wire:model="cabang">
                                <option value="0">—</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Tempat Lahir</label>
                            <input type="text" class="form-control form-control-sm" wire:model="tempatlahir">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tgl Lahir</label>
                            <input type="date" class="form-control form-control-sm" wire:model="tgllahir">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" class="form-control form-control-sm" wire:model="pekerjaan">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tgl Kontrak</label>
                            <input type="date" class="form-control form-control-sm" wire:model="tglkontrak">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Jenis Kelamin</label>
                            <select class="form-select form-select-sm" wire:model="kelamin">
                                <option value="0">Perempuan</option>
                                <option value="1">Laki-laki</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status Pasien</label>
                            <select class="form-select form-select-sm" wire:model="barulama">
                                <option value="0">Baru</option>
                                <option value="1">Lama</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-2">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Alamat</label>
                            <textarea class="form-control form-control-sm" rows="2" wire:model="alamat"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kota</label>
                            <x-search-select model="kota" :value="$kota" :selected-text="$labels['kota'] ?? null"
                                             :endpoint="route('lookup.wilayah.kota')" placeholder="cari kota…" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kecamatan</label>
                            <x-search-select model="kecamatan" :value="$kecamatan" :selected-text="$labels['kecamatan'] ?? null"
                                             :endpoint="route('lookup.wilayah.kecamatan')" placeholder="cari kecamatan…" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telepon</label>
                            <input type="text" class="form-control form-control-sm" wire:model="telp">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="text" class="form-control form-control-sm" wire:model="email">
                            @error('email') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <hr class="my-2">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">No. Kartu</label>
                            <input type="text" class="form-control form-control-sm" wire:model="nokartu">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Kode TADA</label>
                            <input type="text" class="form-control form-control-sm" wire:model="kodetada">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sales / Karyawan</label>
                            <x-search-select model="karyawan" :value="$karyawan" :selected-text="$labels['karyawan'] ?? null"
                                             :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Karyawan Training</label>
                            <x-search-select model="karyawantraining" :value="$karyawantraining" :selected-text="$labels['karyawantraining'] ?? null"
                                             :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Marketing Source</label>
                            <x-search-select model="marketingsource" :value="$marketingsource" :selected-text="$labels['marketingsource'] ?? null"
                                             :endpoint="route('lookup.lain', 'marketing-source')" placeholder="cari marketing source…" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Insider / Ref</label>
                            <input type="text" class="form-control form-control-sm" wire:model="insider">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="psn-aktif" wire:model="aktif">
                                <label class="form-check-label" for="psn-aktif">Aktif</label>
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
