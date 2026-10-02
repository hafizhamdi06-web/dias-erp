{{-- Excel "Daftar Surat Jalan Barang" - data SAMA PERSIS versi PDF. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0.00"; text-align: right; }
        .txt { mso-number-format: "\@"; }
        .tgl { mso-number-format: "dd\/mm\/yyyy"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>{{ $subtitle }}</p>

    <table>
        <thead>
            <tr>
                <th>No SJ</th><th>Tanggal</th><th>Kode</th><th>Nama Item</th><th>Satuan</th>
                <th>Qty</th><th>Pelanggan</th><th>Sales</th><th>Gudang Asal</th>
                <th>Gudang Tujuan</th><th>PT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->nomor }}</td>
                    <td class="tgl">{{ \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') }}</td>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="txt">{{ $x->satuan }}</td>
                    <td class="num">{{ (float) $x->qty }}</td>
                    <td class="txt">{{ $x->pelanggan }}</td>
                    <td class="txt">{{ $x->sales }}</td>
                    <td class="txt">{{ $x->gudangAsal }}</td>
                    <td class="txt">{{ $x->gudangTujuan }}</td>
                    <td class="txt">{{ $x->pt }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="5">TOTAL QTY</td>
                <td class="num">{{ $totalQty }}</td>
                <td colspan="5"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
