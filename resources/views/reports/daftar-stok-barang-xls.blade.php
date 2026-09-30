{{-- Versi Excel "Laporan Real Stok Barang" - data SAMA PERSIS versi PDF
     (`ReportController::dataDaftarStokBarang()` dipakai bersama). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0.00"; text-align: right; }
        /* `.txt` WAJIB utk Kode: banyak kode item spt "P2G 20 GR" / "AM2 CR 20 GR" akan
           ditafsirkan Excel jadi angka/tanggal kalau tidak dipaksa teks. */
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Tanggal : {{ $tanggal }}@if ($subtitle) &mdash; {{ $subtitle }} @endif</p>

    <table>
        <thead>
            <tr>
                <th>Gudang</th>
                <th>Jenis</th>
                <th>Kode</th>
                <th>Nama Item</th>
                <th>Real Stok</th>
                <th>Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->gudang }}</td>
                    <td class="txt">{{ $x->jenis }}</td>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="num">{{ (float) $x->stok }}</td>
                    <td class="txt">{{ $x->satuan }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="4">TOTAL</td>
                <td class="num">{{ (float) $total }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
