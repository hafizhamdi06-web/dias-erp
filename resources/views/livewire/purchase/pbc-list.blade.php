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
            <div class="d-flex flex-wrap gap-2 vb-filter">
                <div class="input-group input-group-sm f-cari">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no PBC / kontak / no SJ / no PR"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm f-pilih" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="1">Aktif</option>
                    <option value="9">Batal</option>
                </select>
                <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fCabang">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                {{-- Dibungkus `.vb-tanggal` supaya pasangan tanggalnya tidak pernah terbelah
                     ke baris berikutnya - sama spt daftar PR/KMB/TMB/SJ. --}}
                <div class="vb-tanggal">
                    <input type="date" class="form-control form-control-sm" wire:model.live="fFrom">
                    <input type="date" class="form-control form-control-sm" wire:model.live="fTo">
                </div>
            </div>
            @if (can_do('purchase/pbc', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="openPicker"><i class="fas fa-box-open me-1"></i> Terima dari SJ</button>
            @endif
        </div>
        <div class="card-body p-0">
            {{-- Daftar ala grid VB6: rapat & SATU font utk semua kolom (gaya `.vb-list` di
                 `public/css/vb-form.css`). --}}
            <table class="table table-bordered align-middle vb-list">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Kontak</th><th>Cabang Penerima</th><th>No SJ Asal</th><th>No PR Asal</th>
                    <th>Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="pbc-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->kontak ?: '—' }}</td>
                            <td>{{ $r->cabang ?: '—' }}</td>
                            <td>{{ $r->noSj ?: '—' }}</td>
                            <td>{{ $r->noPr ?: '—' }}</td>
                            <td><x-status-teks :bg="$bg" :teks="$lbl" /></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editPbc({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if (can_do('purchase/pbc', 'print'))
                                    <a href="{{ route('purchase.pbc.print', $r->id) }}" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Cetak Penerimaan Barang">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                @if ((int) $r->status !== 9 && can_do('purchase/pbc', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan PBC {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada PBC.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Terima dari SJ (Surat Jalan yang belum diterima)" close="$set('showPicker', false)">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no SJ / kontak…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $sj)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pickSj({{ $sj->id }}, @js($sj->nomor))">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $sj->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($sj->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $sj->karyawan ?: '—' }}</div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada SJ yang bisa diterima.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="$set('showPicker', false)">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
