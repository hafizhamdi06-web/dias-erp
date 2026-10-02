{{-- Daftar Surat Jalan Barang (VB6 menu 451) - lihat ReportController::dataDaftarSuratJalan(). --}}
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <table>
        <thead>
            <tr>
                <th style="width:11%">No SJ</th>
                <th style="width:7%">Tanggal</th>
                <th style="width:12%">Kode</th>
                <th>Nama Item</th>
                <th style="width:5%">Sat</th>
                <th class="text-end" style="width:6%">Qty</th>
                <th style="width:13%">Pelanggan</th>
                <th style="width:10%">Sales</th>
                <th style="width:7%">Asal</th>
                <th style="width:7%">Tujuan</th>
                <th style="width:8%">PT</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->nomor }}</td>
                    <td>{{ \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') }}</td>
                    <td>{{ $x->kode }}</td>
                    <td>{{ $x->nama }}</td>
                    <td>{{ $x->satuan }}</td>
                    <td class="text-end">{{ number_format((float) $x->qty, 2, ',', '.') }}</td>
                    <td>{{ $x->pelanggan ?: '—' }}</td>
                    <td>{{ $x->sales ?: '—' }}</td>
                    <td>{{ $x->gudangAsal ?: '—' }}</td>
                    <td>{{ $x->gudangTujuan ?: '—' }}</td>
                    <td>{{ $x->pt ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            <tr>
                <th colspan="5" class="text-end">TOTAL QTY</th>
                <th class="text-end">{{ number_format($totalQty, 2, ',', '.') }}</th>
                <th colspan="5"></th>
            </tr>
        </tbody>
    </table>
</x-reports.layout>
