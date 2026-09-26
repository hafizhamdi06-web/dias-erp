<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $h->SUNOTRANSAKSI }}</title>
    <style>
        * { font-family: "Courier New", monospace; font-size: 12px; }
        body { width: 72mm; margin: 0 auto; padding: 8px; color: #000; }
        .c { text-align: center; }
        .r { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        .big { font-size: 14px; font-weight: bold; }
        @media print { .noprint { display: none; } body { width: auto; } }
    </style>
</head>
<body onload="window.print()">
    <div class="c">
        <div class="big">{{ $branch->GNAMA ?? 'NMW' }}</div>
        @if ($branch?->GALAMAT1)<div>{{ $branch->GALAMAT1 }}</div>@endif
        @if ($branch?->GTELP)<div>{{ $branch->GTELP }}</div>@endif
    </div>
    <hr>
    <table>
        <tr><td>No</td><td class="r">{{ $h->SUNOTRANSAKSI }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ \Carbon\Carbon::parse($h->SUCREATED ?? $h->SUTANGGAL)->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Kasir</td><td class="r">{{ $kasir ?? '-' }}</td></tr>
        @if ($kontak)<tr><td>Plgn</td><td class="r">{{ $kontak->KNAMA }}</td></tr>@endif
    </table>
    <hr>
    <table>
        @foreach ($lines as $l)
            <tr><td colspan="2">{{ $l->INAMA ?? ('Item #' . $l->SDITEM) }}</td></tr>
            <tr>
                <td>{{ rtrim(rtrim(number_format((float) $l->SDKELUAR, 2), '0'), '.') }} x {{ number_format((float) $l->SDHARGA, 0, ',', '.') }}
                    @if ((float) $l->SDDISKONPERSEN > 0) (-{{ rtrim(rtrim(number_format((float) $l->SDDISKONPERSEN, 2), '0'), '.') }}%)@endif
                </td>
                <td class="r">{{ number_format((float) $l->SDKELUAR * ((float) $l->SDHARGA - (float) $l->SDDISKON), 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr class="big"><td>TOTAL</td><td class="r">{{ number_format((float) $h->SUTOTALTRANSAKSI, 0, ',', '.') }}</td></tr>
        @if ((float) $h->SUTOTALKAS > 0)<tr><td>Tunai</td><td class="r">{{ number_format((float) $h->SUTOTALKAS, 0, ',', '.') }}</td></tr>@endif
        @if ((float) $h->SUTOTALKARTUDEBIT > 0)<tr><td>Debit</td><td class="r">{{ number_format((float) $h->SUTOTALKARTUDEBIT, 0, ',', '.') }}</td></tr>@endif
        @if ((float) $h->SUTOTALKARTUKREDIT > 0)<tr><td>Kredit</td><td class="r">{{ number_format((float) $h->SUTOTALKARTUKREDIT, 0, ',', '.') }}</td></tr>@endif
        @if ((float) $h->SUTOTALTRANSFER > 0)<tr><td>Transfer</td><td class="r">{{ number_format((float) $h->SUTOTALTRANSFER, 0, ',', '.') }}</td></tr>@endif
        <tr><td>Bayar</td><td class="r">{{ number_format((float) $h->SUTOTALBAYAR, 0, ',', '.') }}</td></tr>
        <tr><td>Kembali</td><td class="r">{{ number_format(max(0, (float) $h->SUTOTALBAYAR - (float) $h->SUTOTALTRANSAKSI), 0, ',', '.') }}</td></tr>
    </table>
    <hr>
    <div class="c">Terima kasih</div>
    <p class="c noprint"><button onclick="window.print()">Cetak</button> <button onclick="window.close()">Tutup</button></p>
</body>
</html>
