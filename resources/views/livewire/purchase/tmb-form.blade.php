<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $tmbId ? 'TMB ' . $nomor : 'TMB Baru dari KMB ' . $noKmb }}
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
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-success', 'Aktif'],
                    };
                @endphp

                <style>
                    .tmb-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .tmb-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .tmb-field > .tmb-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    {{-- ===== Kolom kiri: isi TMB ===== --}}
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="tmb-field">
                                <label class="form-label">No KMB Asal</label>
                                <div class="tmb-input">
                                    <span class="badge text-bg-primary fs-6">{{ $noKmb ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="tmb-field">
                                <label class="form-label">No PR Asal</label>
                                <div class="tmb-input">
                                    <span class="badge text-bg-info fs-6">{{ $noPr ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="tmb-field">
                                <label class="form-label">Diperintah Oleh</label>
                                <div class="tmb-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $kontakLabel ?: '—' }}</span>
                                    <div class="form-text">{{ $tmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            <div class="tmb-field">
                                <label class="form-label">Cabang Penerima</label>
                                <div class="tmb-input">
                                    <select class="form-select form-select-sm" wire:model="cabang" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">{{ $tmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            <div class="tmb-field">
                                <label class="form-label">Keterangan</label>
                                <div class="tmb-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== Kolom kanan: info dokumen ===== --}}
                    <div class="col-md-6">
                        <div class="tmb-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="tmb-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="tmb-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="tmb-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="tmb-field">
                            <label class="form-label">Status</label>
                            <div class="tmb-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---- Baris item (ditarik dari KMB, tidak bisa tambah bebas) ---- --}}
                <hr class="my-3">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 30%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Dikirim</th>
                                <th class="text-center" style="width: 110px">Qty Diterima</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 150px">No Batch</th>
                                <th>Catatan</th>
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
                                    <td class="text-end">{{ rtrim(rtrim(number_format($l['qtyKirim'], 2), '0'), '.') }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" max="{{ $l['qtyKirim'] }}"
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
                                                {{ $bs === [] ? 'Isi Batch' : (count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch') }}
                                            </button>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center"></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada item dari KMB ini.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end">Total Qty Diterima</td>
                                    <td></td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="4"></td>
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
            $qtyTerima = (float) $bl['qty'];
            $totalBatch = array_sum(array_map(fn ($r) => (float) $r['qty'], $batchRows));
            $selisih = round($qtyTerima - $totalBatch, 4);
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
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
                            <tr wire:key="tmb-batch-{{ $bi }}">
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
                                <td class="text-end">{{ $fmt($r['qty']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 3 : 4 }}" class="text-center text-muted py-3">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Qty Diterima</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($qtyTerima) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Total Batch</label>
                        <input type="text" disabled value="{{ $fmt($totalBatch) }}"
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
                    <button type="button" class="btn btn-success" wire:click="applyBatch" @disabled(abs($selisih) > 0.0001)>
                        <i class="fas fa-check me-1"></i> Simpan Batch
                    </button>
                @endunless
            </div>
        </x-lw-modal>
    @endif
</div>
