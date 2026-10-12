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
            <div class="d-flex flex-wrap gap-2 vb-filter">
                <div class="input-group input-group-sm f-cari">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no SJ / kontak / no PKB"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="1">Aktif</option>
                    <option value="0">Pending</option>
                    <option value="3">Diterima</option>
                    <option value="9">Batal</option>
                </select>
                <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <div class="vb-tanggal">
                    <input type="date" class="form-control form-control-sm" wire:model.live="fFrom">
                    <input type="date" class="form-control form-control-sm" wire:model.live="fTo">
                </div>
            </div>
            @if (can_do('sales/sj', 'add'))
                {{-- SATU tombol saja (permintaan user 2026-10-10). PKB sumbernya dipilih DI
                     DALAM form, lewat tombol cari di kotak "No PKB Asal". --}}
                <button class="btn btn-primary btn-sm" wire:click="inputBaru">
                    <i class="fas fa-plus me-1"></i> Input Baru
                </button>
            @endif
        </div>
        <div class="card-body p-0">
            {{-- Daftar ala grid VB6: rapat & SATU font utk semua kolom (gaya `.vb-list` di
                 `public/css/vb-form.css`). --}}
            <table class="table table-bordered align-middle vb-list">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Cabang Kirim</th><th>Cabang Tujuan</th><th>No PKB Asal</th>
                    <th>Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="sj-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td>{{ $r->cabangKirim ?: '—' }}</td>
                            <td>{{ $r->cabangTujuan ?: '—' }}</td>
                            <td>{{ $r->noPkb ?: '—' }}</td>
                            <td><x-status-teks :bg="$bg" :teks="$lbl" /></td>
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

</div>
