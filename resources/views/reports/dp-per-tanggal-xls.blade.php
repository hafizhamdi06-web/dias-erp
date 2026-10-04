{{-- Excel "Jumlah DP Pertanggal" - data SAMA PERSIS versi PDF. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .rp { mso-number-format: "#,##0"; text-align: right; }
        /* Kode pasien & no transaksi WAJIB teks - angka panjang akan dibulatkan Excel. */
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Posisi s/d {{ $tanggal }}@if ($subtitle) &mdash; {{ $subtitle }} @endif@if ($rinci) &mdash; rinci per mutasi @endif</p>

    @if ($rinci)
        <table>
            <thead>
                <tr>
                    <th>Kode Pasien</th><th>Nama Pasien</th><th>Cabang</th><th>Tanggal</th>
                    <th>Tgl DP</th><th>No Transaksi</th><th>No DP</th><th>Nilai</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $x)
                    <tr>
                        <td class="txt">{{ $x->kodePasien }}</td>
                        <td class="txt">{{ $x->namaPasien }}</td>
                        <td class="txt">{{ $x->cabang }}</td>
                        <td class="txt">{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '' }}</td>
                        <td class="txt">{{ $x->tanggalDp ? \Carbon\Carbon::parse($x->tanggalDp)->format('d/m/Y') : '' }}</td>
                        <td class="txt">{{ $x->nomor }}</td>
                        <td class="txt">{{ $x->noDp }}</td>
                        <td class="rp">{{ (float) $x->nilai }}</td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td colspan="7">SALDO ({{ $total['baris'] }} mutasi)</td>
                    <td class="rp">{{ $total['nilai'] }}</td>
                </tr>
            </tbody>
        </table>
    @else
        <table>
            <thead>
                <tr>
                    <th>Kode Pasien</th><th>Nama Pasien</th><th>Cabang Asal DP</th>
                    <th>DP Masuk</th><th>DP Terpakai</th><th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $x)
                    <tr>
                        <td class="txt">{{ $x->kodePasien }}</td>
                        <td class="txt">{{ $x->namaPasien }}</td>
                        <td class="txt">{{ $x->cabang }}</td>
                        <td class="rp">{{ (float) $x->masuk }}</td>
                        <td class="rp">{{ (float) $x->terpakai }}</td>
                        <td class="rp">{{ (float) $x->saldo }}</td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td colspan="3">TOTAL ({{ $total['baris'] }} pasien)</td>
                    <td class="rp">{{ $total['masuk'] }}</td>
                    <td class="rp">{{ $total['terpakai'] }}</td>
                    <td class="rp">{{ $total['saldo'] }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
