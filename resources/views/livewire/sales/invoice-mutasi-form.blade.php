<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Invoice Penjualan Mutasi (IVM)</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge text-bg-success ms-2">Tersimpan</span>
                @endif
            </div>
            <div>
                @if ($invoiceId && can_do('sales/invoice-mutasi', 'print'))
                    <a href="{{ route('sales.invoice-mutasi.print', $invoiceId) }}" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-print me-1"></i> Cetak
                    </a>
                @endif
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                    <i class="fas fa-xmark me-1"></i> Tutup
                </button>
            </div>
        </div>
        <div class="card-body">
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Gudang Asal (pengirim)</label>
                    <input type="text" class="form-control" value="{{ $gudangAsalLabel }}" disabled placeholder="tarik dari TMB…">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gudang Tujuan (ditagih)</label>
                    <input type="text" class="form-control" value="{{ $gudangTujuanLabel }}" disabled placeholder="tarik dari TMB…">
                </div>
                <div class="col-md-2">
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

                <div class="col-md-3">
                    <label class="form-label">No Transaksi</label>
                    <input type="text" class="form-control" value="{{ $nomor ?: '(otomatis saat simpan)' }}" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kontak Person</label>
                    <input type="text" class="form-control" wire:model="attention" {{ $locked ? 'disabled' : '' }}>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sales</label>
                    <x-search-select model="karyawan" :endpoint="route('lookup.karyawan')"
                                      :value="$karyawan" :selectedText="$karyawanLabel" placeholder="opsional…" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">Termin</label>
                    <x-search-select model="termin" :endpoint="route('lookup.termin')"
                                      :value="$termin" :selectedText="$terminLabel" placeholder="opsional…" />
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pelanggan (opsional)</label>
                    <x-search-select model="kontak" :endpoint="route('lookup.kontak')"
                                      :value="$kontak" :selectedText="$kontakLabel" placeholder="opsional…" />
                </div>
                <div class="col-md-4">
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
                        <i class="fas fa-truck-arrow-right me-1"></i> Tarik dari TMB
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
                            <th class="text-end">Sub Total</th>
                            <th>No TMB</th>
                            @unless ($locked) <th></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            @php $lineSubtotal = ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty']; @endphp
                            <tr wire:key="ivm-line-{{ $i }}">
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
                                <td class="text-end">{{ number_format($lineSubtotal, 0, ',', '.') }}</td>
                                <td class="text-muted small">{{ $l['noTmb'] ?: '—' }}</td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">Belum ada item. Klik "Tarik dari TMB" untuk mulai.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-7">
                    <label class="form-label">TMB Terpilih</label>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        @forelse (collect($lines)->pluck('noTmb')->filter()->unique() as $noTmb)
                            <span class="badge text-bg-secondary">{{ $noTmb }}</span>
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
        <x-lw-modal :show="$showPicker" title="Tarik dari TMB (Terima Mutasi Barang yang belum ditagih)" close="closePicker">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no TMB / gudang…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $tmb)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pullFromTmb({{ $tmb->id }})">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $tmb->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($tmb->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $tmb->asal ?: '—' }} → {{ $tmb->tujuan ?: '—' }}</div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada TMB yang bisa ditagih.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closePicker">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
