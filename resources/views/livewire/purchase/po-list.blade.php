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
            <div class="d-flex flex-wrap gap-2 vb-filter">
                <div class="input-group input-group-sm f-cari">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no PO / vendor / no ref"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fStatus">
                    {{-- Nilai HARUS cocok dgn PoList::statusExpr(). (Dulu "Aktif" bernilai 1 -
                         salah, tidak ada status 1, jadi filternya selalu nihil.) --}}
                    <option value="">Semua status</option>
                    <option value="0">Aktif</option>
                    <option value="2">Ditarik Sebagian</option>
                    <option value="3">Selesai</option>
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
            {{-- Daftar ala grid VB6: rapat & SATU font utk semua kolom (gaya `.vb-list` di
                 `public/css/vb-form.css`). --}}
            <table class="table table-bordered align-middle vb-list">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Vendor</th><th>Gudang</th><th class="text-end">Total</th>
                    <th>Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="po-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->vendor ?: '—' }}</td>
                            <td>{{ $r->cabang ?: '—' }}</td>
                            <td class="text-end">{{ number_format($r->total, 0, ',', '.') }}</td>
                            <td><x-status-teks :bg="$bg" :teks="$lbl" /></td>
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
                @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); @endphp
                {{-- Gaya ringkas `.vb-list` spt layar daftar (permintaan user 2026-10-10):
                     satu font utk SEMUA kolom. Sebelumnya isi modal ini mencampur `small`,
                     `text-muted` & badge sehingga tiap kolom tampak beda ukuran - persis
                     keluhan yg dulu diperbaiki di layar daftarnya. --}}
                <div class="table-responsive">
                    <table class="table table-bordered align-middle vb-list">
                        <thead>
                            <tr>
                                <th style="width: 28%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Order</th>
                                <th style="width: 60px">Satuan</th>
                                <th class="text-end" style="width: 100px">Diterima</th>
                                <th class="text-end" style="width: 90px">Sisa</th>
                                <th>Penerimaan (PB)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($historyLines as $l)
                                <tr wire:key="hist-{{ $loop->index }}">
                                    {{-- Kode & nama digabung satu kolom spt di form PKB/SJ - dua
                                         kolom terpisah saling menyempitkan. --}}
                                    <td><x-nama-produk :kode="$l['item']" :nama="$l['nama']" /></td>
                                    <td class="text-end">{{ $fmt($l['qtyOrder']) }}</td>
                                    {{-- Satuan dikeluarkan jadi kolom sendiri: dulu menempel di
                                         kolom Qty Order dgn font lebih kecil, jadi angkanya tidak
                                         sejajar antar baris. --}}
                                    <td>{{ $l['satuan'] ?: '—' }}</td>
                                    <td class="text-end fw-semibold">{{ $fmt($l['qtyTerima']) }}</td>
                                    {{-- `-emphasis`, bukan `text-warning` polos: terlalu pucat di
                                         latar terang (pelajaran yg sama dgn `x-status-teks`). --}}
                                    <td class="text-end {{ $l['sisa'] > 0 ? 'text-warning-emphasis fw-semibold' : '' }}">
                                        {{ $fmt($l['sisa']) }}
                                    </td>
                                    <td>
                                        @forelse ($l['penerimaan'] as $p)
                                            <div class="d-flex justify-content-between align-items-center gap-2
                                                        {{ $loop->last ? '' : 'border-bottom' }} py-1">
                                                <div>
                                                    <span class="font-monospace fw-semibold">{{ $p['nomor'] }}</span>
                                                    @if ($p['batal'])
                                                        <x-status-teks bg="text-bg-danger" teks="Batal" class="ms-1" />
                                                    @endif
                                                    <div>
                                                        {{ \Carbon\Carbon::parse($p['tanggal'])->format('d/m/Y') }}
                                                        @if ($p['noInvoice']) · Inv: {{ $p['noInvoice'] }} @endif
                                                    </div>
                                                </div>
                                                <span class="fw-semibold">{{ $fmt($p['qty']) }}</span>
                                            </div>
                                        @empty
                                            Belum pernah diterima.
                                        @endforelse
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">PO ini tidak punya baris item.</td></tr>
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
