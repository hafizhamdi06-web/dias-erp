@php
    // Dipakai badge di JUDUL, jadi harus dihitung sebelum header dirender.
    [$statusTeks, $statusKelas] = match ((int) $status) {
        0 => ['Pending', 'text-bg-warning'],
        3 => ['Diterima', 'text-bg-info'],
        9 => ['Batal', 'text-bg-danger'],
        default => ['Aktif', 'text-bg-success'],
    };
@endphp
<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    @if ($sjId)
                        SJ {{ $nomor }}
                    @elseif ($pkbId)
                        SJ Baru dari PKB {{ $noPkb }}
                    @else
                        SJ Baru
                    @endif
                    {{-- Status jadi badge di judul (bukan kotak di badan form) - pola sama
                         KMB/TMB/PKB, dan VB6 pun tidak punya field Status di form ini. --}}
                    @if ($sjId)
                        <span class="badge {{ $statusKelas }} ms-2">{{ $statusTeks }}</span>
                    @endif
                </span>
                <div>
                    @if ($sjId && can_do('sales/sj', 'print'))
                        <a href="{{ route('sales.sj.print', $sjId) }}" target="_blank"
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
                     Tata letak MENGIKUTI form VB6 `fFrmSuratJalanDepo.frm` ("Surat Jalan
                     Pabrik") - `amenu` MID **728** -> `aMod_Menu.bas` `Case 728: Set xForm =
                     fFrmSuratJalanDepo`. Acuan itu DITUNJUK USER 2026-10-10; sebelumnya di sini
                     tertulis MID 442 `fFrmSuratJalan`, yg salah layar.
                     (AWAS: ada ENAM berkas bernama mirip - `_new`, `_coret`, `2`, `Data`, dan
                     `Depo_CL` yg cuma dialog pilih batch.)

                     Untungnya KOORDINAT kedua form itu identik, jadi tata letak yg terlanjur
                     dibangun dari 442 tetap sahih. Beda nyatanya cuma tiga: 728 menambah kotak
                     **No PO** pelanggan di (8115,1620), caption-nya "Surat Jalan Pabrik", dan
                     caption di atas kotak tanggal berbunyi "Tgl Transaksi" - di 442 caption itu
                     salah ketik jadi "No Transaksi" (spt KMB/TMB/PY/PL). Di sini dipakai
                     "Tanggal", yg sejalan dgn 728.
                     Gaya di `public/css/vb-form.css`.

                     Urutan field kiri menurut koordinat Top VB6:
                       675 Kontak | 1020 Gudang | 1320 Gudang Tujuan | 1965 Jenis Penyesuaian
                       2250 Divisi | 2550 Keterangan
                     Kanan: (10080,750) Tanggal | (12135,750) No Transaksi |
                            (10095,1590) No PKB | (12135,1590) No. PO

                     Tidak ikut krn belum ada padanannya di sini: **Jenis Penyesuaian**,
                     **Divisi**, **No. PO**, **No PO pelanggan** (khas 728), dan centang
                     **Pending**.
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== Kolom kiri ===== --}}
                        <div class="col-lg-7">
                            <div class="vb-row">
                                <label class="form-label">Kontak <span class="text-danger">*</span></label>
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
                            @error('kontak') <div class="vb-err text-danger small">{{ $message }}</div> @enderror

                            <div class="vb-row">
                                <label class="form-label">Gudang</label>
                                <select class="form-select form-select-sm vb-ctl" wire:model="cabangKirim" disabled
                                        title="Otomatis cabang Anda, tidak bisa diubah.">
                                    <option value="">—</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="vb-row">
                                <label class="form-label">Gudang Tujuan</label>
                                <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                       value="{{ $cabangTujuanLabel ?: '—' }}" disabled
                                       title="Otomatis dari PR asal (transitif lewat PKB), tidak bisa diubah.">
                            </div>
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

                            {{-- Tombol cari menempel di kotak "No PKB" - persis VB6, yg
                                 `cmdCariSJOnline` letaknya memang di situ. Sejak daftar SJ
                                 disederhanakan jadi satu tombol "Input Baru" (2026-10-10),
                                 DI SINILAH satu-satunya tempat memilih PKB. --}}
                            <div class="vb-box">
                                <div class="cap">No PKB</div>
                                <div class="isi d-flex align-items-center gap-1">
                                    <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                           value="{{ $noPkb ?: '—' }}" disabled>
                                    @unless ($locked)
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                                wire:click="bukaCari('{{ $this::SUMBER_PKB_SJ }}')"
                                                title="Cari PKB yang masih ada sisa qty"
                                                @if (count($lines)) data-confirm="Ganti PKB? Baris yang sekarang — termasuk Qty yang sudah Anda ubah — akan diganti dengan isi PKB yang baru." @endif>
                                            <i class="fas fa-magnifying-glass"></i>
                                        </button>
                                    @endunless
                                </div>
                            </div>

                            @unless ($locked || $pkbId)
                                <div class="alert alert-info py-2 px-3 small mb-0">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Pilih dulu PKB-nya lewat tombol <i class="fas fa-magnifying-glass"></i>
                                    di <strong>No PKB</strong>.
                                </div>
                            @endunless
                        </div>
                    </div>

                    {{-- VB6: txtKeterangan lebarnya 12060 twip, membentang di bawah kedua kolom. --}}
                    <div class="vb-row mt-2">
                        <label class="form-label">Keterangan</label>
                        <input type="text" class="form-control form-control-sm vb-ctl-full" wire:model="uraian"
                               @disabled($locked)>
                    </div>

                    @php
                        $ang = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');

                        // Pembagi konversi = `bitem.IQTYPERBOX`. VB6 saat MEMUAT SJ tersimpan
                        // memakai `SDKELUAR/coalesce(IQTYPERBOX,1)` (baris 1136
                        // `fFrmSuratJalanDepo.frm`), jadi 0/NULL diperlakukan sbg 1 - bukan
                        // kolom kosong. Diikuti apa adanya di sini supaya Qty Depo selalu
                        // terisi, sama spt yg user lihat di VB6. (Di jalur TARIK-DARI-PKB, VB6
                        // membagi tanpa coalesce sehingga hasilnya NULL kalau IQTYPERBOX
                        // kosong - itu bug-nya VB6, tidak ditiru.)
                        $perBoxEfektif = fn ($p) => (float) $p > 0 ? (float) $p : 1.0;
                    @endphp

                    {{-- ---- Baris item (ditarik dari PKB, tidak bisa tambah bebas) ----
                         Acuan VB6-nya **menu 728 `fFrmSuratJalanDepo`** ("Surat Jalan Pabrik"),
                         DIBETULKAN oleh user 2026-10-10 - sebelumnya salah ambil menu 442
                         `fFrmSuratJalan`. `FormatString` grid-nya:
                         <Kode|<Nama|>Qty PKB|<Satuan|>Qty Keluar|<Satuan|>Qty Konversi|<Satuan|
                         QtyDasar|SatuanDasar|Konversi|<Id PO|<Id PKB|<Pakai Batch|<No Batch|
                         <Satuan Unit|<Keterangan|<ID PO

                         Tidak ikut: kolom simpanan tak kasatmata (QtyDasar/SatuanDasar/Konversi/
                         Id PO/Id PKB/Pakai Batch/ID PO).

                         **Konversi box** ditampilkan sbg DUA KOLOM - `Qty Perbox` (IQTYPERBOX) dan
                         `Qty Depo` (Qty Keluar : Qty Perbox) - permintaan user 2026-10-10. Ini
                         MENGGANTI tampilan menyatu "100 / 5 box" di kolom Qty (keputusan
                         2026-09-26); jangan dikembalikan. Penamaannya mengikuti user, bukan VB6:
                         di VB6 kolom hasil-bagi ini bernama "Qty Keluar" (satuan depo) dan kolom
                         satuan dasarnya "Qty Konversi" - membingungkan krn di sini "Qty Keluar"
                         adalah isian satuan dasar.

                         **Kode & Nama digabung** jadi satu kolom (lihat `x-nama-produk`): 74% item
                         aktif `IKODE`-nya persis sama dgn `INAMA`, dan dua kolom terpisah saling
                         menyempitkan. Sama spt yg sudah dilakukan di form PKB.

                         Kolom **Stok** pernah ada di sini sbg tambahan (bantu memutuskan berapa yg
                         sanggup dikirim); DIBUANG atas permintaan user 2026-10-10 - VB6 tidak punya
                         kolom itu di layar ini. Jangan dipasang lagi. --}}
                    <div class="table-responsive vb-grid-area mt-3">
                        <table class="table table-sm table-bordered align-middle vb-grid">
                            <thead>
                                <tr>
                                    <th>Nama Produk</th>
                                    <th class="text-end" style="width: 110px">Qty PKB</th>
                                    <th class="text-end" style="width: 120px">Qty Keluar</th>
                                    <th style="width: 65px">Satuan</th>
                                    <th class="text-end" style="width: 90px">Qty Perbox</th>
                                    <th class="text-end" style="width: 95px">Qty Depo</th>
                                    <th style="width: 140px">No Batch</th>
                                    <th style="width: 18%">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $i => $l)
                                    <tr wire:key="line-{{ $l['item'] }}">
                                        <td><x-nama-produk :kode="$l['kode']" :nama="$l['nama']" /></td>
                                        @php $perBox = $perBoxEfektif($l['qtyPerBox'] ?? 0); @endphp
                                        <td class="text-end">{{ $ang($l['sisa']) }}</td>
                                        <td>
                                            <input type="number" min="0" step="any" max="{{ $l['sisa'] }}"
                                                   class="form-control form-control-sm text-end"
                                                   {{-- `.live` supaya Qty Depo (& Total di bawah)
                                                        ikut berubah saat qty diketik; di-debounce
                                                        spy tidak 1 request per ketikan. --}}
                                                   wire:model.live.debounce.400ms="lines.{{ $i }}.qty"
                                                   @disabled($locked)>
                                        </td>
                                        <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                        <td class="text-end">{{ $ang($perBox) }}</td>
                                        <td class="text-end">{{ $ang((float) $l['qty'] / $perBox) }}</td>
                                        <td>
                                            @if (! empty($l['serial']))
                                                @php $bs = $l['batches'] ?? []; @endphp
                                                <button type="button"
                                                        class="btn btn-sm w-100 text-start {{ $bs === [] ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                        wire:click="openBatch({{ $i }})">
                                                    <i class="fas fa-barcode me-1"></i>
                                                    {{ $bs === [] ? 'Pilih Batch' : (count($bs) === 1 ? $bs[0]['noBatch'] : count($bs) . ' batch') }}
                                                </button>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada item tersisa dari PKB ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- VB6 menampilkan Total Qty Masuk DAN Total Qty Keluar; di Surat Jalan
                         hanya yg Keluar yg punya arti - barangnya keluar, bukan masuk. --}}
                    <div class="vb-total">
                        <span>Total Qty Keluar</span>
                        <span class="nilai">{{ $ang($totalQty) }}</span>
                        <span>Total Qty Depo</span>
                        <span class="nilai">{{ $ang($totalDepo) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Dialog "Cari Transaksi" sumber RS_SJ - dipakai bersama form KMB/TMB/PKB,
         lihat trait `App\Livewire\Concerns\CariTransaksi`. --}}
    @if ($ctShow)
        <x-cari-transaksi :branches="$branches" :kolom="$this->ctKolom()" :hasil="$ctHasil"
                          :pilih="$ctPilih" :total-tanpa-saringan="$ctTotalTanpaSaringan"
                          :batas="$ctBatas" :cabang="$ctCabang"
                          :cabang-terkunci="$this->ctCabangTerkunci()"
                          :pakai-kode-produk="$this->ctPakaiKodeProduk()" />
    @endif

    @if ($showBatch && $batchIdx !== null && isset($lines[$batchIdx]))
        @php
            $bl = $lines[$batchIdx];
            $jumlahProduct = (float) $bl['qty'];
            $jumlahDipilih = array_sum(array_map(fn ($r) => $r['pilih'] ? (float) $r['qty'] : 0, $batchRows));
            $selisih = round($jumlahProduct - $jumlahDipilih, 4);
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        @endphp
        <x-lw-modal :show="$showBatch" title="Pilih Serial — {{ $bl['kode'] }} {{ $bl['nama'] }}" close="closeBatch">
            <div class="modal-body">
                @if ($errors->has('batchRows'))
                    <div class="alert alert-danger py-2">{{ $errors->first('batchRows') }}</div>
                @endif

                <table class="table table-sm table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">X</th>
                            <th>No Batch</th>
                            <th style="width:140px">Tanggal Expired</th>
                            <th class="text-end" style="width:110px">Tersedia</th>
                            <th class="text-end" style="width:120px">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batchRows as $bi => $r)
                            <tr wire:key="sj-batch-{{ $bi }}" class="{{ $r['pilih'] ? 'table-success' : '' }}">
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" @checked($r['pilih'])
                                           wire:click="toggleBatch({{ $bi }})" @disabled($locked)>
                                </td>
                                <td class="fw-semibold">{{ $r['noBatch'] }}</td>
                                <td>{{ $r['expired'] ? \Carbon\Carbon::parse($r['expired'])->format('d/m/Y') : '—' }}</td>
                                <td class="text-end text-muted">{{ $fmt($r['tersedia']) }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="{{ $r['tersedia'] }}"
                                           class="form-control form-control-sm text-end"
                                           wire:model="batchRows.{{ $bi }}.qty" @disabled($locked || ! $r['pilih'])>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">
                                Tidak ada batch dengan stok tersedia di {{ $cabangKirimLabel ?: 'gudang ini' }}.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Product</label>
                        <input type="text" class="form-control form-control-sm text-end" disabled value="{{ $fmt($jumlahProduct) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1 small text-muted">Jumlah Serial di Pilih</label>
                        <input type="text" disabled value="{{ $fmt($jumlahDipilih) }}"
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
                    <button type="button" class="btn btn-outline-primary" wire:click="isiUlangBatch">
                        <i class="fas fa-rotate me-1"></i> Isi Ulang Serial
                    </button>
                    <button type="button" class="btn btn-success" wire:click="applyBatch" @disabled(abs($selisih) > 0.0001)>
                        <i class="fas fa-check me-1"></i> OK
                    </button>
                @endunless
            </div>
        </x-lw-modal>
    @endif
    {{-- Dialog "Cari Transaksi" sumber RS_SJ - dipakai bersama form KMB/TMB/PKB,
         lihat trait `App\Livewire\Concerns\CariTransaksi`. --}}
    @if ($ctShow)
        <x-cari-transaksi :branches="$branches" :kolom="$this->ctKolom()" :hasil="$ctHasil"
                          :pilih="$ctPilih" :total-tanpa-saringan="$ctTotalTanpaSaringan"
                          :batas="$ctBatas" :cabang="$ctCabang"
                          :cabang-terkunci="$this->ctCabangTerkunci()"
                          :pakai-kode-produk="$this->ctPakaiKodeProduk()" />
    @endif
</div>
