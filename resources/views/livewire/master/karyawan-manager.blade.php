<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 260px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="kode / nama / telp"
                           wire:model.live.debounce.400ms="search">
                </div>
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
            @if (can_do('master/karyawan', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-user-plus me-1"></i> Karyawan Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Kode</th><th>Nama</th><th>Jenis</th><th>Cabang</th><th>No HP</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="kry-{{ $r->KID }}">
                            <td>{{ $r->KKODE }}</td>
                            <td>{{ $r->KNAMA }}</td>
                            <td class="text-muted small">{{ $r->jenis ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->K1TELP1 ?: '—' }}</td>
                            <td class="text-center">
                                <span class="badge {{ (int) $r->KAKTIF !== 0 ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ (int) $r->KAKTIF !== 0 ? 'Aktif' : 'Non-Aktif' }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/karyawan', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->KID }})"><i class="fas fa-pen"></i></button>
                                @endif
                                @if (can_do('master/karyawan', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->KID }})"
                                            data-confirm="Nonaktifkan karyawan {{ $r->KNAMA }}?"><i class="fas fa-user-slash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada karyawan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Karyawan' : 'Karyawan Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="kode">
                            @error('kode') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="nama">
                            @error('nama') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="kry-aktif" wire:model="aktif">
                                <label class="form-check-label" for="kry-aktif">Karyawan Aktif</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kategori</label>
                            <select class="form-select form-select-sm" wire:model="kategori">
                                @foreach ($kategoris as $k)
                                    <option value="{{ $k->KTID }}">{{ $k->KTNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Jenis Karyawan</label>
                            <select class="form-select form-select-sm" wire:model="jeniskaryawan">
                                @foreach ($jenisList as $j)
                                    <option value="{{ $j->KJID }}">{{ $j->KJNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cabang</label>
                            <select class="form-select form-select-sm" wire:model="cabang">
                                <option value="0">—</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div x-data="{ tab: 'pos' }" x-cloak>
                    <ul class="nav nav-tabs mt-3" role="tablist">
                        <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'pos' }" @click="tab = 'pos'" type="button">Seting POS</button></li>
                        <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'alamat' }" @click="tab = 'alamat'" type="button">Alamat &amp; Identitas</button></li>
                        <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'payroll' }" @click="tab = 'payroll'" type="button">Payroll</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 p-3">
                        <div x-show="tab === 'pos'" id="kt-pos">
                            <div class="row">
                                @php
                                    $flagLabels = [
                                        'doktersmy'=>'Dokter SMY','salesmarketing'=>'Sales Marketing','aos'=>'AOS','dokterbedah'=>'Dokter Bedah','reseller'=>'Reseller',
                                        'dokterpj'=>'Dokter PJ','dokterinsider'=>'Dokter Insider','kolomdokter'=>'Tampil Kolom Dokter','kolomperawat'=>'Tampil Kolom Perawat','kolomresep'=>'Tampil Kolom Resep',
                                    ];
                                @endphp
                                @foreach ($posFlags as $f)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="kf-{{ $f }}" wire:model="flags.{{ $f }}">
                                            <label class="form-check-label small" for="kf-{{ $f }}">{{ $flagLabels[$f] ?? $f }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div x-show="tab === 'alamat'" id="kt-alamat">
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
                                <div class="col-md-4">
                                    <label class="form-label">No HP</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="nohp">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Email</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="email">
                                    @error('email') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select class="form-select form-select-sm" wire:model="kelamin">
                                        <option value="0">Perempuan</option>
                                        <option value="1">Laki-laki</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tgl Lahir</label>
                                    <input type="date" class="form-control form-control-sm" wire:model="tgllahir">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">No KTP</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="noktp">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tgl Join</label>
                                    <input type="date" class="form-control form-control-sm" wire:model="tgljoin">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">User Login</label>
                                    <x-search-select model="user" :value="$user" :selected-text="$labels['user'] ?? null"
                                                     :endpoint="route('lookup.user')" placeholder="cari user…" />
                                </div>
                            </div>
                        </div>

                        <div x-show="tab === 'payroll'" id="kt-payroll">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">NIK</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="nik">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Nama Panjang</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="namapanjang">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Kode Insider</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="kodeinsider">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Kelompok FU</label>
                                    <x-search-select model="kelompokfu" :value="$kelompokfu" :selected-text="$labels['kelompokfu'] ?? null"
                                                     :endpoint="route('lookup.lain', 'kelompok-fu')" placeholder="cari kelompok FU…" />
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>{{-- /x-data tab --}}
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
