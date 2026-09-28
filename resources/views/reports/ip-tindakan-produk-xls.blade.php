{{-- Versi Excel Laporan IP Tindakan/Produk Per Bulan - data & aturan hitung SAMA PERSIS
     versi PDF (`ReportController::dataIpTindakanProduk()` dipakai bersama). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0"; text-align: right; }
        .num2 { mso-number-format: "#,##0.00"; text-align: right; }
        .grup { background: #e4e4e4; font-weight: bold; }
        .bulan { background: #f4f4f4; font-style: italic; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <h4>{{ $subtitle }}</h4>
    <p style="font-size:10px;color:#555">
        *Kolom Pasien per baris hanya menghitung transaksi yang ada harganya. Total pasien hanya di
        baris "Total &lt;Bulan&gt;" (1 pasien per hari = 1, walau transaksi berkali-kali).
    </p>

    <table>
        <thead>
            <tr>
                <th>Cabang</th>
                <th>Periode</th>
                <th>Tindakan / Produk</th>
                <th>Qty Transaksi</th>
                <th>Nilai</th>
                <th>Pasien</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($grup as $cabang)
                @foreach ($cabang['bulan'] as $bulan)
                    @foreach ($bulan['baris'] as $r)
                        {{-- Cabang & periode diulang tiap baris supaya bisa di-pivot di Excel. --}}
                        <tr>
                            <td>{{ $cabang['nama'] }}</td>
                            <td>{{ $bulan['label'] }}</td>
                            <td>{{ $r->barang }}</td>
                            <td class="num2">{{ (float) $r->qty }}</td>
                            <td class="num">{{ (float) $r->nilai }}</td>
                            <td class="num">{{ (int) $r->pasien }}</td>
                        </tr>
                    @endforeach
                    <tr class="bulan">
                        <td>{{ $cabang['nama'] }}</td>
                        <td>{{ $bulan['label'] }}</td>
                        <td>Total {{ $bulan['label'] }}</td>
                        <td class="num2">{{ (float) $bulan['qty'] }}</td>
                        <td class="num">{{ (float) $bulan['nilai'] }}</td>
                        <td class="num">{{ (int) $bulan['pasien'] }}</td>
                    </tr>
                @endforeach
                <tr class="grup">
                    <td>{{ $cabang['nama'] }}</td>
                    <td></td>
                    <td>Total Cabang {{ $cabang['nama'] }}</td>
                    <td class="num2">{{ (float) $cabang['qty'] }}</td>
                    <td class="num">{{ (float) $cabang['nilai'] }}</td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center">Tidak ada transaksi pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if ($grup !== [])
            <tfoot>
                <tr class="grup">
                    <td colspan="3">Grand Total</td>
                    <td class="num2">{{ (float) $totalQty }}</td>
                    <td class="num">{{ (float) $totalNilai }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
