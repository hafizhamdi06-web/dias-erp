{{-- Dashboard Penjualan Markom - 3. Penjualan Promo. Total per promo + rincian
     produk/tindakan per promo (tombol Detail). Mode "Bandingkan": Periode A vs B. --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $tumbuh = fn ($a, $b) => \App\Services\DashboardMarkom::tumbuh((float) $a, (float) $b);
    $banding = $mode === 'banding';
    $kolomJual = $banding ? 'penjualanA' : 'penjualan';
    $maks = $baris->max($kolomJual) ?: 1;
    $jmlKolom = $banding ? 11 : 7;
@endphp

<div class="dsb">
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">PENJUALAN PROMO</h2>
            <div class="dsb-sub">Total per promo &middot; {{ $labelCabang }} &middot; {{ $periode }}
                @if ($bandingkan) &middot; vs {{ $periodeB }} @endif</div>
        </div>

        @include('livewire.markom.partials.cabang')

        <button type="button" class="btn btn-sm {{ $tampil ? 'btn-outline-primary' : 'btn-primary' }}"
                wire:click="tampilkan" wire:loading.attr="disabled" wire:target="tampilkan">
            <i class="fas fa-rotate me-1" wire:loading.class="fa-spin" wire:target="tampilkan"></i> Tampilkan
        </button>
    </div>

    <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <div>
            <label class="form-label mb-1 small fw-semibold">{{ $bandingkan ? 'Periode A' : 'Periode' }}</label>
            <div class="d-flex align-items-center gap-1">
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dari">
                <span class="text-muted small">s/d</span>
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampai">
            </div>
        </div>
        <div class="form-check pb-1">
            <input type="checkbox" class="form-check-input" id="promo-banding" wire:model.live="bandingkan">
            <label class="form-check-label small fw-semibold" for="promo-banding">Bandingkan</label>
        </div>
        @if ($bandingkan)
            <div>
                <label class="form-label mb-1 small fw-semibold">Periode B (pembanding)</label>
                <div class="d-flex align-items-center gap-1">
                    <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dariB">
                    <span class="text-muted small">s/d</span>
                    <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampaiB">
                </div>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" wire:click="samakan('bulan')" title="Periode B = Periode A bulan lalu">Bulan lalu</button>
                <button type="button" class="btn btn-outline-secondary" wire:click="samakan('tahun')" title="Periode B = Periode A tahun lalu">Tahun lalu</button>
            </div>
        @endif
    </div>

    @include('livewire.markom.partials.status', ['target' => 'tampilkan,toggleDetail'])

    @if ($tampil && ! $galat)
        {{-- ===================== KARTU RINGKASAN ===================== --}}
        @if ($banding)
            @php
                $kartu = [
                    ['w-jual', 'Penjualan Promo', 'penjualan'],
                    ['w-cogs', 'Potongan Promo', 'diskon'],
                    ['w-laba', 'Pasien Promo', 'pasien'],
                ];
            @endphp
            <div class="dsb-ringkas">
                @foreach ($kartu as [$warna, $label, $k])
                    @php
                        $a = $baris->sum($k . 'A');
                        $b = $baris->sum($k . 'B');
                        $t = $tumbuh($a, $b);
                    @endphp
                    <div class="dsb-kartu {{ $warna }}">
                        <div class="label">{{ $label }} A</div>
                        <div class="nilai">{{ $rp($a) }}</div>
                        <div class="kecil">B: {{ $rp($b) }} &middot;
                            <span class="{{ $t['kelas'] }} fw-semibold">{{ $t['selisih'] }} ({{ $t['persen'] }})</span></div>
                    </div>
                @endforeach
            </div>
            <div class="dsb-sub mb-1">A = {{ $periode }} &middot; B = {{ $periodeB }}
                &middot; pasien dijumlah per promo</div>
        @else
            <div class="dsb-ringkas">
                <div class="dsb-kartu w-jual">
                    <div class="label">Total Penjualan Promo</div>
                    <div class="nilai">{{ $rp($baris->sum('penjualan')) }}</div>
                    <div class="kecil">{{ $rp($baris->count()) }} promo terpakai</div>
                </div>
                <div class="dsb-kartu w-cogs">
                    <div class="label">Total Potongan Promo</div>
                    <div class="nilai">{{ $rp($baris->sum('diskon')) }}</div>
                    <div class="kecil">qty &times; diskon per baris</div>
                </div>
                <div class="dsb-kartu w-laba">
                    <div class="label">Transaksi Promo</div>
                    <div class="nilai">{{ $rp($baris->sum('transaksi')) }}</div>
                    <div class="kecil">dijumlah per promo</div>
                </div>
            </div>
        @endif

        {{-- ===================== TABEL PROMO ===================== --}}
        @if ($baris->isEmpty())
            <div class="dsb-kosong">Tidak ada penjualan promo pada periode ini.</div>
        @else
            <div class="table-responsive" style="max-height: 65vh; overflow-y: auto">
                <table class="dsb-tabel">
                    <thead>
                        @if ($banding)
                            <tr>
                                <th rowspan="2">Promo</th>
                                <th class="text-center" colspan="4">Nilai Penjualan</th>
                                <th class="text-center" colspan="3">Pasien</th>
                                <th class="text-center" colspan="2">Potongan</th>
                                <th rowspan="2"></th>
                            </tr>
                            <tr>
                                <th class="angka">A</th><th class="angka">B</th><th class="angka">Selisih</th><th class="angka">%</th>
                                <th class="angka">A</th><th class="angka">B</th><th class="angka">%</th>
                                <th class="angka">A</th><th class="angka">B</th>
                            </tr>
                        @else
                            <tr>
                                <th>Promo</th>
                                <th class="angka">Qty</th>
                                <th class="angka">Nilai Penjualan</th>
                                <th class="angka">Potongan</th>
                                <th class="angka">Pasien</th>
                                <th class="angka">Transaksi</th>
                                <th></th>
                            </tr>
                        @endif
                    </thead>
                    <tbody>
                        @foreach ($baris as $r)
                            <tr wire:key="mp-{{ $r->id }}">
                                <td class="nama dsb-bilah">
                                    <span class="isi" style="width: {{ max(0, min(100, $r->{$kolomJual} / $maks * 100)) }}%"></span>
                                    <span class="teks">
                                        {{ $r->nama ?: '(tanpa nama)' }}
                                        @if ($r->kode)<span class="text-muted small fw-normal ms-1">{{ $r->kode }}</span>@endif
                                    </span>
                                </td>
                                @if ($banding)
                                    @php
                                        $j = $tumbuh($r->penjualanA, $r->penjualanB);
                                        $p = $tumbuh($r->pasienA, $r->pasienB);
                                    @endphp
                                    <td class="angka dsb-jual-t">{{ $rp($r->penjualanA) }}</td>
                                    <td class="angka">{{ $rp($r->penjualanB) }}</td>
                                    <td class="angka {{ $j['kelas'] }}">{{ $j['selisih'] }}</td>
                                    <td class="angka {{ $j['kelas'] }}">{{ $j['persen'] }}</td>
                                    <td class="angka">{{ $rp($r->pasienA) }}</td>
                                    <td class="angka">{{ $rp($r->pasienB) }}</td>
                                    <td class="angka {{ $p['kelas'] }}">{{ $p['persen'] }}</td>
                                    <td class="angka dsb-cogs-t">{{ $rp($r->diskonA) }}</td>
                                    <td class="angka">{{ $rp($r->diskonB) }}</td>
                                @else
                                    <td class="angka">{{ $qty($r->qty) }}</td>
                                    <td class="angka dsb-jual-t">{{ $rp($r->penjualan) }}</td>
                                    <td class="angka dsb-cogs-t">{{ $rp($r->diskon) }}</td>
                                    <td class="angka">{{ $rp($r->pasien) }}</td>
                                    <td class="angka">{{ $rp($r->transaksi) }}</td>
                                @endif
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                            wire:click="toggleDetail({{ $r->id }})">
                                        {{ $promoDibuka === (int) $r->id ? 'Tutup' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>

                            {{-- ============ RINCIAN PRODUK / TINDAKAN ============ --}}
                            @if ($promoDibuka === (int) $r->id)
                                <tr wire:key="mp-{{ $r->id }}-detail">
                                    <td colspan="{{ $jmlKolom }}" class="p-0">
                                        <div class="ps-4 pe-2 py-2" style="background: var(--dsb-latar)">
                                            <div class="small fw-semibold mb-1">Produk / tindakan dalam promo {{ $r->nama }}</div>
                                            <table class="table table-sm mb-0 small">
                                                <thead>
                                                    @if ($banding)
                                                        <tr>
                                                            <th>Kode</th><th>Produk / Tindakan</th>
                                                            <th class="text-end">Qty A</th><th class="text-end">Qty B</th>
                                                            <th class="text-end">Penjualan A</th><th class="text-end">Penjualan B</th>
                                                            <th class="text-end">%</th>
                                                            <th class="text-end">Pasien A</th><th class="text-end">Pasien B</th>
                                                        </tr>
                                                    @else
                                                        <tr>
                                                            <th>Kode</th><th>Produk / Tindakan</th>
                                                            <th class="text-end">Qty</th>
                                                            <th class="text-end">Nilai Penjualan</th>
                                                            <th class="text-end">Potongan</th>
                                                            <th class="text-end">Pasien</th>
                                                        </tr>
                                                    @endif
                                                </thead>
                                                <tbody>
                                                    @forelse ($detail as $d)
                                                        <tr wire:key="mpd-{{ $r->id }}-{{ $d->id }}">
                                                            <td class="font-monospace">{{ $d->kode }}</td>
                                                            <td>{{ $d->nama ?: '—' }}</td>
                                                            @if ($banding)
                                                                @php($dj = $tumbuh($d->penjualanA, $d->penjualanB))
                                                                <td class="text-end">{{ $qty($d->qtyA) }}</td>
                                                                <td class="text-end">{{ $qty($d->qtyB) }}</td>
                                                                <td class="text-end">{{ $rp($d->penjualanA) }}</td>
                                                                <td class="text-end">{{ $rp($d->penjualanB) }}</td>
                                                                <td class="text-end {{ $dj['kelas'] }}">{{ $dj['persen'] }}</td>
                                                                <td class="text-end">{{ $rp($d->pasienA) }}</td>
                                                                <td class="text-end">{{ $rp($d->pasienB) }}</td>
                                                            @else
                                                                <td class="text-end">{{ $qty($d->qty) }}</td>
                                                                <td class="text-end">{{ $rp($d->penjualan) }}</td>
                                                                <td class="text-end">{{ $rp($d->diskon) }}</td>
                                                                <td class="text-end">{{ $rp($d->pasien) }}</td>
                                                            @endif
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="{{ $banding ? 9 : 6 }}" class="text-muted text-center">Tidak ada item.</td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
