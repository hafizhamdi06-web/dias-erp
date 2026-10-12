@php
    // Dipakai badge di JUDUL, jadi harus dihitung sebelum header dirender.
    // TMB cuma punya dua status di data nyata: 1 aktif (315 dok) & 9 batal (1 dok).
    [$statusTeks, $statusKelas] = match ($status) {
        9 => ['Batal', 'text-bg-danger'],
        default => ['Aktif', 'text-bg-success'],
    };
@endphp
<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{-- Sejak KMB dipilih DI FORM, judulnya harus tahan keadaan "belum ada
                         rujukan" - dulu berbunyi "TMB Baru dari KMB " dgn nomor kosong. --}}
                    @if ($tmbId)
                        TMB {{ $nomor }}
                    @elseif ($kmbId)
                        TMB Baru dari KMB {{ $noKmb }}
                    @else
                        TMB Baru
                    @endif
                    {{-- Status pindah ke SINI dari kotak ber-caption di badan form (permintaan
                         user 2026-10-10: kotaknya makan tempat). Kotak itu memang tambahan saya,
                         BUKAN dari VB6 - `fFrmPenerimaanMutasi` tidak punya field Status sama
                         sekali. Sekalian membetulkan badge lama yg SELALU hijau "terkunci"
                         walau dokumennya batal. Cuma utk dokumen tersimpan: TMB baru statusnya
                         selalu "Aktif", tidak memberi informasi apa pun. --}}
                    @if ($tmbId)
                        <span class="badge {{ $statusKelas }} ms-2">{{ $statusTeks }}</span>
                    @endif
                </span>
                <div>
                    @if ($tmbId && can_do('inventory/tmb', 'print'))
                        <a href="{{ route('inventory.tmb.print', $tmbId) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-1"></i> Cetak
                        </a>
                    @endif
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @unless ($locked)
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endunless
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                {{-- ============================================================================
                     Tata letak MENGIKUTI form VB6 `fFrmPenerimaanMutasi.frm` - dipastikan lewat
                     `amenu` MID **585** "Terima Mutasi Barang" -> `aMod_Menu.bas`
                     `Case 585: Set xForm = fFrmPenerimaanMutasi`. (Jangan tertukar: menu 605
                     namanya sama persis tapi mengarah ke `zfFrmFilterLaporanDaftar`, itu filter
                     laporan.) Gaya di `public/css/vb-form.css`.

                     Urutan field kiri menurut koordinat Top VB6: 675 Kontak | 1020 Gudang |
                     2550 Keterangan. TMB TIDAK punya "Gudang Tujuan" - memang tidak ada di VB6.
                     Kotak kanan: (10080,750) Tanggal | (12135,750) No Transaksi |
                     (10080,1635) No Kirim Mutasi Barang | (12135,1590) No. Reff.

                     Caption VB6 di atas kotak tanggal lagi-lagi salah ketik "No Transaksi"
                     (sama spt `fFrmKirimMutasiBarang`) - ditulis "Tanggal" yg benar.
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== Kolom kiri ===== --}}
                        <div class="col-lg-7">
                            <fieldset @disabled($locked)>
                                <div class="vb-row">
                                    <label class="form-label">Kontak</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                           value="{{ $kontakLabel ?: '—' }}" disabled
                                           title="{{ $tmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}">
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">Gudang</label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model="cabang" disabled
                                            title="{{ $tmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}">
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </fieldset>
                        </div>

                        {{-- ===== Kolom kanan: kotak ber-caption ===== --}}
                        <div class="col-lg-5">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">Tanggal</div>
                                        <div class="isi">
                                            <input type="date" class="form-control form-control-sm border-0 p-0 px-1"
                                                   wire:model="tanggal" @disabled($locked)>
                                        </div>
                                    </div>
                                    @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">No Transaksi</div>
                                        <div class="isi">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $nomor ?: '[otomatis]' }}" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- "No PO" TAMBAHAN dari saya: VB6 TMB cuma punya "No Kirim Mutasi
                                 Barang" dan "No. Reff" (SJ Online, belum ada padanannya). Nomor
                                 PR asalnya tetap berguna, dan "No PO" itu nama yg dipakai VB6
                                 sendiri utk nomor yg sama di form Kirim Mutasi Barang. --}}
                            <div class="row g-2">
                                <div class="col-7">
                                    <div class="vb-box">
                                        <div class="cap">No Kirim Mutasi Barang</div>
                                        {{-- Tombol cari menempel di sini - persis VB6, yg
                                             `cmdCariNoReff` letaknya memang di kotak ini lalu
                                             memanggil dialog "Cari Transaksi" (sumber KMB2) dan
                                             `TampilkanRetur` utk mengisi ulang grid. Sejak daftar
                                             TMB disederhanakan jadi satu tombol "Input Baru"
                                             (2026-10-10), DI SINILAH satu-satunya tempat memilih
                                             KMB. Hanya saat TMB belum tersimpan: TMB tersimpan
                                             selalu read-only. --}}
                                        <div class="isi d-flex align-items-center gap-1">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $noKmb ?: '—' }}" disabled>
                                            @unless ($locked)
                                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                                        wire:click="bukaCari('{{ $this::SUMBER_KMB }}')"
                                                        title="Cari KMB yang mau diterima"
                                                        @if (count($lines)) data-confirm="Ganti KMB? Baris yang sekarang — termasuk Qty Masuk dan No Batch yang sudah Anda isi — akan diganti dengan isi KMB yang baru." @endif>
                                                    <i class="fas fa-magnifying-glass"></i>
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <div class="vb-box">
                                        <div class="cap">No PO</div>
                                        <div class="isi">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $noPr ?: '—' }}" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @unless ($locked || $kmbId)
                                <div class="alert alert-info py-2 px-3 small mb-2">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Pilih dulu KMB-nya lewat tombol <i class="fas fa-magnifying-glass"></i>
                                    di <strong>No Kirim Mutasi Barang</strong>.
                                </div>
                            @endunless

                        </div>
                    </div>

                    {{-- VB6: txtKeterangan lebarnya 12060 twip, membentang di bawah kedua kolom. --}}
                    <fieldset @disabled($locked)>
                        <div class="vb-row mt-2">
                            <label class="form-label">Keterangan</label>
                            <input type="text" class="form-control form-control-sm vb-ctl-full" wire:model="uraian">
                        </div>
                    </fieldset>

                    {{-- ---- Baris item (ditarik dari KMB, tidak bisa tambah bebas) ----
                         Susunan kolom = `FormatString` VB6 (dari .frx):
                         <Kode|<Nama|>Qty Masuk|>Qty Keluar|<Satuan|>Harga Masuk|<Catatan|
                         QtyDasar|SatuanDasar|Konversi|<Pakai Batch|<No Batch

                         "Qty Keluar" tidak ikut: di dokumen TERIMA nilainya selalu 0 - barangnya
                         masuk (kebalikan Kirim Mutasi Barang). "Harga Masuk" tidak ikut (TMB di
                         sini tidak memegang harga), "Pakai Batch" juga tidak - di sini cukup
                         tombol di kolom "No Batch" yg membuka dialog batch.
                         TAMBAHAN dari saya: "Qty Dikirim" - tidak ada di VB6, tapi tanpa itu
                         petugas tidak tahu batas terimanya berapa. --}}
                    <div class="table-responsive vb-grid-area mt-3">
                        <table class="table table-sm table-bordered align-middle vb-grid">
                            <thead>
                                <tr>
                                    <th style="width: 18%">Kode</th>
                                    <th>Nama</th>
                                    <th class="text-end" style="width: 100px">Qty Dikirim</th>
                                    <th class="text-end" style="width: 110px">Qty Masuk</th>
                                    <th style="width: 70px">Satuan</th>
                                    <th style="width: 20%">Catatan</th>
                                    <th style="width: 150px">No Batch</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $i => $l)
                                    <tr wire:key="line-{{ $l['item'] }}">
                                        <td>{{ $l['kode'] }}</td>
                                        <td>{{ $l['nama'] }}</td>
                                        <td class="text-end">{{ number_format($l['qtyKirim'], 2, ',', '.') }}</td>
                                        <td>
                                            <input type="number" min="0" step="any" max="{{ $l['qtyKirim'] }}"
                                                   class="form-control form-control-sm text-end"
                                                   wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                        </td>
                                        <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                        </td>
                                        <td>
                                            @if (! empty($l['serial']))
                                                @php $bs = $l['batches'] ?? []; @endphp
                                                <button type="button"
                                                        class="btn btn-sm w-100 text-start {{ $bs === [] ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                        wire:click="openBatch({{ $i }})">
                                                    <i class="fas fa-barcode me-1"></i>
                                                    {{ $bs === [] ? 'Isi Batch' : (count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch') }}
                                                </button>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada item dari KMB ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- VB6 menampilkan Total Qty Masuk DAN Total Qty Keluar; di dokumen terima
                         hanya yg Masuk yg punya arti. --}}
                    <div class="vb-total">
                        <span>Total Qty Masuk</span>
                        <span class="nilai">{{ number_format($totalQty, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </form>

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $qtyTerima = (float) $bl['qty'];
            $totalBatch = array_sum(array_map(fn ($r) => (float) $r['qty'], $batchRows));
            $selisih = round($qtyTerima - $totalBatch, 4);
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
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
                            <tr wire:key="tmb-batch-{{ $bi }}">
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
                                <td class="text-end">{{ $fmt($r['qty']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 3 : 4 }}" class="text-center text-muted py-3">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Qty Diterima</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($qtyTerima) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Total Batch</label>
                        <input type="text" disabled value="{{ $fmt($totalBatch) }}"
                               class="form-control form-control-sm text-end fw-semibold {{ abs($selisih) > 0.0001 ? 'border-danger text-danger' : 'border-success text-success' }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Selisih</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($selisih) }}">
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

    {{-- Dialog "Cari Transaksi" sumber KMB2 - markup & logikanya dipakai bersama dgn form
         KMB, lihat trait `App\Livewire\Concerns\CariTransaksi`. --}}
    @if ($ctShow)
        <x-cari-transaksi :branches="$branches" :kolom="$this->ctKolom()" :hasil="$ctHasil"
                          :pilih="$ctPilih" :total-tanpa-saringan="$ctTotalTanpaSaringan"
                          :batas="$ctBatas" :cabang="$ctCabang"
                          :cabang-terkunci="$this->ctCabangTerkunci()"
                          :pakai-kode-produk="$this->ctPakaiKodeProduk()" />
    @endif
</div>
