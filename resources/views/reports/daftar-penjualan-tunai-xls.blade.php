{{-- Versi Excel "Daftar Penjualan Tunai" - data & rumus SAMA PERSIS versi PDF
     (`ReportController::dataDaftarPenjualanTunai()` dipakai bersama). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0"; text-align: right; }
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Periode : {{ $subtitle }}</p>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Nomor</th>
                <th>Kontak</th>
                <th>Kas Nett</th>
                <th>Debit</th>
                <th>Kredit</th>
                <th>Transfer</th>
                <th>Merchant</th>
                <th>Total Real</th>
                <th>Voucher</th>
                <th>Piutang</th>
                <th>DP Surgery</th>
                <th>Surgery</th>
                <th>DP</th>
                <th>- Tarik DP</th>
                <th>Total Semua</th>
                <th>Cash Back</th>
                <th>Piutang Bayar</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td class="txt">{{ \Carbon\Carbon::parse($r->tanggal)->format('d-m-Y') }}</td>
                    <td class="txt">{{ $r->nomor }}</td>
                    <td class="txt">{{ $r->kontak }}</td>
                    <td class="num">{{ $r->kas }}</td>
                    <td class="num">{{ $r->debit }}</td>
                    <td class="num">{{ $r->kredit }}</td>
                    <td class="num">{{ $r->transfer }}</td>
                    <td class="num">{{ $r->merchant }}</td>
                    <td class="num">{{ $r->totalReal }}</td>
                    <td class="num">{{ $r->voucher }}</td>
                    <td class="num">{{ $r->piutang }}</td>
                    <td class="num">{{ $r->dpSurgery }}</td>
                    <td class="num">{{ $r->surgery }}</td>
                    <td class="num">{{ $r->dp }}</td>
                    <td class="num">{{ $r->tarikDp * -1 }}</td>
                    <td class="num">{{ $r->totalSemua }}</td>
                    <td class="num">{{ $r->cashback }}</td>
                    <td class="num">{{ $r->piutangBayar }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="tot">
                <td colspan="3">Total Termasuk Piutang Surgery</td>
                <td class="num">{{ $total['kas'] }}</td>
                <td class="num">{{ $total['debit'] }}</td>
                <td class="num">{{ $total['kredit'] }}</td>
                <td class="num">{{ $total['transfer'] }}</td>
                <td class="num">{{ $total['merchant'] }}</td>
                <td class="num">{{ $total['totalReal'] }}</td>
                <td class="num">{{ $total['voucher'] }}</td>
                <td class="num">{{ $total['piutang'] }}</td>
                <td class="num">{{ $total['dpSurgery'] }}</td>
                <td class="num">{{ $total['surgery'] }}</td>
                <td class="num">{{ $total['dp'] }}</td>
                <td class="num">{{ $total['tarikDp'] * -1 }}</td>
                <td class="num">{{ $total['totalSemua'] }}</td>
                <td class="num">{{ $total['cashback'] }}</td>
                <td class="num">{{ $total['piutangBayar'] }}</td>
            </tr>
            <tr class="tot">
                <td colspan="3">Total TANPA Piutang Surgery</td>
                <td class="num">{{ $totalTanpa['kas'] }}</td>
                <td class="num">{{ $totalTanpa['debit'] }}</td>
                <td class="num">{{ $totalTanpa['kredit'] }}</td>
                <td class="num">{{ $totalTanpa['transfer'] }}</td>
                <td class="num">{{ $totalTanpa['merchant'] }}</td>
                <td class="num">{{ $totalTanpa['totalReal'] }}</td>
                <td class="num">{{ $totalTanpa['voucher'] }}</td>
                <td class="num">{{ $totalTanpa['piutang'] }}</td>
                <td class="num">{{ $totalTanpa['dpSurgery'] }}</td>
                <td class="num">{{ $totalTanpa['surgery'] }}</td>
                <td class="num">{{ $totalTanpa['dp'] }}</td>
                <td class="num">{{ $totalTanpa['tarikDp'] * -1 }}</td>
                <td class="num">{{ $totalTanpa['totalSemua'] }}</td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
