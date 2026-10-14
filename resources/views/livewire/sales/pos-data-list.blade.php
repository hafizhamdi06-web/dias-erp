<div>
    {{-- Tempat pesan hasil aksi. Sebelumnya layar ini murni baca-saja jadi tidak perlu;
         sejak ada tombol Batalkan, tanpa ini user tidak tahu berhasil atau ditolak. --}}
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center vb-filter">
            <div class="input-group input-group-sm f-cari">
                <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                <input type="text" class="form-control" placeholder="No. Transaksi…" wire:model.live.debounce.400ms="fNomor">
            </div>
            <div class="input-group input-group-sm f-cari">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input type="text" class="form-control" placeholder="Nama Pasien…" wire:model.live.debounce.400ms="fPasien">
            </div>
            <select class="form-select form-select-sm f-pilih" wire:model.live="fStatus">
                <option value="1">Aktif</option>
                <option value="0">Batal</option>
                <option value="">Semua status</option>
            </select>
            <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fCabang">
                <option value="">Semua cabang</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                @endforeach
            </select>
            {{-- `.vb-tanggal` menjaga pasangan tanggal tidak terbelah ke baris berikutnya -
                 sama spt daftar PR/KMB/TMB/SJ. --}}
            <div class="d-flex align-items-center gap-1 vb-tanggal">
                <span class="text-muted">Tanggal</span>
                <input type="date" class="form-control form-control-sm" wire:model.live="fTanggalDari" title="Dari tanggal">
                <span class="text-muted">&ndash;</span>
                <input type="date" class="form-control form-control-sm" wire:model.live="fTanggalSampai" title="Sampai tanggal">
                @if ($fTanggalDari || $fTanggalSampai)
                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="clearTanggalFilter" title="Hapus filter tanggal">
                        <i class="fas fa-xmark"></i>
                    </button>
                @endif
            </div>
        </div>
        <div class="card-body p-0">
            {{-- Daftar ala grid VB6: rapat & SATU font utk semua kolom (gaya `.vb-list` di
                 `public/css/vb-form.css`). --}}
            <table class="table table-bordered align-middle vb-list">
                <thead>
                    <tr>
                        <th>No. Transaksi</th>
                        <th>Tanggal</th>
                        <th>Nama Pasien</th>
                        <th class="text-end">Total Transaksi</th>
                        <th>Nama Kasir</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="posdata-{{ $r->SUID }}">
                            <td class="fw-semibold">{{ $r->SUNOTRANSAKSI }}</td>
                            <td>
                                {{ $r->SUTANGGAL ? \Illuminate\Support\Carbon::parse($r->SUTANGGAL)->format('d/m/Y') : '—' }}
                            </td>
                            <td>{{ $r->pasien ?: '—' }}</td>
                            <td class="text-end">{{ number_format((float) $r->SUTOTALTRANSAKSI, 0, ',', '.') }}</td>
                            <td>{{ $r->kasir ?: '—' }}</td>
                            <td>{{ $r->cabang ?: '—' }}</td>
                            {{-- Status sbg TEKS berwarna, bukan badge - badge punya ukuran font
                                 sendiri (0.75em) jadi merusak keseragaman kolom. --}}
                            <td>
                                @if ((int) $r->SUSTATUS === 9)
                                    <x-status-teks bg="text-bg-danger" teks="Batal" />
                                @else
                                    <x-status-teks bg="text-bg-success" teks="Aktif" />
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-outline-primary btn-sm" wire:click="openDetailModal({{ $r->SUID }})" title="Detail">
                                    <i class="fas fa-list"></i>
                                </button>
                                @if (can_do('sales/pos-data', 'print'))
                                    <a href="{{ route('sales.pos.receipt', $r->SUID) }}" target="_blank"
                                       class="btn btn-outline-secondary btn-sm" title="Cetak">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                {{-- Batalkan transaksi POS - hanya pemegang hak `delete` pada
                                     `sales/pos-data` (diatur per user di Admin > Hak Akses Menu),
                                     dan hanya utk transaksi yg belum batal. Server memeriksa
                                     ulang keduanya - lihat `PosDataList::cancel()`. --}}
                                @if ((int) $r->SUSTATUS !== 9 && can_do('sales/pos-data', 'delete'))
                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                            wire:click="cancel({{ $r->SUID }})"
                                            data-confirm="Batalkan transaksi {{ $r->SUNOTRANSAKSI }}? Stok akan dikembalikan dan poin loyalti dihapus. Tindakan ini tidak bisa dibatalkan."
                                            title="Batalkan transaksi">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showDetailModal)
        <x-lw-modal :show="$showDetailModal" title="Detail Transaksi" size="xl" close="closeDetailModal">
            <div class="modal-body">
                <div class="small text-muted mb-2">
                    No. Transaksi: <span class="fw-semibold">{{ $detailNomor }}</span>
                    &middot; Pasien: <span class="fw-semibold">{{ $detailPasien ?: '—' }}</span>
                </div>
                <div class="table-responsive" style="max-height: 420px; overflow-y:auto">
                    {{-- Gaya rapat yg sama dgn tabel daftarnya - `.vb-list` juga menetralkan
                         kelas `small` di sel, jadi semua kolom satu ukuran. --}}
                    <table class="table table-sm table-bordered vb-list">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Harga</th>
                                <th class="text-end">Disc 1</th>
                                <th class="text-end">Disc 2</th>
                                <th class="text-end">Subtotal</th>
                                <th>Dokter</th>
                                <th>Operator</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($detailLines as $l)
                                <tr>
                                    <td class="small">{{ $l->kode }}</td>
                                    <td class="small">
                                        {{ $l->nama }}
                                        @if ($l->promo_label)
                                            <div class="small text-success"><i class="fas fa-tag me-1"></i>Promo: {{ $l->promo_label }}</div>
                                        @endif
                                        @if ($l->paket_label)
                                            <div class="small text-info">
                                                <i class="fas fa-boxes-packing me-1"></i>Paket: {{ $l->paket_label }}
                                                @if ($l->sdcatatankoli)
                                                    &middot; No {{ $l->sdcatatankoli }} &middot; kedatangan ke-{{ $l->sdkedatangan }}
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end small">{{ rtrim(rtrim(number_format($l->qty, 2), '0'), '.') }}</td>
                                    <td class="text-end small">{{ number_format($l->harga, 0, ',', '.') }}</td>
                                    <td class="text-end small">{{ rtrim(rtrim(number_format($l->dis1, 2), '0'), '.') }}%</td>
                                    <td class="text-end small">{{ rtrim(rtrim(number_format($l->dis2, 2), '0'), '.') }}%</td>
                                    <td class="text-end small fw-semibold">{{ number_format($l->subtotal, 0, ',', '.') }}</td>
                                    <td class="text-muted small">{{ $l->dokter ?: '—' }}</td>
                                    <td class="text-muted small">{{ $l->operator ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada baris.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeDetailModal">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
