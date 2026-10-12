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
        // Wording & nilai disamakan PERSIS dgn VB6 `fFrmPermintaanBarangData.frm` baris 642:
        //   case PKBUSTATUS when 0 then 'Belum Proses' when 1 then 'Dalam Proses'
        //                   when 2 then 'Selesai' end
        //
        // SEBELUM 2026-10-10 peta ini cuma mengenal 0 & 9, padahal di data nyata ada
        // 257 dari 1.103 PKB ber-PKBUSTATUS=1 - SELURUHNYA tampil "-" di daftar.
        // Status 2 belum muncul di data, tapi ikut dipetakan karena VB6 mengenalnya.
        // (9 = Batal BUKAN dari VB6 - `case`-nya menghasilkan NULL utk 9 - tapi itu
        // konvensi kita sendiri, lihat docblock `PkbWriter`.)
        $statusBadge = fn ($s) => match ((int) $s) {
            0 => ['text-bg-secondary', 'Belum Proses'],
            1 => ['text-bg-info', 'Dalam Proses'],
            2 => ['text-bg-success', 'Selesai'],
            9 => ['text-bg-danger', 'Batal'],
            default => ['text-bg-light', '-'],
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2 vb-filter">
                <div class="input-group input-group-sm f-cari">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no PKB / diperintah / no PR"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm f-pilih-lebar" wire:model.live="fStatus">
                    <option value="">Semua status</option>
                    <option value="0">Belum Proses</option>
                    <option value="1">Dalam Proses</option>
                    <option value="2">Selesai</option>
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
            @if (can_do('purchase/pkb', 'add'))
                {{-- SATU tombol saja (permintaan user 2026-10-10). PR sumbernya dipilih DI
                     DALAM form, lewat tombol cari di kotak "No PR Asal". --}}
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
                    <th>Nomor</th><th>Tanggal</th><th>Diperintah Oleh</th><th>Cabang</th><th>No PR Asal</th>
                    <th>Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$bg, $lbl] = $statusBadge($r->status); @endphp
                        <tr wire:key="pkb-{{ $r->id }}">
                            <td>{{ $r->nomor }}</td>
                            <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ $r->diperintah ?: '—' }}</td>
                            <td>{{ $r->cabang ?: '—' }}</td>
                            <td>{{ $r->noPr ?: '—' }}</td>
                            <td><x-status-teks :bg="$bg" :teks="$lbl" /></td>
                            <td class="text-end text-nowrap">
                                @if (can_do('purchase/pkb', 'print'))
                                    <a href="{{ route('purchase.pkb.print', $r->id) }}" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Cetak Perintah Kirim Barang">
                                        <i class="fas fa-print"></i>
                                    </a>
                                @endif
                                <button class="btn btn-outline-secondary btn-sm" wire:click="editPkb({{ $r->id }}, @js($r->nomor))" title="Buka">
                                    <i class="fas fa-{{ (int) $r->status === 0 ? 'pen' : 'eye' }}"></i>
                                </button>
                                @if ((int) $r->status === 0 && can_do('purchase/pkb', 'delete'))
                                    <button class="btn btn-outline-danger btn-sm" wire:click="cancel({{ $r->id }})"
                                            data-confirm="Batalkan PKB {{ $r->nomor }}?" title="Batalkan"><i class="fas fa-ban"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada PKB.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

</div>
