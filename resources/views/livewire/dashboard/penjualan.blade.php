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
    </div>

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
    <div class="dsb-sub mb-1">{{ $judulTingkat }}</div>

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
                            <th>Tanggal</th><th>Nama Pasien</th>
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
                                <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                                <td>{{ $r->pasien ?: '—' }}</td>
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
                                    {{-- Tingkat terdalam: yg bisa dibuka tinggal rincian alkesnya. --}}
                                    @if ($r->cogsAlkes > 0)
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                wire:click="toggleAlkes(@js($r->nama))">
                                            {{ $alkesDibuka === $r->nama ? 'Tutup' : 'Detail Alkes' }}
                                        </button>
                                    @endif
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                            wire:click="detailkan({{ $r->id }}, @js($r->nama ?: '(tanpa nama)'))">
                                        Detailkan <i class="fas fa-chevron-right ms-1"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if ($this->tingkat() === 'transaksi' && $alkesDibuka === $r->nama)
                            <tr class="dsb-alkes" wire:key="alkes-{{ $r->id }}">
                                <td colspan="13">
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
                                    {{-- COGS alkes di baris transaksi adalah BAGIAN proporsional dari
                                         dokumen alkes (lihat DashboardPenjualan), jadi jumlah rincian
                                         di atas bisa lebih besar kalau satu IP punya banyak baris. --}}
                                    <div class="dsb-sub mt-1" style="font-size:.7rem">
                                        Rincian ini milik seluruh dokumen alkes No IP {{ $r->nama }}.
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
