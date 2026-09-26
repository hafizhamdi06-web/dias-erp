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
        // Wording 3-7 disamakan PERSIS dgn label legacy VB6
        // (fFrmPermintaanbarangData.frm::IsiData(), CASE PBUSTATUS) per permintaan
        // user 2026-09-18. HANYA berlaku utk jenis=1 (Permintaan Pembelian, alur
        // PR->PKB->SJ->PBC) - jenis=0 pakai $kmTmbBadge di bawah (lihat itu).
        $statusBadge = fn ($s) => match ((int) $s) {
            0 => ['text-bg-secondary', 'Belum Verifikasi'],
            1 => ['text-bg-warning', 'Pending'],
            2 => ['text-bg-success', 'Disetujui'],
            3 => ['text-bg-info', 'Perintah Kirim'],
            4 => ['text-bg-info', 'Sedang Dikirim'],
            5 => ['text-bg-primary', 'Progress Diterima Cabang'],
            6 => ['text-bg-success', 'Selesai Diterima Cabang'],
            7 => ['text-bg-primary', 'Konfirmasi Bag Pembelian'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };

        // PR jenis=0 (Permintaan Barang) TANPA VERIFIKASI: RS->KMB->TMB, alur PARALEL
        // TERPISAH dari PBUSTATUS 0-9 di atas - PBUSTATUS PR jenis ini TIDAK PERNAH
        // berubah (tetap 0 selamanya), progres dilacak murni dari PBUSTATUSKM + status
        // KMB terkait (lihat docblock `KmbWriter`/`PurchaseRequestWriter::pullableForKmb()`,
        // keputusan 2026-09-21). $statusKm=0 -> belum ditarik, =1 dgn $kmbStatus=1 ->
        // sedang dikirim KMB, =1 dgn $kmbStatus=3 -> sudah diterima TMB.
        $kmTmbBadge = function ($statusKm, $kmbStatus) {
            if ((int) $statusKm === 0) {
                return ['text-bg-secondary', 'Belum Ditarik'];
            }

            return (int) $kmbStatus === 3
                ? ['text-bg-success', 'Diterima (TMB)']
                : ['text-bg-info', 'Dikirim (KMB)'];
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 240px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no / karyawan / uraian"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 160px" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="0">Belum Verifikasi</option>
                    <option value="1">Pending</option>
                    <option value="2">Disetujui</option>
                    <option value="3">Perintah Kirim</option>
                    <option value="4">Sedang Dikirim</option>
                    <option value="5">Progress Diterima Cabang</option>
                    <option value="6">Selesai Diterima Cabang</option>
                    <option value="7">Konfirmasi Bag Pembelian</option>
                    <option value="9">Batal</option>
                </select>
                <select class="form-select form-select-sm" style="max-width: 180px" wire:model.live="fCabang"
                        title="Cabang peminta">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                {{-- Cabang Tujuan = PBUGUDANGSUMBER (kolom "Gudang Asal" di tabel) - lihat
                     docblock `PrList::$fGudangTujuan`. --}}
                <select class="form-select form-select-sm" style="max-width: 190px" wire:model.live="fGudangTujuan"
                        title="Cabang tujuan (gudang yang diminta mengirim)">
                    <option value="">Semua cabang tujuan</option>
                    @foreach ($branchesTujuan as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
                <div class="d-flex gap-2">
                    <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fFrom">
                    <input type="date" class="form-control form-control-sm" style="max-width: 150px" wire:model.live="fTo">
                </div>
            </div>
            @if (can_do('inventory/pr', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="newPr"><i class="fas fa-plus me-1"></i> Permintaan Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Nomor</th><th>Tanggal</th><th>Karyawan</th><th>Cabang</th><th>Tujuan</th><th>Gudang Asal</th><th>Jenis</th>
                    <th class="text-center">Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php
                            $isJenis0 = (int) $r->jenis === 0;
                            // "Batal" (PBUSTATUS=9) SELALU menang, TERLEPAS dari jenis - $kmTmbBadge
                            // (badge KMB/TMB jenis=0) TIDAK PERNAH mengecek PBUSTATUS sama sekali,
                            // jadi PR jenis=0 yg dibatalkan tetap salah nampilin badge progres lama
                            // (mis. "Belum Ditarik") kalau tidak dicek di sini dulu (2026-09-24,
                            // ditemukan user dari data nyata PG-RS26090044 yg sudah dibatalkan).
                            [$bg, $lbl] = match (true) {
                                (int) $r->status === 9 => ['text-bg-danger', 'Batal'],
                                $isJenis0 => $kmTmbBadge($r->statusKm, $r->kmbStatus),
                                default => $statusBadge($r->status),
                            };
                        @endphp
                        <tr wire:key="pr-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td class="text-muted small">{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->karyawan ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->cabang ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->tujuan ?: '—' }}</td>
                            <td class="text-muted small">{{ $r->gudangAsal ?: '—' }}</td>
                            <td class="small">{{ (int) $r->jenis === 1 ? 'Permintaan Pembelian' : 'Permintaan Barang' }}</td>
                            <td class="text-center"><span class="badge {{ $bg }}">{{ $lbl }}</span></td>
                            <td class="text-end text-nowrap">
                                @if (can_do('inventory/pr', 'view'))
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="editPr({{ $r->id }}, @js($r->nomor))" title="Buka">
                                        <i class="fas fa-{{ ! $isJenis0 && (int) $r->status === 0 ? 'pen' : 'eye' }}"></i>
                                    </button>
                                @endif
                                {{-- Histori - alur otomatis sesuai jenis: jenis=1 PKB/SJ/PBC,
                                     jenis=0 KMB/TMB (lihat PrList::openHistory()). --}}
                                @if (can_do('inventory/pr', 'view'))
                                    <button class="btn btn-outline-info btn-sm" wire:click="openHistory({{ $r->id }})"
                                            title="{{ $isJenis0 ? 'Histori KMB / TMB' : 'Histori PKB / Surat Jalan / PBC' }}">
                                        <i class="fas fa-timeline"></i>
                                    </button>
                                @endif
                                {{-- Jenis=0 (Permintaan Barang) TANPA VERIFIKASI - tombol Verifikasi
                                     HANYA relevan utk jenis=1, lihat docblock $kmTmbBadge di atas. --}}
                                @if (! $isJenis0 && (int) $r->status === 0 && can_do('inventory/pr', 'approve'))
                                    <button class="btn btn-outline-success btn-sm" wire:click="openVerify({{ $r->id }})" title="Verifikasi">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                @endif
                                {{-- Batalkan Verifikasi - HANYA status "Disetujui" (2) DAN belum
                                     ada PKB yg menarik (`hasPkb`, ground-truth EXISTS - lihat
                                     gotcha panjang di docblock PurchaseRequestWriter::unverify())
                                     - permintaan user 2026-09-24. --}}
                                @if (! $isJenis0 && (int) $r->status === 2 && ! $r->hasPkb && can_do('inventory/pr', 'approve'))
                                    <button class="btn btn-outline-warning btn-sm" wire:click="unverifyPr({{ $r->id }})"
                                            data-confirm="Batalkan verifikasi {{ $r->nomor }}? Status kembali ke Belum Verifikasi." title="Batalkan Verifikasi">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                @endif
                                @if (can_do('inventory/pr', 'print'))
                                    <button class="btn btn-outline-primary btn-sm" wire:click="printPr({{ $r->id }})" title="Cetak">
                                        <i class="fas fa-print"></i>
                                    </button>
                                @endif
                                @if ((int) $r->status === 0 && (int) $r->statusKm === 0 && can_do('inventory/pr', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan permintaan {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada permintaan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showVerify)
        <x-lw-modal :show="$showVerify" title="Verifikasi Permintaan" close="$set('showVerify', false)">
            <form wire:submit="saveVerify">
                <div class="modal-body">
                    <p class="mb-2">Permintaan <span class="fw-semibold">{{ $verifyNomor }}</span></p>
                    <div class="mb-3">
                        <label class="form-label">Keputusan</label>
                        <select class="form-select" wire:model.live="verifyStatus">
                            <option value="2">Setujui</option>
                            <option value="1">Pending (butuh perbaikan)</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan {{ (int) $verifyStatus === 1 ? '(wajib)' : '(opsional)' }}</label>
                        <textarea class="form-control" rows="2" wire:model="verifyCatatan"></textarea>
                        @error('verifyCatatan') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="$set('showVerify', false)">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-lw-modal>
    @endif

    @if ($showHistory)
        <x-lw-modal :show="$showHistory"
                    title="{{ $historyMode === 'mutasi' ? 'Histori KMB / TMB' : 'Histori PKB / Surat Jalan / PBC' }} — {{ $historyNomor }}"
                    close="closeHistory">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto">
                @forelse ($historyLines as $l)
                    <div class="mb-3 pb-2 {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold">{{ $l['kode'] }} — {{ $l['nama'] }}</span>
                            <span class="text-muted small">
                                Diminta {{ rtrim(rtrim(number_format($l['qtyDiminta'], 2), '0'), '.') }}
                                / Dikirim {{ rtrim(rtrim(number_format($l['qtyDitarik'], 2), '0'), '.') }}
                                {{ $l['satuan'] }}
                            </span>
                        </div>

                        @if ($historyMode === 'mutasi')
                            {{-- Alur jenis=0: PR -> KMB -> TMB --}}
                            @forelse ($l['kmb'] as $kmb)
                                <div class="ms-3 mt-2 ps-2 border-start">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="fas fa-truck-ramp-box me-1 text-muted"></i>
                                            <span class="fw-semibold">{{ $kmb['nomor'] }}</span>
                                            <span class="text-muted small">{{ \Carbon\Carbon::parse($kmb['tanggal'])->format('d/m/Y') }}</span>
                                            @if ($kmb['batal'])
                                                <span class="badge text-bg-danger">Batal</span>
                                            @elseif ($kmb['diterima'])
                                                <span class="badge text-bg-success">Diterima</span>
                                            @endif
                                        </span>
                                        <span class="text-muted small">
                                            @if ($kmb['qty'] === null)
                                                <span title="Detail item dokumen ini belum ada di database">Qty —</span>
                                            @else
                                                Qty {{ rtrim(rtrim(number_format($kmb['qty'], 2), '0'), '.') }}
                                            @endif
                                        </span>
                                    </div>

                                    @forelse ($kmb['tmb'] as $tmb)
                                        <div class="ms-3 mt-1 ps-2 border-start small">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span>
                                                    <i class="fas fa-dolly me-1 text-muted"></i>
                                                    {{ $tmb['nomor'] }}
                                                    <span class="text-muted">{{ \Carbon\Carbon::parse($tmb['tanggal'])->format('d/m/Y') }}</span>
                                                    @if ($tmb['batal'])
                                                        <span class="badge text-bg-danger">Batal</span>
                                                    @endif
                                                </span>
                                                <span class="text-muted">
                                                    @if ($tmb['qty'] === null)
                                                        <span title="Detail item dokumen ini belum ada di database">Qty —</span>
                                                    @else
                                                        Qty {{ rtrim(rtrim(number_format($tmb['qty'], 2), '0'), '.') }}
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="ms-3 mt-1 ps-2 border-start small text-muted">Belum diterima cabang (TMB).</div>
                                    @endforelse
                                </div>
                            @empty
                                <div class="ms-3 mt-2 text-muted small">Belum ditarik ke KMB manapun.</div>
                            @endforelse
                        @else
                        {{-- Alur jenis=1: PR -> PKB -> SJ -> PBC --}}
                        @forelse ($l['pkb'] as $pkb)
                            <div class="ms-3 mt-2 ps-2 border-start">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas fa-dolly-flatbed me-1 text-muted"></i>
                                        <span class="fw-semibold">{{ $pkb['nomor'] }}</span>
                                        <span class="text-muted small">{{ \Carbon\Carbon::parse($pkb['tanggal'])->format('d/m/Y') }}</span>
                                        @if ($pkb['batal'])
                                            <span class="badge text-bg-danger">Batal</span>
                                        @endif
                                    </span>
                                    <span class="text-muted small">Qty {{ rtrim(rtrim(number_format($pkb['qty'], 2), '0'), '.') }}</span>
                                </div>

                                @forelse ($pkb['sj'] as $sj)
                                    <div class="ms-3 mt-1 ps-2 border-start small">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>
                                                <i class="fas fa-truck-fast me-1 text-muted"></i>
                                                {{ $sj['nomor'] }}
                                                <span class="text-muted">{{ \Carbon\Carbon::parse($sj['tanggal'])->format('d/m/Y') }}</span>
                                                @if ($sj['batal'])
                                                    <span class="badge text-bg-danger">Batal</span>
                                                @endif
                                            </span>
                                            <span class="text-muted">Qty {{ rtrim(rtrim(number_format($sj['qty'], 2), '0'), '.') }}</span>
                                        </div>

                                        @forelse ($sj['pbc'] as $pbc)
                                            <div class="ms-3 mt-1 ps-2 border-start">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span>
                                                        <i class="fas fa-box-open me-1 text-muted"></i>
                                                        {{ $pbc['nomor'] }}
                                                        <span class="text-muted">{{ \Carbon\Carbon::parse($pbc['tanggal'])->format('d/m/Y') }}</span>
                                                        @if ($pbc['batal'])
                                                            <span class="badge text-bg-danger">Batal</span>
                                                        @endif
                                                    </span>
                                                    <span class="text-muted">
                                                        @if ($pbc['qty'] === null)
                                                            <span title="Detail item dokumen ini belum ada di database">Qty —</span>
                                                        @else
                                                            Qty {{ rtrim(rtrim(number_format($pbc['qty'], 2), '0'), '.') }}
                                                        @endif
                                                    </span>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="ms-3 mt-1 ps-2 border-start text-muted">Belum diterima cabang (PBC).</div>
                                        @endforelse
                                    </div>
                                @empty
                                    <div class="ms-3 mt-1 ps-2 border-start small text-muted">Belum ada Surat Jalan.</div>
                                @endforelse
                            </div>
                        @empty
                            <div class="ms-3 mt-2 text-muted small">Belum ditarik ke PKB manapun.</div>
                        @endforelse
                        @endif
                    </div>
                @empty
                    <div class="text-center text-muted py-4">Tidak ada item.</div>
                @endforelse
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeHistory">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
