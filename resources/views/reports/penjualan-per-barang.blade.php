<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    @php $cols = 9 + ($showItemColumn ? 1 : 0); @endphp
    <table>
        <thead>
            <tr>
                @if ($showItemColumn)
                    <th>Item</th>
                @endif
                <th>No Transaksi</th>
                <th>Tanggal</th>
                <th>Nama Pasien</th>
                <th>No. HP Pasien</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Harga</th>
                <th class="text-end">Disc 1</th>
                <th class="text-end">Disc 2</th>
                <th class="text-end">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    @if ($showItemColumn)
                        <td>{{ $r->itemKode }} — {{ $r->itemNama }}</td>
                    @endif
                    <td>{{ $r->nomor }}</td>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                    <td>{{ $r->pasien ?: '—' }}</td>
                    <td>{{ $r->hp ?: '—' }}</td>
                    <td class="text-end">{{ number_format($r->qty, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format($r->harga, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format($r->disc1, 2, ',', '.') }}%</td>
                    <td class="text-end">{{ number_format($r->disc2, 2, ',', '.') }}%</td>
                    <td class="text-end">{{ number_format($r->jumlah, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="text-align:center">Tidak ada data penjualan pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="{{ $showItemColumn ? 5 : 4 }}" class="text-end">Total</th>
                    <th class="text-end">{{ number_format($totalQty, 0, ',', '.') }}</th>
                    <th colspan="3"></th>
                    <th class="text-end">{{ number_format($totalJumlah, 0, ',', '.') }}</th>
                </tr>
            </tfoot>
        @endif
    </table>
</x-reports.layout>
