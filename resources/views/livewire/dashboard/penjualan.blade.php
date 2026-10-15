{{-- Dashboard Penjualan & COGS - lima tingkat rincian (PT > Cabang > COA > Item >
     Transaksi), sesuai RANCANGAN DASHBOARD PENJUALAN.xlsx.
     Gaya di `public/css/dashboard-penjualan.css`. Rumus angkanya di
     `App\Services\DashboardPenjualan`. --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $rp2 = fn ($n) => number_format((float) $n, 2, ',', '.');

    // Judul & label kolom pertama per tingkat.
    [$judulTingkat, $kolom1] = match ($this->tingkat()) {
        'pt'     => ['Per PT', 'PT'],
        'cabang' => ['Per Cabang', 'Cabang'],
        'coa'    => ['Per COA Pendapatan', 'COA Pendapatan'],
        'item'   => ['Per Item', 'Item'],
        default  => ['Per Transaksi', 'No IP'],
    };
    // Pembagi bilah proporsi - baris terbesar jadi 100%.
    $maks = $baris->max('penjualan') ?: 1;
@endphp

<div class="dsb">
    {{-- ===================== KEPALA & SARINGAN ===================== --}}
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">DAFTAR PENJUALAN</h2>
            <div class="dsb-sub">Total Penjualan, COGS dan Laba Kotor &middot; {{ $namaPt }} &middot; {{ $namaBulan }}</div>
        </div>

        <select class="form-select form-select-sm" style="width: 11rem" wire:model.live="pt">
            <option value="">Semua PT</option>
            @foreach ($ptList as $p)
                <option value="{{ $p->id }}">{{ $p->nama }}</option>
            @endforeach
        </select>

        {{-- Bulan: kotak month + dua tombol geser, supaya pindah bulan tidak perlu
             membuka pemilih tanggal tiap kali. --}}
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" wire:click="geserBulan(-1)" title="Bulan sebelumnya">
                <i class="fas fa-chevron-left"></i>
            </button>
            <input type="month" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="bulan">
            <button type="button" class="btn btn-outline-secondary" wire:click="geserBulan(1)" title="Bulan berikutnya">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        {{-- Satu-satunya pemicu penarikan data. Ditandai saat saringan berubah supaya
             user tahu angka di layar belum mengikuti pilihan barunya. --}}
        <button type="button"
                class="btn btn-sm {{ $tampil ? 'btn-outline-primary' : 'btn-primary' }}"
                wire:click="tampilkan" wire:loading.attr="disabled" wire:target="tampilkan">
            <i class="fas fa-rotate me-1" wire:loading.class="fa-spin" wire:target="tampilkan"></i>
            Tampilkan
        </button>
    </div>

    {{-- ===================== BILAH KEMAJUAN =====================
         Kuerinya menyapu seluruh baris IP sebulan (Juni 2026 saja 15.804 dokumen), jadi
         jedanya terasa. `wire:target` menyebut SEMUA aksi yg menarik data - kalau tidak,
         bilahnya cuma muncul saat "Tampilkan" dan "Detailkan" terasa menggantung. --}}
    <div wire:loading.flex wire:target="tampilkan,detailkan,kembali,keTingkat,keHalaman,toggleAlkes,toggleInfo,geserBulan,pt,bulan"
         class="dsb-progres">
        <div class="bar"><span></span></div>
        <div class="ket">Sedang menarik data…</div>
    </div>

    {{-- Sebelum "Tampilkan" ditekan: layar sengaja KOSONG, tidak ada kueri berat yg jalan. --}}
    @unless ($tampil)
        <div class="dsb-kosong dsb-mulai">
            <i class="fas fa-chart-line fa-2x mb-2 d-block"></i>
            @if ($saringanBerubah)
                <div class="fw-semibold mb-1">Saringan sudah diubah.</div>
                Klik <strong>Tampilkan</strong> untuk menarik data {{ $namaPt }} &middot; {{ $namaBulan }}.
            @else
                <div class="fw-semibold mb-1">Siap menarik data.</div>
                Pilih PT &amp; bulan di atas, lalu klik <strong>Tampilkan</strong>.
            @endif
            <div class="mt-2" style="font-size:.75rem">
                Data tidak ditarik otomatis karena sekali tarik menyapu seluruh transaksi
                satu bulan penuh.
            </div>
        </div>
    @endunless

    @if ($tampil)
    {{-- ===================== REMAH JEJAK ===================== --}}
    @if ($jalur !== [])
        <div class="dsb-remah">
            <button type="button" wire:click="keTingkat(0)">{{ $namaPt }}</button>
            @foreach ($jalur as $i => $l)
                <span class="pisah">&rsaquo;</span>
                @if ($i === count($jalur) - 1)
                    <span class="kini">{{ $l['nama'] }}</span>
                @else
                    <button type="button" wire:click="keTingkat({{ $i + 1 }})">{{ $l['nama'] }}</button>
                @endif
            @endforeach
            <button type="button" class="ms-2" wire:click="kembali">&larr; Kembali</button>
        </div>
    @endif

    {{-- Penarikan data gagal. Tanpa pesan ini, galat hanya terlihat sbg bilah kemajuan yg
         berjalan terus - user menunggu sesuatu yg tidak akan pernah datang. Diletakkan
         SESUDAH remah jejak supaya tombol "Kembali" tetap bisa dipakai utk keluar dari
         tingkat yg bermasalah. --}}
    @if ($galat)
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>
                Data gagal ditarik.</div>
            <div style="font-size:.8rem">{{ $galat }}</div>
            <div class="mt-1" style="font-size:.75rem">
                Galatnya sudah dicatat di <code>storage/logs/laravel.log</code>. Tekan
                <strong>&larr; Kembali</strong> di atas untuk keluar dari tingkat ini.
            </div>
        </div>
    @else

    {{-- ===================== KARTU RINGKASAN ===================== --}}
    {{-- Susunannya mengikuti rancangan: Penjualan / COGS / Laba Kotor di depan, lalu
         pecahan "DETAIL PENJUALAN" (Produk, Alkes, PPN, Tindakan) & "NILAI COGS". --}}
    <div class="dsb-ringkas">
        <div class="dsb-kartu w-jual">
            <div class="label">Nilai Penjualan</div>
            <div class="nilai">{{ $rp($total->penjualan) }}</div>
            <div class="kecil">{{ $rp($total->jmlTransaksi) }} transaksi &middot; qty {{ $rp2($total->qty) }}</div>
        </div>
        <div class="dsb-kartu w-cogs">
            <div class="label">Nilai COGS</div>
            <div class="nilai">{{ $rp($total->cogs) }}</div>
            <div class="kecil">Produk {{ $rp($total->cogsProduk) }} &middot; Alkes {{ $rp($total->cogsAlkes) }}</div>
        </div>
        <div class="dsb-kartu w-laba">
            <div class="label">Laba Kotor</div>
            <div class="nilai">{{ $rp($total->labaKotor) }}</div>
            <div class="kecil">Margin {{ number_format($total->marginPersen, 1, ',', '.') }}%</div>
        </div>
        <div class="dsb-kartu w-klinik">
            <div class="label">Pendapatan Klinik</div>
            <div class="nilai">{{ $rp($total->pendapatanKlinik) }}</div>
            <div class="kecil">Penjualan dikurangi produk, alkes &amp; PPN</div>
        </div>
    </div>

    <div class="dsb-ringkas">
        <div class="dsb-kartu w-jual">
            <div class="label">Penjualan Produk</div>
            <div class="nilai">{{ $rp($total->penjProduk) }}</div>
            <div class="kecil">COGS &times; {{ number_format(\App\Services\DashboardPenjualan::MARKUP, 2, ',', '.') }}</div>
        </div>
        <div class="dsb-kartu w-jual">
            <div class="label">Penjualan Alkes</div>
            <div class="nilai">{{ $rp($total->penjAlkes) }}</div>
            <div class="kecil">COGS &times; {{ number_format(\App\Services\DashboardPenjualan::MARKUP, 2, ',', '.') }}</div>
        </div>
        <div class="dsb-kartu w-ppn">
            <div class="label">PPN {{ rtrim(rtrim(number_format(\App\Services\DashboardPenjualan::PPN * 100, 1, ',', '.'), '0'), ',') }}%</div>
            <div class="nilai">{{ $rp($total->ppn) }}</div>
            <div class="kecil">atas produk + alkes</div>
        </div>
        <div class="dsb-kartu w-cogs">
            <div class="label">COGS Produk / Alkes</div>
            <div class="nilai">{{ $rp($total->cogsProduk) }}</div>
            <div class="kecil">Alkes {{ $rp($total->cogsAlkes) }}</div>
        </div>
    </div>

    {{-- ===================== TABEL TINGKAT ===================== --}}
    <div class="dsb-sub mb-1 d-flex flex-wrap gap-2 align-items-center">
        <span>{{ $judulTingkat }}</span>
        @if ($adaSisa)
            {{-- Baris dibatasi supaya respons Livewire tidak membengkak - lihat
                 `DashboardPenjualan::BATAS_BARIS`. Angka di kartu atas TETAP dari
                 seluruh data, jadi ringkasannya tidak ikut terpotong. --}}
            <span class="dsb-margin">
                <i class="fas fa-circle-info me-1"></i>
                {{ number_format($batas, 0, ',', '.') }} teratas ditampilkan &middot;
                total di atas tetap dari seluruh data
            </span>
        @endif

        @if ($this->tingkat() === 'transaksi')
            <span class="dsb-margin">
                {{ number_format($jmlBaris, 0, ',', '.') }} baris &middot;
                halaman {{ $halaman }} dari {{ number_format($jmlHalaman, 0, ',', '.') }}
            </span>
            {{-- Ekspor lewat route BIASA (listener `report-excel-download` di
                 `dias-helpers.js`), jadi tab Workspace lain tidak terganggu. Yg diunduh
                 SELURUH saringan, bukan halaman yg sedang tampil. --}}
            <button type="button" class="btn btn-sm btn-outline-success ms-auto text-nowrap"
                    wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                <i class="fas fa-file-excel me-1"></i>Export Excel
            </button>
        @endif
    </div>

    @if ($baris->isEmpty())
        <div class="dsb-kosong">
            <i class="fas fa-chart-column fa-lg mb-2 d-block"></i>
            Tidak ada penjualan pada {{ $namaBulan }}{{ $pt ? ' untuk ' . $namaPt : '' }}.
            <div class="mt-1" style="font-size:.8rem">Coba ganti bulan atau pilih Semua PT.</div>
        </div>
    @else
        <div class="table-responsive" style="max-height: 60vh; overflow-y: auto">
            <table class="dsb-tabel">
                <thead>
                    <tr>
                        <th>{{ $kolom1 }}</th>
                        @if ($this->tingkat() === 'transaksi')
                            {{-- Kolom Item wajib ada di sini: tingkat ini dipecah per baris
                                 `fstokd`, jadi satu No IP bisa muncul beberapa kali dan
                                 tanpa nama item barisnya terlihat kembar. --}}
                            <th>Tanggal</th><th>Nama Pasien</th><th>Item</th>
                        @endif
                        <th class="angka">Qty</th>
                        <th class="angka">Penjualan</th>
                        <th class="angka">Produk</th>
                        <th class="angka">Alkes</th>
                        <th class="angka">PPN</th>
                        <th class="angka">Pendapatan Klinik</th>
                        <th class="angka">COGS Produk</th>
                        <th class="angka">COGS Alkes</th>
                        <th class="angka">Laba Kotor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($baris as $r)
                        <tr wire:key="dsb-{{ $this->tingkat() }}-{{ $r->id }}">
                            <td class="nama dsb-bilah">
                                {{-- lebar bilah = porsi baris ini thd baris terbesar --}}
                                <span class="isi" style="width: {{ max(0, min(100, $r->penjualan / $maks * 100)) }}%"></span>
                                <span class="teks">{{ $r->nama ?: '(tanpa nama)' }}</span>
                            </td>
                            @if ($this->tingkat() === 'transaksi')
                                {{-- `Carbon::parse` MELEMPAR galat utk tanggal tak sah
                                     ('0000-00-00' masih mungkin ada di data legacy) - dan
                                     galat di sini membuat seluruh request Livewire gagal,
                                     yg di layar terlihat sbg bilah kemajuan berjalan terus
                                     tanpa tabel. Satu baris cacat tidak boleh menjatuhkan
                                     halaman. --}}
                                <td>
                                    @php
                                        try {
                                            $tgl = $r->tanggal
                                                ? \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') : '—';
                                        } catch (\Throwable $e) {
                                            $tgl = '—';
                                        }
                                    @endphp
                                    {{ $tgl }}
                                </td>
                                <td>{{ $r->pasien ?: '—' }}</td>
                                <td>{{ $r->item ?? '—' }}</td>
                            @endif
                            <td class="angka">{{ $rp2($r->qty) }}</td>
                            <td class="angka dsb-jual-t">{{ $rp($r->penjualan) }}</td>
                            <td class="angka">{{ $rp($r->penjProduk) }}</td>
                            <td class="angka">{{ $rp($r->penjAlkes) }}</td>
                            <td class="angka">{{ $rp($r->ppn) }}</td>
                            <td class="angka">{{ $rp($r->pendapatanKlinik) }}</td>
                            <td class="angka dsb-cogs-t">{{ $rp($r->cogsProduk) }}</td>
                            <td class="angka dsb-cogs-t">{{ $rp($r->cogsAlkes) }}</td>
                            <td class="angka dsb-laba-t">
                                {{ $rp($r->labaKotor) }}
                                <span class="dsb-margin">{{ number_format($r->marginPersen, 0) }}%</span>
                            </td>
                            <td class="text-end">
                                @if ($this->tingkat() === 'transaksi')
                                    {{-- Tingkat terdalam: tidak ada "Detailkan" lagi, yg ada
                                         tombol2 rincian baris ini. Paket/Promo/Dokter jadi
                                         TOMBOL, bukan kolom (permintaan user) - tabelnya
                                         sudah 14 kolom, tiga kolom teks lagi membuatnya
                                         harus digeser ke samping utk membaca angkanya. --}}
                                    <div class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                wire:click="toggleInfo({{ $r->id }})">
                                            {{ $infoDibuka === (int) $r->id ? 'Tutup' : 'Detail Paket' }}
                                        </button>
                                        {{-- Muncul selama baris alkesnya ADA, bukan hanya kalau
                                             COGS-nya > 0 - alkes yg HPP-nya belum dihitung tetap
                                             harus kelihatan (lihat `DashboardPenjualan::baris()`). --}}
                                        @if (($r->jmlAlkes ?? 0) > 0)
                                            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                    wire:click="toggleAlkes({{ $r->id }})">
                                                {{ $alkesDibuka === (int) $r->id ? 'Tutup' : 'Detail Alkes' }}
                                            </button>
                                            @if ((float) ($r->nilaiAlkes ?? 0) == 0.0)
                                                <span class="badge text-bg-warning align-self-center"
                                                      title="Alkes sudah diinput, tapi harga pokoknya (SDBELI) masih 0 - COGS Alkes belum terhitung.">
                                                    HPP belum dihitung
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                            wire:click="detailkan({{ $r->id }}, @js($r->nama ?: '(tanpa nama)'))">
                                        Detailkan <i class="fas fa-chevron-right ms-1"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if ($this->tingkat() === 'transaksi' && $infoDibuka === (int) $r->id)
                            <tr class="dsb-alkes" wire:key="info-{{ $r->id }}">
                                <td colspan="14">
                                    <table>
                                        <thead>
                                            <tr><th>Nama Paket</th><th>Nama Promo</th><th>Nama Dokter</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    {{ $info?->paket ?: '—' }}
                                                    {{-- Nomor koli & kedatangan menyertai paket: satu paket
                                                         dipakai berkali-kali, tanpa ini barisnya kembar. --}}
                                                    @if ($info?->paket && $info?->koli)
                                                        <span class="dsb-sub">&middot; No {{ $info->koli }}
                                                            &middot; kedatangan ke-{{ $info->kedatangan }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ $info?->promo ?: '—' }}</td>
                                                <td>{{ $info?->dokter ?: '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif

                        @if ($this->tingkat() === 'transaksi' && $alkesDibuka === (int) $r->id)
                            <tr class="dsb-alkes" wire:key="alkes-{{ $r->id }}">
                                <td colspan="14">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Kode Alkes</th><th>Nama Alkes</th>
                                                <th class="angka text-end">Qty</th><th class="angka text-end">COGS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($alkes as $a)
                                                <tr>
                                                    <td>{{ $a->kode }}</td>
                                                    <td>{{ $a->nama }}</td>
                                                    <td class="text-end">{{ $rp2($a->qty) }}</td>
                                                    <td class="text-end">{{ $rp($a->cogs) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="4" class="text-center">Tidak ada rincian alkes.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                    {{-- Rincian ini milik BARIS-nya (`SDIDUALKES`), bukan seluruh nota,
                                         jadi jumlahnya pas dgn kolom COGS Alkes di baris itu. Catatan
                                         lama di sini menyebut pembagian proporsional per dokumen -
                                         cara itu sudah dibuang. --}}
                                    <div class="dsb-sub mt-1" style="font-size:.7rem">
                                        Alkes terpakai pada baris {{ $r->item ?? '—' }} ({{ $r->nama }}).
                                        @if ((float) ($r->nilaiAlkes ?? 0) == 0.0 && $alkes->isNotEmpty())
                                            <span class="text-warning fw-semibold">
                                                Harga pokok (SDBELI) alkes ini masih 0 - HPP belum dihitung,
                                                jadi COGS Alkes baris ini belum terisi.
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($this->tingkat() === 'transaksi' && $jmlHalaman > 1)
            {{-- Pager sederhana: lompat ke ujung, geser satu-satu, dan sejumlah nomor di
                 sekitar halaman yg sedang dibuka. Tidak menampilkan SEMUA nomor - sebulan
                 penuh bisa ratusan halaman dan barisnya sendiri jadi lebih panjang
                 daripada tabelnya. --}}
            <div class="d-flex flex-wrap gap-1 align-items-center mt-2 dsb-sub">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="keHalaman(1)" @disabled($halaman <= 1)>&laquo;</button>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="keHalaman({{ $halaman - 1 }})" @disabled($halaman <= 1)>&lsaquo;</button>

                @php
                    $dari = max(1, $halaman - 2);
                    $sampai = min($jmlHalaman, $dari + 4);
                    $dari = max(1, $sampai - 4);
                @endphp
                @for ($h = $dari; $h <= $sampai; $h++)
                    <button type="button"
                            class="btn btn-sm {{ $h === $halaman ? 'btn-primary' : 'btn-outline-secondary' }}"
                            wire:click="keHalaman({{ $h }})">{{ $h }}</button>
                @endfor

                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="keHalaman({{ $halaman + 1 }})" @disabled($halaman >= $jmlHalaman)>&rsaquo;</button>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="keHalaman({{ $jmlHalaman }})" @disabled($halaman >= $jmlHalaman)>&raquo;</button>

                <span class="ms-2">
                    Baris {{ number_format(($halaman - 1) * $perHalaman + 1, 0, ',', '.') }}&ndash;{{ number_format(min($halaman * $perHalaman, $jmlBaris), 0, ',', '.') }}
                    dari {{ number_format($jmlBaris, 0, ',', '.') }}
                </span>
            </div>
        @endif
    @endif
    @endif{{-- /$galat --}}
    @endif{{-- /$tampil --}}
</div>
