{{-- Cetakan "Job Order Produksi" - dari contoh cetakan lama user
     (Job Order Produksi RP-JOP26090001.pdf). Lihat docblock JopPrintController:
     2 kolom qty tanpa kolom Satuan, dan DUA tanda tangan BERNAMA. --}}
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

        table.items { margin-top: 8pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9.5pt; }
        table.items td { padding: 3pt 2pt; font-size: 9.5pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        table.items tfoot td { border-top: .8pt solid #000; padding-top: 4pt; }
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

<h1 class="judul">Job Order Produksi</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Tujuan :</td>
        <td class="isi-kiri">{{ $h->kontak ?: '-' }}</td>
        <td class="lbl-r">No Transaksi :</td>
        <td>{{ $h->PUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Gudang :</td>
        <td class="isi-kiri">{{ $h->gudang ?: '-' }}</td>
        <td class="lbl-r">Tanggal :</td>
        <td>{{ \Carbon\Carbon::parse($h->PUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="lbl" style="padding-top: 6pt">Keterangan :</td>
        <td colspan="3" style="padding-top: 6pt">{{ $h->PUURAIAN }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
{{-- TIDAK ada kolom Satuan di cetakan ini (beda dari cetakan Produksi). --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 30%" align="left">Item</th>
            <th style="width: 37%" align="left">Nama Item</th>
            <th style="width: 14%" class="r">Qty Masuk</th>
            <th style="width: 14%" class="r">Qty Keluar</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $l->kode }}</td>
                <td>{{ $l->nama }}</td>
                <td class="r">{{ number_format((float) $l->masuk, 2, ',', '.') }}</td>
                <td class="r">{{ number_format((float) $l->keluar, 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
    @if (count($lines))
        <tfoot>
            <tr>
                <td colspan="3" class="r"><strong>Total Qty</strong></td>
                <td class="r"><strong>{{ number_format((float) $totalMasuk, 2, ',', '.') }}</strong></td>
                <td class="r"><strong>{{ number_format((float) $totalKeluar, 2, ',', '.') }}</strong></td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- ================= TANDA TANGAN (BERNAMA) ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 30%" class="c">Di Buat Oleh</td>
        <td style="width: 34%" class="c">Di Setujui Oleh</td>
        <td></td>
    </tr>
    <tr>
        {{-- Pembuat = kontak dokumen; penyetuju dari config per PT (kosong kalau belum
             terdaftar - lihat docblock controller). --}}
        <td class="c ttd-nama">(&nbsp;{{ $h->kontak }}&nbsp;)</td>
        <td class="c ttd-nama">(&nbsp;{{ $penyetuju }}&nbsp;)</td>
        <td></td>
    </tr>
</table>

@if ((int) $h->PUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** JOB ORDER INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
