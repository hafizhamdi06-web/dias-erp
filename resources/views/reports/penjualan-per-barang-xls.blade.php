<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0"; text-align: right; }
        .pct { mso-number-format: "0.00\%"; text-align: right; }
        h2, h4 { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <h4>{{ $subtitle }}</h4>
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
                <th>Qty</th>
                <th>Harga</th>
                <th>Disc 1</th>
                <th>Disc 2</th>
                <th>Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    @if ($showItemColumn)
                        <td>{{ $r->itemKode }} — {{ $r->itemNama }}</td>
                    @endif
                    <td>{{ $r->nomor }}</td>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                    <td>{{ $r->pasien }}</td>
                    <td>{{ $r->hp }}</td>
                    <td class="num">{{ round($r->qty, 2) }}</td>
                    <td class="num">{{ round($r->harga) }}</td>
                    <td class="pct">{{ round($r->disc1 / 100, 4) }}</td>
                    <td class="pct">{{ round($r->disc2 / 100, 4) }}</td>
                    <td class="num">{{ round($r->jumlah) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="{{ $showItemColumn ? 5 : 4 }}">Total</th>
                <th class="num">{{ round($totalQty, 2) }}</th>
                <th colspan="3"></th>
                <th class="num">{{ round($totalJumlah) }}</th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
