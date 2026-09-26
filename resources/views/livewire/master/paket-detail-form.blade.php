<div>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold">{{ $packageKode }}</span>
                @if ($packageNama && $packageNama !== $packageKode)
                    <span class="text-muted"> — {{ $packageNama }}</span>
                @endif
            </div>
            @if (can_do('master/paket', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baris Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Item</th>
                            <th class="text-end">Qty 1</th>
                            <th class="text-end">Qty Lanjutan</th>
                            <th class="text-end">Diskon</th>
                            <th class="text-end">Subtotal</th>
                            <th>Pilihan</th>
                            <th class="text-center">Flag</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $l)
                            <tr wire:key="pd-{{ $l->PDID }}">
                                <td class="text-muted">{{ $l->PDURUTAN }}</td>
                                <td class="small">{{ $itemNames[$l->PDITEM] ?? ('#' . $l->PDITEM) }}</td>
                                <td class="text-end small">{{ $l->PDQTY ?: 0 }}</td>
                                <td class="text-end small">{{ $l->PDQTYTINDAKAN ?: 0 }}</td>
                                <td class="text-end small">{{ $l->PDDISKONPERSEN1 ?: 0 }}% / {{ $l->PDDISKONPERSEN2 ?: 0 }}%</td>
                                <td class="text-end small">{{ number_format((float) $l->PDSUBTOTAL, 0, ',', '.') }}</td>
                                <td class="small text-muted">
                                    @php $pil = trim((string) $l->PDPILIHAN); @endphp
                                    {{ $pil !== '' ? count(array_filter(explode('|', $pil))) . ' item' : '—' }}
                                </td>
                                <td class="text-center">
                                    @if ((int) $l->PDHARGA0 === 1) <span class="badge text-bg-info" title="Harga 0 / bonus">0</span> @endif
                                    @if ((int) $l->PDCETAK !== 1) <span class="badge text-bg-secondary" title="Tidak dicetak">no-print</span> @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if (can_do('master/paket', 'edit'))
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $l->PDID }})"><i class="fas fa-pen"></i></button>
                                    @endif
                                    @if (can_do('master/paket', 'delete'))
                                        <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $l->PDID }})"
                                                data-confirm="Hapus baris item paket ini?"><i class="fas fa-trash"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">Belum ada item paket.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Baris Item Paket' : 'Baris Item Paket Baru'" size="lg">
            <form wire:submit="save">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small mb-1">Item <span class="text-danger">*</span></label>
                            <x-search-select model="item" :value="$item" :selected-text="$itemLabel"
                                              :endpoint="route('lookup.item-id')" placeholder="cari item…" />
                            @error('item') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check me-3">
                                <input type="checkbox" class="form-check-input" id="pd-cetak" wire:model="cetak">
                                <label class="form-check-label small" for="pd-cetak">Cetak di struk</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pd-harga0" wire:model.live="harga0">
                                <label class="form-check-label small" for="pd-harga0">Harga 0 (bonus)</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Qty Kedatangan 1</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model.live="qty">
                            @error('qty') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Qty Kedatangan Lanjutan</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model="qtyTindakan">
                            @error('qtyTindakan') <div class="text-danger small">{{ $message }}</div> @enderror
                            <div class="form-text">Dipakai kalau paket punya &gt; 1 kedatangan/sesi (Jumlah Kedatangan di header).</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Harga</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm"
                                   wire:model.live="harga" @disabled($harga0)>
                            @error('harga') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Jumlah Paket</label>
                            <input type="number" min="0" step="1" class="form-control form-control-sm" wire:model="jumlahPaket">
                            @error('jumlahPaket') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small mb-1">Diskon 1 (%)</label>
                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm"
                                   wire:model.live.debounce.400ms="diskonPersen1" @disabled($harga0)>
                            @error('diskonPersen1') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Diskon 2 (%)</label>
                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm"
                                   wire:model.live.debounce.400ms="diskonPersen2" @disabled($harga0)>
                            @error('diskonPersen2') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="text-muted small">
                                Subtotal (preview): <span class="fw-semibold">{{ number_format($previewAmounts['subtotal'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-2" wire:key="pilihan-box">
                        <label class="form-label small mb-1">Pilihan (item alternatif yg bisa dipilih kasir sbg pengganti)</label>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @forelse ($pilihan as $kode)
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
                               wire:model.live.debounce.300ms="pilihanQ">
                        @if (mb_strlen(trim($pilihanQ)) >= 2)
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
