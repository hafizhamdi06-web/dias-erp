{{-- Bukti Kas Masuk / Kas Keluar - SATU blade utk kedua arah, lihat docblock
     KasBankPrintController. `$judul` satu-satunya yg beda; angka jatuh di kolom Debit atau
     Kredit apa adanya dari DB - JANGAN ditukar di sini. Rumah gaya mengikuti
     `pengajuan-dana-print` supaya cetakan finance seragam. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        /* ---------- kop berulang ---------- */
        .kop td { vertical-align: top; font-size: 9.5pt; }
        .kop .judul { text-align: center; font-weight: bold; font-size: 11pt; }
        .kop .nomor { text-align: center; font-size: 9.5pt; padding-top: 3pt; }

        /* ---------- blok identitas dokumen ---------- */
        table.info { margin-top: 6pt; }
        table.info td { font-size: 9.5pt; padding: 1.5pt 0; vertical-align: top; }
        table.info .label { width: 27%; }
        table.info .label small { font-weight: normal; font-size: 8.5pt; }
        table.info .titik { width: 2%; }

        /* ---------- tabel utama ---------- */
        table.items { border: .8pt solid #000; margin-top: 6pt; }
        table.items th { border-bottom: .8pt solid #000; padding: 4pt 5pt; font-size: 9.5pt; }
        /* TANPA `display:block` - pemisah barisnya `<br>` di HTML (lihat komentar di <thead>). */
        table.items th small { font-weight: normal; font-size: 9pt; }
        table.items td { padding: 3pt 5pt; font-size: 9.5pt; vertical-align: top; }
        table.items .sekat { border-left: .8pt solid #000; }
        table.items tr.jumlah td { border-top: .8pt solid #000; padding: 4pt 5pt; font-weight: bold; }
        table.items tr.terbilang td { border-top: .8pt solid #000; padding: 5pt; }
        .r { text-align: right; } .c { text-align: center; }

        /* ---------- kotak tanda tangan ---------- */
        table.ttd-kotak { border: .8pt solid #000; margin-top: 12pt; }
        table.ttd-kotak td { border-right: .8pt solid #000; padding: 4pt 6pt; font-size: 9pt; }
        table.ttd-kotak td:last-child { border-right: 0; }
        table.ttd-kotak .isi { height: 48pt; vertical-align: bottom; font-size: 8.5pt; }

        /* ---------- rekap COA ---------- */
        table.rekap { width: 78%; border: .8pt solid #000; margin: 18pt auto 0 auto; }
        table.rekap th { border-bottom: .8pt solid #000; padding: 4pt 6pt; font-size: 9.5pt; }
        table.rekap td { padding: 2.5pt 6pt; font-size: 9.5pt; }
        table.rekap tr.jumlah td { border-top: .8pt solid #000; font-weight: bold; }
        table.rekap .sekat { border-left: .8pt solid #000; }
    </style>
</head>
<body>

{{-- Kop DIULANG tiap halaman, pola sama Pengajuan Dana - bukti kas bisa >1 halaman. --}}
<htmlpageheader name="kop">
    <table class="kop">
        <tr>
            <td style="width: 36%">
                NMW {{ $h->gudang }}<br>
                {!! nl2br(e($h->gudangAlamat ?: '')) !!}
            </td>
            <td style="width: 30%">
                <div class="judul">{{ $judul }}</div>
                <div class="nomor">{{ $h->CUNOTRANSAKSI }}</div>
            </td>
            <td style="width: 34%; text-align: right">
                Tanggal : {{ \Carbon\Carbon::parse($h->CUTANGGAL)->format('d/m/Y') }}
            </td>
        </tr>
    </table>
</htmlpageheader>
<sethtmlpageheader name="kop" value="on" show-this-page="1" />

<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8.5pt; padding-top: 2pt">
        <tr>
            <td>Halaman&nbsp;&nbsp;&nbsp;Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y') }}&nbsp;&nbsp;&nbsp;{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

{{-- ================= IDENTITAS DOKUMEN ================= --}}
{{-- Label dwibahasa DI BAWAH label Indonesianya, pakai `<br>` - lihat catatan di <thead>. --}}
<table class="info">
    <tr>
        <td class="label">Kontak<br><small>Contact</small></td>
        <td class="titik">:</td>
        <td>{{ $h->kontak ?: '—' }}</td>
    </tr>
    <tr>
        <td class="label">Rekening Kas<br><small>Cash Account</small></td>
        <td class="titik">:</td>
        <td>{{ trim(($h->rekKode ?: '') . ' — ' . ($h->rekNama ?: '')) ?: '—' }}</td>
    </tr>
    <tr>
        <td class="label">Uraian<br><small>Description</small></td>
        <td class="titik">:</td>
        <td>{{ $h->CUURAIAN ?: '—' }}</td>
    </tr>
</table>

{{-- ================= TABEL RINCIAN ================= --}}
<table class="items">
    <thead>
        <tr>
            {{-- `<br>` WAJIB, bukan sekadar `display:block` di CSS: mpdf TIDAK menghormati
                 `display:block` pada elemen inline spt <small>, jadi judul Inggrisnya
                 menempel sebaris dgn yg Indonesia (dilaporkan user 2026-09-30). --}}
            <th align="center">Keterangan<br><small>Description</small></th>
            <th class="sekat" style="width: 14%">COA<br><small>Account No.</small></th>
            <th class="sekat" style="width: 24%">Nama Akun<br><small>Account Name</small></th>
            <th class="sekat" style="width: 15%">Debit<br><small>Debit</small></th>
            <th class="sekat" style="width: 15%">Kredit<br><small>Credit</small></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td>{{ $i + 1 }}. {{ $l->keterangan ?: ($l->coaNama ?: '—') }}</td>
                <td class="sekat c">{{ $l->coa ?: '—' }}</td>
                <td class="sekat">{{ $l->coaNama ?: '—' }}</td>
                <td class="sekat r">{{ (float) $l->debit != 0.0 ? number_format((float) $l->debit, 2, ',', '.') : '' }}</td>
                <td class="sekat r">{{ (float) $l->kredit != 0.0 ? number_format((float) $l->kredit, 2, ',', '.') : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="c" style="padding: 10pt">Tidak ada rincian.</td></tr>
        @endforelse

        <tr class="jumlah">
            <td colspan="3" class="r">Jumlah / Total</td>
            <td class="sekat r">{{ number_format($totalDebit, 2, ',', '.') }}</td>
            <td class="sekat r">{{ number_format($totalKredit, 2, ',', '.') }}</td>
        </tr>
        <tr class="terbilang">
            <td colspan="5">Terbilang : {{ terbilang_rupiah((float) $h->CUTOTALTRANS) }}</td>
        </tr>
    </tbody>
</table>

{{-- ================= KOTAK TANDA TANGAN ================= --}}
<table class="ttd-kotak">
    <tr>
        <td class="c" style="width: 25%">Dibuat<br><small>Prepared</small></td>
        <td class="c" style="width: 25%">Diperiksa<br><small>Checked</small></td>
        <td class="c" style="width: 25%">Disetujui<br><small>Approved</small></td>
        <td class="c" style="width: 25%">Diterima<br><small>Received</small></td>
    </tr>
    <tr>
        <td class="isi">{{ $h->dibuatOleh ?: '' }}<br>Tgl/Date : {{ \Carbon\Carbon::parse($h->CUTANGGAL)->format('d/m/Y') }}</td>
        <td class="isi">Tgl/Date</td>
        <td class="isi">Tgl/Date</td>
        <td class="isi">Tgl/Date</td>
    </tr>
</table>

{{-- ================= REKAP PER COA ================= --}}
@if ($rekap !== [])
    <table class="rekap">
        <thead>
            <tr>
                <th style="width: 20%">KODE</th>
                <th class="sekat" align="left">KETERANGAN</th>
                <th class="sekat" style="width: 22%" align="right">DEBIT</th>
                <th class="sekat" style="width: 22%" align="right">KREDIT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rekap as $r)
                <tr>
                    <td class="c">{{ $r['kode'] }}</td>
                    <td class="sekat">{{ $r['nama'] }}</td>
                    <td class="sekat r">{{ number_format((float) $r['debit'], 2, ',', '.') }}</td>
                    <td class="sekat r">{{ number_format((float) $r['kredit'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="jumlah">
                <td colspan="2" class="r">JUMLAH</td>
                <td class="sekat r">{{ number_format($totalDebit, 2, ',', '.') }}</td>
                <td class="sekat r">{{ number_format($totalKredit, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
@endif

</body>
</html>
