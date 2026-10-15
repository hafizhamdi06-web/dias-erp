{{-- Dashboard Penjualan Markom - 1. Penjualan Total. Gaya `dsb-*` dari
     public/css/dashboard-penjualan.css, rumus di App\Services\DashboardMarkom. --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $maks = $baris->max('penjualan') ?: 1;
@endphp

<div class="dsb">
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">PENJUALAN TOTAL</h2>
            <div class="dsb-sub">Nilai Penjualan &amp; Jumlah Pasien &middot; {{ $labelCabang }} &middot; {{ $periode }}</div>
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

    @include('livewire.markom.partials.status', ['target' => 'tampilkan'])

    @if ($tampil && ! $galat)
        <div class="dsb-ringkas">
            <div class="dsb-kartu w-jual">
                <div class="label">Nilai Penjualan</div>
                <div class="nilai">{{ $rp($total->penjualan) }}</div>
                <div class="kecil">{{ $rp($total->transaksi) }} transaksi</div>
            </div>
            <div class="dsb-kartu w-laba">
                <div class="label">Jumlah Pasien</div>
                <div class="nilai">{{ $rp($total->pasien) }}</div>
                <div class="kecil">1 pasien per hari dihitung 1</div>
            </div>
            <div class="dsb-kartu w-klinik">
                <div class="label">Rata-rata per Pasien</div>
                <div class="nilai">{{ $rp($total->pasien ? $total->penjualan / $total->pasien : 0) }}</div>
                <div class="kecil">Nilai penjualan &divide; jumlah pasien</div>
            </div>
        </div>

        <div class="dsb-sub mb-1">Per Cabang</div>
        @if ($baris->isEmpty())
            <div class="dsb-kosong">Tidak ada penjualan pada periode ini.</div>
        @else
            <div class="table-responsive" style="max-height: 60vh; overflow-y: auto">
                <table class="dsb-tabel">
                    <thead><tr>
                        <th>Cabang</th>
                        <th class="angka">Nilai Penjualan</th>
                        <th class="angka">Jumlah Pasien</th>
                        <th class="angka">Transaksi</th>
                        <th class="angka">Rata-rata / Pasien</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($baris as $r)
                            <tr wire:key="mt-{{ $r->id }}">
                                <td class="nama dsb-bilah">
                                    <span class="isi" style="width: {{ max(0, min(100, $r->penjualan / $maks * 100)) }}%"></span>
                                    <span class="teks">{{ $r->nama ?: '(tanpa cabang)' }}</span>
                                </td>
                                <td class="angka dsb-jual-t">{{ $rp($r->penjualan) }}</td>
                                <td class="angka">{{ $rp($r->pasien) }}</td>
                                <td class="angka">{{ $rp($r->transaksi) }}</td>
                                <td class="angka">{{ $rp($r->pasien ? $r->penjualan / $r->pasien : 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
