<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1 small text-muted">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px" wire:model.live="fFrom">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px" wire:model.live="fTo">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Gudang</label>
                    <select class="form-select form-select-sm" style="width: 170px" wire:model.live="fCabang">
                        <option value="">Semua</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Jenis</label>
                    <select class="form-select form-select-sm" style="width: 180px" wire:model.live="fJenis">
                        <option value="">Semua jenis</option>
                        @foreach ($jenisList as $j)
                            <option value="{{ $j->JID }}">{{ $j->JNAMA ?: $j->JKODE }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Status</label>
                    <select class="form-select form-select-sm" style="width: 120px" wire:model.live="fStatus">
                        <option value="">Semua</option>
                        <option value="0">Aktif</option>
                        <option value="9">Batal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Cari</label>
                    <div class="input-group input-group-sm" style="width: 240px">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="no / uraian / kontak…"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>
            </div>
            @if (can_do('inventory/pengeluaran-lain', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="newPl">
                    <i class="fas fa-plus me-1"></i> Pengeluaran Baru
                </button>
            @endif
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th style="width: 160px">No Transaksi</th>
                        <th style="width: 105px">Tanggal</th>
                        <th>Jenis</th>
                        <th>Kontak</th>
                        <th>Gudang</th>
                        <th class="text-end" style="width: 110px">Keluar</th>
                        <th class="text-center" style="width: 85px">Status</th>
                        <th class="text-end" style="width: 100px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); @endphp
                            <tr wire:key="pl-{{ $r->id }}">
                                <td class="font-monospace small fw-semibold">{{ $r->nomor }}</td>
                                <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                                <td class="small">{{ $r->jenis ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->kontak ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->gudang ?: '—' }}</td>
                                <td class="text-end text-danger">{{ $fmt($r->totalKeluar) }}</td>
                                <td class="text-center">
                                    @if ((int) $r->status === 9)
                                        <span class="badge text-bg-danger">Batal</span>
                                    @else
                                        <span class="badge text-bg-success">Aktif</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-outline-secondary btn-sm"
                                            wire:click="editPl({{ $r->id }}, @js($r->nomor))" title="Buka">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    @if (can_do('inventory/pengeluaran-lain', 'print'))
                                        <a href="{{ route('inventory.pengeluaran-lain.print', $r->id) }}" target="_blank"
                                           class="btn btn-outline-primary btn-sm" title="Cetak Pengeluaran Lain">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    @endif
                                    @if ((int) $r->status !== 9 && can_do('inventory/pengeluaran-lain', 'delete'))
                                        <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                                data-confirm="Batalkan {{ $r->nomor }}? Stok akan dikembalikan ke posisi semula."
                                                title="Batalkan"><i class="fas fa-ban"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                Tidak ada pengeluaran pada rentang tanggal ini.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
