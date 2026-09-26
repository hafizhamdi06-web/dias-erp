<div>
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- ============ LIST ============ --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 260px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="kode / nama"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 150px" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="0">Aktif</option>
                    <option value="1">Tidak Aktif</option>
                    <option value="2">Tidak Terpakai</option>
                </select>
                <select class="form-select form-select-sm" style="max-width: 160px" wire:model.live="fKelompok">
                    <option value="">Semua kelompok</option>
                    @foreach ($kelompok as $k)
                        <option value="{{ $k->IK2ID }}">{{ $k->IK2KODE }}</option>
                    @endforeach
                </select>
                <select class="form-select form-select-sm" style="max-width: 130px" wire:model.live="fTipe">
                    <option value="">Semua tipe</option>
                    <option value="0">Stok</option>
                    <option value="1">Non Stok</option>
                </select>
            </div>
            @if (can_do('master/item', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Item Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Kode</th><th>Nama</th><th>Satuan</th>
                    <th class="text-end">Harga Jual 1</th><th class="text-center">Tipe</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="itm-{{ $r->iid }}">
                            <td>{{ $r->ikode }}</td>
                            <td>{{ $r->inama }}</td>
                            <td class="text-muted small">{{ $r->satuan ?: '—' }}</td>
                            <td class="text-end">{{ number_format((float) $r->ihargajual1, 0, ',', '.') }}</td>
                            <td class="text-center"><span class="badge text-bg-light">{{ (int) $r->itipeitem === 1 ? 'Non Stok' : 'Stok' }}</span></td>
                            <td class="text-center">
                                <span class="badge {{ (int) $r->istatus === 0 ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ \App\Models\Item::statusLabel((int) $r->istatus) }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if (can_do('master/item', 'edit'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->iid }})"><i class="fas fa-pen"></i></button>
                                @endif
                                @if (can_do('master/item', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $r->iid }})"
                                            data-confirm="Hapus item {{ $r->inama }}?"><i class="fas fa-trash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada item.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    {{-- ============ MODAL FORM ============ --}}
    @if ($showModal)
        @php
            $chkLabels = [
                'tidakdihitungjumlahpasien'=>'Tidak Dihitung Jumlah Pasien','bisasharing'=>'Bisa Sharing',
                'cetak'=>'Cetak','promo'=>'Promo','bhp'=>'BHP','resep'=>'Resep',
            ];
            $hargaFields = [
                'hargajual1'=>'Harga Jual 1','hargakaryawan'=>'Harga Jual Karyawan','hargaweb'=>'Harga Web',
                'hargaweb2'=>'Harga Web 2','diskon'=>'Discount','cogs'=>'COGS','cogspo'=>'COGS PO',
            ];
        @endphp
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Item' : 'Item Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="f.kode">
                            @error('f.kode') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" wire:model="f.nama">
                            @error('f.nama') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Nama Web</label>
                            <input type="text" class="form-control form-control-sm" wire:model="f.namaweb">
                        </div>
                    </div>

                    <div x-data="{ tab: 'detail' }" x-cloak>
                    <ul class="nav nav-tabs mt-3" role="tablist">
                        @foreach (['detail'=>'Detail','harga'=>'Harga','kelompok'=>'Pengelompokan','info'=>'Info Lain','po'=>'PO','cabang'=>'Cabang'] as $key => $label)
                            <li class="nav-item">
                                <button class="nav-link" :class="{ active: tab === '{{ $key }}' }"
                                        @click="tab = '{{ $key }}'" type="button">{{ $label }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="tab-content border border-top-0 p-3">

                        {{-- Detail --}}
                        <div x-show="tab === 'detail'" id="itm-detail">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select form-select-sm" wire:model="f.status">
                                        <option value="0">Aktif</option><option value="1">Tidak Aktif</option><option value="2">Tidak Terpakai</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Satuan Dasar <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" wire:model="f.satuand">
                                        <option value="0">— pilih —</option>
                                        @foreach ($units as $u)<option value="{{ $u->SID }}">{{ $u->SKODE }}</option>@endforeach
                                    </select>
                                    @error('f.satuand') <div class="text-danger small">{{ $message }}</div> @enderror
                                    <div class="form-text">Satuan default otomatis = satuan dasar.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Qty Per Box</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="f.qtyperbox">
                                </div>
                                <div class="col-md-3 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="itm-serial" wire:model="f.serial">
                                        <label class="form-check-label" for="itm-serial">Serial</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Harga --}}
                        <div x-show="tab === 'harga'" id="itm-harga">
                            <div class="row g-2">
                                @foreach ($hargaFields as $fld => $label)
                                    <div class="col-md-3">
                                        <label class="form-label">{{ $label }}</label>
                                        <input type="text" class="form-control form-control-sm text-end" wire:model="f.{{ $fld }}">
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Pengelompokan --}}
                        <div x-show="tab === 'kelompok'" id="itm-kelompok">
                            <div class="row g-2">
                                <div class="col-md-4"><label class="form-label">Jenis Item</label>
                                    <select class="form-select form-select-sm" wire:model="f.jenisitem"><option value="">—</option>
                                        @foreach ($jenisItem as $x)<option value="{{ $x->JID }}">{{ $x->JKODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Tipe Persediaan</label>
                                    <select class="form-select form-select-sm" wire:model="f.tipepersediaan"><option value="0">Stok</option><option value="1">Non Stok</option></select></div>
                                <div class="col-md-4"><label class="form-label">Jenis Item COA</label>
                                    <select class="form-select form-select-sm" wire:model="f.jenisitemcoa"><option value="">—</option>
                                        @foreach ($coaPend as $x)<option value="{{ $x->CTID }}">{{ $x->CTNAMA }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Jenis COA Pendapatan</label>
                                    <select class="form-select form-select-sm" wire:model="f.jeniscoapendapatan"><option value="">—</option>
                                        @foreach ($coaPend as $x)<option value="{{ $x->CTID }}">{{ $x->CTNAMA }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Kelompok Baru</label>
                                    <select class="form-select form-select-sm" wire:model="f.kelompokbaru"><option value="">—</option>
                                        @foreach ($jenisItem as $x)<option value="{{ $x->JID }}">{{ $x->JKODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Kelompok 2020</label>
                                    <select class="form-select form-select-sm" wire:model="f.kelompok2020"><option value="">—</option>
                                        @foreach ($kelompok as $x)<option value="{{ $x->IK2ID }}">{{ $x->IK2KODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Kelompok 2021</label>
                                    <select class="form-select form-select-sm" wire:model="f.kelompok21"><option value="">—</option>
                                        @foreach ($kelompok21 as $x)<option value="{{ $x->IK21ID }}">{{ $x->IK21KODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Kelompok 2023</label>
                                    <select class="form-select form-select-sm" wire:model="f.kelompok23"><option value="">—</option>
                                        @foreach ($kelompok23 as $x)<option value="{{ $x->IK23ID }}">{{ $x->IK23KODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Jenis COA 2021</label>
                                    <select class="form-select form-select-sm" wire:model="f.coa2021"><option value="">—</option>
                                        @foreach ($coaPerpt as $x)<option value="{{ $x->CTID }}">{{ $x->CTNAMA }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Kelompok Komisi 2020</label>
                                    <select class="form-select form-select-sm" wire:model="f.komisi2020"><option value="">—</option>
                                        @foreach ($kelompok as $x)<option value="{{ $x->IK2ID }}">{{ $x->IK2KODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Jenis Web</label>
                                    <select class="form-select form-select-sm" wire:model="f.jenisweb"><option value="">—</option>
                                        @foreach ($jenisWeb as $x)<option value="{{ $x->IJID }}">{{ $x->IJKODE }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Model / Kelompok Harga</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="f.model" placeholder="mis. IPL, BOTOX"></div>
                            </div>
                        </div>

                        {{-- Info Lain --}}
                        <div x-show="tab === 'info'" id="itm-info">
                            <div class="row g-1">
                                @foreach ($chkFields as $c)
                                    @continue($c === 'serial')
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="itmc-{{ $c }}" wire:model="f.{{ $c }}">
                                            <label class="form-check-label small" for="itmc-{{ $c }}">{{ $chkLabels[$c] ?? $c }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <hr class="my-2">
                            <div class="row g-2">
                                <div class="col-md-3"><label class="form-label">Berat</label>
                                    <input type="text" class="form-control form-control-sm text-end" wire:model="f.berat"></div>
                            </div>
                        </div>

                        {{-- PO --}}
                        <div x-show="tab === 'po'" id="itm-po">
                            <div class="row g-2">
                                <div class="col-md-3"><label class="form-label">Harga PO</label><input type="text" class="form-control form-control-sm text-end" wire:model="f.hargapo"></div>
                                <div class="col-md-3"><label class="form-label">Qty Satuan PO</label><input type="text" class="form-control form-control-sm text-end" wire:model="f.qtypo"></div>
                                <div class="col-md-3"><label class="form-label">Kemasan</label><input type="text" class="form-control form-control-sm" wire:model="f.kemasan"></div>
                                <div class="col-md-3"><label class="form-label">Coding</label><input type="text" class="form-control form-control-sm" wire:model="f.coding"></div>
                                <div class="col-md-6"><label class="form-label">Nama PO</label><input type="text" class="form-control form-control-sm" wire:model="f.namapo"></div>
                            </div>
                        </div>

                        {{-- Cabang --}}
                        <div x-show="tab === 'cabang'" id="itm-cabang">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">{{ count($cabang) }} cabang dipilih</span>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" wire:click="selectAllBranches">Pilih semua</button>
                                    <button type="button" class="btn btn-outline-secondary" wire:click="clearBranches">Kosongkan</button>
                                </div>
                            </div>
                            <div class="border rounded p-2" style="max-height: 260px; overflow-y: auto">
                                <div class="row g-1">
                                    @foreach ($branches as $b)
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="itmg-{{ $b->GID }}"
                                                       value="{{ $b->GID }}" wire:model="cabang">
                                                <label class="form-check-label small" for="itmg-{{ $b->GID }}">{{ $b->GNAMA }}</label>
                                            </div>
                                        </div>
                                    @endforeach
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
