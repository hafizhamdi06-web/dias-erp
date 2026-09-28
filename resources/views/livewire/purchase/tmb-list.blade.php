<div>
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    @php
        $statusBadge = fn ($s) => match ((int) $s) {
            1 => ['text-bg-success', 'Aktif'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no TMB / kontak / no KMB / no PR"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 160px" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="1">Aktif</option>
                    <option value="9">Batal</option>
                </select>
                <select class="form-select form-select-sm" style="max-width: 180px" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fFrom">
                <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fTo">
            </div>
            @if (can_do('inventory/tmb', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="openPicker"><i class="fas fa-dolly me-1"></i> Terima dari KMB</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Cabang Penerima</th><th>No KMB Asal</th><th>No PR Asal</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="tmb-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->noKmb ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->noPr ?: '—' }}</td>
                            <td class="text-center"><span class="badge {{ $bg }}">{{ $lbl }}</span></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editTmb({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if (can_do('inventory/tmb', 'print'))
                                    <a href="{{ route('inventory.tmb.print', $r->id) }}" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Cetak TMB">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                @if ((int) $r->status !== 9 && can_do('inventory/tmb', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan TMB {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada TMB.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Terima dari KMB (Kirim Mutasi Barang yang belum diterima)" close="$set('showPicker', false)">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no KMB / kontak…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $kmb)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pickKmb({{ $kmb->id }}, @js($kmb->nomor))">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $kmb->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($kmb->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $kmb->karyawan ?: '—' }}</div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada KMB yang bisa diterima.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="$set('showPicker', false)">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
