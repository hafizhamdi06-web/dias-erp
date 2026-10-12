{{-- Excel "IP IV Per Tanggal" - data SAMA PERSIS versi PDF, plus kolom dokumen (ongkir,
     diskon invoice, pajak, alamat, karyawan) yang tidak muat di PDF. --}}
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
                <th>No Invoice</th><th>Tanggal</th><th>Jatuh Tempo</th>
                <th>Pelanggan</th><th>No. Telp</th><th>Alamat</th><th>Karyawan</th><th>Termin</th>
                <th>Kode</th><th>Nama Item</th><th>Satuan</th><th>Jenis COA</th>
                <th>Qty</th><th>Harga</th><th>Diskon</th><th>Diskon %</th><th>Subtotal</th>
                <th>Total Invoice</th><th>Ongkir</th><th>Diskon Invoice</th><th>Pajak</th>
                <th>Gudang Asal</th><th>Gudang Tujuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->nomor }}</td>
                    <td class="txt">{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '' }}</td>
                    <td class="txt">{{ $x->jatuhTempo ? \Carbon\Carbon::parse($x->jatuhTempo)->format('d/m/Y') : '' }}</td>
                    <td class="txt">{{ $x->pelanggan }}</td>
                    <td class="txt">{{ $x->telp }}</td>
                    <td class="txt">{{ $x->alamat }}</td>
                    <td class="txt">{{ $x->karyawan }}</td>
                    <td class="txt">{{ $x->termin }}</td>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="txt">{{ $x->satuan }}</td>
                    <td class="txt">{{ $x->jenisCoa }}</td>
                    <td class="num">{{ (float) $x->qty }}</td>
                    <td class="rp">{{ (float) $x->harga }}</td>
                    <td class="rp">{{ (float) $x->diskon }}</td>
                    <td class="num">{{ (float) $x->diskonPersen }}</td>
                    <td class="rp">{{ (float) $x->subtotal }}</td>
                    <td class="rp">{{ (float) $x->totalInvoice }}</td>
                    <td class="rp">{{ (float) $x->ongkir }}</td>
                    <td class="rp">{{ (float) $x->diskonInvoice }}</td>
                    <td class="rp">{{ (float) $x->pajak }}</td>
                    <td class="txt">{{ $x->gudangSumber }}</td>
                    <td class="txt">{{ $x->gudangTujuan }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="12">TOTAL ({{ $total['baris'] }} baris)</td>
                <td class="num">{{ $total['qty'] }}</td>
                <td colspan="3"></td>
                <td class="rp">{{ $total['subtotal'] }}</td>
                <td colspan="6"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
