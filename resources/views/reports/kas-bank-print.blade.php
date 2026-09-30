{{-- Bukti Kas Masuk / Kas Keluar - SATU blade utk kedua arah, lihat docblock
     KasBankPrintController. Layout MENIRU contoh cetakan sistem lama yg dikirim user
     2026-09-30 (`Bukti Kas Keluar BZ-KK26090002.pdf`), BUKAN rumah gaya Petty Cash:
     tanpa kop cabang, tanpa bingkai luar, tanpa rekap COA, garis horizontal saja.
     `$judul` satu-satunya teks yg beda antar arah; angka jatuh di kolom Debit atau Kredit
     apa adanya dari DB - JANGAN ditukar di sini. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        /* Nilai isian di contoh memakai font SANS, beda dari label/tabel yg serif -
           ditiru apa adanya (Uraian, tanggal, nama kontak). */
        .isi { font-family: sans-serif; }

        .judul { text-align: center; font-weight: bold; font-size: 14pt; }
        .nomor { text-align: center; font-size: 10pt; padding-top: 2pt; }

        /* ---------- baris Uraian ---------- */
        table.uraian { margin-top: 16pt; }
        table.uraian td { font-size: 9.5pt; padding-bottom: 3pt; vertical-align: bottom; }
        table.uraian .label { width: 9%; }
        table.uraian .titik { width: 4%; }

        /* ---------- tabel utama: GARIS HORIZONTAL SAJA, tanpa bingkai/sekat ---------- */
        table.items th { border-top: .6pt solid #000; border-bottom: .6pt solid #000;
                         padding: 2.5pt 4pt; font-size: 9.5pt; font-weight: bold; }
        table.items td { padding: 2.5pt 4pt; font-size: 9.5pt; }
        table.items tr.jumlah td { border-top: .6pt solid #000; font-weight: bold; }
        .r { text-align: right; }

        .terbilang { font-size: 9.5pt; padding-top: 4pt; }

        /* ---------- tanda tangan ---------- */
        table.ttd { margin-top: 22pt; }
        table.ttd td { font-size: 9.5pt; }
        table.ttd .kurung { padding-top: 46pt; }
    </style>
</head>
<body>

<div class="judul">{{ $judul }}</div>
<div class="nomor">No :&nbsp; {{ $h->CUNOTRANSAKSI }}</div>

<table class="uraian">
    <tr>
        <td class="label">Uraian</td>
        <td class="titik">:</td>
        <td class="isi">{{ $h->CUURAIAN ?: '—' }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th align="left" style="width: 17%">Kode Akun</th>
            <th align="left">Nama</th>
            <th class="r" style="width: 19%">Debit</th>
            <th class="r" style="width: 19%">Kredit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $l)
            <tr>
                <td>{{ $l->coa ?: '—' }}</td>
                <td>{{ $l->coaNama ?: '—' }}</td>
                {{-- Nol tetap ditulis "0,00" spt di contoh, TIDAK dikosongkan. --}}
                <td class="r">{{ number_format((float) $l->debit, 2, ',', '.') }}</td>
                <td class="r">{{ number_format((float) $l->kredit, 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="padding: 10pt; text-align: center">Tidak ada rincian.</td></tr>
        @endforelse

        <tr class="jumlah">
            <td colspan="2">Jumlah</td>
            <td class="r">{{ number_format($totalDebit, 2, ',', '.') }}</td>
            <td class="r">{{ number_format($totalKredit, 2, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="terbilang">Terbilang : {{ terbilang_rupiah($totalDebit) }}</div>

<table class="ttd">
    <tr>
        <td style="width: 18%">Dikeluarkan</td>
        <td style="width: 18%">Dicatat</td>
        <td style="width: 18%">Disetujui</td>
        <td class="r">{{ $kota }}, <span class="isi">{{ $tanggalPanjang }}</span></td>
    </tr>
    <tr>
        <td class="kurung">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="kurung">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="kurung">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</td>
        <td class="kurung r isi">{{ $h->kontak }}</td>
    </tr>
</table>

</body>
</html>
