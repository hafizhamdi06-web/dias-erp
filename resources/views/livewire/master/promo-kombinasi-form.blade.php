<div>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold">{{ $promoKode }}</span>
                @if ($promoNama && $promoNama !== $promoKode)
                    <span class="text-muted"> — {{ $promoNama }}</span>
                @endif
                <span class="badge text-bg-info ms-2">Kombinasi 1</span>
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
                            <th>Item 1</th>
                            <th>Item 2</th>
                            <th>Item 3</th>
                            <th>Item 4</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $itemDiskonCell = function ($nama, $d1, $d2) {
                                if (! $nama) {
                                    return '<span class="text-muted">—</span>';
                                }
                                $disc = ($d1 ?: 0) . '% / ' . ($d2 ?: 0) . '%';

                                return e($nama) . '<br><span class="text-muted small">Disc: ' . e($disc) . '</span>';
                            };
                        @endphp
                        @forelse ($lines as $l)
                            <tr wire:key="pd-{{ $l->MPDID }}">
                                <td class="text-muted">{{ $l->MPDURUTAN }}</td>
                                <td class="small">{!! $itemDiskonCell($l->MPDKELITEM1, $l->MPDDISKON, $l->MPDDISKON2) !!}</td>
                                <td class="small">{!! $itemDiskonCell($l->MPDKELITEM2, $l->MPDDISKONITEM2, $l->MPDDISKONITEM22) !!}</td>
                                <td class="small">{!! $itemDiskonCell($l->MPDKELITEM3, $l->MPDDISKON1KE3, $l->MPDDISKON2KE3) !!}</td>
                                <td class="small">{!! $itemDiskonCell($l->MPDKELITEM4, $l->MPDDISKON1KE4, $l->MPDDISKON2KE4) !!}</td>
                                <td class="text-end text-nowrap">
                                    @if (can_do('master/promo', 'edit'))
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $l->MPDID }})"><i class="fas fa-pen"></i></button>
                                    @endif
                                    @if (can_do('master/promo', 'delete'))
                                        <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $l->MPDID }})"
                                                data-confirm="Hapus baris kombinasi ini?"><i class="fas fa-trash"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada baris kombinasi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Ubah Baris Kombinasi' : 'Baris Kombinasi Baru'" size="xl">
            <form wire:submit="save">
                <div class="modal-body">
                    <h6 class="text-muted small text-uppercase mb-2">Item, Minimal Qty &amp; Diskon</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:70px"></th>
                                    <th>Item</th>
                                    <th style="width:130px" class="text-end">Harga Jual</th>
                                    <th style="width:100px">Minimal Qty</th>
                                    <th style="width:100px">Diskon 1 (%)</th>
                                    <th style="width:100px">Diskon 2 (%)</th>
                                    <th style="width:140px" class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($itemSlots as $n => $slot)
                                    <tr wire:key="item-slot-{{ $n }}">
                                        <td class="fw-semibold small">Item {{ $n }}</td>
                                        <td>
                                            <x-search-select :model="'item' . $n" :value="$slot['value']"
                                                              :selected-text="$slot['label']" :live="true"
                                                              :endpoint="route('lookup.item-kode')" placeholder="cari item…" />
                                        </td>
                                        <td class="text-end small text-nowrap">
                                            {{ $slot['harga'] !== null ? number_format($slot['harga'], 0, ',', '.') : '—' }}
                                        </td>
                                        <td>
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" wire:model.live.debounce.400ms="minimal{{ $n }}">
                                            @error('minimal' . $n) <div class="text-danger small">{{ $message }}</div> @enderror
                                        </td>
                                        <td>
                                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" wire:model.live.debounce.400ms="diskon{{ $n }}_1">
                                            @error('diskon' . $n . '_1') <div class="text-danger small">{{ $message }}</div> @enderror
                                        </td>
                                        <td>
                                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" wire:model.live.debounce.400ms="diskon{{ $n }}_2">
                                            @error('diskon' . $n . '_2') <div class="text-danger small">{{ $message }}</div> @enderror
                                        </td>
                                        <td class="text-end small text-nowrap fw-semibold">
                                            {{ $slot['subtotal'] !== null ? number_format($slot['subtotal'], 0, ',', '.') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <th colspan="6" class="text-end">Total (gambaran nilai promo ini)</th>
                                    <th class="text-end text-nowrap">{{ number_format($totalPromo, 0, ',', '.') }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <h6 class="text-muted small text-uppercase mb-2">Item Pilihan (alternatif yang bisa dipilih kasir per slot)</h6>
                    <div class="row g-3">
                        @foreach ($pilihanSlots as $n => $slot)
                            <div class="col-md-6">
                                <div class="border rounded p-2" wire:key="pilihan-box-{{ $n }}">
                                    <label class="form-label small mb-1">Item {{ $n }} Pilihan</label>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        @forelse ($slot['values'] as $kode)
                                            <span class="badge text-bg-secondary">
                                                {{ $kode }}
                                                <a href="#" class="text-white ms-1" wire:click.prevent="removePilihan({{ $n }}, {{ Js::from($kode) }})">
                                                    <i class="fas fa-xmark"></i>
                                                </a>
                                            </span>
                                        @empty
                                            <span class="text-muted small">Belum ada item pilihan.</span>
                                        @endforelse
                                    </div>
                                    <input type="text" class="form-control form-control-sm" placeholder="ketik utk cari &amp; tambah…"
                                           wire:model.live.debounce.300ms="pilihanQ{{ $n }}">
                                    @if (mb_strlen(trim($slot['q'])) >= 2)
                                        <div class="border rounded mt-1" style="max-height: 160px; overflow-y:auto">
                                            @forelse ($slot['results'] as $r)
                                                <button type="button" class="dropdown-item small text-wrap d-block w-100 text-start"
                                                        wire:click="addPilihan({{ $n }}, {{ Js::from($r->kode) }})">
                                                    <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                                </button>
                                            @empty
                                                <div class="px-2 py-1 small text-muted">Tidak ada hasil.</div>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
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
