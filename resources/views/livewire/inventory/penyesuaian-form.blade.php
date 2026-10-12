<div>
    <div class="card vb-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="card-title mb-0">
                Penyesuaian Barang
                @if ($nomor)
                    <span>— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge {{ $status === 9 ? 'text-bg-danger' : 'text-bg-success' }} ms-2">
                        {{ $status === 9 ? 'Batal' : 'Aktif' }}
                    </span>
                @endif
            </div>
            <div>
                @unless ($locked)
                    <button type="button" class="btn btn-success btn-sm" wire:click="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                @endunless
                @if ($pyId && can_do('inventory/adjust', 'print'))
                    <a href="{{ route('inventory.adjust.print', $pyId) }}" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-print me-1"></i> Cetak
                    </a>
                @endif
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                    <i class="fas fa-xmark me-1"></i> Tutup
                </button>
            </div>
        </div>

        <div class="card-body">
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            {{-- ============================================================================
                 Tata letak MENGIKUTI form VB6 `fFrmPenyesuaianBarang.frm` - dipastikan lewat
                 `amenu` MID **273** "Penyesuaian Barang" -> `aMod_Menu.bas`
                 `Case 273: Set xForm = fFrmPenyesuaianBarang`. (MID 277 = `...BarangData`,
                 itu layar DAFTAR-nya; MID 740 = `...BarangDEPO`, varian lain.)
                 Gaya di `public/css/vb-form.css`.

                 Urutan field kiri menurut koordinat Top VB6: 675 Kontak | 1020 Gudang |
                 1560 Jenis Penyesuaian | 1845 Divisi | 2550 Keterangan.
                 **Jenis Penyesuaian di VB6 ada di kolom KIRI**, bukan kanan spt sebelumnya.
                 Kotak kanan: (10080,750) Tanggal | (12135,750) No Transaksi.

                 Tidak ikut krn datanya belum ada di sini: **Divisi**, dan kotak kanan bawah
                 **No PBO** / **No Permintaan** / **No. Reff**.
                 ============================================================================ --}}
            <div class="vb-form">
                <div class="row g-3">
                    {{-- ===== Kolom kiri ===== --}}
                    <div class="col-lg-7">
                        <div class="vb-row">
                            <label class="form-label">Kontak</label>
                            <div class="vb-ctl">
                                @if ($locked)
                                    <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                           value="{{ $kontakLabel ?: '—' }}">
                                @else
                                    <x-search-select model="kontak" :value="$kontak" :selected-text="$kontakLabel"
                                                     :endpoint="route('lookup.kontak')" placeholder="cari kontak…" />
                                @endif
                            </div>
                        </div>
                        <div class="vb-row">
                            <label class="form-label">Gudang</label>
                            {{-- Selalu cabang user login, tidak bisa diubah. --}}
                            <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary" disabled
                                   value="{{ $gudangLabel ?: '—' }}"
                                   title="Otomatis cabang Anda, tidak bisa diubah.">
                        </div>
                        @error('gudang') <div class="vb-err text-danger small">{{ $message }}</div> @enderror

                        <div class="vb-row">
                            <label class="form-label">Jenis Penyesuaian <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm vb-ctl @error('jenis') is-invalid @enderror"
                                    wire:model="jenis" {{ $locked ? 'disabled' : '' }}>
                                <option value="">— pilih —</option>
                                @foreach ($jenisList as $j)
                                    <option value="{{ $j->JID }}">{{ $j->JNAMA ?: $j->JKODE }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('jenis') <div class="vb-err text-danger small">{{ $message }}</div> @enderror
                    </div>

                    {{-- ===== Kolom kanan: kotak ber-caption ===== --}}
                    <div class="col-lg-5">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="vb-box">
                                    <div class="cap">Tanggal</div>
                                    <div class="isi">
                                        <input type="date" class="form-control form-control-sm border-0 p-0 px-1 @error('tanggal') is-invalid @enderror"
                                               wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                                    </div>
                                </div>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6">
                                <div class="vb-box">
                                    <div class="cap">No Transaksi</div>
                                    <div class="isi">
                                        <input type="text" class="form-control form-control-sm border-0 p-0 px-1" disabled
                                               value="{{ $nomor ?: '[otomatis]' }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- VB6: txtKeterangan lebarnya 12060 twip, membentang di bawah kedua kolom.
                     Label VB6-nya "Keterangan" (sebelumnya di sini tertulis "Uraian"). --}}
                <div class="vb-row mt-2">
                    <label class="form-label">Keterangan</label>
                    <input type="text" class="form-control form-control-sm vb-ctl-full" wire:model="uraian"
                           {{ $locked ? 'disabled' : '' }}>
                </div>

                {{-- Kotak tambah item di ATAS grid, sebentuk dgn form Permintaan Barang
                     (permintaan user 2026-10-10) - sebelumnya di bawah grid, terpisah jauh
                     dari tabel yg diisinya. --}}
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-3 mb-1">
                    @unless ($locked)
                        <div class="position-relative" style="max-width: 420px; flex: 1 1 auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-plus"></i></span>
                                <input type="text" class="form-control" placeholder="Cari item untuk ditambahkan…"
                                       wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                            </div>
                            @if (count($items))
                                <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                    @foreach ($items as $it)
                                        <button type="button" wire:key="py-it-{{ $it->IID }}"
                                                class="list-group-item list-group-item-action small"
                                                wire:click="addItem({{ $it->IID }})">
                                            <span class="fw-semibold">{{ $it->IKODE }}</span> — {{ $it->INAMA }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div></div>
                    @endunless
                </div>

                {{-- ---- Baris item ----
                     Susunan kolom = `FormatString` VB6 (dari .frx):
                     <Kode|<Nama|>Qty Masuk|>Qty Keluar|<Satuan|>Harga Masuk|<Catatan|QtyDasar|
                     SatuanDasar|Konversi|>Harga Jual|>Jumlah Masuk|>Jumlah Keluar|<Pakai Batch|<No Batch

                     Tidak ikut: kolom simpanan tak kasatmata (QtyDasar/SatuanDasar/Konversi),
                     kolom berbasis harga (Harga Masuk/Harga Jual/Jumlah Masuk/Jumlah Keluar -
                     penyesuaian di sini tidak memegang harga), dan Pakai Batch/No Batch
                     (batch belum ditangani modul ini). --}}
                <div class="table-responsive vb-grid-area">
                    <table class="table table-sm table-bordered align-middle vb-grid">
                        <thead>
                            <tr>
                                <th style="width: 20%">Kode</th>
                                <th>Nama</th>
                                <th class="text-end" style="width: 110px">Qty Masuk</th>
                                <th class="text-end" style="width: 110px">Qty Keluar</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 22%">Catatan</th>
                                @unless ($locked) <th style="width: 36px"></th> @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="py-line-{{ $i }}">
                                    <td>{{ $l['kode'] }}</td>
                                    <td>{{ $l['nama'] }}</td>
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                               class="form-control form-control-sm text-end"
                                               wire:model="lines.{{ $i }}.masuk" {{ $locked ? 'disabled' : '' }}>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                               class="form-control form-control-sm text-end"
                                               wire:model="lines.{{ $i }}.keluar" {{ $locked ? 'disabled' : '' }}>
                                    </td>
                                    <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" {{ $locked ? 'disabled' : '' }}>
                                    </td>
                                    @unless ($locked)
                                        <td class="text-center">
                                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                    wire:click="removeLine({{ $i }})" title="Hapus baris">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr><td colspan="{{ $locked ? 6 : 7 }}" class="text-center text-muted py-4">
                                    Belum ada item — cari dan tambahkan di bawah.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- VB6 menaruh DUA total ini berdampingan di bawah grid (Total Qty Masuk di
                     Left 1425, Total Qty Keluar di bawahnya). Total Nilai Masuk/Keluar tidak
                     ikut - lihat catatan kolom berbasis harga di atas. --}}
                <div class="vb-total">
                    <span>Total Qty Masuk</span>
                    <span class="nilai">{{ number_format($jumlahMasuk, 2, ',', '.') }}</span>
                    <span>Total Qty Keluar</span>
                    <span class="nilai">{{ number_format($jumlahKeluar, 2, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
