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
        // SUSTATUS SJ terbalik dari konvensi "0=aktif" - 1=Normal/Aktif, 0=Pending
        // (jarang kepakai, chkPending tersembunyi di VB6 asli), 9=Batal (konvensi kita).
        $statusBadge = fn ($s) => match ((int) $s) {
            0 => ['text-bg-warning', 'Pending'],
            1 => ['text-bg-success', 'Aktif'],
            3 => ['text-bg-info', 'Diterima'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no SJ / kontak / no PKB"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 160px" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="1">Aktif</option>
                    <option value="0">Pending</option>
                    <option value="3">Diterima</option>
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
            @if (can_do('sales/sj', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="openPicker"><i class="fas fa-truck-fast me-1"></i> Tarik dari PKB</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Cabang Kirim</th><th>Cabang Tujuan</th><th>No PKB Asal</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="sj-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabangKirim ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabangTujuan ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->noPkb ?: '—' }}</td>
                            <td class="text-center"><span class="badge {{ $bg }}">{{ $lbl }}</span></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editSj({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if (can_do('sales/sj', 'print'))
                                    <a href="{{ route('sales.sj.print', $r->id) }}" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Cetak Surat Jalan">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                @if ((int) $r->status !== 9 && can_do('sales/sj', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan SJ {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada SJ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Tarik dari PKB (Perintah Kirim Barang yang masih ada sisa)" close="$set('showPicker', false)">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no PKB / diperintah oleh…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $pkb)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pickPkb({{ $pkb->id }}, @js($pkb->nomor))">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $pkb->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($pkb->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $pkb->karyawan ?: '—' }}</div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada PKB yang bisa ditarik.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="$set('showPicker', false)">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
