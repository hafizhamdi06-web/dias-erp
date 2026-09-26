<div>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold">{{ $promoKode }}</span>
                @if ($promoNama && $promoNama !== $promoKode)
                    <span class="text-muted"> — {{ $promoNama }}</span>
                @endif
                <span class="badge text-bg-secondary ms-2">Biasa</span>
            </div>
            @if (can_do('master/promo', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baris Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Jenis Item</th>
                            <th class="text-end">Min. Belanja</th>
                            <th class="text-end">Diskon</th>
                            <th>Berlaku</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $l)
                            <tr wire:key="pd-{{ $l->MPDID }}">
                                <td class="text-muted">{{ $l->MPDURUTAN }}</td>
                                <td class="small">{{ $l->MPDKELITEM1 ?: '—' }}</td>
                                <td class="text-end small">{{ $l->MPDTOTALINVOICE1 ? number_format($l->MPDTOTALINVOICE1, 0, ',', '.') : '—' }}</td>
                                <td class="text-end small">{{ $l->MPDDISKON ?: 0 }}% / {{ $l->MPDDISKON2 ?: 0 }}%</td>
                                <td class="small text-muted">
                                    @if ($l->MPDTANGGAL || $l->MPDTANGGAL2)
                                        {{ $l->MPDTANGGAL ? \Illuminate\Support\Carbon::parse($l->MPDTANGGAL)->format('d/m/Y') : '—' }}
                                        &ndash; {{ $l->MPDTANGGAL2 ? \Illuminate\Support\Carbon::parse($l->MPDTANGGAL2)->format('d/m/Y') : '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if (can_do('master/promo', 'edit'))
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $l->MPDID }})"><i class="fas fa-pen"></i></button>
                                    @endif
                                    @if (can_do('master/promo', 'delete'))
                                        <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $l->MPDID }})"
                                                data-confirm="Hapus baris promo ini?"><i class="fas fa-trash"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada baris promo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Baris Promo' : 'Baris Promo Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <h6 class="text-muted small text-uppercase mb-2">Jenis Item &amp; Syarat</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small mb-1">Jenis Item</label>
                            <x-search-select model="kelitem1" :value="$kelitem1" :selected-text="$kelitem1Label" :live="true"
                                              :endpoint="route('lookup.item-kode')" placeholder="cari item…" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Minimal Belanja</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model="totalinvoice1">
                            @error('totalinvoice1') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Min Qty</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model="minimalqty">
                            @error('minimalqty') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pb-invoice2" wire:model="totalinvoice2">
                                <label class="form-check-label small" for="pb-invoice2">Seluruh Invoice</label>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-2" wire:key="pilihan-box">
                                <label class="form-label small mb-1">Pilihan (item alternatif yg jg dianggap cocok utk Jenis Item)</label>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    @forelse ($pilihan1 as $kode)
                                        <span class="badge text-bg-secondary">
                                            {{ $kode }}
                                            <a href="#" class="text-white ms-1" wire:click.prevent="removePilihan({{ Js::from($kode) }})">
                                                <i class="fas fa-xmark"></i>
                                            </a>
                                        </span>
                                    @empty
                                        <span class="text-muted small">Belum ada item pilihan.</span>
                                    @endforelse
                                </div>
                                <input type="text" class="form-control form-control-sm" placeholder="ketik utk cari &amp; tambah…"
                                       wire:model.live.debounce.300ms="pilihanQ1">
                                @if (mb_strlen(trim($pilihanQ1)) >= 2)
                                    <div class="border rounded mt-1" style="max-height: 160px; overflow-y:auto">
                                        @forelse ($pilihanResults as $r)
                                            <button type="button" class="dropdown-item small text-wrap d-block w-100 text-start"
                                                    wire:click="addPilihan({{ Js::from($r->kode) }})">
                                                <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                            </button>
                                        @empty
                                            <div class="px-2 py-1 small text-muted">Tidak ada hasil.</div>
                                        @endforelse
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <h6 class="text-muted small text-uppercase mb-2">Diskon &amp; Markup</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Diskon (%)</label>
                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" wire:model.live.debounce.400ms="diskon">
                            @error('diskon') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Diskon Persen 2 (%)</label>
                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" wire:model.live.debounce.400ms="diskon2">
                            @error('diskon2') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Markup</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model="markup">
                            @error('markup') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Harga Jual saat ini</label>
                            <div class="form-control form-control-sm bg-body-secondary text-end">
                                {{ $harga !== null ? number_format($harga, 0, ',', '.') : '—' }}
                            </div>
                        </div>
                        @if ($hasilDiskon !== null)
                            <div class="col-12">
                                <div class="text-muted small">
                                    Preview harga setelah diskon: <span class="fw-semibold">{{ number_format($hasilDiskon, 0, ',', '.') }}</span>
                                    (gambaran saja, tidak disimpan)
                                </div>
                            </div>
                        @endif
                    </div>

                    <h6 class="text-muted small text-uppercase mb-2">Periode Berlaku (opsional, lebih spesifik dari tanggal berlaku promo)</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Tanggal</label>
                            <input type="date" class="form-control form-control-sm" wire:model="tanggal">
                            @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Tanggal 2</label>
                            <input type="date" class="form-control form-control-sm" wire:model="tanggal2">
                            @error('tanggal2') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Jam 1</label>
                            <input type="time" class="form-control form-control-sm" wire:model="jam1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Jam 2</label>
                            <input type="time" class="form-control form-control-sm" wire:model="jam2">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pb-pakaitanggal" wire:model="pakaitanggal">
                                <label class="form-check-label small" for="pb-pakaitanggal">Pakai Tanggal</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-muted small text-uppercase mb-2">Batasan &amp; Item Bonus</h6>
                    <div class="row g-3 mb-2">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Max Pasien</label>
                            <input type="number" min="0" step="1" class="form-control form-control-sm" wire:model="maxpasien">
                            @error('maxpasien') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="row g-3">
                        @foreach ($itemBonusSlots as $n => $slot)
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Item Bonus {{ $n }}</label>
                                <x-search-select :model="'item' . $n" :value="$slot['value']"
                                                  :selected-text="$slot['label']"
                                                  :endpoint="route('lookup.item-id')" placeholder="cari item…" />
                            </div>
                        @endforeach
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
