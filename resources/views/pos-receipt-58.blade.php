{{-- Struk POS printer TERMAL 58 mm (permintaan user 2026-09-30).
     Lebar cetak efektif kertas 58mm adalah ~48mm; sisanya margin mekanis printer.
     Font monospace kecil supaya 32 karakter muat satu baris - patokan umum printer 58mm. --}}
@php
    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $h->SUNOTRANSAKSI }}</title>
    <style>
        @page { size: 58mm auto; margin: 0; }
        * { font-family: "Courier New", monospace; font-size: 9.5px; line-height: 1.25; }
        body { width: 48mm; margin: 0 auto; padding: 2mm 0; color: #000; }
        .c { text-align: center; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { vertical-align: top; padding: 0; word-wrap: break-word; }
        hr { border: none; border-top: 1px dashed #000; margin: 1.5mm 0; }
        .judul { font-size: 11px; font-weight: bold; }
        .total { font-size: 11px; font-weight: bold; }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="c">
        <div class="judul">{{ $branch->GNAMA ?? 'NMW' }}</div>
        @if ($branch?->GALAMAT1)<div>{{ $branch->GALAMAT1 }}</div>@endif
        @if ($branch?->GTELP)<div>{{ $branch->GTELP }}</div>@endif
    </div>
    <hr>

    <table>
        <tr><td style="width:38%">No</td><td class="r">{{ $h->SUNOTRANSAKSI }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ \Carbon\Carbon::parse($h->SUCREATED ?? $h->SUTANGGAL)->format('d/m/y H:i') }}</td></tr>
        <tr><td>Kasir</td><td class="r">{{ $kasir ?? '-' }}</td></tr>
        @if ($kontak)<tr><td>Plgn</td><td class="r">{{ $kontak->KNAMA }}</td></tr>@endif
    </table>
    <hr>

    <table>
        @foreach ($lines as $l)
            <tr><td colspan="2">{{ $l->INAMA ?? ('Item #' . $l->SDITEM) }}</td></tr>
            <tr>
                <td>{{ $qty($l->SDKELUAR) }} x {{ $rp($l->SDHARGA) }}@if ((float) $l->SDDISKONPERSEN > 0) -{{ $qty($l->SDDISKONPERSEN) }}%@endif</td>
                <td class="r">{{ $rp((float) $l->SDKELUAR * ((float) $l->SDHARGA - (float) $l->SDDISKON)) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>

    <table>
        <tr class="total"><td>TOTAL</td><td class="r">{{ $rp($h->SUTOTALTRANSAKSI) }}</td></tr>
        @foreach ([
            'Tunai'    => $h->SUTOTALKAS,
            'Debit'    => $h->SUTOTALKARTUDEBIT,
            'Kredit'   => $h->SUTOTALKARTUKREDIT,
            'Transfer' => $h->SUTOTALTRANSFER,
            'Merchant' => $h->SUMERCHANTJUMLAH,
            'Voucher'  => $h->SUTOTALVOUCHER,
            'DP'       => $h->SUTOTALDP,
            'Piutang'  => $h->SUNILAIPIUTANG,
        ] as $label => $nilai)
            @if ((float) $nilai != 0)
                <tr><td>{{ $label }}</td><td class="r">{{ $rp($nilai) }}</td></tr>
            @endif
        @endforeach
        <tr><td>Bayar</td><td class="r">{{ $rp($h->SUTOTALBAYAR) }}</td></tr>
        <tr class="b"><td>Kembali</td><td class="r">{{ $rp($h->SUTOTALSISA) }}</td></tr>
    </table>
    <hr>

    <div class="c">
        Sudah termasuk jasa dokter<br>dan PPN 11% atas produk.
        <br><br>
        Terima Kasih Atas Kunjungannya<br>
        Transaksi yang telah dilakukan<br>tidak dapat dibatalkan.<br>
        <em>Care Love Smile</em>
    </div>

    <div class="c noprint" style="margin-top:4mm">
        <button onclick="window.print()">Cetak</button>
    </div>
</body>
</html>
