<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $prId ? 'Permintaan ' . $nomor : 'Permintaan Barang Baru' }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci (sudah verifikasi)</span> @endif
                </span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @if ($prId && can_do('inventory/pr', 'print'))
                        <button type="button" class="btn btn-outline-primary btn-sm" wire:click="printPr">
                            <i class="fas fa-print me-1"></i> Cetak
                        </button>
                    @endif
                    @if (! $locked && can_do('inventory/pr', $prId ? 'edit' : 'add'))
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                {{-- Wording 3-7 disamakan PERSIS dgn label legacy VB6
                     (fFrmPermintaanbarangData.frm::IsiData(), CASE PBUSTATUS) per
                     permintaan user 2026-09-18 - status 4-7 blm pernah tampil di v1
                     sblm ini (baru kelihatan skrg krn data hasil import beneran
                     mengandung status lanjutan itu, v1 sblmnya cuma pernah test 0/1/2/9). --}}
                @php
                    $statusBadge = match ($status) {
                        1 => ['text-bg-warning', 'Pending'],
                        2 => ['text-bg-success', 'Disetujui'],
                        3 => ['text-bg-info', 'Perintah Kirim'],
                        4 => ['text-bg-info', 'Sedang Dikirim'],
                        5 => ['text-bg-primary', 'Progress Diterima Cabang'],
                        6 => ['text-bg-success', 'Selesai Diterima Cabang'],
                        7 => ['text-bg-primary', 'Konfirmasi Bag Pembelian'],
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-secondary', 'Belum Verifikasi'],
                    };
                @endphp

                <style>
                    .pr-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .pr-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .pr-field > .pr-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    {{-- ===== Kolom kiri: isi permintaan ===== --}}
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="pr-field">
                                <label class="form-label">Nama Karyawan</label>
                                <div class="pr-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $karyawanLabel ?: '—' }}</span>
                                    <div class="form-text">{{ $prId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            {{-- Urutan baris: Nama Karyawan > Depo/Farmasi > Tujuan > Gudang
                                 Tujuan > Tipe Permintaan > Keterangan (permintaan user
                                 2026-09-25) - murni urutan tampilan, logika/binding tiap
                                 field TIDAK diubah. --}}
                            <div class="pr-field">
                                <label class="form-label">Depo / Farmasi</label>
                                <div class="pr-input">
                                    <select class="form-select form-select-sm" wire:model="gudang" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">{{ $prId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}</div>
                                    @error('gudang') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="pr-field">
                                <label class="form-label">Tujuan <span class="text-danger">*</span></label>
                                <div class="pr-input">
                                    <select class="form-select form-select-sm" wire:model.live="tujuan">
                                        <option value="">— pilih —</option>
                                        @foreach ($tujuanList as $t)
                                            <option value="{{ $t->lid }}">{{ $t->lnama }}</option>
                                        @endforeach
                                    </select>
                                    @error('tujuan') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="pr-field">
                                <label class="form-label">Gudang Tujuan (PO Ke) <span class="text-danger">*</span></label>
                                <div class="pr-input">
                                    <select class="form-select form-select-sm" wire:model="gudangSumber" @disabled($locked || $gudangSumberLocked)>
                                        <option value="">— pilih —</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    @if ($gudangSumberLocked && ! $locked)
                                        <div class="form-text">Otomatis sesuai Tujuan yang dipilih, tidak bisa diubah.</div>
                                    @endif
                                    @error('gudangSumber') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="pr-field">
                                <label class="form-label">Tipe Permintaan</label>
                                <div class="pr-input">
                                    <span class="badge text-bg-info fs-6">{{ $jenis === 1 ? 'Permintaan Pembelian' : 'Permintaan Barang' }}</span>
                                    <div class="form-text">Otomatis mengikuti Tujuan yang dipilih, tidak bisa diubah manual.</div>
                                </div>
                            </div>
                            <div class="pr-field">
                                <label class="form-label">Keterangan</label>
                                <div class="pr-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== Kolom kanan: info dokumen ===== --}}
                    <div class="col-md-6">
                        <div class="pr-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="pr-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="pr-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="pr-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="pr-field">
                            <label class="form-label">Status</label>
                            <div class="pr-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                        @if ($status !== 0)
                            <div class="pr-field">
                                <label class="form-label">Catatan Verifikasi</label>
                                <div class="pr-input">
                                    <textarea class="form-control form-control-sm bg-body-secondary" rows="2" disabled>{{ $catatanVerifikasi ?: '—' }}</textarea>
                                </div>
                            </div>
                            <div class="pr-field">
                                <label class="form-label"></label>
                                <div class="pr-input text-muted small">
                                    <i class="fas fa-user-check me-1"></i>
                                    Diverifikasi oleh <span class="fw-semibold">{{ $verifiedBy ?: '—' }}</span>
                                    @if ($verifiedAt)
                                        pada {{ \Carbon\Carbon::parse($verifiedAt)->translatedFormat('d F Y') }}
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ---- Baris item ---- --}}
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    @unless ($locked)
                        <div class="position-relative" style="max-width: 420px; flex: 1 1 auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-plus"></i></span>
                                <input type="text" class="form-control" placeholder="Cari item untuk ditambahkan…"
                                       wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                            </div>
                            @if (count($itemResults))
                                <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                    @foreach ($itemResults as $r)
                                        <button type="button" wire:key="ir-{{ $r->id }}"
                                                class="list-group-item list-group-item-action small"
                                                wire:click="addItem({{ $r->id }})">
                                            <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div></div>
                    @endif
                    {{-- Real Stok default DISEMBUNYIKAN - user isi Qty dari hitungan manual
                         sendiri, bukan nyontek stok sistem. Toggle ini KHUSUS verifikator
                         (`can_do('inventory/pr','approve')` - SAMA persis hak yg dipakai
                         tombol Verifikasi/notif lonceng, permintaan user 2026-09-24, BUKAN
                         "siapa pun" lagi spt niat awal). --}}
                    @if (can_do('inventory/pr', 'approve'))
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="toggleStok">
                            <i class="fas fa-{{ $showStok ? 'eye-slash' : 'eye' }} me-1"></i>
                            {{ $showStok ? 'Sembunyikan' : 'Tampilkan' }} Stok Sistem
                        </button>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 34%">Item</th>
                                <th class="text-center" style="width: 110px">Qty</th>
                                <th style="width: 90px">Satuan</th>
                                <th class="text-end" style="width: 100px">Stok</th>
                                @if ($showStok)
                                    <th class="text-end" style="width: 100px">Real Stok</th>
                                @endif
                                <th>Catatan</th>
                                <th style="width: 36px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $l['nama'] }}</div>
                                        <div class="text-muted small">{{ $l['kode'] }}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live.debounce.400ms="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live.debounce.400ms="lines.{{ $i }}.stokManual" @disabled($locked)>
                                    </td>
                                    @if ($showStok)
                                        <td class="text-end">{{ rtrim(rtrim(number_format($l['stok'], 2), '0'), '.') }}</td>
                                    @endif
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" wire:click="removeLine({{ $i }})">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $showStok ? 7 : 6 }}" class="text-center text-muted py-4">Belum ada item.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end">Total Qty</td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="{{ $showStok ? 5 : 4 }}"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>
