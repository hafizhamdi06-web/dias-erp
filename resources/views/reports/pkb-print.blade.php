{{-- Cetakan "Perintah Kirim Barang" - dari contoh cetakan lama user
     (Perintah Kirim Barang DE-PKB26090001.pdf). Lihat docblock PkbPrintController:
     blok "Data Permintaan" & Gudang/Tipe diambil dari PR asal, bukan dari PKB. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        .kop-clinic { font-size: 13pt; font-weight: bold; }
        h1.judul { font-size: 13pt; text-align: center; margin: 0; }

        .atas td { vertical-align: top; }
        .blok td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .blok .lbl { font-weight: bold; width: 84pt; }
        .sub { font-weight: bold; font-size: 9.5pt; padding-top: 10pt; }

        table.items { margin-top: 14pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9.5pt; }
        table.items td { padding: 3pt 2pt; font-size: 9.5pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        table.items tfoot td { border-top: .8pt solid #000; padding-top: 4pt; }
        .r { text-align: right; } .c { text-align: center; }

        .bawah { margin-top: 10pt; font-size: 9.5pt; }
        .bawah td { vertical-align: top; }
        .ttd-garis { padding-top: 34pt; font-size: 9.5pt; }
    </style>
</head>
<body>

{{-- Footer PKB TANPA awalan "Halaman" - beda dari cetakan SJ/PB/KMB/TMB, ikut contoh. --}}
<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8.5pt; padding-top: 2pt">
        <tr>
            <td>Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y') }}&nbsp;&nbsp;&nbsp;{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

{{-- ================= KOP + JUDUL ================= --}}
<table class="atas">
    <tr>
        <td style="width: 34%" class="kop-clinic">{{ $clinic }}</td>
        <td style="width: 40%"><h1 class="judul">Perintah Kirim Barang</h1></td>
        <td style="width: 26%"></td>
    </tr>
</table>

{{-- ================= IDENTITAS PKB + DATA PERMINTAAN ================= --}}
<table class="blok" style="margin-top: 14pt">
    <tr>
        {{-- Nilainya sengaja kosong - sumbernya tidak ketemu, lihat docblock controller. --}}
        <td class="lbl">Diperintah oleh</td>
        <td style="width: 34%"></td>
        <td class="lbl" style="width: 60pt">No PKB</td>
        <td>{{ $h->PKBUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td class="lbl">Tanggal</td>
        <td>{{ \Carbon\Carbon::parse($h->PKBUTANGGAL)->format('d/m/Y') }}</td>
    </tr>
    <tr><td colspan="4" class="sub">Data Permintaan :</td></tr>
    <tr>
        <td class="lbl">No Transaksi :</td>
        <td>{{ $h->prNomor ?: '-' }}</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td class="lbl">Tanggal :</td>
        <td>{{ $h->prTanggal ? \Carbon\Carbon::parse($h->prTanggal)->format('d/m/Y') : '-' }}</td>
        <td class="lbl">Gudang :</td>
        <td>{{ $h->prGudang ?: '-' }}</td>
    </tr>
    <tr>
        <td class="lbl">No Ref :</td>
        <td>{{ $h->prNoRef }}</td>
        <td class="lbl">Tipe</td>
        <td>{{ $h->prTipe ?: '-' }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 6%" class="c">No</th>
            <th style="width: 44%" align="left">Nama Item</th>
            <th style="width: 12%" class="r">Qty</th>
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
    @if (count($lines))
        <tfoot>
            <tr>
                {{-- Cetakan lama menampilkan label ini TANPA angka - diisi di sini. --}}
                <td colspan="2" class="r"><strong>Total Qty</strong></td>
                <td class="r"><strong>{{ number_format((float) $totalQty, 2, ',', '.') }}</strong></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- ================= TANDA TANGAN ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 28%" class="c">Dibuat Oleh</td>
        <td style="width: 28%" class="c">Disetujui Oleh</td>
        <td></td>
    </tr>
    <tr>
        <td class="c ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="c ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td></td>
    </tr>
</table>

@if ((int) $h->PKBUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** PKB INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
