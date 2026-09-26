<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $sjId ? 'SJ ' . $nomor : 'SJ Baru dari PKB ' . $noPkb }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci</span> @endif
                </span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @unless ($locked)
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endunless
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                @php
                    $statusBadge = match ($status) {
                        0 => ['text-bg-warning', 'Pending'],
                        3 => ['text-bg-info', 'Diterima'],
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-success', 'Aktif'],
                    };
                @endphp

                <style>
                    .sj-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .sj-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .sj-field > .sj-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    {{-- ===== Kolom kiri: isi SJ ===== --}}
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="sj-field">
                                <label class="form-label">No PKB Asal</label>
                                <div class="sj-input">
                                    <span class="badge text-bg-primary fs-6">{{ $noPkb ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="sj-field">
                                <label class="form-label">Kontak <span class="text-danger">*</span></label>
                                <div class="sj-input">
                                    @if ($locked)
                                        <span class="badge text-bg-secondary fs-6"><i class="fas fa-user me-1"></i>{{ $kontakLabel ?: '—' }}</span>
                                    @else
                                        <x-search-select model="kontak" :value="$kontak" :selected-text="$kontakLabel"
                                                          :endpoint="route('lookup.kontak')" placeholder="cari kontak…" />
                                        @error('kontak') <div class="text-danger small">{{ $message }}</div> @enderror
                                    @endif
                                </div>
                            </div>
                            <div class="sj-field">
                                <label class="form-label">Cabang Kirim</label>
                                <div class="sj-input">
                                    <select class="form-select form-select-sm" wire:model="cabangKirim" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Otomatis cabang Anda, tidak bisa diubah.</div>
                                </div>
                            </div>
                            <div class="sj-field">
                                <label class="form-label">Cabang Tujuan</label>
                                <div class="sj-input">
                                    <span class="badge text-bg-info fs-6">{{ $cabangTujuanLabel ?: '—' }}</span>
                                    <div class="form-text">Otomatis dari PR sumber, tidak bisa diubah.</div>
                                </div>
                            </div>
                            <div class="sj-field">
                                <label class="form-label">Keterangan</label>
                                <div class="sj-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== Kolom kanan: info dokumen ===== --}}
                    <div class="col-md-6">
                        <div class="sj-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="sj-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="sj-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="sj-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="sj-field">
                            <label class="form-label">Status</label>
                            <div class="sj-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---- Baris item (ditarik dari PKB, tidak bisa tambah bebas) ---- --}}
                <hr class="my-3">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 30%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Diminta</th>
                                <th class="text-center" style="width: 110px">Qty Dikirim</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 150px">No Batch</th>
                                <th>Catatan</th>
                                @unless ($locked)
                                    <th class="text-end" style="width: 90px">Real Stok</th>
                                @endunless
                                <th style="width: 36px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $l['nama'] }}</div>
                                        <div class="text-muted small">{{ $l['kode'] }}</div>
                                    </td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format($l['sisa'], 2), '0'), '.') }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" max="{{ $l['sisa'] }}"
                                               class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        @if (! empty($l['serial']))
                                            @php $bs = $l['batches'] ?? []; @endphp
                                            <button type="button"
                                                    class="btn btn-sm w-100 text-start {{ $bs === [] ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                    wire:click="openBatch({{ $i }})">
                                                <i class="fas fa-barcode me-1"></i>
                                                {{ $bs === [] ? 'Pilih Batch' : (count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch') }}
                                            </button>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    @unless ($locked)
                                        <td class="text-end">{{ rtrim(rtrim(number_format($l['stok'], 2), '0'), '.') }}</td>
                                    @endunless
                                    <td class="text-center"></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada item tersisa dari PKB ini.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end">Total Qty Dikirim</td>
                                    <td></td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="{{ $locked ? 4 : 5 }}"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </form>

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $jumlahProduct = (float) $bl['qty'];
            $jumlahDipilih = array_sum(array_map(fn ($r) => $r['pilih'] ? (float) $r['qty'] : 0, $batchRows));
            $selisih = round($jumlahProduct - $jumlahDipilih, 4);
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        @endphp
        <x-lw-modal :show="$showBatch" title="Pilih Serial — {{ $bl['kode'] }} {{ $bl['nama'] }}" close="closeBatch">
            <div class="modal-body">
                @if ($errors->has('batchRows'))
                    <div class="alert alert-danger py-2">{{ $errors->first('batchRows') }}</div>
                @endif

                <table class="table table-sm table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">X</th>
                            <th>No Batch</th>
                            <th style="width:140px">Tanggal Expired</th>
                            <th class="text-end" style="width:110px">Tersedia</th>
                            <th class="text-end" style="width:120px">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batchRows as $bi => $r)
                            <tr wire:key="sj-batch-{{ $bi }}" class="{{ $r['pilih'] ? 'table-success' : '' }}">
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" @checked($r['pilih'])
                                           wire:click="toggleBatch({{ $bi }})" @disabled($locked)>
                                </td>
                                <td class="fw-semibold">{{ $r['noBatch'] }}</td>
                                <td>{{ $r['expired'] ? \Carbon\Carbon::parse($r['expired'])->format('d/m/Y') : '—' }}</td>
                                <td class="text-end text-muted">{{ $fmt($r['tersedia']) }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="{{ $r['tersedia'] }}"
                                           class="form-control form-control-sm text-end"
                                           wire:model="batchRows.{{ $bi }}.qty" @disabled($locked || ! $r['pilih'])>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">
                                Tidak ada batch dengan stok tersedia di {{ $cabangKirimLabel ?: 'gudang ini' }}.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Product</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($jumlahProduct) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Serial di Pilih</label>
                        <input type="text" disabled value="{{ $fmt($jumlahDipilih) }}"
                               class="form-control form-control-sm text-end fw-semibold {{ abs($selisih) > 0.0001 ? 'border-danger text-danger' : 'border-success text-success' }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Selisih</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($selisih) }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeBatch">Tutup</button>
                @unless ($locked)
                    <button type="button" class="btn btn-outline-primary" wire:click="isiUlangBatch">
                        <i class="fas fa-rotate me-1"></i> Isi Ulang Serial
                    </button>
                    <button type="button" class="btn btn-success" wire:click="applyBatch" @disabled(abs($selisih) > 0.0001)>
                        <i class="fas fa-check me-1"></i> OK
                    </button>
                @endunless
            </div>
        </x-lw-modal>
    @endif
</div>
