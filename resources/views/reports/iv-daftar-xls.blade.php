{{-- Excel BERSAMA lima laporan IV/IVM - data SAMA PERSIS versi PDF. --}}
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
        /* No invoice/SJ/PKB & kode item WAJIB teks - kalau tidak Excel menafsirkannya angka/tanggal. */
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
                <th>No Invoice</th><th>Tanggal</th>
                @if ($pakaiPelanggan)<th>Pelanggan</th><th>Total Invoice</th>@endif
                <th>Kode</th><th>Nama Item</th><th>Satuan</th><th>Jenis COA</th>
                <th>Qty</th><th>Harga</th><th>Diskon</th><th>Subtotal</th>
                <th>COGS</th><th>COGS Pabrik</th>
                <th>Cabang Transaksi</th><th>Cabang Pelanggan</th>
                <th>No SJ</th><th>No PKB</th><th>No PO</th>
                @if ($pakaiPt)<th>PT</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->nomor }}</td>
                    <td class="txt">{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '' }}</td>
                    @if ($pakaiPelanggan)
                        <td class="txt">{{ $x->pelanggan }}</td>
                        <td class="rp">{{ (float) $x->totalInvoice }}</td>
                    @endif
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="txt">{{ $x->satuan }}</td>
                    <td class="txt">{{ $x->jenisCoa }}</td>
                    <td class="num">{{ (float) $x->qty }}</td>
                    <td class="rp">{{ (float) $x->harga }}</td>
                    <td class="rp">{{ (float) $x->diskon }}</td>
                    <td class="rp">{{ (float) $x->subtotal }}</td>
                    <td class="rp">{{ (float) $x->cogs }}</td>
                    <td class="rp">{{ (float) $x->cogsPabrik }}</td>
                    <td class="txt">{{ $x->cabangTransaksi }}</td>
                    <td class="txt">{{ $x->cabangPelanggan }}</td>
                    <td class="txt">{{ $x->noSj }}</td>
                    <td class="txt">{{ $x->noPkb }}</td>
                    <td class="txt">{{ $x->noPo }}</td>
                    @if ($pakaiPt)<td class="txt">{{ $x->pt }}</td>@endif
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="{{ $pakaiPelanggan ? 8 : 6 }}">TOTAL ({{ $total['baris'] }} baris)</td>
                <td class="num">{{ $total['qty'] }}</td>
                <td colspan="2"></td>
                <td class="rp">{{ $total['subtotal'] }}</td>
                <td colspan="{{ 6 + ($pakaiPt ? 1 : 0) }}"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
