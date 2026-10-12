<div>
    <div class="card vb-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="card-title mb-0">
                <strong>Penerimaan Barang (PB)</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge {{ $status === 9 ? 'text-bg-danger' : 'text-bg-success' }} ms-2">
                        {{ $status === 9 ? 'Batal' : 'Aktif' }}
                    </span>
                @endif
            </div>
            <div>
                @if ($pbId && can_do('purchase/receipt', 'print'))
                    <a href="{{ route('purchase.pb.print', $pbId) }}" target="_blank"
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
                 Tata letak MENGIKUTI form VB6 `fFrmPenerimaanBarangPBDepo.frm` ("Penerimaan
                 Barang Supplier Di Depo") - `amenu` MID **729** -> `aMod_Menu.bas`
                 `Case 729: Set xForm = fFrmPenerimaanBarangPBDepo`. MID-nya DIPASTIKAN KE
                 USER 2026-10-11.

                 AWAS jangan tertukar: MID 234 & 443 sama-sama menunjuk `fFrmPenerimaanBarang`,
                 dan form itu caption-nya **"Retur Barang"** dgn grid Qty Masuk/Qty Keluar -
                 bukan layar penerimaan PO sama sekali.

                 Gaya di `public/css/vb-form.css`; blade ini dulu punya blok gaya inline
                 sendiri (kelas `pb-field`/`pb-top`).

                 Urutan field kiri menurut Top VB6: 675 Kontak | 1020 Gudang | 2550 Keterangan
                 Kanan baris 1 (750): (10080) Tanggal | (12135) No Transaksi
                 Kanan baris 2 (~1600): (10080) No PO | (12135) No. Invoice
                 Grid Top 3120. Bawah: Total Qty Masuk / Total Qty Keluar.

                 Caption VB6 di atas kotak tanggal lagi-lagi salah ketik "No Transaksi" (sama
                 spt KMB/TMB/PY/PL) - ditulis "Tanggal" yg benar.

                 Tidak ikut: centang **Pending** (`Visible = 0` di VB6, sudah dicek). --}}
            @php
                $noPoList = collect($lines)->pluck('noPo')->filter()->unique()->values();
            @endphp

            <div class="vb-form">
            <div class="row g-3">
                {{-- ===== KIRI: Kontak & Gudang ===== --}}
                <div class="col-lg-6">
                    <div class="vb-row">
                        <label class="form-label">Kontak</label>
                        <input type="text" class="form-control form-control-sm vb-ctl-wide" disabled
                               value="{{ $vendorLabel }}" placeholder="otomatis dari PO yang ditarik…">
                    </div>
                    @error('vendor') <div class="text-danger small vb-err">{{ $message }}</div> @enderror
                    <div class="vb-row">
                        <label class="form-label">Gudang</label>
                        {{-- Selalu cabang user login, tidak bisa diubah. --}}
                        <input type="text" class="form-control form-control-sm vb-ctl-wide" disabled
                               value="{{ $gudangLabel ?: '—' }}">
                    </div>
                    @error('gudang') <div class="text-danger small vb-err">{{ $message }}</div> @enderror
                </div>

                {{-- ===== KANAN: kotak ber-caption, spt SSPanel VB6 ===== --}}
                <div class="col-lg-6">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="vb-box">
                                <div class="cap">Tanggal</div>
                                <div class="isi">
                                    <input type="date" class="form-control form-control-sm @error('tanggal') is-invalid @enderror"
                                           wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="vb-box">
                                <div class="cap">No Transaksi</div>
                                <div class="isi">
                                    <input type="text" class="form-control form-control-sm" disabled
                                           value="{{ $nomor ?: '[otomatis]' }}">
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="vb-box">
                                <div class="cap">No PO</div>
                                <div class="isi">
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" disabled
                                               value="{{ $noPoList->implode(', ') }}"
                                               title="{{ $noPoList->implode(', ') }}">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-outline-secondary" wire:click="openPicker"
                                                    title="Tarik dari PO">
                                                <i class="fas fa-magnifying-glass"></i>
                                            </button>
                                        @endunless
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="vb-box">
                                <div class="cap">No. Invoice</div>
                                <div class="isi">
                                    <input type="text" class="form-control form-control-sm" wire:model="noReff"
                                           {{ $locked ? 'disabled' : '' }}>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="vb-row">
                        <label class="form-label">Keterangan</label>
                        <input type="text" class="form-control form-control-sm vb-ctl-full" wire:model="catatan"
                               {{ $locked ? 'disabled' : '' }}>
                    </div>
                </div>
            </div>

            @unless ($locked)
                <button type="button" class="btn btn-outline-primary btn-sm mb-2" wire:click="openPicker">
                    <i class="fas fa-truck-ramp-box me-1"></i> Tarik dari PO
                </button>
            @endunless

            {{-- Susunan kolom = `FormatString` VB6:
                 <Kode|<Nama|>Qty PO|>Qty Terima|<Satuan|>Harga|<Catatan|ID POdetail|>Disc|
                 >Disc % 1|>Disc % 2|>Disc % 3|>Cabang|<No PO|<Pakai Batch|<No Batch|<Kode|
                 <Id PO Header|>Qty Depo|<Satuan Depo|>Konversi|>Qty Klinik|<Satuan Klinik|^Print

                 Nama kolom disamakan dgn VB6: "Sisa PO" -> **Qty PO**, "Qty Diterima" ->
                 **Qty Terima**.

                 Tidak ikut: kolom simpanan tak kasatmata (ID POdetail/Pakai Batch/Kode kedua/
                 Id PO Header), **Disc + Disc % 1/2/3** & **Cabang** (diskon dipegang di PO,
                 tidak diubah saat menerima), serta **Qty Depo / Satuan Depo / Konversi /
                 Qty Klinik / Satuan Klinik / Print** yg belum ada padanannya di app ini.

                 Kolom **Harga** SENGAJA DISEMBUNYIKAN dari tampilan (permintaan user
                 2026-09-26) tapi nilainya TETAP DITARIK dari PO dan TETAP DISIMPAN ke
                 `fstokd.SDHARGA` - sumber perhitungan HPP. Jangan hapus `harga` dari
                 state/`save()` hanya karena kolomnya tidak kelihatan di sini.

                 Kode & Nama digabung satu kolom (`x-nama-produk`) spt form PKB/SJ/PO. --}}
            <div class="table-responsive vb-grid-area">
                <table class="table table-sm table-bordered align-middle vb-grid">
                    <thead>
                        <tr>
                            <th style="width: 12%">No PO</th>
                            <th>Nama Produk</th>
                            <th class="text-end" style="width: 90px">Qty PO</th>
                            <th style="width: 70px">Satuan PO</th>
                            <th class="text-end" style="width: 110px">Qty Terima</th>
                            <th style="width: 70px">Satuan</th>
                            <th style="width: 150px">No Batch</th>
                            <th>Catatan</th>
                            @unless ($locked) <th style="width: 36px"></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            <tr wire:key="pb-line-{{ $i }}">
                                <td>{{ $l['noPo'] }}</td>
                                <td><x-nama-produk :kode="$l['kode']" :nama="$l['nama']" /></td>
                                <td class="text-end">{{ $l['sisaPoUnit'] !== null ? number_format($l['sisaPoUnit'], 2) : '—' }}</td>
                                <td>{{ $l['satuanPoKode'] }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model="lines.{{ $i }}.qty" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td>{{ $l['satuanDasarKode'] }}</td>
                                <td>
                                    @if (! empty($l['serial']))
                                        @php $bs = $l['batches'] ?? []; @endphp
                                        <button type="button"
                                                class="btn btn-sm w-100 text-start {{ $bs === [] ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                wire:click="openBatch({{ $i }})">
                                            @if ($bs === [])
                                                <i class="fas fa-barcode me-1"></i> Isi Batch
                                            @else
                                                <i class="fas fa-barcode me-1"></i>
                                                {{ count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch' }}
                                            @endif
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                {{-- kolom Harga disembunyikan, nilainya tetap ada di state & tersimpan --}}
                                <td>
                                    <input type="text" class="form-control form-control-sm" wire:model="lines.{{ $i }}.catatan" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 8 : 9 }}" class="text-center text-muted py-4">Belum ada item. Klik "Tarik dari PO" untuk mulai.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Total di TENGAH BAWAH grid, spt VB6 (bukan di kaki tabel). VB6 menampilkan
                 Total Qty Masuk DAN Total Qty Keluar; di Penerimaan Barang hanya yg Masuk
                 yg punya arti - barangnya masuk, bukan keluar. --}}
            <div class="vb-total">
                <span>Total Qty Masuk</span>
                <span class="nilai">{{ number_format($totalQty, 2, ',', '.') }}</span>
            </div>

            @unless ($locked)
                <div class="mt-3 text-end">
                    <button type="button" class="btn btn-success" wire:click="save">
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                </div>
            @endunless
            </div>{{-- /.vb-form --}}
        </div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Tarik dari PO (Purchase Order yang belum lunas diterima)" close="closePicker">
            <div class="modal-body">
                <div class="row g-2 mb-2">
                    <div class="col-md-7">
                        <input type="text" class="form-control form-control-sm" placeholder="cari no PO / supplier…"
                               wire:model.live.debounce.300ms="pickerQ">
                    </div>
                    <div class="col-md-5">
                        {{-- PO dari SEMUA cabang boleh ditarik; filter ini cuma mempersempit. --}}
                        <select class="form-select form-select-sm" wire:model.live="pickerCabang">
                            <option value="">Semua cabang</option>
                            @foreach ($cabangPo as $c)
                                <option value="{{ $c->id }}">{{ $c->nama ?: 'Cabang #' . $c->id }} ({{ $c->jml }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="list-group" style="max-height: 360px; overflow-y:auto">
                    @forelse ($pullable as $po)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pullFromPo({{ $po->id }})">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $po->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($po->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small">{{ $po->vendor ?: '—' }}</span>
                                <span class="badge text-bg-secondary">{{ $po->cabang ?: '—' }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">Tidak ada PO yang bisa ditarik.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closePicker">Tutup</button>
            </div>
        </x-lw-modal>
    @endif

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $jumlahProduct = (float) $bl['qty'];
            $totalSerial = array_sum(array_map(fn ($r) => (float) $r['qty'], $batchRows));
            $selisih = round($jumlahProduct - $totalSerial, 4);
        @endphp
        <x-lw-modal :show="$showBatch" title="No Batch — {{ $bl['kode'] }} {{ $bl['nama'] }}" close="closeBatch">
            <div class="modal-body">
                @if ($errors->has('batchRows'))
                    <div class="alert alert-danger py-2">{{ $errors->first('batchRows') }}</div>
                @endif

                @unless ($locked)
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-5">
                            <label class="form-label mb-1">No Batch / Serial</label>
                            <input type="text" class="form-control form-control-sm @error('bNo') is-invalid @enderror"
                                   wire:model="bNo" wire:keydown.enter.prevent="addBatchRow" placeholder="mis. G260022155Y">
                            @error('bNo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Tanggal Expired</label>
                            <input type="date" class="form-control form-control-sm" wire:model="bExp">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Jumlah</label>
                            <input type="number" step="0.01" min="0"
                                   class="form-control form-control-sm text-end @error('bQty') is-invalid @enderror"
                                   wire:model="bQty" wire:keydown.enter.prevent="addBatchRow">
                            @error('bQty') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-primary btn-sm w-100" wire:click="addBatchRow" title="Tambah">
                                <i class="fas fa-angle-right"></i>
                            </button>
                        </div>
                    </div>
                @endunless

                <table class="table table-sm table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            @unless ($locked) <th style="width:40px"></th> @endunless
                            <th>No Batch</th><th style="width:140px">Tanggal Expired</th>
                            <th class="text-end" style="width:110px">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batchRows as $bi => $r)
                            <tr wire:key="batch-row-{{ $bi }}">
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                wire:click="removeBatchRow({{ $bi }})" title="Hapus">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </td>
                                @endunless
                                <td>{{ $r['noBatch'] }}</td>
                                <td>{{ $r['expired'] ? \Carbon\Carbon::parse($r['expired'])->format('d/m/Y') : '—' }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $r['qty'], 2, ',', '.'), '0'), ',') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 3 : 4 }}" class="text-center text-muted py-3">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Product</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled
                               value="{{ rtrim(rtrim(number_format($jumlahProduct, 2, ',', '.'), '0'), ',') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Total Serial</label>
                        <input type="text" class="form-control form-control-sm text-end {{ abs($selisih) > 0.0001 ? 'border-danger text-danger fw-semibold' : 'border-success text-success fw-semibold' }}"
                               disabled value="{{ rtrim(rtrim(number_format($totalSerial, 2, ',', '.'), '0'), ',') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Selisih</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled
                               value="{{ rtrim(rtrim(number_format($selisih, 2, ',', '.'), '0'), ',') }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeBatch">Tutup</button>
                @unless ($locked)
                    <button type="button" class="btn btn-success" wire:click="applyBatch" @disabled(abs($selisih) > 0.0001)>
                        <i class="fas fa-check me-1"></i> Simpan Batch
                    </button>
                @endunless
            </div>
        </x-lw-modal>
    @endif
</div>
