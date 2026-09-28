{{-- Cetakan "Petty Cash" (Pengajuan Dana) - dari contoh cetakan lama user
     (Pengajuan dana PG-PDN26090001.pdf). Lihat docblock PengajuanDanaPrintController:
     kop BERULANG tiap halaman, baris urutan 1 (akun sumber) dikecualikan. --}}
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

        /* ---------- tabel utama ---------- */
        table.items { border: .8pt solid #000; margin-top: 4pt; }
        table.items th { border-bottom: .8pt solid #000; padding: 4pt 5pt; font-size: 9.5pt; }
        table.items th small { display: block; font-weight: normal; font-size: 9pt; }
        table.items td { padding: 3pt 5pt; font-size: 9.5pt; vertical-align: top; }
        table.items .sekat { border-left: .8pt solid #000; }
        table.items tr.terbilang td { border-top: .8pt solid #000; padding: 5pt; }
        .r { text-align: right; } .c { text-align: center; }

        /* ---------- kotak tanda tangan ---------- */
        table.ttd-kotak { width: 60%; border: .8pt solid #000; margin-top: 12pt; }
        table.ttd-kotak td { border-right: .8pt solid #000; padding: 4pt 6pt; font-size: 9.5pt; }
        table.ttd-kotak td:last-child { border-right: 0; }
        table.ttd-kotak .isi { height: 42pt; vertical-align: bottom; }

        /* ---------- rekap COA ---------- */
        table.rekap { width: 78%; border: .8pt solid #000; margin: 22pt auto 0 auto; }
        table.rekap th { border-bottom: .8pt solid #000; padding: 4pt 6pt; font-size: 9.5pt; }
        table.rekap td { padding: 2.5pt 6pt; font-size: 9.5pt; }
        table.rekap .sekat { border-left: .8pt solid #000; }

        .ttd-akhir { margin-top: 18pt; font-size: 9.5pt; width: 78%; margin-left: auto; margin-right: auto; }
        .ttd-akhir td { padding-top: 4pt; }
        .ttd-akhir .garis { border-top: .8pt solid #000; padding-top: 2pt; }
    </style>
</head>
<body>

{{-- Kop DIULANG tiap halaman - contoh 2 halaman menampilkannya di keduanya. --}}
<htmlpageheader name="kop">
    <table class="kop">
        <tr>
            <td style="width: 36%">
                NMW {{ $h->gudang }}<br>
                {!! nl2br(e($h->gudangAlamat ?: '')) !!}
            </td>
            <td style="width: 30%">
                <div class="judul">Petty Cash</div>
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

{{-- ================= TABEL RINCIAN ================= --}}
<table class="items">
    <thead>
        <tr>
            <th align="center">Keterangan<small>Description</small></th>
            {{-- Kolom tanggal memang TANPA judul di cetakan contoh. --}}
            <th class="sekat" style="width: 13%"></th>
            <th class="sekat" style="width: 20%">COA<small>Account No.</small></th>
            <th class="sekat" style="width: 17%">Jumlah<small>Amount</small></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td>{{ $i + 1 }}. {{ $l->keterangan }}</td>
                <td class="sekat c">{{ \Carbon\Carbon::parse($h->CUTANGGAL)->format('d/m/Y') }}</td>
                <td class="sekat c">{{ $l->coa }}</td>
                <td class="sekat r">{{ number_format((float) $l->debit, 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="c" style="padding: 10pt">Tidak ada rincian.</td></tr>
        @endforelse

        <tr class="terbilang">
            <td colspan="3">Terbilang : {{ terbilang_rupiah((float) $total) }}</td>
            <td class="sekat r"><strong>{{ number_format((float) $total, 2, ',', '.') }}</strong></td>
        </tr>
    </tbody>
</table>

{{-- ================= KOTAK TANDA TANGAN ================= --}}
<table class="ttd-kotak">
    <tr>
        <td class="c">Dibuat/Prepared</td>
        <td class="c">Diperiksa/Checked</td>
        <td class="c">Disetujui/Approved</td>
    </tr>
    <tr>
        <td class="isi">Tgl/Date : {{ \Carbon\Carbon::parse($h->CUTANGGAL)->format('d/m/Y') }}</td>
        <td class="isi">Tgl/Date</td>
        <td class="isi">Tgl/Date</td>
    </tr>
</table>

{{-- ================= REKAP PER COA ================= --}}
@if ($rekap !== [])
    <table class="rekap">
        <thead>
            <tr>
                <th style="width: 26%">KODE</th>
                <th class="sekat" align="left">KETERANGAN</th>
                <th class="sekat" style="width: 22%" align="right">DEBIT</th>
                <th class="sekat" style="width: 18%" align="right">KREDIT</th>
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
        </tbody>
    </table>
@endif

<table class="ttd-akhir">
    <tr>
        <td style="width: 46%">Diperiksa Oleh</td>
        <td>Dibukukan Oleh</td>
    </tr>
    <tr>
        <td style="padding-top: 40pt"></td>
        <td style="padding-top: 40pt"></td>
    </tr>
    <tr>
        <td class="garis">Accounting Manager/SPV</td>
        <td class="garis">Accounting</td>
    </tr>
</table>

@if ((int) $h->CUSTATUS === 9)
    <div style="margin-top: 10pt; font-weight: bold; color: #b00;">** PENGAJUAN INI SUDAH DIBATALKAN **</div>
@endif

</body>
</html>
