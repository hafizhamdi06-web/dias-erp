{{-- Cetakan "Terima Mutasi Barang" - layout direplikasi dari contoh sistem lama
     (Terima Mutasi Barang PG-TMB26090031.pdf). Kembaran kmb-print, bedanya: tidak ada
     baris "Gudang Tujuan" dan qty dari SDMASUK. Lihat docblock TmbPrintController. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        h1.judul { font-size: 13pt; text-align: center; margin: 0 0 14pt; }

        .info td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .info .lbl { font-weight: bold; width: 72pt; }
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

<h1 class="judul">Terima Mutasi Barang</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Tujuan :</td>
        <td style="width: 36%">{{ $h->kontak ?: '-' }}</td>
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
        <td colspan="2"></td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 27%" align="left">Item</th>
            <th style="width: 44%" align="left">Nama Item</th>
            {{-- Label "Qty Keluar" MEMANG begitu di cetakan lama walau isinya SDMASUK
                 (warisan salin-tempel dari laporan KMB) - lihat docblock controller. --}}
            <th style="width: 12%" class="r">Qty Keluar</th>
            <th style="width: 12%" align="left">Satuan</th>
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
            </tr>
        @empty
            <tr><td colspan="5" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ================= TANDA TANGAN + TOTAL ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 26%" class="c">Dikirim Oleh</td>
        <td style="width: 26%" class="c">Diterima Oleh</td>
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
