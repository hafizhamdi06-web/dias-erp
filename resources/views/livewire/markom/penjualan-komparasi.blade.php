{{-- Dashboard Penjualan Markom - 2. Penjualan Komparasi (Periode A vs Periode B). --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    // Selisih A - B & persen pertumbuhan terhadap B. B = 0 -> persen tidak bermakna ('—').
    $tumbuh = function ($a, $b) use ($rp) {
        $s = $a - $b;
        $kelas = $s > 0 ? 'text-success' : ($s < 0 ? 'text-danger' : 'text-muted');
        $panah = $s > 0 ? '▲' : ($s < 0 ? '▼' : '');
        $persen = $b != 0 ? number_format($s / abs($b) * 100, 1, ',', '.') . '%' : '—';

        return ['selisih' => ($s > 0 ? '+' : '') . $rp($s), 'persen' => trim($panah . ' ' . $persen), 'kelas' => $kelas];
    };
@endphp

<div class="dsb">
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">PENJUALAN KOMPARASI</h2>
            <div class="dsb-sub">Periode A vs Periode B &middot; {{ $labelCabang }}</div>
        </div>

        @include('livewire.markom.partials.cabang')

        <button type="button" class="btn btn-sm {{ $tampil ? 'btn-outline-primary' : 'btn-primary' }}"
                wire:click="tampilkan" wire:loading.attr="disabled" wire:target="tampilkan">
            <i class="fas fa-rotate me-1" wire:loading.class="fa-spin" wire:target="tampilkan"></i> Tampilkan
        </button>
    </div>

    <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <div>
            <label class="form-label mb-1 small fw-semibold">Periode A</label>
            <div class="d-flex align-items-center gap-1">
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dariA">
                <span class="text-muted small">s/d</span>
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampaiA">
            </div>
        </div>
        <div class="text-muted pb-1">vs</div>
        <div>
            <label class="form-label mb-1 small fw-semibold">Periode B (pembanding)</label>
            <div class="d-flex align-items-center gap-1">
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dariB">
                <span class="text-muted small">s/d</span>
                <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampaiB">
            </div>
        </div>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" wire:click="samakanBulanLalu" title="Periode B = Periode A bulan lalu">Bulan lalu</button>
            <button type="button" class="btn btn-outline-secondary" wire:click="samakanTahunLalu" title="Periode B = Periode A tahun lalu">Tahun lalu</button>
        </div>
    </div>

    @include('livewire.markom.partials.status', ['target' => 'tampilkan'])

    @if ($tampil && ! $galat)
        @php
            $tj = $tumbuh($totalA->penjualan, $totalB->penjualan);
            $tp = $tumbuh($totalA->pasien, $totalB->pasien);
        @endphp
        <div class="dsb-ringkas">
            <div class="dsb-kartu w-jual">
                <div class="label">Nilai Penjualan A</div>
                <div class="nilai">{{ $rp($totalA->penjualan) }}</div>
                <div class="kecil">B: {{ $rp($totalB->penjualan) }} &middot;
                    <span class="{{ $tj['kelas'] }} fw-semibold">{{ $tj['selisih'] }} ({{ $tj['persen'] }})</span></div>
            </div>
            <div class="dsb-kartu w-laba">
                <div class="label">Jumlah Pasien A</div>
                <div class="nilai">{{ $rp($totalA->pasien) }}</div>
                <div class="kecil">B: {{ $rp($totalB->pasien) }} &middot;
                    <span class="{{ $tp['kelas'] }} fw-semibold">{{ $tp['selisih'] }} ({{ $tp['persen'] }})</span></div>
            </div>
        </div>
        <div class="dsb-sub mb-1">A = {{ $periodeA }} &middot; B = {{ $periodeB }}</div>

        @if ($baris->isEmpty())
            <div class="dsb-kosong">Tidak ada penjualan di kedua periode.</div>
        @else
            <div class="table-responsive" style="max-height: 60vh; overflow-y: auto">
                <table class="dsb-tabel">
                    <thead>
                        <tr>
                            <th rowspan="2">Cabang</th>
                            <th class="text-center" colspan="4">Nilai Penjualan</th>
                            <th class="text-center" colspan="4">Jumlah Pasien</th>
                        </tr>
                        <tr>
                            <th class="angka">A</th><th class="angka">B</th><th class="angka">Selisih</th><th class="angka">%</th>
                            <th class="angka">A</th><th class="angka">B</th><th class="angka">Selisih</th><th class="angka">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($baris as $r)
                            @php($j = $tumbuh($r->jualA, $r->jualB))
                            @php($p = $tumbuh($r->pasA, $r->pasB))
                            <tr wire:key="mk-{{ $r->id }}">
                                <td class="nama">{{ $r->nama ?: '(tanpa cabang)' }}</td>
                                <td class="angka dsb-jual-t">{{ $rp($r->jualA) }}</td>
                                <td class="angka">{{ $rp($r->jualB) }}</td>
                                <td class="angka {{ $j['kelas'] }}">{{ $j['selisih'] }}</td>
                                <td class="angka {{ $j['kelas'] }}">{{ $j['persen'] }}</td>
                                <td class="angka">{{ $rp($r->pasA) }}</td>
                                <td class="angka">{{ $rp($r->pasB) }}</td>
                                <td class="angka {{ $p['kelas'] }}">{{ $p['selisih'] }}</td>
                                <td class="angka {{ $p['kelas'] }}">{{ $p['persen'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="fw-bold">
                            <td>TOTAL</td>
                            <td class="angka">{{ $rp($totalA->penjualan) }}</td>
                            <td class="angka">{{ $rp($totalB->penjualan) }}</td>
                            <td class="angka {{ $tj['kelas'] }}">{{ $tj['selisih'] }}</td>
                            <td class="angka {{ $tj['kelas'] }}">{{ $tj['persen'] }}</td>
                            <td class="angka">{{ $rp($totalA->pasien) }}</td>
                            <td class="angka">{{ $rp($totalB->pasien) }}</td>
                            <td class="angka {{ $tp['kelas'] }}">{{ $tp['selisih'] }}</td>
                            <td class="angka {{ $tp['kelas'] }}">{{ $tp['persen'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
