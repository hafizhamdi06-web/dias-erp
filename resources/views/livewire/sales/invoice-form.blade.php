<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Invoice Penjualan (IV)</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge text-bg-success ms-2">Tersimpan</span>
                @endif
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                <i class="fas fa-xmark me-1"></i> Tutup
            </button>
        </div>
        <div class="card-body">
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            {{-- Susunan atas mengikuti layout form VB6 asli: kiri = data pelanggan,
                 kanan = pajak/tanggal/nomor lalu gudang/sales/termin. --}}
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label">Pelanggan</label>
                    <input type="text" class="form-control" value="{{ $kontakLabel }}" disabled placeholder="tarik dari SJ…">
                    @error('kontak') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Pajak</label>
                    <select class="form-select" wire:model.live="jenisPajak" {{ $locked ? 'disabled' : '' }}>
                        <option value="0">Tanpa Pajak</option>
                        <option value="1">PPN 11%</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control @error('tanggal') is-invalid @enderror" wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                    @error('tanggal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">No Transaksi</label>
                    <input type="text" class="form-control" value="{{ $nomor ?: '(otomatis saat simpan)' }}" disabled>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Kontak Person</label>
                    <input type="text" class="form-control" wire:model="attention" {{ $locked ? 'disabled' : '' }}>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gudang</label>
                    <select class="form-select @error('cabang') is-invalid @enderror" wire:model="cabang" {{ $locked ? 'disabled' : '' }}>
                        <option value="">— pilih —</option>
                        @foreach (\App\Models\Branch::options() as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                    @error('cabang') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sales</label>
                    <x-search-select model="karyawan" :endpoint="route('lookup.karyawan')"
                                      :value="$karyawan" :selectedText="$karyawanLabel" placeholder="opsional…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label">Termin</label>
                    <x-search-select model="termin" :endpoint="route('lookup.termin')"
                                      :value="$termin" :selectedText="$terminLabel" placeholder="opsional…" />
                </div>

                <div class="col-md-8">
                    <label class="form-label">Alamat</label>
                    <textarea class="form-control" rows="2" wire:model="alamat" {{ $locked ? 'disabled' : '' }}></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jatuh Tempo</label>
                    <input type="date" class="form-control" wire:model="tglJatuhTempo" {{ $locked ? 'disabled' : '' }}>
                </div>

                <div class="col-12">
                    <label class="form-label">Uraian</label>
                    <input type="text" class="form-control" wire:model="uraian" {{ $locked ? 'disabled' : '' }}>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Item</strong>
                @unless ($locked)
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openPicker">
                        <i class="fas fa-truck-fast me-1"></i> Tarik dari SJ
                    </button>
                @endunless
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kode</th><th>Nama</th>
                            <th class="text-end" style="width:100px">Qty</th><th>Satuan</th>
                            <th class="text-end" style="width:120px">Harga</th>
                            <th class="text-end" style="width:100px">Diskon</th>
                            <th class="text-end" style="width:90px">Disc %</th>
                            <th class="text-end">Sub Total</th>
                            <th>No SJ</th>
                            @unless ($locked) <th></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            @php $lineSubtotal = ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty']; @endphp
                            <tr wire:key="iv-line-{{ $i }}">
                                <td>{{ $l['kode'] }}</td>
                                <td>{{ $l['nama'] }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model.live="lines.{{ $i }}.qty" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td class="text-muted small">{{ $l['satuanKode'] }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model.live="lines.{{ $i }}.harga" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model.live="lines.{{ $i }}.disc" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td class="text-end text-muted small">{{ number_format($l['discPersen'], 2) }}</td>
                                <td class="text-end">{{ number_format($lineSubtotal, 0, ',', '.') }}</td>
                                <td class="text-muted small">{{ $l['noSj'] ?: '—' }}</td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">Belum ada item. Klik "Tarik dari SJ" untuk mulai.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Baris "SJ Terpilih" + Catatan (kiri) & rekap total (kanan) - pola sama posisi VB6. --}}
            <div class="row g-3 mt-1">
                <div class="col-md-7">
                    <label class="form-label">SJ Terpilih</label>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        @forelse (collect($lines)->pluck('noSj')->filter()->unique() as $noSj)
                            <span class="badge text-bg-secondary">{{ $noSj }}</span>
                        @empty
                            <span class="text-muted small">— belum ada —</span>
                        @endforelse
                    </div>
                    <label class="form-label">Catatan</label>
                    <input type="text" class="form-control" wire:model="catatan" {{ $locked ? 'disabled' : '' }}>
                </div>
                <div class="col-md-5">
                    <div class="border rounded p-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Total Qty</span>
                            <span>{{ number_format($totalQty, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Sub Total</span>
                            <span>{{ number_format($subtotal, 0, ',', '.') }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Pajak</span>
                            <span>{{ number_format($pajak, 0, ',', '.') }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total</span>
                            <span>{{ number_format($total, 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            @unless ($locked)
                <div class="mt-3 text-end">
                    <button type="button" class="btn btn-success" wire:click="save">
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                </div>
            @endunless
        </div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Tarik dari Surat Jalan (yang belum ditagih)" close="closePicker">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no SJ / pelanggan…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $sj)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pullFromSj({{ $sj->id }})">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $sj->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($sj->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $sj->kontak ?: '—' }}</div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada SJ yang bisa ditagih.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closePicker">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
