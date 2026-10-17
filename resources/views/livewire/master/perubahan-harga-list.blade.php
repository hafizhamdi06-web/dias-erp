<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1 small text-muted">Tgl Berlaku dari</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px" wire:model.live="fFrom">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">s/d</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px" wire:model.live="fTo">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Status</label>
                    <select class="form-select form-select-sm" style="width: 190px" wire:model.live="fStatus">
                        <option value="">Semua</option>
                        <option value="tempo">Jatuh tempo, belum diterapkan</option>
                        @foreach (\App\Services\PerubahanHargaWriter::STATUS_LABEL as $k => $v)
                            <option value="{{ $k }}">{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Cari</label>
                    <div class="input-group input-group-sm" style="width: 240px">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="no / keterangan…"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>
            </div>
            @if (can_do('master/perubahan-harga', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="newDoc">
                    <i class="fas fa-plus me-1"></i> Perubahan Harga Baru
                </button>
            @endif
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle vb-list">
                    <thead class="table-light"><tr>
                        <th style="width: 160px">No Transaksi</th>
                        <th style="width: 95px">Tanggal</th>
                        <th style="width: 95px">Tgl Berlaku</th>
                        <th>Keterangan</th>
                        <th class="text-end" style="width: 70px">Item</th>
                        <th class="text-end" style="width: 70px">%</th>
                        <th style="width: 120px">Dibuat oleh</th>
                        <th style="width: 200px">Status</th>
                        <th class="text-end" style="width: 90px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php
                                $st = (int) $r->status;
                                $tempo = $st !== \App\Services\PerubahanHargaWriter::STATUS_DITERAPKAN
                                    && $r->berlaku && substr($r->berlaku, 0, 10) <= $hariIni;
                                $bg = match ($st) { 1 => 'text-bg-success', 2 => 'text-bg-secondary', default => 'text-bg-warning' };
                            @endphp
                            <tr wire:key="ph-{{ $r->id }}">
                                <td>{{ $r->nomor }}</td>
                                <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                                <td>{{ $r->berlaku ? \Carbon\Carbon::parse($r->berlaku)->format('d/m/Y') : '—' }}</td>
                                <td>{{ $r->uraian ?: '—' }}</td>
                                <td class="text-end">{{ number_format($r->jmlItem, 0, ',', '.') }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $r->persen, 2, ',', '.'), '0'), ',') }}</td>
                                <td>{{ $r->pembuat ?: '—' }}</td>
                                <td>
                                    <x-status-teks :bg="$bg" :teks="\App\Services\PerubahanHargaWriter::STATUS_LABEL[$st] ?? ('#' . $st)" />
                                    @if ($st === 1 && $r->diterapkan)
                                        <span class="text-muted">{{ \Carbon\Carbon::parse($r->diterapkan)->format('d/m/Y H:i') }}</span>
                                    @elseif ($tempo)
                                        <span class="badge text-bg-danger" title="Tgl berlaku sudah tiba, harga belum diterapkan">Jatuh tempo</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-outline-secondary btn-sm"
                                            wire:click="openDoc({{ $r->id }}, @js($r->nomor))" title="Buka">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    @if (can_do('master/perubahan-harga', 'print'))
                                        <a href="{{ route('master.perubahan-harga.print', $r->id) }}" target="_blank"
                                           class="btn btn-outline-primary btn-sm" title="Cetak">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">
                                Tidak ada perubahan harga pada rentang tanggal berlaku ini.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
