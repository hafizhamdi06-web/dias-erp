{{-- Dashboard Penjualan Markom - 3. Penjualan Promo. Total per promo + rincian
     produk/tindakan per promo (tombol Detail). --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $maks = $baris->max('penjualan') ?: 1;
@endphp

<div class="dsb">
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">PENJUALAN PROMO</h2>
            <div class="dsb-sub">Total per promo &middot; {{ $labelCabang }} &middot; {{ $periode }}</div>
        </div>

        @include('livewire.markom.partials.cabang')

        <div class="d-flex align-items-center gap-1">
            <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dari">
            <span class="text-muted small">s/d</span>
            <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampai">
        </div>

        <button type="button" class="btn btn-sm {{ $tampil ? 'btn-outline-primary' : 'btn-primary' }}"
                wire:click="tampilkan" wire:loading.attr="disabled" wire:target="tampilkan">
            <i class="fas fa-rotate me-1" wire:loading.class="fa-spin" wire:target="tampilkan"></i> Tampilkan
        </button>
    </div>

    @include('livewire.markom.partials.status', ['target' => 'tampilkan,toggleDetail'])

    @if ($tampil && ! $galat)
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

        @if ($baris->isEmpty())
            <div class="dsb-kosong">Tidak ada penjualan promo pada periode ini.</div>
        @else
            <div class="table-responsive" style="max-height: 65vh; overflow-y: auto">
                <table class="dsb-tabel">
                    <thead><tr>
                        <th>Promo</th>
                        <th class="angka">Qty</th>
                        <th class="angka">Nilai Penjualan</th>
                        <th class="angka">Potongan</th>
                        <th class="angka">Pasien</th>
                        <th class="angka">Transaksi</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($baris as $r)
                            <tr wire:key="mp-{{ $r->id }}">
                                <td class="nama dsb-bilah">
                                    <span class="isi" style="width: {{ max(0, min(100, $r->penjualan / $maks * 100)) }}%"></span>
                                    <span class="teks">
                                        {{ $r->nama ?: '(tanpa nama)' }}
                                        @if ($r->kode)<span class="text-muted small fw-normal ms-1">{{ $r->kode }}</span>@endif
                                    </span>
                                </td>
                                <td class="angka">{{ $qty($r->qty) }}</td>
                                <td class="angka dsb-jual-t">{{ $rp($r->penjualan) }}</td>
                                <td class="angka dsb-cogs-t">{{ $rp($r->diskon) }}</td>
                                <td class="angka">{{ $rp($r->pasien) }}</td>
                                <td class="angka">{{ $rp($r->transaksi) }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                            wire:click="toggleDetail({{ $r->id }})">
                                        {{ $promoDibuka === (int) $r->id ? 'Tutup' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($promoDibuka === (int) $r->id)
                                <tr wire:key="mp-{{ $r->id }}-detail">
                                    <td colspan="7" class="p-0">
                                        <div class="ps-4 pe-2 py-2" style="background: var(--dsb-latar)">
                                            <div class="small fw-semibold mb-1">Produk / tindakan dalam promo {{ $r->nama }}</div>
                                            <table class="table table-sm mb-0 small">
                                                <thead><tr>
                                                    <th>Kode</th><th>Produk / Tindakan</th>
                                                    <th class="text-end">Qty</th>
                                                    <th class="text-end">Nilai Penjualan</th>
                                                    <th class="text-end">Potongan</th>
                                                    <th class="text-end">Pasien</th>
                                                </tr></thead>
                                                <tbody>
                                                    @forelse ($detail as $d)
                                                        <tr wire:key="mpd-{{ $r->id }}-{{ $d->id }}">
                                                            <td class="font-monospace">{{ $d->kode }}</td>
                                                            <td>{{ $d->nama ?: '—' }}</td>
                                                            <td class="text-end">{{ $qty($d->qty) }}</td>
                                                            <td class="text-end">{{ $rp($d->penjualan) }}</td>
                                                            <td class="text-end">{{ $rp($d->diskon) }}</td>
                                                            <td class="text-end">{{ $rp($d->pasien) }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6" class="text-muted text-center">Tidak ada item.</td></tr>
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
