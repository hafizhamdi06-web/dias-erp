{{-- Cetakan "Produksi" - dari contoh cetakan lama user (Produksi RP-PRO26090001.pdf).
     Lihat docblock ProduksiPrintController: 2 kolom qty (Produk Jadi / Bahan Baku)
     dgn 2 total, dan tanda tangan tunggal "Bag Produksi". --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        h1.judul { font-size: 13pt; text-align: center; margin: 0 0 14pt; }

        .info td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .info .lbl { font-weight: bold; width: 70pt; }
        .info .lbl-r { font-weight: bold; width: 74pt; }
        .info .isi-kiri { width: 46%; }

        table.items { margin-top: 10pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9.5pt; vertical-align: bottom; }
        table.items td { padding: 3pt 2pt; font-size: 9.5pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        .r { text-align: right; } .c { text-align: center; }

        .bawah { margin-top: 8pt; font-size: 9.5pt; }
        .bawah td { vertical-align: top; }
        .ttd-garis { padding-top: 34pt; font-size: 9.5pt; }
    </style>
</head>
<body>

<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8.5pt; padding-top: 2pt">
        <tr>
            <td>Halaman&nbsp;&nbsp;&nbsp;Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y') }}&nbsp;&nbsp;&nbsp;{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

<h1 class="judul">Produksi</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Tujuan :</td>
        <td class="isi-kiri">{{ $h->kontak ?: '-' }}</td>
        <td class="lbl-r">No Transaksi :</td>
        <td>{{ $h->SUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Gudang :</td>
        <td class="isi-kiri">{{ $h->gudang ?: '-' }}</td>
        <td class="lbl-r">Tanggal :</td>
        <td>{{ \Carbon\Carbon::parse($h->SUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="lbl" style="padding-top: 6pt">Keterangan :</td>
        <td colspan="3" style="padding-top: 6pt">{{ $h->SUURAIAN }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 24%" align="left">Item</th>
            <th style="width: 31%" align="left">Nama Item</th>
            {{-- Inti cetakan ini: qty dipisah per arah, bukan satu kolom. --}}
            <th style="width: 13%" class="r">Qty Produk Jadi</th>
            <th style="width: 14%" class="r">Qty Bahan Baku</th>
            <th style="width: 13%" align="left">Satuan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $l->kode }}</td>
                <td>{{ $l->nama }}</td>
                <td class="r">{{ number_format((float) $l->jadi, 2, ',', '.') }}</td>
                <td class="r">{{ number_format((float) $l->bahan, 2, ',', '.') }}</td>
                <td>{{ $l->satuan }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
    @if (count($lines))
        <tfoot>
            <tr>
                <td colspan="3" class="r"><strong>Total Qty</strong></td>
                <td class="r"><strong>{{ number_format((float) $totalJadi, 2, ',', '.') }}</strong></td>
                <td class="r"><strong>{{ number_format((float) $totalBahan, 2, ',', '.') }}</strong></td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- ================= TANDA TANGAN ================= --}}
{{-- Hanya satu penanda tangan: dokumen internal, tidak ada serah terima. --}}
<table class="bawah">
    <tr><td style="width: 30%" class="c">Bag Produksi</td><td></td></tr>
    <tr>
        <td class="c ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td></td>
    </tr>
</table>

@if ((int) $h->SUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** PRODUKSI INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
