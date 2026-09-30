{{-- "Laporan Real Stok Barang" - layout MENIRU contoh cetakan lama yg dikirim user
     (`Daftar Stok Barang BIZPARK.pdf`), BUKAN <x-reports.layout> rumah gaya: tanpa kop
     beralamat, tanpa bingkai sel, garis horizontal saja, kaki halaman dwibahasa.
     Lihat docblock `ReportController::dataDaftarStokBarang()`. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 9.5pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        .perusahaan { text-align: center; font-weight: bold; font-size: 13pt; }
        .judul { text-align: center; font-weight: bold; font-size: 12pt; padding-top: 2pt; }
        .tanggal { text-align: center; font-size: 9pt; padding-top: 3pt; }
        .cabang { text-align: center; font-size: 9pt; }

        table.items { margin-top: 10pt; }
        table.items th { border-top: .7pt solid #000; border-bottom: .7pt solid #000;
                         padding: 3pt 4pt; font-size: 9.5pt; font-weight: bold; }
        table.items td { padding: 2pt 4pt; font-size: 9pt; }
        table.items tr.total td { border-top: .7pt solid #000; font-weight: bold; padding-top: 3pt; }
        .r { text-align: right; }
    </style>
</head>
<body>

<div class="perusahaan">{{ $company['nama'] ?? '' }}</div>
<div class="judul">{{ $title }}</div>
<div class="tanggal">Tanggal : {{ $tanggal }}</div>
@if ($subtitle)
    <div class="cabang">{{ $subtitle }}</div>
@endif

{{-- Kaki halaman dwibahasa, pola & format sama `pengajuan-dana-print`. --}}
<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8.5pt; padding-top: 2pt">
        <tr>
            <td>Halaman&nbsp;&nbsp;&nbsp;Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y') }}&nbsp;&nbsp;&nbsp;{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

<table class="items">
    <thead>
        <tr>
            <th align="left" style="width: 11%">Gudang</th>
            <th align="left" style="width: 12%">Jenis</th>
            <th align="left" style="width: 24%">Kode</th>
            <th align="left">Nama Item</th>
            <th class="r" style="width: 13%">Real Stok</th>
            <th align="left" style="width: 9%">Satuan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $x)
            <tr>
                <td>{{ $x->gudang }}</td>
                <td>{{ $x->jenis }}</td>
                <td>{{ $x->kode }}</td>
                <td>{{ $x->nama }}</td>
                <td class="r">{{ number_format((float) $x->stok, 2, ',', '.') }}</td>
                <td>{{ $x->satuan }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding: 12pt; text-align: center">Tidak ada data.</td></tr>
        @endforelse

        {{-- TOTAL menjumlah kolom apa adanya walau SATUANNYA BERCAMPUR (Pcs, Gr, Kg, Liter) -
             itu memang perilaku cetakan lama, bukan kekeliruan. --}}
        <tr class="total">
            <td colspan="4">TOTAL</td>
            <td class="r">{{ number_format((float) $total, 2, ',', '.') }}</td>
            <td></td>
        </tr>
    </tbody>
</table>

</body>
</html>
