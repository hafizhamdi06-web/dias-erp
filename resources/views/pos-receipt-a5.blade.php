{{-- Struk POS SETENGAH A4 - dirender sebagai PDF (mpdf) lewat `PdfReport::preview()`,
     BUKAN halaman HTML yang auto-print. Alasan user 2026-09-30: kasir jarang mencetak
     fisik, lebih sering screenshot lalu dikirim lewat WA/email - jadi pratinjau PDF yang
     rapi & ukurannya pasti lebih berguna daripada halaman web.

     Layout mengikuti CI3 (`views/modul/laporan/formulir-penjualan-tunai.php`).

     CATATAN mpdf: ukuran & orientasi kertas ditentukan `PdfReport` lewat opsi
     `['size' => 'A5', 'orientasi' => 'L']` - JANGAN pakai `@page`, mpdf mengabaikannya.
     Hindari juga flexbox/grid & `rem`; pakai tabel + `pt`.

     PENGGABUNGAN BARIS PAKET (disalin dari CI3): baris yang berasal dari paket ber-
     `PUCETAKHEADERSAJA = 1` TIDAK dicetak satu per satu - subtotalnya dijumlah lalu
     dicetak SATU baris "Paket <kode>". Pengelompokannya per `kodePaketLengkap`
     (kode paket + kedatangan), bukan per kode paket saja, supaya paket yang sama pada
     kedatangan berbeda tidak tergabung jadi satu. --}}
@php
    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
    $qt = fn ($v) => number_format((float) $v, 2, ',', '.');

    // Nama PT mengikuti cabang - aturan CI3 apa adanya.
    $gid = (int) ($branch->GID ?? 0);
    $namaPt = in_array($gid, [26, 28, 48], true) ? 'DAPS' : ($gid === 32 ? 'NATIONAL HOSPITAL - DAPS' : 'NMW');
    $namaIg = $gid === 32 ? 'nh.daps' : 'nmwskincare www.nmwskincare.co.id';

    /* Susun baris cetak: yang bukan "header saja" langsung, sisanya dikumpulkan per paket. */
    $cetak = [];
    $paket = [];   // kodePaketLengkap => ['kode'=>..,'ket'=>..,'ketPaket'=>..,'subtotal'=>..]

    foreach ($baris as $b) {
        if ($b->kodePaket !== '' && (int) $b->headerSaja === 1) {
            $k = $b->kodePaketLengkap;
            $paket[$k] ??= [
                'kode'     => $b->kodePaket,
                'ket'      => $b->ket,
                'ketPaket' => (float) $b->jumlahPaket > 1
                    ? 'Nomer ' . $b->nomerPaket . ' Ke ' . $b->kedatangan
                    : '',
                'subtotal' => 0.0,
            ];
            $paket[$k]['subtotal'] += (float) $b->subtotal;

            continue;
        }

        $cetak[] = $b;
    }
@endphp
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 7.5pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1pt 2pt; vertical-align: top; }
        .r { text-align: right; }
        .c { text-align: center; }
        .judul { font-size: 12pt; font-weight: bold; color: #1b5faa; }
        .besar { font-size: 11pt; font-weight: bold; }
        .garis { border-bottom: 0.4pt solid #000; }
        thead th { border-bottom: 0.4pt solid #000; font-weight: bold; text-align: center; }
    </style>
</head>
<body>

    {{-- ---------- KEPALA ---------- --}}
    <table>
        <tr>
            <td width="25%"><span class="judul">{{ $namaPt }}</span></td>
            <td>IG : {{ $namaIg }}@if ($branch?->GNOHP) &nbsp; WA : {{ $branch->GNOHP }}@endif</td>
            <td class="r" width="30%"><span class="judul">Invoice Penjualan</span></td>
        </tr>
        <tr>
            <td colspan="3" class="garis">{{ $branch->GALAMAT2 ?: ($branch->GALAMAT1 ?? '') }}</td>
        </tr>
    </table>

    <table style="margin-top:3pt">
        <tr>
            <td width="30%">{{ $kontak->KIDPASIEN ?? '' }}</td>
            <td width="20%">No Transaksi</td>
            <td width="20%">{{ $h->SUNOTRANSAKSI }}</td>
            <td class="r" width="30%">TOTAL TRANSAKSI</td>
        </tr>
        <tr>
            <td rowspan="2">{{ $kontak->KNAMA ?? '' }}</td>
            <td>Tanggal</td>
            <td>{{ \Carbon\Carbon::parse($h->SUTANGGAL)->format('d-m-Y') }}</td>
            <td class="r besar" rowspan="2">{{ $rp($h->SUTOTALTRANSAKSI) }}</td>
        </tr>
        <tr>
            <td>Kasir</td>
            <td>{{ $kasir ?? '-' }}</td>
        </tr>
    </table>

    {{-- ---------- ITEM ---------- --}}
    <table style="margin-top:4pt">
        <thead>
            <tr>
                <th width="29%">Item</th>
                <th width="16%">Ref-IC-Dokt-Perawat</th>
                <th width="11%">Harga</th>
                <th width="7%">Dis %</th>
                <th width="11%">Dis Rp</th>
                <th width="8%">Qty</th>
                <th width="12%">Sub Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cetak as $b)
                <tr>
                    <td>{{ $b->item }}</td>
                    <td>{{ trim((string) $b->ket, '-') }}</td>
                    <td class="r">{{ $rp($b->harga) }}</td>
                    <td class="r">{{ $rp($b->dis1) }}</td>
                    <td class="r">{{ $rp($b->diskon) }}</td>
                    <td class="r">{{ $qt($b->qty) }}</td>
                    <td class="r">{{ $rp($b->subtotal) }}</td>
                </tr>
            @endforeach

            @foreach ($paket as $p)
                <tr>
                    <td>Paket {{ $p['kode'] }}</td>
                    <td>{{ trim((string) $p['ket'], '-') }}</td>
                    <td colspan="4">{{ $p['ketPaket'] }}</td>
                    <td class="r">{{ $rp($p['subtotal']) }}</td>
                </tr>
            @endforeach

            @if ($cetak === [] && $paket === [])
                <tr><td colspan="7" class="c">Tidak ada baris.</td></tr>
            @endif
        </tbody>
    </table>

    {{-- ---------- PEMBAYARAN (3 pasang kolom, susunan sama CI3) ---------- --}}
    <table style="margin-top:4pt; border-top:0.4pt solid #000">
        <tr>
            <td width="15%">Cash</td>
            <td class="r" width="15%">{{ $rp($h->SUTOTALKAS) }}</td>
            <td width="15%">Kredit {{ $bank['kredit'] }}</td>
            <td class="r" width="15%">{{ $rp($h->SUTOTALKARTUKREDIT) }}</td>
            <td width="20%">Penjualan</td>
            <td class="r" width="20%">{{ $rp($h->SUTOTALTRANSAKSI) }}</td>
        </tr>
        <tr>
            <td>Debit {{ $bank['debit'] }}</td>
            <td class="r">{{ $rp($h->SUTOTALKARTUDEBIT) }}</td>
            <td>DP</td>
            <td class="r">{{ $rp($h->SUTOTALDP) }}</td>
            <td>Jumlah Bayar</td>
            <td class="r">{{ $rp($h->SUTOTALBAYAR) }}</td>
        </tr>
        <tr>
            <td>Pend DP</td>
            <td class="r">{{ $rp($h->SUPENDAPATANDP) }}</td>
            <td>Transfer {{ $bank['transfer'] }}</td>
            <td class="r">{{ $rp($h->SUTOTALTRANSFER) }}</td>
            <td>Kembali</td>
            <td class="r">{{ $rp($h->SUTOTALSISA) }}</td>
        </tr>
        <tr>
            <td>Piutang</td>
            <td class="r">{{ $rp($h->SUNILAIPIUTANG) }}</td>
            <td>Merchant {{ $h->SUMERCHANTJENIS }}</td>
            <td class="r">{{ $rp($h->SUMERCHANTJUMLAH) }}</td>
            <td colspan="2"></td>
        </tr>

        @if ((float) $h->SUTOTALKARTUKREDIT != 0)
            <tr>
                <td colspan="2">No Kartu Kredit {{ $h->SUNOKARTUKREDIT }}</td>
                <td colspan="4">Jenis Kartu Kredit {{ $h->SUKREDITJENIS }}</td>
            </tr>
        @endif
        @if ((float) $h->SUTOTALKARTUDEBIT != 0)
            <tr>
                <td colspan="2">No Kartu Debit {{ $h->SUNOKARTUDEBIT }}</td>
                <td colspan="4">Jenis Kartu Debit {{ $h->SUDEBITJENIS }}</td>
            </tr>
        @endif
    </table>

    <table style="margin-top:6pt">
        <tr>
            <td class="c">
                Sudah termasuk jasa dokter dan ppn 11% atas produk.
                @if ($point > 0) &nbsp; Jumlah Point {{ $point }} @endif
            </td>
        </tr>
        <tr>
            <td class="c">
                Terima Kasih Atas Kunjungannya<br>
                Transaksi yang telah dilakukan, tidak dapat dibatalkan.<br>
                <i>Care Love Smile</i>
            </td>
        </tr>
    </table>

</body>
</html>
