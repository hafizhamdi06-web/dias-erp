{{-- Excel "Daftar Stok Barang Serial" - data SAMA PERSIS versi PDF. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0.00"; text-align: right; }
        .rp { mso-number-format: "#,##0"; text-align: right; }
        /* No serial WAJIB teks: banyak yg berupa angka panjang & akan dibulatkan Excel. */
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Posisi s/d {{ $tanggal }}@if ($subtitle) &mdash; {{ $subtitle }} @endif</p>

    <table>
        <thead>
            <tr>
                <th>Kode</th><th>Nama Item</th><th>No Serial</th><th>Expired</th>
                <th>Jumlah</th><th>Satuan</th><th>Harga Beli</th><th>Harga Jual</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="txt">{{ $x->noSerial }}</td>
                    <td class="txt">{{ $x->expired ? \Carbon\Carbon::parse($x->expired)->format('d/m/Y') : '' }}</td>
                    <td class="num">{{ (float) $x->jumlah }}</td>
                    <td class="txt">{{ $x->satuan }}</td>
                    <td class="rp">{{ (float) $x->hargaBeli }}</td>
                    <td class="rp">{{ (float) $x->hargaJual }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="4">TOTAL</td>
                <td class="num">{{ (float) $total }}</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
