<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1 small text-muted">Cabang</label>
                    <select class="form-select form-select-sm" style="width: 200px" wire:model.live="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 160px" wire:model.live="dari">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 160px" wire:model.live="sampai">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Cari</label>
                    <div class="input-group input-group-sm" style="width: 260px">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="no IP / pasien / tindakan…"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if ((int) $ringkas->baris === 0)
                    <span class="badge text-bg-success fs-6"><i class="fas fa-check me-1"></i> Semua alkes sudah diinput</span>
                @else
                    <span class="badge text-bg-warning fs-6">
                        {{ number_format($ringkas->baris) }} tindakan belum diinput alkes
                        · {{ number_format($ringkas->ip) }} transaksi IP
                    </span>
                    {{-- Mengunduh SELURUH baris sesuai saringan, bukan cuma halaman ini. --}}
                    <button type="button" class="btn btn-sm btn-outline-success text-nowrap"
                            wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                        <i class="fas fa-file-excel me-1"></i>Export Excel
                    </button>
                @endif
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th style="width: 110px">Tanggal</th>
                        <th style="width: 160px">No IP</th>
                        <th>Cabang</th>
                        <th>Pasien</th>
                        <th>Tindakan</th>
                        <th class="text-end" style="width: 70px">Qty</th>
                        <th>Dokter</th>
                        <th class="text-end" style="width: 140px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="closing-{{ $r->sdid }}">
                                <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                                <td class="font-monospace small fw-semibold">{{ $r->noIp }}</td>
                                <td class="small">{{ $r->cabang ?: '—' }}</td>
                                <td>{{ $r->pasien ?: '—' }}</td>
                                <td>
                                    <span class="font-monospace small text-muted">{{ $r->kode }}</span>
                                    {{ $r->tindakan }}
                                    @if ((int) $r->dariPaket !== 0)
                                        <span class="badge text-bg-info ms-1">Paket · kedatangan ke-{{ (int) $r->kedatangan }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ (float) $r->qty + 0 }}</td>
                                <td class="text-muted small">{{ $r->dokter ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @if (! can_do('sales/alkes', 'add'))
                                    @elseif ((int) $r->cabangId === $cabangAktif)
                                        <button class="btn btn-primary btn-sm" wire:click="inputAlkes({{ $r->ipId }})">
                                            <i class="fas fa-syringe me-1"></i> Input Alkes
                                        </button>
                                    @else
                                        {{-- Form Input Alkes hanya menerima IP cabang aktif. --}}
                                        <span class="text-muted small" title="Ganti cabang aktif ke {{ $r->cabang }} untuk input alkes">
                                            <i class="fas fa-circle-info"></i> Cabang lain
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                Tidak ada tindakan yang belum diinput alkes pada rentang tanggal ini.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
