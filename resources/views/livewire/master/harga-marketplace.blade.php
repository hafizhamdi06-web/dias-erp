<div>
    @php($rp = fn ($n) => number_format((float) $n, 0, ',', '.'))
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label mb-1 small text-muted">Cari</label>
                <div class="input-group input-group-sm" style="width: 280px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="kode / nama / barcode…"
                           wire:model.live.debounce.400ms="search">
                </div>
            </div>
            <div>
                <label class="form-label mb-1 small text-muted">Status Harga</label>
                <select class="form-select form-select-sm" style="width: 230px" wire:model.live="fStatus">
                    <option value="">Semua</option>
                    <option value="kosong">Belum ada harga marketplace</option>
                    <option value="beda">Beda dengan harga jual 1</option>
                    <option value="sama">Sama dengan harga jual 1</option>
                </select>
            </div>
            <div class="form-check pb-1">
                <input type="checkbox" class="form-check-input" id="hmp-cabang" wire:model.live="hanyaMarketplace">
                <label class="form-check-label small" for="hmp-cabang">Hanya item yang dijual di cabang Marketplace</label>
            </div>
            <div class="ms-auto small text-muted pb-1">{{ number_format($rows->total(), 0, ',', '.') }} item</div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th style="width: 180px">Kode</th>
                        <th>Nama</th>
                        <th class="text-end" style="width: 140px">Harga Jual 1</th>
                        <th class="text-end" style="width: 190px">Harga Marketplace</th>
                        <th class="text-end" style="width: 90px">Selisih</th>
                        <th class="text-end" style="width: 150px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php($selisih = $r->hargaMp > 0 && $r->hargaJual1 > 0 ? ($r->hargaMp - $r->hargaJual1) / $r->hargaJual1 * 100 : null)
                            <tr wire:key="hmp-{{ $r->id }}" @class(['table-warning' => $editId === (int) $r->id])>
                                <td class="font-monospace small">{{ $r->kode }}</td>
                                <td>{{ $r->nama }}</td>
                                <td class="text-end">{{ $rp($r->hargaJual1) }}</td>
                                <td class="text-end">
                                    @if ($editId === (int) $r->id)
                                        <x-uang-input model="editHarga" :value="$editHarga" />
                                        @error('editHarga') <div class="text-danger small">{{ $message }}</div> @enderror
                                    @elseif ((float) $r->hargaMp > 0)
                                        <span class="fw-semibold">{{ $rp($r->hargaMp) }}</span>
                                    @else
                                        <span class="badge text-bg-secondary">belum ada</span>
                                    @endif
                                </td>
                                <td class="text-end small {{ $selisih === null ? 'text-muted' : ($selisih > 0 ? 'text-success' : ($selisih < 0 ? 'text-danger' : 'text-muted')) }}">
                                    {{ $selisih === null ? '—' : ($selisih > 0 ? '+' : '') . number_format($selisih, 1, ',', '.') . '%' }}
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($editId === (int) $r->id)
                                        {{-- Simpan menunggu isian selesai dikirim (`uang-input` commit saat blur). --}}
                                        <button class="btn btn-primary btn-sm" wire:click="simpan" wire:loading.attr="disabled">
                                            <i class="fas fa-check me-1"></i>Simpan
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="batal">Batal</button>
                                    @elseif ($bisaUbah)
                                        <button class="btn btn-outline-primary btn-sm" wire:click="ubah({{ $r->id }})">
                                            <i class="fas fa-pen me-1"></i>Ubah
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada item.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
