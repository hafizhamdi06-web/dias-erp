{{-- Excel "Stok Per Hari" - data SAMA PERSIS versi PDF (dataStokPerHari() dipakai bersama). --}}
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
        /* Kode item spt "P2G 20 GR" harus dipaksa TEKS, kalau tidak Excel menjadikannya tanggal. */
        .txt { mso-number-format: "\@"; }
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
                <th>Kode</th><th>Nama Item</th><th>Saldo Awal</th><th>Masuk</th>
                <th>Keluar</th><th>Stok Akhir</th><th>COGS</th><th>Harga Jual</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="num">{{ (float) $x->saldoAwal }}</td>
                    <td class="num">{{ (float) $x->masuk }}</td>
                    <td class="num">{{ (float) $x->keluar }}</td>
                    <td class="num">{{ (float) $x->akhir }}</td>
                    <td class="rp">{{ (float) $x->cogs }}</td>
                    <td class="rp">{{ (float) $x->hargaJual }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="2">TOTAL</td>
                <td class="num">{{ $total['saldoAwal'] }}</td>
                <td class="num">{{ $total['masuk'] }}</td>
                <td class="num">{{ $total['keluar'] }}</td>
                <td class="num">{{ $total['akhir'] }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
