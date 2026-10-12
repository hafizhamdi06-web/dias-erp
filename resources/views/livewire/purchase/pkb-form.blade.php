@php
    // Dipakai badge di JUDUL, jadi harus dihitung sebelum header dirender.
    // Wording status sama dgn daftar PKB - lihat `pkb-list.blade.php`.
    [$statusTeks, $statusKelas] = match ((int) $status) {
        1 => ['Dalam Proses', 'text-bg-info'],
        2 => ['Selesai', 'text-bg-success'],
        9 => ['Batal', 'text-bg-danger'],
        default => ['Belum Proses', 'text-bg-secondary'],
    };
@endphp
<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    @if ($pkbId)
                        PKB {{ $nomor }}
                    @elseif ($prId)
                        PKB Baru dari PR {{ $noPr }}
                    @else
                        PKB Baru
                    @endif
                    {{-- Status jadi badge di judul (bukan kotak di badan form) - pola sama
                         KMB/TMB, dan VB6 pun tidak punya field Status di form ini. --}}
                    @if ($pkbId)
                        <span class="badge {{ $statusKelas }} ms-2">{{ $statusTeks }}</span>
                    @endif
                </span>
                <div>
                    @if ($pkbId && can_do('purchase/pkb', 'print'))
                        <a href="{{ route('purchase.pkb.print', $pkbId) }}" target="_blank"
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
                     Tata letak MENGIKUTI form VB6 `fFrmPermintaanBarang.frm` (caption "Perintah
                     Kirim Barang"). Gaya di `public/css/vb-form.css`.

                     **AWAS penelusuran menunya**: `amenu` MID **598** "Perintah Kirim Barang"
                     menunjuk `fFrmPerintahKiirmBarang` di `aMod_Menu.bas` - form itu
                     **TIDAK ADA berkasnya** dan tidak terdaftar di `DIAS.vbp` (rujukan mati).
                     Form yg sungguh hidup dibuka MID **232** (namanya justru "Permintaan
                     Barang"), yaitu `fFrmPermintaanBarang.frm` - caption-nya "Perintah Kirim
                     Barang" dan isinya memang alur PKB (`cmdCariPermintaan` -> `TampilkanRS`).
                     Jangan tertukar dgn `fFrmPermintaanBarang2.frm`, itu form Permintaan
                     Barang (PR).

                     Urutan field kiri menurut koordinat Top VB6:
                       780 Diperintah Oleh | 1125 Keterangan | 1560 "Data Permintaan :"
                       1875 No Permintaan | 2175 Nama Karyawan | 2490 Gudang | 2790 Jenis
                     Kanan: (10080,750) Tanggal | (12135,750) No Transaksi |
                            (12135,1590) No. Reff | (9135,2520) Cabang

                     Tidak ikut krn belum ada padanannya di sini: **Nama Karyawan** (nama
                     peminta di PR), **No. Reff**, dan centang **Pending**.
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== Kolom kiri ===== --}}
                        <div class="col-lg-7">
                            <fieldset @disabled($locked)>
                                <div class="vb-row">
                                    <label class="form-label">Diperintah Oleh</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                           value="{{ $diperintahLabel ?: '—' }}" disabled
                                           title="{{ $pkbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}">
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">Keterangan</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl-wide" wire:model="uraian">
                                </div>
                            </fieldset>

                            {{-- VB6 memberi kelompok ini judul sendiri di Top 1560. --}}
                            <div class="vb-sela"></div>
                            <div class="fw-semibold mb-1">Data Permintaan :</div>

                            <div class="vb-row">
                                <label class="form-label">No Permintaan</label>
                                {{-- Tombol cari menempel di sini - persis VB6, yg
                                     `cmdCariPermintaan` letaknya memang di kotak ini lalu
                                     memanggil dialog "Cari Transaksi" (sumber PRS) dan
                                     `TampilkanRS` utk mengisi ulang grid. Sejak daftar PKB
                                     disederhanakan jadi satu tombol "Input Baru" (2026-10-10),
                                     DI SINILAH satu-satunya tempat memilih PR. --}}
                                <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                       value="{{ $noPr ?: '—' }}" disabled>
                                @unless ($locked)
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                            wire:click="bukaCari('{{ $this::SUMBER_PR_PKB }}')"
                                            title="Cari PR yang sudah Disetujui"
                                            @if (count($lines)) data-confirm="Ganti PR? Baris yang sekarang — termasuk Qty yang sudah Anda ubah — akan diganti dengan isi PR yang baru." @endif>
                                        <i class="fas fa-magnifying-glass"></i>
                                    </button>
                                @endunless
                            </div>
                            <div class="vb-row">
                                <label class="form-label">Gudang</label>
                                <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                       value="{{ $tujuanCabangLabel ?: '—' }}" disabled
                                       title="Cabang peminta, otomatis dari PR sumber.">
                            </div>
                            <div class="vb-row">
                                <label class="form-label">Jenis</label>
                                <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                       value="{{ $tujuanKategoriLabel ?: '—' }}" disabled
                                       title="Tipe permintaan, otomatis dari PR sumber.">
                            </div>

                            @unless ($locked || $prId)
                                <div class="alert alert-info py-2 px-3 small mt-2 mb-0">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Pilih dulu PR-nya lewat tombol <i class="fas fa-magnifying-glass"></i>
                                    di <strong>No Permintaan</strong>.
                                </div>
                            @endunless
                        </div>

                        {{-- ===== Kolom kanan ===== --}}
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

                            {{-- VB6 menaruh "Cabang" di sisi KANAN sbg label+field biasa
                                 (Left 9135), bukan kotak ber-caption - ini cabang PKB-nya
                                 sendiri (pengirim), beda dari "Gudang" di kiri yg cabang
                                 peminta. --}}
                            <div class="vb-row">
                                <label class="form-label">Cabang</label>
                                <select class="form-select form-select-sm vb-ctl" wire:model="gudang" disabled
                                        title="{{ $pkbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}">
                                    <option value="">—</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('gudang') <div class="vb-err text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- ---- Baris item (ditarik dari PR, tidak bisa tambah bebas) ----
                         Susunan kolom = `FormatString` VB6:
                         <Kode|<Nama|>Qty RS|>Qty Approval|<Satuan|<Catatan|QtyDasar|SatuanDasar|
                         Konversi|>Stok|<ID Detail|<ID Utama|<Pemakain Rata Rata 3 Bulan|
                         <Pemakaian Bulan Lalu

                         Tidak ikut: kolom simpanan tak kasatmata (QtyDasar/SatuanDasar/
                         Konversi/ID Detail/ID Utama).

                         **Kode & Nama DIGABUNG jadi satu kolom "Nama Produk"** (permintaan
                         user 2026-10-10: kolom Nama terlalu sempit, sampai pecah 3 baris).
                         Alasannya data: 74% item aktif (2.059 dari 2.764) `IKODE`-nya PERSIS
                         SAMA dgn `INAMA`, jadi dua kolom itu menampilkan teks yg sama di tiga
                         dari empat baris sambil saling menyempitkan. Yg berbeda tetap tampil
                         keduanya - lihat `x-nama-produk`. Ini SADAR menyimpang dari VB6 yg
                         memisah `<Kode|<Nama`.

                         **Dua kolom terakhir DIGANTI NAMANYA** dari "Saldo 3 Bln"/"Saldo 1 Bln"
                         jadi "Pemakaian ..." - nama lama MENYESATKAN. `F_SALDO3BULANLALU`
                         menghitung `sum(sdkeluar)/3` dan `F_SALDO1LALU` `sum(sdkeluar)`, yaitu
                         PEMAKAIAN (barang keluar), bukan saldo. VB6 menamainya benar
                         ("Pemakain Rata Rata 3 Bulan" - typo "Pemakain" tidak ikut ditiru). --}}
                    <div class="table-responsive vb-grid-area mt-3">
                        <table class="table table-sm table-bordered align-middle vb-grid">
                            <thead>
                                <tr>
                                    <th>Nama Produk</th>
                                    <th class="text-end" style="width: 85px">Qty RS</th>
                                    <th class="text-end th-wrap" style="width: 90px">Qty Approval</th>
                                    <th style="width: 65px">Satuan</th>
                                    <th style="width: 16%">Catatan</th>
                                    <th class="text-end" style="width: 75px">Stok</th>
                                    <th class="text-end th-wrap" style="width: 85px"
                                        title="Pemakaian rata-rata 3 bulan terakhir (sum(sdkeluar)/3)">Pemakaian 3 Bln</th>
                                    <th class="text-end th-wrap" style="width: 85px"
                                        title="Pemakaian bulan lalu (sum(sdkeluar))">Pemakaian Bln Lalu</th>
                                    <th style="width: 32px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $i => $l)
                                    <tr wire:key="line-{{ $l['item'] }}">
                                        <td><x-nama-produk :kode="$l['kode']" :nama="$l['nama']" /></td>
                                        <td class="text-end">{{ number_format($l['sisa'], 2, ',', '.') }}</td>
                                        <td>
                                            <input type="number" min="0" step="any" max="{{ $l['sisa'] }}"
                                                   class="form-control form-control-sm text-end"
                                                   wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                        </td>
                                        <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                        </td>
                                        <td class="text-end">{{ number_format($l['stok'], 2, ',', '.') }}</td>
                                        <td class="text-end">{{ number_format($l['saldo3'], 2, ',', '.') }}</td>
                                        <td class="text-end">{{ number_format($l['saldo1'], 2, ',', '.') }}</td>
                                        <td class="text-center">
                                            @unless ($locked)
                                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                        wire:click="removeLine({{ $i }})" title="Jangan kirim item ini sekarang">
                                                    <i class="fas fa-xmark"></i>
                                                </button>
                                            @endunless
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada item tersisa dari PR ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- VB6 menaruh Total Qty di TENGAH bawah grid, bukan di kaki tabel. --}}
                    <div class="vb-total">
                        <span>Total Qty</span>
                        <span class="nilai">{{ number_format($totalQty, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Dialog "Cari Transaksi" sumber PRS - markup & logikanya dipakai bersama dgn
         form KMB/TMB, lihat trait `App\Livewire\Concerns\CariTransaksi`. --}}
    @if ($ctShow)
        <x-cari-transaksi :branches="$branches" :kolom="$this->ctKolom()" :hasil="$ctHasil"
                          :pilih="$ctPilih" :total-tanpa-saringan="$ctTotalTanpaSaringan"
                          :batas="$ctBatas" :cabang="$ctCabang"
                          :cabang-terkunci="$this->ctCabangTerkunci()"
                          :pakai-kode-produk="$this->ctPakaiKodeProduk()" />
    @endif
</div>
