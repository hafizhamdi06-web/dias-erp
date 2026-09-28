{{-- Cetakan "Penerimaan Barang" - layout direplikasi dari contoh sistem lama
     (Penerimaan BarangRB-PB26080001.pdf). Lihat docblock PbPrintController utk
     sumber tiap field. TIDAK ADA kop PT - contoh aslinya memang tanpa kop. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        h1.judul { font-size: 13pt; text-align: center; margin: 0 0 14pt; }

        .info td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .info .lbl { font-weight: bold; width: 68pt; }
        .info .lbl-r { font-weight: bold; width: 78pt; }

        table.items { margin-top: 10pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9.5pt; }
        table.items td { padding: 3pt 2pt; font-size: 9.5pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        .r { text-align: right; } .c { text-align: center; }

        .bawah { margin-top: 10pt; font-size: 9.5pt; }
        .bawah td { vertical-align: top; }
        .ttd-nama { padding-top: 34pt; font-size: 9.5pt; }
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

<h1 class="judul">Penerimaan Barang</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Tujuan :</td>
        <td style="width: 36%">{{ $h->vendor ?: '-' }}</td>
        <td class="lbl-r">No Transaksi :</td>
        <td>{{ $h->SUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Gudang :</td>
        <td>{{ $h->gudang ?: '-' }}</td>
        <td class="lbl-r">Tanggal :</td>
        <td>{{ \Carbon\Carbon::parse($h->SUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="lbl" style="padding-top: 6pt">Keterangan :</td>
        <td style="padding-top: 6pt">{{ $h->SUURAIAN ?: '-' }}</td>
        <td class="lbl-r">No Invoice :</td>
        <td>{{ $h->SUNOREF ?: '-' }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 22%" align="left">Item</th>
            <th style="width: 34%" align="left">Nama Item</th>
            <th style="width: 10%" class="r">Qty Masuk</th>
            <th style="width: 7%" align="left">Satuan</th>
            {{-- No PO dilebarkan 14% -> 22% + nowrap supaya nomor spt "PG-PO26090001"
                 tidak patah 2 baris. AMAN: semua `SOUNOTRANSAKSI` PO panjangnya PERSIS
                 13 karakter (dicek 236 PO), jadi tidak mungkin meluber.
                 Tambahan lebar diambil dari Qty Masuk (12->10) & Satuan (10->7) yg isinya
                 pendek, plus Nama Item (37->34) - Item TIDAK dikurangi krn isinya `IKODE`
                 yg di data ini sering sepanjang nama. --}}
            <th style="width: 22%" align="left">No PO</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $l->kode }}</td>
                <td>{{ $l->nama }}</td>
                <td class="r">{{ number_format((float) $l->qty, 2, ',', '.') }}</td>
                <td>{{ $l->satuan }}</td>
                <td style="white-space: nowrap">{{ $l->noPo ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ================= TANDA TANGAN + TOTAL ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 26%" class="c">Bag Gudang</td>
        <td style="width: 26%" class="c">Penerima</td>
        <td style="width: 26%" class="r"><strong>Total Qty</strong></td>
        <td style="width: 22%" class="r"><strong>{{ number_format((float) $totalQty, 2, ',', '.') }}</strong></td>
    </tr>
    <tr>
        <td class="c ttd-nama">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="c ttd-nama">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td colspan="2"></td>
    </tr>
</table>

@if ((int) $h->SUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** PENERIMAAN INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
