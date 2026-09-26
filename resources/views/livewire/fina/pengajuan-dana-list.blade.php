<div>
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no transaksi / kontak / uraian"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 180px" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fFrom">
                <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fTo">
            </div>
            @if (can_do('finance/pengajuan-dana', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="newTransaksi"><i class="fas fa-plus me-1"></i> Pengajuan Dana Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Rekening</th><th>Cabang</th>
                    <th class="text-end">Total</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="pdn-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->rekening ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-end">{{ number_format($r->total, 0, ',', '.') }}</td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editTransaksi({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if (can_do('finance/pengajuan-dana', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="hapus({{ $r->id }})"
                                            data-confirm="Hapus Pengajuan Dana {{ $r->nomor }}? Baris Kas Keluar terkait akan bisa ditarik lagi." title="Hapus"><i class="fas fa-trash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data Pengajuan Dana.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>
</div>
