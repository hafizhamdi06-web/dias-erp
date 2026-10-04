{{-- IP Penjualan Per Dokter (VB6 menu 583) - lihat ReportController::dataIpPenjualanPerDokter(). --}}
<x-reports.layout :title="$title"
                  :subtitle="$subtitle . ($rinci ? ' — rinci per kelompok' : '')"
                  :company="$company">
    <table>
        <thead>
            <tr>
                <th style="width:14%">Kode Dokter</th>
                <th>Nama Dokter</th>
                @if ($rinci)
                    <th style="width:14%">Kelompok</th>
                @endif
                <th class="text-end" style="width:10%">Jml Pasien</th>
                <th class="text-end" style="width:10%">Qty</th>
                <th class="text-end" style="width:16%">Nilai</th>
                <th class="text-end" style="width:12%">Alkes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->kodeDokter ?: '—' }}</td>
                    <td>{{ $x->namaDokter }}</td>
                    @if ($rinci)
                        <td>{{ $x->kelompok ?: '—' }}</td>
                    @endif
                    <td class="text-end">{{ number_format((int) $x->pasien, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->qty, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->nilai, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->alkes, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $rinci ? 7 : 6 }}" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            <tr>
                <th colspan="{{ $rinci ? 3 : 2 }}" class="text-end">TOTAL</th>
                <th class="text-end">{{ number_format($total['pasien'], 0, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['qty'], 2, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['nilai'], 0, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['alkes'], 0, ',', '.') }}</th>
            </tr>
        </tbody>
    </table>

    {{-- Jumlah Pasien dijumlah LINTAS dokter/kelompok, jadi pasien yg ditangani lebih dari
         satu dokter terhitung di masing-masing - sama seperti cetakan lama. --}}
    <p style="font-size:8px; color:#777; margin-top:6px">
        Jumlah Pasien dihitung per tanggal (satu pasien pada satu tanggal = satu).
        Total Jml Pasien menjumlahkan antar-baris, sehingga pasien yang ditangani lebih dari satu
        dokter ikut terhitung di masing-masing dokter.
    </p>
</x-reports.layout>
