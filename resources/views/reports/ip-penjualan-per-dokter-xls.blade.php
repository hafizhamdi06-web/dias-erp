{{-- Excel "IP Penjualan Per Dokter" - data SAMA PERSIS versi PDF. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .int { mso-number-format: "#,##0"; text-align: right; }
        .num { mso-number-format: "#,##0.00"; text-align: right; }
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>{{ $subtitle }}@if ($rinci) &mdash; rinci per kelompok @endif</p>

    <table>
        <thead>
            <tr>
                <th>Kode Dokter</th><th>Nama Dokter</th>
                @if ($rinci)<th>Kelompok</th>@endif
                <th>Jml Pasien</th><th>Qty</th><th>Nilai</th><th>Alkes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->kodeDokter }}</td>
                    <td class="txt">{{ $x->namaDokter }}</td>
                    @if ($rinci)<td class="txt">{{ $x->kelompok }}</td>@endif
                    <td class="int">{{ (int) $x->pasien }}</td>
                    <td class="num">{{ (float) $x->qty }}</td>
                    <td class="int">{{ (float) $x->nilai }}</td>
                    <td class="int">{{ (float) $x->alkes }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="{{ $rinci ? 3 : 2 }}">TOTAL</td>
                <td class="int">{{ $total['pasien'] }}</td>
                <td class="num">{{ $total['qty'] }}</td>
                <td class="int">{{ $total['nilai'] }}</td>
                <td class="int">{{ $total['alkes'] }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
