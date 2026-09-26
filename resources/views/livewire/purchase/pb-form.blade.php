<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Penerimaan Barang (PB)</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge {{ $status === 9 ? 'text-bg-danger' : 'text-bg-success' }} ms-2">
                        {{ $status === 9 ? 'Batal' : 'Aktif' }}
                    </span>
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

            {{-- Susunan header MENGIKUTI VB6 `fFrmPenerimaanBarangPBDepo` (screenshot user
                 2026-09-26): KIRI = Kontak (nama vendor) & Gudang dgn label di samping,
                 KANAN = 2 baris field berlabel DI ATAS (Tanggal|No Transaksi, No PO|No.
                 Invoice), lalu Keterangan selebar form.
                 Catatan: di VB6 kotak TANGGAL ikut berlabel "No Transaksi" (jelas salah
                 label di form lama) - di sini dilabeli "Tanggal" sesuai isinya. --}}
            <style>
                .pb-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                .pb-field > label { flex: 0 0 100px; max-width: 100px; padding-top: .35rem; margin-bottom: 0; }
                .pb-field > .pb-input { flex: 1 1 auto; min-width: 0; }
                .pb-top label.form-label { margin-bottom: .15rem; font-size: .8rem; }
            </style>

            @php
                $noPoList = collect($lines)->pluck('noPo')->filter()->unique()->values();
            @endphp

            <div class="row g-3 mb-2">
                {{-- ===== KIRI: Kontak & Gudang ===== --}}
                <div class="col-xl-6">
                    <div class="pb-field">
                        <label class="form-label">Kontak</label>
                        <div class="pb-input">
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                   value="{{ $vendorLabel }}" placeholder="otomatis dari PO yang ditarik…">
                            @error('vendor') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="pb-field">
                        <label class="form-label">Gudang</label>
                        <div class="pb-input">
                            {{-- Selalu cabang user login, tidak bisa diubah. --}}
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                   value="{{ $gudangLabel ?: '—' }}">
                            @error('gudang') <div class="text-danger small">{{ $message }}</div> @enderror
                            @unless ($locked)
                                <div class="form-text">Otomatis cabang Anda, tidak bisa diubah.</div>
                            @endunless
                        </div>
                    </div>
                </div>

                {{-- ===== KANAN: 2 baris, label di atas ===== --}}
                <div class="col-xl-6 pb-top">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Tanggal</label>
                            <input type="date" class="form-control form-control-sm @error('tanggal') is-invalid @enderror"
                                   wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">No Transaksi</label>
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                   value="{{ $nomor ?: '[otomatis]' }}">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">No PO</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control bg-body-secondary" disabled
                                       value="{{ $noPoList->implode(', ') }}"
                                       title="{{ $noPoList->implode(', ') }}">
                                @unless ($locked)
                                    <button type="button" class="btn btn-primary" wire:click="openPicker"
                                            title="Tarik dari PO">
                                        <i class="fas fa-magnifying-glass"></i>
                                    </button>
                                @endunless
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">No. Invoice</label>
                            <input type="text" class="form-control form-control-sm" wire:model="noReff"
                                   {{ $locked ? 'disabled' : '' }}>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="pb-field">
                        <label class="form-label">Keterangan</label>
                        <div class="pb-input">
                            <input type="text" class="form-control form-control-sm" wire:model="catatan"
                                   {{ $locked ? 'disabled' : '' }}>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Item</strong>
                @unless ($locked)
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openPicker">
                        <i class="fas fa-truck-ramp-box me-1"></i> Tarik dari PO
                    </button>
                @endunless
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No PO</th><th>Kode</th><th>Nama Item</th>
                            <th class="text-end">Sisa PO</th><th>Satuan PO</th>
                            <th class="text-end" style="width:120px">Qty Diterima</th><th>Satuan</th>
                            <th style="width:150px">No Batch</th>
                            {{-- Kolom "Harga" SENGAJA DISEMBUNYIKAN dari tampilan (permintaan
                                 user 2026-09-26) tapi nilainya TETAP DITARIK dari PO dan TETAP
                                 DISIMPAN ke `fstokd.SDHARGA` - akan jadi sumber perhitungan HPP.
                                 Jangan hapus `harga` dari state/`save()` hanya karena kolomnya
                                 tidak kelihatan di sini. --}}
                            <th>Catatan</th>
                            @unless ($locked) <th></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            <tr wire:key="pb-line-{{ $i }}">
                                <td class="text-muted small">{{ $l['noPo'] }}</td>
                                <td>{{ $l['kode'] }}</td>
                                <td>{{ $l['nama'] }}</td>
                                <td class="text-end text-muted small">{{ $l['sisaPoUnit'] !== null ? number_format($l['sisaPoUnit'], 2) : '—' }}</td>
                                <td class="text-muted small">{{ $l['satuanPoKode'] }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model="lines.{{ $i }}.qty" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td class="text-muted small">{{ $l['satuanDasarKode'] }}</td>
                                <td>
                                    @if (! empty($l['serial']))
                                        @php $bs = $l['batches'] ?? []; @endphp
                                        <button type="button"
                                                class="btn btn-sm w-100 text-start {{ $bs === [] ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                wire:click="openBatch({{ $i }})">
                                            @if ($bs === [])
                                                <i class="fas fa-barcode me-1"></i> Isi Batch
                                            @else
                                                <i class="fas fa-barcode me-1"></i>
                                                {{ count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch' }}
                                            @endif
                                        </button>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                {{-- kolom Harga disembunyikan, nilainya tetap ada di state & tersimpan --}}
                                <td>
                                    <input type="text" class="form-control form-control-sm" wire:model="lines.{{ $i }}.catatan" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">Belum ada item. Klik "Tarik dari PO" untuk mulai.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($lines !== [])
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="5" class="text-end fw-semibold">Total Qty</td>
                                <td class="text-end fw-semibold">{{ number_format($totalQty, 2) }}</td>
                                <td colspan="{{ $locked ? 3 : 4 }}"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
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
        <x-lw-modal :show="$showPicker" title="Tarik dari PO (Purchase Order yang belum lunas diterima)" close="closePicker">
            <div class="modal-body">
                <div class="row g-2 mb-2">
                    <div class="col-md-7">
                        <input type="text" class="form-control form-control-sm" placeholder="cari no PO / supplier…"
                               wire:model.live.debounce.300ms="pickerQ">
                    </div>
                    <div class="col-md-5">
                        {{-- PO dari SEMUA cabang boleh ditarik; filter ini cuma mempersempit. --}}
                        <select class="form-select form-select-sm" wire:model.live="pickerCabang">
                            <option value="">Semua cabang</option>
                            @foreach ($cabangPo as $c)
                                <option value="{{ $c->id }}">{{ $c->nama ?: 'Cabang #' . $c->id }} ({{ $c->jml }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $po)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pullFromPo({{ $po->id }})">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $po->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($po->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small">{{ $po->vendor ?: '—' }}</span>
                                <span class="badge text-bg-secondary">{{ $po->cabang ?: '—' }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada PO yang bisa ditarik.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closePicker">Tutup</button>
            </div>
        </x-lw-modal>
    @endif

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $jumlahProduct = (float) $bl['qty'];
            $totalSerial = array_sum(array_map(fn ($r) => (float) $r['qty'], $batchRows));
            $selisih = round($jumlahProduct - $totalSerial, 4);
        @endphp
        <x-lw-modal :show="$showBatch" title="No Batch — {{ $bl['kode'] }} {{ $bl['nama'] }}" close="closeBatch">
            <div class="modal-body">
                @if ($errors->has('batchRows'))
                    <div class="alert alert-danger py-2">{{ $errors->first('batchRows') }}</div>
                @endif

                @unless ($locked)
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-5">
                            <label class="form-label mb-1">No Batch / Serial</label>
                            <input type="text" class="form-control form-control-sm @error('bNo') is-invalid @enderror"
                                   wire:model="bNo" wire:keydown.enter.prevent="addBatchRow" placeholder="mis. G260022155Y">
                            @error('bNo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Tanggal Expired</label>
                            <input type="date" class="form-control form-control-sm" wire:model="bExp">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Jumlah</label>
                            <input type="number" step="0.01" min="0"
                                   class="form-control form-control-sm text-end @error('bQty') is-invalid @enderror"
                                   wire:model="bQty" wire:keydown.enter.prevent="addBatchRow">
                            @error('bQty') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-primary btn-sm w-100" wire:click="addBatchRow" title="Tambah">
                                <i class="fas fa-angle-right"></i>
                            </button>
                        </div>
                    </div>
                @endunless

                <table class="table table-sm table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            @unless ($locked) <th style="width:40px"></th> @endunless
                            <th>No Batch</th><th style="width:140px">Tanggal Expired</th>
                            <th class="text-end" style="width:110px">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batchRows as $bi => $r)
                            <tr wire:key="batch-row-{{ $bi }}">
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                wire:click="removeBatchRow({{ $bi }})" title="Hapus">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </td>
                                @endunless
                                <td>{{ $r['noBatch'] }}</td>
                                <td>{{ $r['expired'] ? \Carbon\Carbon::parse($r['expired'])->format('d/m/Y') : '—' }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $r['qty'], 2, ',', '.'), '0'), ',') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 3 : 4 }}" class="text-center text-muted py-3">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Product</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled
                               value="{{ rtrim(rtrim(number_format($jumlahProduct, 2, ',', '.'), '0'), ',') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Total Serial</label>
                        <input type="text" class="form-control form-control-sm text-end {{ abs($selisih) > 0.0001 ? 'border-danger text-danger fw-semibold' : 'border-success text-success fw-semibold' }}"
                               disabled value="{{ rtrim(rtrim(number_format($totalSerial, 2, ',', '.'), '0'), ',') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Selisih</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled
                               value="{{ rtrim(rtrim(number_format($selisih, 2, ',', '.'), '0'), ',') }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeBatch">Tutup</button>
                @unless ($locked)
                    <button type="button" class="btn btn-success" wire:click="applyBatch" @disabled(abs($selisih) > 0.0001)>
                        <i class="fas fa-check me-1"></i> Simpan Batch
                    </button>
                @endunless
            </div>
        </x-lw-modal>
    @endif
</div>
