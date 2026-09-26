<div>
    <div class="card mb-3">
        <div class="card-header"><strong>Data Histori Serial</strong></div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Gudang</label>
                    <select class="form-select form-select-sm" wire:model.live="cabang">
                        <option value="">— pilih —</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                    @if ($cabang && ! $pakaiBatch)
                        <div class="form-text text-warning">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Gudang ini tidak memakai batch — transaksi baru di sini tidak akan mencatat No Batch.
                        </div>
                    @endif
                </div>
                <div class="col-md-5">
                    <label class="form-label">Item (hanya yang pakai batch)</label>
                    <x-search-select model="item" :value="$item" :selected-text="$itemLabel"
                                     :endpoint="route('lookup.item-serial')" placeholder="cari kode / nama item…" live />
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="sh-adastok" wire:model.live="hanyaAdaStok">
                        <label class="form-check-label" for="sh-adastok">Hanya yang masih ada stok</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (! $item || ! $cabang)
        <div class="card"><div class="card-body text-center text-muted py-5">
            Pilih Gudang dan Item dulu untuk melihat daftar batch.
        </div></div>
    @else
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>Batch / No Serial</strong>
                    <div class="text-body-secondary small">
                        <i class="fas fa-box me-1"></i>{{ $itemLabel ?: '—' }}
                        <span class="mx-1">·</span>
                        <i class="fas fa-warehouse me-1"></i>{{ $gudangLabel ?: '—' }}
                    </div>
                </div>
                <span class="text-muted small">
                    {{ count($batches) }} batch — total sisa
                    <strong>{{ rtrim(rtrim(number_format($totalSisa, 2, ',', '.'), '0'), ',') }}</strong>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th>No Batch</th>
                            <th style="width: 140px">Tanggal Expired</th>
                            <th class="text-center" style="width: 80px">Status</th>
                            <th class="text-end" style="width: 110px">Masuk</th>
                            <th class="text-end" style="width: 110px">Keluar</th>
                            <th class="text-end" style="width: 110px">Tersedia</th>
                            <th class="text-end" style="width: 110px">Mutasi</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($batches as $b)
                                @php $kadaluarsa = $b->expired && \Carbon\Carbon::parse($b->expired)->isPast(); @endphp
                                <tr wire:key="batch-{{ $b->isid }}" class="{{ $serialId === (int) $b->isid ? 'table-primary' : '' }}">
                                    <td class="fw-semibold">{{ $b->noBatch }}</td>
                                    <td>
                                        @if ($b->expired)
                                            <span class="{{ $kadaluarsa ? 'text-danger fw-semibold' : '' }}">
                                                {{ \Carbon\Carbon::parse($b->expired)->format('d/m/Y') }}
                                            </span>
                                            @if ($kadaluarsa) <span class="badge text-bg-danger ms-1">Kedaluwarsa</span> @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ((int) $b->aktif === 1)
                                            <span class="badge text-bg-success">Ada</span>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-muted">{{ rtrim(rtrim(number_format((float) $b->totalMasuk, 2, ',', '.'), '0'), ',') }}</td>
                                    <td class="text-end text-muted">{{ rtrim(rtrim(number_format((float) $b->totalKeluar, 2, ',', '.'), '0'), ',') }}</td>
                                    <td class="text-end fw-semibold {{ (float) $b->tersedia > 0 ? '' : 'text-muted' }}">
                                        {{ rtrim(rtrim(number_format((float) $b->tersedia, 2, ',', '.'), '0'), ',') }}
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                wire:click="lihatMutasi({{ $b->isid }}, @js($b->noBatch))">
                                            <i class="fas fa-clock-rotate-left me-1"></i> Histori
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">
                                    Item ini belum punya batch di gudang tersebut.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if ($serialId)
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Mutasi Batch</strong>
                        <span class="badge text-bg-primary ms-2">{{ $serialLabel }}</span>
                        <div class="text-body-secondary small">
                            <i class="fas fa-box me-1"></i>{{ $itemLabel ?: '—' }}
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="tutupMutasi">
                        <i class="fas fa-xmark me-1"></i> Tutup
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th style="width: 100px">Sumber</th>
                                <th style="width: 180px">No Transaksi</th>
                                <th style="width: 110px">Tanggal</th>
                                <th>Kontak</th>
                                <th class="text-end" style="width: 100px">Masuk</th>
                                <th class="text-end" style="width: 100px">Keluar</th>
                                <th class="text-end" style="width: 110px">Saldo</th>
                            </tr></thead>
                            <tbody>
                                @forelse ($mutasi as $m)
                                    <tr wire:key="mutasi-{{ $m->ishid }}">
                                        <td><span class="badge text-bg-secondary">{{ $m->sumber ?: '—' }}</span></td>
                                        <td class="font-monospace small">
                                            {{ $m->nomor ?: '—' }}
                                            @if ((int) $m->status === 9)
                                                <span class="badge text-bg-danger ms-1">Batal</span>
                                            @endif
                                        </td>
                                        <td>{{ $m->tanggal ? \Carbon\Carbon::parse($m->tanggal)->format('d/m/Y') : '—' }}</td>
                                        <td class="text-muted small">{{ $m->kontak ?: '—' }}</td>
                                        <td class="text-end">
                                            @if ((float) $m->masuk > 0)
                                                <span class="text-success fw-semibold">{{ rtrim(rtrim(number_format((float) $m->masuk, 2, ',', '.'), '0'), ',') }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ((float) $m->keluar > 0)
                                                <span class="text-danger fw-semibold">{{ rtrim(rtrim(number_format((float) $m->keluar, 2, ',', '.'), '0'), ',') }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold">{{ rtrim(rtrim(number_format((float) $m->saldo, 2, ',', '.'), '0'), ',') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada mutasi.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
