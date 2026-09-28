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
        // 0/2/3 DIKONFIRMASI data nyata (235 PO asli hasil import) - VB6 asli
        // `dFrmOrderPembelian.frm` TIDAK PERNAH menulis SOUSTATUS, nilai ini datang dari
        // mekanisme lain (kemungkinan modul Penerimaan Barang/CI3 paralel). 9 = konvensi
        // batal KITA sendiri (soft-cancel, TIDAK ada di data nyata - lihat docblock
        // PurchaseOrderWriter).
        // Status DIHITUNG dari penerimaan (lihat PoList::statusExpr()), bukan dari
        // kolom SOUSTATUS yg terbukti tidak pernah diperbarui sistem.
        $statusBadge = fn ($s) => match ((int) $s) {
            0 => ['text-bg-secondary', 'Aktif'],
            2 => ['text-bg-info', 'Ditarik Sebagian'],
            3 => ['text-bg-success', 'Selesai'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no PO / vendor / no ref"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 160px" wire:model.live="fStatus">
                    {{-- Nilai HARUS cocok dgn PoList::statusExpr(). (Dulu "Aktif" bernilai 1 -
                         salah, tidak ada status 1, jadi filternya selalu nihil.) --}}
                    <option value="">Semua status</option>
                    <option value="0">Aktif</option>
                    <option value="2">Ditarik Sebagian</option>
                    <option value="3">Selesai</option>
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
                @if (can_do('purchase/po', 'add'))
                    <button class="btn btn-primary btn-sm" wire:click="newPo"><i class="fas fa-plus me-1"></i> PO Baru</button>
                @endif
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Vendor</th><th>Gudang</th><th class="text-end">Total</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="po-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->vendor ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-end">{{ number_format($r->total, 0, ',', '.') }}</td>
                            <td class="text-center"><span class="badge {{ $bg }}">{{ $lbl }}</span></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editPo({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-{{ (int) $r->status === 0 ? 'pen' : 'eye' }}"></i>
                                </button>
                                <button class="btn btn-outline-info btn-sm" wire:click="openHistory({{ $r->id }})"
                                        title="Histori penerimaan (PB)">
                                    <i class="fas fa-clock-rotate-left"></i>
                                </button>
                                @if (can_do('purchase/po', 'print'))
                                    {{-- <a target="_blank">, BUKAN wire:click: cetak tidak perlu
                                         round-trip ke server & tab Workspace tidak ikut pindah. --}}
                                    <a href="{{ route('purchase.po.print', $r->id) }}" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Cetak PO">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                @if ((int) $r->status === 0 && can_do('purchase/po', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan PO {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada Purchase Order.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showHistory)
        <x-lw-modal :show="$showHistory" title="Histori Penerimaan — {{ $historyNomor }}" close="closeHistory">
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 28%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Order</th>
                                <th class="text-end" style="width: 100px">Diterima</th>
                                <th class="text-end" style="width: 90px">Sisa</th>
                                <th>Penerimaan (PB)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($historyLines as $l)
                                @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); @endphp
                                <tr wire:key="hist-{{ $loop->index }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $l['nama'] }}</div>
                                        <div class="text-muted small">{{ $l['item'] }}</div>
                                    </td>
                                    <td class="text-end">{{ $fmt($l['qtyOrder']) }} <span class="text-muted small">{{ $l['satuan'] }}</span></td>
                                    <td class="text-end fw-semibold">{{ $fmt($l['qtyTerima']) }}</td>
                                    <td class="text-end {{ $l['sisa'] > 0 ? 'text-warning fw-semibold' : 'text-muted' }}">
                                        {{ $fmt($l['sisa']) }}
                                    </td>
                                    <td>
                                        @forelse ($l['penerimaan'] as $p)
                                            <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                                                <div>
                                                    <span class="font-monospace small fw-semibold">{{ $p['nomor'] }}</span>
                                                    @if ($p['batal'])
                                                        <span class="badge text-bg-danger ms-1">Batal</span>
                                                    @endif
                                                    <div class="text-muted small">
                                                        {{ \Carbon\Carbon::parse($p['tanggal'])->format('d/m/Y') }}
                                                        @if ($p['noInvoice']) · Inv: {{ $p['noInvoice'] }} @endif
                                                    </div>
                                                </div>
                                                <span class="fw-semibold">{{ $fmt($p['qty']) }}</span>
                                            </div>
                                        @empty
                                            <span class="text-muted small">Belum pernah diterima.</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">PO ini tidak punya baris item.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeHistory">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
