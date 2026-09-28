{{-- Cetakan "INVOICE" penjualan - direplikasi dari contoh cetakan lama user
     (Invoice Penjualan BZ-IV26090001.pdf). Lihat docblock InvoicePrintController:
     kop surat PER CABANG (bgudang.GNAMAPT/GALAMAT2), dan SEMUA angka dihitung ulang
     dari baris karena kolom subtotal di DB selalu 0. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: serif; font-size: 10pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }

        /* ---------- kop ---------- */
        .kop td { vertical-align: top; font-size: 9pt; }
        .kop .pt { font-size: 13pt; font-weight: bold; padding-bottom: 3pt; }
        .judul { font-size: 15pt; font-weight: bold; text-align: center; letter-spacing: .5pt; }
        .nomor { text-align: center; font-size: 10pt; padding-top: 4pt; }
        .kanan td { font-size: 9.5pt; padding: 1pt 0; vertical-align: top; }
        .kanan .lbl { width: 62pt; }

        /* ---------- baris termin ---------- */
        .termin { margin-top: 12pt; font-size: 10pt; }
        .termin td { padding: 2pt 0; }
        .termin .lbl { font-weight: bold; width: 68pt; }

        /* ---------- tabel item ---------- */
        table.items { margin-top: 8pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 3pt; font-size: 9.5pt; font-weight: bold; }
        table.items td { padding: 2.5pt 3pt; font-size: 9pt; vertical-align: top; }
        table.items tbody tr:last-child td { padding-bottom: 5pt; }
        .r { text-align: right; } .c { text-align: center; }
        .garis-bawah td { border-top: .8pt solid #000; }

        /* ---------- blok bawah ---------- */
        .bawah { margin-top: 2pt; font-size: 9pt; }
        .bawah td { vertical-align: top; }
        .terbilang { font-size: 9.5pt; padding-bottom: 8pt; }
        .syarat { font-size: 9pt; }
        .syarat .judul-syarat { font-size: 9.5pt; }
        .ref { font-size: 9pt; padding-top: 8pt; }

        table.total td { font-size: 9.5pt; padding: 1.5pt 0; }
        table.total .lbl { font-weight: bold; }
        table.total .nilai { text-align: right; }
        table.total .baris-total td { border-top: .5pt solid #000; padding-top: 3pt; }

        .ttd { padding-top: 6pt; font-size: 9.5pt; }
        .ttd-garis { padding-top: 30pt; font-size: 9.5pt; }

        /* ---------- kotak rekap ---------- */
        table.rekap { margin-top: 18pt; border: .8pt solid #000; width: 72%; }
        table.rekap th { padding: 5pt 8pt; font-size: 9.5pt; font-weight: bold; }
        table.rekap td { padding: 2.5pt 8pt; font-size: 9pt; }
        table.rekap .rekap-gudang { font-weight: bold; font-size: 9.5pt; padding-top: 5pt; }
        table.rekap tbody tr:last-child td { padding-bottom: 6pt; }
    </style>
</head>
<body>

<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8pt; padding-top: 2pt">
        <tr>
            <td>Halaman&nbsp;&nbsp;&nbsp;Page {PAGENO} of {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y') }}&nbsp;&nbsp;&nbsp;{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

{{-- ================= KOP + JUDUL + TUJUAN ================= --}}
<table class="kop">
    <tr>
        <td style="width: 38%">
            <div class="pt">{{ $h->pt ?: '-' }}</div>
            {!! nl2br(e($h->ptAlamat ?: '')) !!}
        </td>
        <td style="width: 24%">
            <div class="judul">INVOICE</div>
            <div class="nomor">{{ $h->IPUNOTRANSAKSI }}</div>
        </td>
        <td style="width: 38%">
            <table class="kanan">
                <tr>
                    <td class="lbl">{{ $h->ptKota ?: 'Jakarta' }},</td>
                    <td>{{ \Carbon\Carbon::parse($h->IPUTANGGAL)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    {{-- "NMW " + gudang tujuan SJ, bukan nama kontak - lihat docblock controller. --}}
                    <td class="lbl">Kepada Yth,</td>
                    <td>{{ $kepadaYth }}</td>
                </tr>
                <tr>
                    <td class="lbl">Alamat</td>
                    <td>{{ $h->IPUALAMAT ?: '' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ================= JATUH TEMPO / TERMIN ================= --}}
<table class="termin">
    <tr>
        <td class="lbl">Jatuh Tempo</td>
        <td style="width: 28%">
            {{ $h->IPUTGLJATUHTEMPO ? \Carbon\Carbon::parse($h->IPUTGLJATUHTEMPO)->format('d/m/Y') : '-' }}
        </td>
        <td class="lbl" style="width: 52pt">Termin</td>
        <td>{{ $h->termin !== null ? $h->termin : '-' }}</td>
    </tr>
</table>

{{-- ================= TABEL ITEM ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%" class="c">No</th>
            <th style="width: 30%" align="left">Nama</th>
            {{-- Judul kolom dokumen sumber berganti sesuai modul:
                 IV = No SJ / No PBC, IVM = No KMB / No TMB. --}}
            <th style="width: 15%" align="left">{{ $kolom1 }}</th>
            <th style="width: 15%" align="left">{{ $kolom2 }}</th>
            <th style="width: 8%" class="r">Qty</th>
            <th style="width: 8%" align="left">Kemas</th>
            <th style="width: 9%" class="r">Harga</th>
            <th style="width: 10%" class="r">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $i => $l)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                {{-- Nama DIBIARKAN WRAP (cetakan lama memotongnya) - lihat docblock controller. --}}
                <td>{{ $l->nama }}</td>
                <td>{{ $l->noSumber1 }}</td>
                <td>{{ $l->noSumber2 }}</td>
                <td class="r">{{ number_format((float) $l->qty, 2, ',', '.') }}</td>
                <td>{{ $l->kemas }}</td>
                <td class="r">{{ number_format((float) $l->harga, 2, ',', '.') }}</td>
                <td class="r">{{ number_format((float) $l->subtotal, 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
        <tr class="garis-bawah"><td colspan="8" style="padding: 0"></td></tr>
    </tbody>
</table>

{{-- ================= TERBILANG / SYARAT + TOTAL ================= --}}
<table class="bawah">
    <tr>
        <td style="width: 58%; padding-right: 12pt">
            <div class="terbilang">
                Terbilang : {{ terbilang_rupiah((float) $total) }}
            </div>

            <div class="syarat">
                <div class="judul-syarat">SYARAT-SYARAT PEMBAYARAN</div>
                1. Pembayaran dengan Cek/Bilyet Giro harap atas nama {{ $h->pt ?: '-' }}<br>
                2. Pembayaran dengan Cek/Bilyet Giro baru dianggap sah setelah diuangkan,
            </div>

            <div class="ref">
                {{-- Digabung rapi; cetakan lama diawali koma nyasar (lihat docblock controller). --}}
                {{ $kolom1 === 'No SJ' ? 'No Surat Jalan' : $kolom1 }} : {{ implode(', ', $ref1List) ?: '-' }}<br>
                {{ $kolom2 }} : {{ implode(', ', $ref2List) ?: '-' }}
            </div>
        </td>

        <td style="width: 42%">
            <table class="total">
                <tr>
                    <td class="lbl">Sub Total</td>
                    <td class="nilai" style="width: 22%"></td>
                    <td class="nilai">{{ number_format((float) $subtotal, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Diskon</td>
                    <td class="nilai">{{ number_format((float) $h->IPUDISKONPERSEN, 2, ',', '.') }}&nbsp;&nbsp;%</td>
                    <td class="nilai">{{ number_format((float) $diskon, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Pajak</td>
                    <td></td>
                    <td class="nilai">{{ number_format((float) $pajak, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Ongkir</td>
                    <td></td>
                    <td class="nilai">{{ number_format((float) $ongkir, 2, ',', '.') }}</td>
                </tr>
                <tr class="baris-total">
                    <td class="lbl">Total</td>
                    <td></td>
                    <td class="nilai"><strong>{{ number_format((float) $total, 2, ',', '.') }}</strong></td>
                </tr>
            </table>

            <table class="ttd">
                <tr>
                    <td class="c" style="width: 50%">Penerima,</td>
                    <td class="c" style="width: 50%">Finance,</td>
                </tr>
                <tr>
                    <td class="c ttd-garis">(&nbsp;_________________&nbsp;)</td>
                    <td class="c ttd-garis">(&nbsp;_________________&nbsp;)</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ================= REKAP PER GUDANG TUJUAN & TIPE PENDAPATAN ================= --}}
@if ($rekap !== [])
    <table class="rekap">
        <thead>
            <tr>
                <th align="left">Kelompok</th>
                <th class="r" style="width: 15%">Qty</th>
                <th align="left" style="width: 15%">Satuan</th>
                <th class="r" style="width: 22%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rekap as $tujuan => $tipeList)
                <tr>
                    <td colspan="4" class="rekap-gudang">{{ $tujuan }}</td>
                </tr>
                @foreach ($tipeList as $t)
                    <tr>
                        <td style="padding-left: 10pt">{{ $t['tipe'] }}</td>
                        <td class="r">{{ number_format((float) $t['qty'], 2, ',', '.') }}</td>
                        <td>{{ $t['satuan'] }}</td>
                        <td class="r"><strong>{{ number_format((float) $t['jumlah'], 2, ',', '.') }}</strong></td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
