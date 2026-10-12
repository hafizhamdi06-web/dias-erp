<div>
    <div class="card vb-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="card-title mb-0">
                Pengeluaran Lain
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
                @if ($plId && can_do('inventory/pengeluaran-lain', 'print'))
                    <a href="{{ route('inventory.pengeluaran-lain.print', $plId) }}" target="_blank"
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
                 Tata letak MENGIKUTI form VB6 `fFrmPengeluaranLain.frm` - dipastikan lewat
                 `amenu` MID **711** "Pengeluaran Lain" -> `aMod_Menu.bas`
                 `Case 711: Set xForm = fFrmPengeluaranLain`. Gaya di `public/css/vb-form.css`.

                 Bentuknya kembar dgn `fFrmPenyesuaianBarang.frm`, bedanya cuma label jenisnya
                 ("Jenis Pengeluaran", bukan "Jenis Penyesuaian") dan tidak ada kotak "No PBO".
                 Urutan field kiri menurut koordinat Top VB6: 675 Kontak | 1020 Gudang |
                 1560 Jenis Pengeluaran | 1845 Divisi | 2550 Keterangan.
                 Kotak kanan: (10080,750) Tanggal | (12135,750) No Transaksi.

                 Tidak ikut krn datanya belum ada di sini: **Divisi**, **No Permintaan**,
                 **No. Reff**.
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
                            <label class="form-label">Jenis Pengeluaran <span class="text-danger">*</span></label>
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

                <div class="text-muted small mt-1">Semua baris mengurangi stok gudang di atas.</div>

                {{-- ---- Baris item ----
                     Susunan kolom = `FormatString` VB6 (dari .frx), SAMA dgn Penyesuaian Barang:
                     <Kode|<Nama|>Qty Masuk|>Qty Keluar|<Satuan|>Harga Masuk|<Catatan|…

                     "Qty Masuk" tidak ikut: di dokumen PENGELUARAN nilainya selalu 0. Kolom
                     berbasis harga & batch juga tidak - lihat catatan di form Penyesuaian. --}}
                <div class="table-responsive vb-grid-area mt-2">
                    <table class="table table-sm table-bordered align-middle vb-grid">
                        <thead>
                            <tr>
                                <th style="width: 20%">Kode</th>
                                <th>Nama</th>
                                <th class="text-end" style="width: 110px">Qty Keluar</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 26%">Catatan</th>
                                @unless ($locked) <th style="width: 36px"></th> @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="pl-line-{{ $i }}">
                                    <td>{{ $l['kode'] }}</td>
                                    <td>{{ $l['nama'] }}</td>
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
                                <tr><td colspan="{{ $locked ? 5 : 6 }}" class="text-center text-muted py-4">
                                    Belum ada item — cari dan tambahkan di bawah.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- VB6: Total Qty Keluar & Total Nilai Keluar. Yg Nilai tidak ikut - form ini
                     tidak memegang harga. --}}
                <div class="vb-total">
                    <span>Total Qty Keluar</span>
                    <span class="nilai">{{ number_format($jumlahKeluar, 2, ',', '.') }}</span>
                </div>

                @unless ($locked)
                    {{-- tambah item --}}
                    <div class="mt-3" style="max-width: 460px">
                        <label class="form-label mb-1 small text-muted">Tambah item</label>
                        <input type="text" class="form-control form-control-sm" placeholder="ketik kode / nama item…"
                               wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        @if (count($items))
                            <div class="list-group mt-1" style="max-height: 240px; overflow-y:auto">
                                @foreach ($items as $it)
                                    <button type="button" class="list-group-item list-group-item-action py-1"
                                            wire:click="addItem({{ $it->IID }})">
                                        <span class="font-monospace small">{{ $it->IKODE }}</span> — {{ $it->INAMA }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless
            </div>
        </div>
    </div>
</div>
