{{-- Stok Per Hari (VB6 menu 417) - lihat ReportController::dataStokPerHari(). --}}
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <table>
        <thead>
            <tr>
                <th style="width:16%">Kode</th>
                <th>Nama Item</th>
                <th class="text-end" style="width:9%">Saldo Awal</th>
                <th class="text-end" style="width:9%">Masuk</th>
                <th class="text-end" style="width:9%">Keluar</th>
                <th class="text-end" style="width:9%">Stok Akhir</th>
                <th class="text-end" style="width:10%">COGS</th>
                <th class="text-end" style="width:10%">Harga Jual</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->kode }}</td>
                    <td>{{ $x->nama }}</td>
                    <td class="text-end">{{ number_format((float) $x->saldoAwal, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->masuk, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->keluar, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->akhir, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->cogs, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->hargaJual, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            <tr>
                <th colspan="2" class="text-end">TOTAL</th>
                <th class="text-end">{{ number_format($total['saldoAwal'], 2, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['masuk'], 2, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['keluar'], 2, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['akhir'], 2, ',', '.') }}</th>
                <th colspan="2"></th>
            </tr>
        </tbody>
    </table>
</x-reports.layout>
