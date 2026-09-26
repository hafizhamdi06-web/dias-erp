<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $produksiId ? 'Produksi ' . $nomor : ($noJop ? 'Produksi Baru dari JOP ' . $noJop : 'Produksi Baru (Bebas)') }}
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
                    .pro-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .pro-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .pro-field > .pro-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            @if ($noJop)
                                <div class="pro-field">
                                    <label class="form-label">No JOP Asal</label>
                                    <div class="pro-input">
                                        <span class="badge text-bg-info fs-6">{{ $noJop }}</span>
                                    </div>
                                </div>
                            @endif
                            <div class="pro-field">
                                <label class="form-label">Diperintah Oleh</label>
                                <div class="pro-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $kontakLabel ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="pro-field">
                                <label class="form-label">Gudang Produksi</label>
                                <div class="pro-input">
                                    <select class="form-select form-select-sm" wire:model="cabang" disabled>
                                        <option value="">—</option>
                                        @foreach (\App\Models\Branch::options() as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Tempat bahan baku dikonsumsi - otomatis cabang Anda.</div>
                                </div>
                            </div>
                            <div class="pro-field">
                                <label class="form-label">Gudang Jadi <span class="text-danger">*</span></label>
                                <div class="pro-input">
                                    @if ($gudangJadiLabel)
                                        <span class="badge text-bg-primary fs-6">{{ $gudangJadiLabel }}</span>
                                        @unless ($locked)
                                            <button type="button" class="btn btn-sm btn-link p-0 ms-1" wire:click="$set('gudangJadi', null)">ganti</button>
                                        @endunless
                                    @else
                                        <span class="text-muted">— cari di bawah —</span>
                                    @endif
                                    @error('gudangJadi') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="pro-field">
                                <label class="form-label">Gudang Sample</label>
                                <div class="pro-input">
                                    @if ($gudangSampleLabel)
                                        <span class="badge text-bg-secondary fs-6">{{ $gudangSampleLabel }}</span>
                                        @unless ($locked)
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" wire:click="clearGudangSample">hapus</button>
                                        @endunless
                                    @else
                                        <span class="text-muted">— opsional —</span>
                                    @endif
                                </div>
                            </div>
                            @unless ($locked && $gudangJadiLabel)
                                <div class="pro-field">
                                    <label class="form-label">Cari Gudang</label>
                                    <div class="pro-input position-relative">
                                        <input type="text" class="form-control form-control-sm"
                                               placeholder="Cari gudang, lalu pilih Jadi/Sample di hasil…"
                                               wire:model.live.debounce.300ms="gudangQ" autocomplete="off">
                                        @if (count($gudangResults))
                                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 220px; overflow-y:auto">
                                                @foreach ($gudangResults as $r)
                                                    <div class="list-group-item p-1 d-flex justify-content-between align-items-center">
                                                        <span class="small"><span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}</span>
                                                        <span>
                                                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="pickGudangJadi({{ $r->id }}, @js($r->nama))">Jadi</button>
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="pickGudangSample({{ $r->id }}, @js($r->nama))">Sample</button>
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endunless
                            <div class="pro-field">
                                <label class="form-label">Keterangan</label>
                                <div class="pro-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col-md-6">
                        <div class="pro-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="pro-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="pro-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="pro-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="pro-field">
                            <label class="form-label">Status</label>
                            <div class="pro-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                @unless ($locked || $jopId)
                    <div class="position-relative mb-2" style="max-width: 420px">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari produk jadi untuk ditambahkan…"
                                   wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        </div>
                        @if (count($itemResults))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                @foreach ($itemResults as $r)
                                    <button type="button" wire:key="ir-{{ $r->id }}"
                                            class="list-group-item list-group-item-action small"
                                            wire:click="addProdukJadi({{ $r->id }})">
                                        <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 28%">Produk Jadi</th>
                                @if ($jopId) <th class="text-end" style="width: 90px">Sisa JOP</th> @endif
                                <th class="text-center" style="width: 110px">Qty Diproduksi</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 150px">No Batch</th>
                                <th>Komposisi Bahan Baku</th>
                                <th>Catatan</th>
                                @unless ($locked || $jopId) <th style="width: 36px"></th> @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $l['nama'] }}</div>
                                        <div class="text-muted small">{{ $l['kode'] }}</div>
                                    </td>
                                    @if ($jopId)
                                        <td class="text-end">{{ rtrim(rtrim(number_format($l['qtySisa'] ?? 0, 2), '0'), '.') }}</td>
                                    @endif
                                    <td>
                                        <input type="number" min="0" step="any" @if($l['qtySisa'] !== null) max="{{ $l['qtySisa'] }}" @endif
                                               class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $i }}.qty" @disabled($locked)>
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
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="openKomposisi({{ $i }})">
                                            <i class="fas fa-flask me-1"></i>{{ count($l['komposisi']) }} bahan
                                        </button>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    @unless ($locked || $jopId)
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeLine({{ $i }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada produk jadi.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end" colspan="{{ $jopId ? 2 : 1 }}">Total Qty Diproduksi</td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="5"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </form>

    @if ($showKomposisi && $komposisiLine !== null)
        <x-lw-modal :show="$showKomposisi" title="Komposisi Bahan Baku - {{ $lines[$komposisiLine]['nama'] ?? '' }}" close="closeKomposisi" size="lg">
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-muted small">Qty Diproduksi: <span class="fw-semibold">{{ rtrim(rtrim(number_format($lines[$komposisiLine]['qty'] ?? 0, 2), '0'), '.') }}</span></div>
                    @unless ($locked)
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="ambilResepDefault">
                                <i class="fas fa-book me-1"></i>Ambil Resep Default
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="hitungUlangKomposisi">
                                <i class="fas fa-calculator me-1"></i>Hitung Ulang Qty
                            </button>
                        </div>
                    @endunless
                </div>

                @unless ($locked)
                    <div class="position-relative mb-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari bahan baku untuk ditambahkan…"
                                   wire:model.live.debounce.300ms="komposisiQ" autocomplete="off">
                        </div>
                        @if (count($komposisiResults))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1060; max-height: 220px; overflow-y:auto">
                                @foreach ($komposisiResults as $r)
                                    <button type="button" wire:key="kr-{{ $r->id }}"
                                            class="list-group-item list-group-item-action small"
                                            wire:click="addKomposisiItem({{ $r->id }})">
                                        <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Bahan Baku</th>
                                <th class="text-center" style="width: 110px">Qty/Unit</th>
                                <th class="text-center" style="width: 110px">Qty Pakai</th>
                                <th style="width: 80px">Satuan</th>
                                <th>Catatan</th>
                                <th style="width: 36px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines[$komposisiLine]['komposisi'] as $k => $row)
                                <tr wire:key="komp-{{ $row['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $row['nama'] }}</div>
                                        <div class="text-muted small">{{ $row['kode'] }}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.qtyDefault" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $row['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeKomposisiItem({{ $k }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada bahan baku - klik "Ambil Resep Default" atau tambah manual.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" wire:click="closeKomposisi">Selesai</button>
            </div>
        </x-lw-modal>
    @endif

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $qtyJadi = (float) $bl['qty'];
            $totalBatch = array_sum(array_map(fn ($r) => (float) $r['qty'], $batchRows));
            $selisih = round($qtyJadi - $totalBatch, 4);
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
                            <tr wire:key="pro-batch-{{ $bi }}">
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
                        <label class="form-label mb-1 small text-muted">Qty Diproduksi</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($qtyJadi) }}">
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
