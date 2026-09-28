{{-- Cetakan "Surat Jalan" - direplikasi dari contoh cetakan lama user
     (Surat Jalan DE-SJ26090001.pdf). Lihat docblock SjPrintController: kop apotek dari
     config per NPID, dan "No PO" yg isinya sebenarnya nomor Permintaan Barang. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        .kop-apotek { font-family: sans-serif; font-size: 15pt; margin: 0 0 4pt; }
        .kop-baris { font-family: sans-serif; font-size: 8.5pt; line-height: 1.4; }

        h1.judul { font-size: 13pt; text-align: center; margin: 16pt 0 12pt; }

        .info td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .info .lbl { font-weight: bold; width: 78pt; }
        .info .lbl-r { font-weight: bold; width: 68pt; }
        /* Kolom isi kiri (Tujuan/Gudang/Keterangan) dilebarkan - permintaan user
           2026-09-27. Blok kanan isinya pendek (nomor & tanggal), jadi sisanya cukup. */
        .info .isi-kiri { width: 46%; }

        table.items { margin-top: 8pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9.5pt; }
        table.items td { padding: 3pt 2pt; font-size: 9.5pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        .r { text-align: right; } .c { text-align: center; }

        .bawah { margin-top: 10pt; font-size: 9.5pt; }
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

{{-- ================= KOP APOTEK ================= --}}
{{-- Dikosongkan kalau PT belum terdaftar di config - lihat docblock controller. --}}
@if (! empty($apotek['apotek']) || ! empty($apotek['apoteker_nama']) || ! empty($apotek['sipa']))
    <div>
        @if (! empty($apotek['apotek']))
            <div class="kop-apotek">{{ $apotek['apotek'] }}</div>
        @endif
        <div class="kop-baris">
            @if (! empty($apotek['apoteker_nama']))
                Apoteker : {{ $apotek['apoteker_nama'] }}<br>
            @endif
            @if (! empty($apotek['sipa']))
                SIPA : {{ $apotek['sipa'] }}
            @endif
        </div>
    </div>
@endif

<h1 class="judul">Surat Jalan</h1>

{{-- ================= INFO 2 KOLOM ================= --}}
<table class="info">
    <tr>
        <td class="lbl">Tujuan :</td>
        <td class="isi-kiri">{{ $h->kontak ?: '-' }}</td>
        <td class="lbl-r">No Transaksi :</td>
        <td>{{ $h->SUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Gudang Tujuan :</td>
        <td class="isi-kiri">{{ $h->gudangTujuan ?: '-' }}</td>
        <td class="lbl-r">Tanggal :</td>
        <td>{{ \Carbon\Carbon::parse($h->SUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="lbl">Gudang Sumber :</td>
        <td class="isi-kiri">{{ $h->gudangSumber ?: '-' }}</td>
        {{-- Label "No PO" dipertahankan walau isinya nomor PR - lihat docblock controller. --}}
        <td class="lbl-r">No PO</td>
        <td>{{ $h->noPr ?: '-' }}</td>
    </tr>
    <tr>
        {{-- Sisi kanan baris ini kosong, jadi Keterangan diberi SELURUH sisa lebar. --}}
        <td class="lbl">Keterangan :</td>
        <td colspan="3">{{ $h->SUURAIAN ?: '-' }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 6%" class="c">No</th>
            {{-- TIDAK ada kolom kode item di cetakan ini (beda dari KMB/TMB/PB). --}}
            <th style="width: 44%" align="left">Nama Item</th>
            <th style="width: 12%" class="r">Keluar</th>
            <th style="width: 12%" align="left">Satuan</th>
            <th style="width: 26%" align="left">Catatan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $l->nama }}</td>
                <td class="r">{{ number_format((float) $l->qty, 2, ',', '.') }}</td>
                <td>{{ $l->satuan }}</td>
                <td>{{ $l->catatan }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ================= TANDA TANGAN + TOTAL ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 26%" class="c">Bag Apotik</td>
        <td style="width: 26%" class="c">Penerima</td>
        <td style="width: 26%" class="r"><strong>Total Qty</strong></td>
        <td style="width: 22%" class="r"><strong>{{ number_format((float) $totalQty, 2, ',', '.') }}</strong></td>
    </tr>
    <tr>
        <td class="c ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="c ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td colspan="2"></td>
    </tr>
</table>

@if ((int) $h->SUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** SURAT JALAN INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
