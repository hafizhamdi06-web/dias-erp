{{-- Ekspor Excel Closing Harian IP. Barisnya dari `ClosingHarianIp` yg SAMA dgn layar
     (`ReportController::closingIpExcel()`) - jangan dihitung ulang di sini. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0.##"; text-align: right; }
        {{-- Paksa TEKS supaya kode/nomor yg seluruhnya angka tidak kehilangan nol di depan. --}}
        .txt { mso-number-format: "\@"; }
        .tgl { mso-number-format: "dd\/mm\/yyyy"; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Periode : {{ $periode }}</p>
    <p>{{ $subtitle }}</p>
    <p>{{ number_format($rows->count(), 0, ',', '.') }} tindakan belum diinput alkes &middot;
        {{ number_format($rows->unique('ipId')->count(), 0, ',', '.') }} transaksi IP</p>
    <br>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>No IP</th>
                <th>Cabang</th>
                <th>Pasien</th>
                <th>Kode</th>
                <th>Tindakan</th>
                <th>Paket</th>
                <th>Kedatangan</th>
                <th>Qty</th>
                <th>Dokter</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="tgl">{{ $r->tanggal ? \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') : '' }}</td>
                    <td class="txt">{{ $r->noIp }}</td>
                    <td>{{ $r->cabang }}</td>
                    <td>{{ $r->pasien }}</td>
                    <td class="txt">{{ $r->kode }}</td>
                    <td>{{ $r->tindakan }}</td>
                    <td>{{ (int) $r->dariPaket !== 0 ? 'Ya' : '' }}</td>
                    <td class="num">{{ (int) $r->dariPaket !== 0 ? (int) $r->kedatangan : '' }}</td>
                    <td class="num">{{ (float) $r->qty + 0 }}</td>
                    <td>{{ $r->dokter }}</td>
                </tr>
            @empty
                <tr><td colspan="11">Tidak ada tindakan yang belum diinput alkes.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
