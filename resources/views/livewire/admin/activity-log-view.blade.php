<div>
    <div class="card">
        <div class="card-header">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Cari</label>
                    <input type="text" class="form-control form-control-sm" placeholder="user / deskripsi / modul"
                           wire:model.live.debounce.400ms="q">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Aksi</label>
                    <select class="form-select form-select-sm" wire:model.live="action">
                        <option value="">semua</option>
                        @foreach ($actions as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Dari tgl</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="dateFrom">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Sampai tgl</label>
                    <input type="date" class="form-control form-control-sm" wire:model.live="dateTo">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary btn-sm w-100" wire:click="resetFilter">Reset</button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 150px">Waktu</th>
                        <th>User</th>
                        <th>Aksi</th>
                        <th>Modul</th>
                        <th>Deskripsi</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="text-muted small">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $log->user_label ?? '—' }}</td>
                            <td><span class="badge text-bg-light">{{ $log->action }}</span></td>
                            <td class="text-muted small">{{ $log->module ?? '—' }}</td>
                            <td>{{ $log->description }}</td>
                            <td class="text-muted small">{{ $log->ip }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada aktivitas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $logs->links() }}
        </div>
    </div>
</div>
