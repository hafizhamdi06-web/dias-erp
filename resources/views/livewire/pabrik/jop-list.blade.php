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
            1 => ['text-bg-secondary', 'Belum Ditarik'],
            2 => ['text-bg-info', 'Sebagian Ditarik'],
            3 => ['text-bg-success', 'Selesai Ditarik'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no JOP / kontak / uraian"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 170px" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="1">Belum Ditarik</option>
                    <option value="2">Sebagian Ditarik</option>
                    <option value="3">Selesai Ditarik</option>
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
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" wire:click="$refresh" title="Muat ulang daftar">
                    <i class="fas fa-rotate" wire:loading.class="fa-spin" wire:target="$refresh"></i>
                </button>
                @if (can_do('pabrik/jop', 'add'))
                    <button class="btn btn-primary btn-sm" wire:click="newJop"><i class="fas fa-plus me-1"></i> JOP Baru</button>
                @endif
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Gudang Produksi</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="jop-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-center"><span class="badge {{ $bg }}">{{ $lbl }}</span></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editJop({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-{{ (int) $r->status === 1 ? 'pen' : 'eye' }}"></i>
                                </button>
                                @if ((int) $r->status === 1 && can_do('pabrik/jop', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan JOP {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada Job Order Produksi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
