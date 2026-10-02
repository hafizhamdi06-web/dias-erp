{{-- Daftar Stok Barang Serial (VB6 menu 499) - lihat ReportController::dataDaftarStokSerial(). --}}
<x-reports.layout :title="$title"
                  :subtitle="'Posisi s/d ' . $tanggal . ($subtitle ? ' — ' . $subtitle : '')"
                  :company="$company">
    <table>
        <thead>
            <tr>
                <th style="width:16%">Kode</th>
                <th>Nama Item</th>
                <th style="width:18%">No Serial</th>
                <th style="width:9%">Expired</th>
                <th class="text-end" style="width:8%">Jumlah</th>
                <th style="width:6%">Sat</th>
                <th class="text-end" style="width:10%">Harga Beli</th>
                <th class="text-end" style="width:10%">Harga Jual</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->kode }}</td>
                    <td>{{ $x->nama }}</td>
                    <td>{{ $x->noSerial ?: '—' }}</td>
                    <td>{{ $x->expired ? \Carbon\Carbon::parse($x->expired)->format('d/m/Y') : '—' }}</td>
                    <td class="text-end">{{ number_format((float) $x->jumlah, 2, ',', '.') }}</td>
                    <td>{{ $x->satuan }}</td>
                    <td class="text-end">{{ number_format((float) $x->hargaBeli, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->hargaJual, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            <tr>
                <th colspan="4" class="text-end">TOTAL</th>
                <th class="text-end">{{ number_format((float) $total, 2, ',', '.') }}</th>
                <th colspan="3"></th>
            </tr>
        </tbody>
    </table>
</x-reports.layout>
