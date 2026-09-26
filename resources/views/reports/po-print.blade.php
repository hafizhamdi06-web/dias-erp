{{-- Cetakan PURCHASE ORDER - layout direplikasi dari contoh sistem lama
     (Order Pembelian GB-PO26090001.pdf). Lihat docblock PoPrintController utk
     sumber tiap field. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 9.5pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        .kop-pt { font-size: 17pt; margin: 0 0 2pt; }
        .kop-alamat { font-size: 9pt; margin: 0 0 8pt; }
        .kop-izin { font-size: 8.5pt; line-height: 1.35; }
        .kop-clinic { font-size: 20pt; font-weight: bold; text-align: right; letter-spacing: .5pt; }
        .kop-clinic small { display: block; font-size: 8.5pt; font-weight: normal; letter-spacing: 0; }
        h1.judul { font-size: 13pt; text-align: center; margin: 14pt 0 10pt; letter-spacing: .5pt; }

        .info td { vertical-align: top; padding: 1pt 0; font-size: 9pt; }
        .info .lbl { font-weight: bold; width: 62pt; }
        .info .lbl-r { font-weight: bold; width: 70pt; }

        table.items { margin-top: 8pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9pt; }
        table.items td { padding: 2pt; font-size: 9pt; }
        table.items tfoot td { border-top: .8pt solid #000; }
        .r { text-align: right; } .c { text-align: center; }

        .total td { padding: 2pt 0; font-size: 9.5pt; }
        .total .lbl { font-weight: bold; }
        .ttd { margin-top: 26pt; font-size: 9pt; }
        .ttd td { text-align: center; vertical-align: bottom; height: 46pt; }
        .ttd .nm { font-size: 8.5pt; }
    </style>
</head>
<body>

{{-- Footer tiap halaman: "Page x of y" + waktu cetak, sama seperti cetakan lama. --}}
<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8pt; padding-top: 2pt">
        <tr>
            <td>Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

{{-- ================= KOP ================= --}}
<table>
    <tr>
        <td style="width: 68%">
            <div class="kop-pt">{{ $pt->NPNAMA2 ?? '' }}</div>
            <div class="kop-alamat">{{ $pt->NPALAMAT ?? '' }}</div>
            @if (! empty($extra))
                <div class="kop-izin">
                    @if (! empty($extra['izin'])) No Izin : {{ $extra['izin'] }}<br> @endif
                    @if (! empty($extra['apoteker'])) {{ $extra['apoteker'] }}<br> @endif
                    @if (! empty($extra['sipa'])) SIPA : {{ $extra['sipa'] }} @endif
                </div>
            @endif
        </td>
        <td style="width: 32%" class="kop-clinic">
            {{ $pt->NPNAMACLINIC ?? '' }}
        </td>
    </tr>
</table>

<h1 class="judul">PURCHASE ORDER</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Supplier :</td>
        <td style="width: 38%">{{ $h->vendor ?: '-' }}</td>
        <td class="lbl-r">No Transaksi</td>
        <td>{{ $h->SOUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Alamat :</td>
        <td style="font-size: 8.5pt">
            {{ $h->vendorAlamat ?: '-' }}
            @if ($h->vendorKota) <br>{{ $h->vendorKota }} @endif
        </td>
        <td class="lbl-r">Tanggal</td>
        <td>{{ \Carbon\Carbon::parse($h->SOUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td></td><td></td>
        <td class="lbl-r">Termin</td>
        <td>{{ $h->termin ?: '-' }}</td>
    </tr>
    <tr>
        <td class="lbl">Jenis :</td>
        <td>{{ $jenis }}</td>
        <td class="lbl-r">Di Kirim Ke</td>
        <td>{{ $h->gudang ?: '-' }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 31%" align="left">Nama Item</th>
            <th style="width: 13%" align="left">Kemasan</th>
            <th style="width: 6%" class="r">Qty</th>
            <th style="width: 9%" align="left">Satuan</th>
            <th style="width: 11%" class="r">Harga</th>
            <th style="width: 8%" class="r">Disc %</th>
            <th style="width: 11%" class="r">Harga</th>
            <th style="width: 13%" class="r">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $l->nama }}</td>
                <td>{{ $l->kemasan }}</td>
                <td class="r">{{ rtrim(rtrim(number_format($l->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td>{{ $l->satuan }}</td>
                <td class="r">{{ number_format($l->harga, 0, ',', '.') }}</td>
                <td class="r">{{ rtrim(rtrim(number_format($l->diskonPersen, 2, ',', '.'), '0'), ',') }} %</td>
                <td class="r">{{ number_format($l->hargaBersih, 0, ',', '.') }}</td>
                <td class="r">{{ number_format($l->subTotal, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="9" style="padding: 0"></td></tr>
    </tfoot>
</table>

{{-- ================= TERBILANG + TOTAL ================= --}}
<table style="margin-top: 6pt">
    <tr>
        <td style="width: 56%; vertical-align: top">
            Terbilang # {{ terbilang($total) }} Rupiah #

            {{-- Tanda tangan --}}
            <table class="ttd">
                <tr>
                    <td>Diinput Oleh</td>
                    <td>Diketahui Oleh</td>
                    <td>Disetujui Oleh</td>
                </tr>
                <tr>
                    <td class="nm">
                        {{ $h->dibuatOleh ? ucwords(mb_strtolower($h->dibuatOleh)) : '(&nbsp;&nbsp;&nbsp;&nbsp;)' }}
                        @if ($h->dibuatHp && $h->dibuatHp !== '-')
                            <br>HP : {{ $h->dibuatHp }}
                        @endif
                    </td>
                    <td class="nm">
                        (&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)
                        @if (! empty($extra['apoteker_hp']))
                            <br>HP : {{ $extra['apoteker_hp'] }}
                        @endif
                    </td>
                    <td class="nm">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
                </tr>
            </table>
        </td>
        <td style="width: 44%; vertical-align: top">
            <table class="total">
                <tr>
                    <td class="lbl">Total Qty</td>
                    <td class="r">{{ rtrim(rtrim(number_format($totalQty, 2, ',', '.'), '0'), ',') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Sub Total</td>
                    <td class="r">{{ number_format($subTotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Diskon {{ number_format((float) $h->SOUDISKONPERSEN, 2, ',', '.') }} %</td>
                    <td class="r">{{ number_format($diskon, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Pajak</td>
                    <td class="r">{{ number_format($pajak, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Total Transaksi</td>
                    <td class="r">{{ number_format($total, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 10pt">Catatan : {{ $h->SOUCATATAN }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ((int) $h->SOUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** PO INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
