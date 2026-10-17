{{-- Cetakan "Perubahan Harga" - lihat docblock PerubahanHargaPrintController.
     Ditulis BERTAHAP oleh PdfReport (perBagian): kepala & info HANYA di potongan pertama,
     <pagebreak /> sebelum tabel potongan berikutnya, total & tanda tangan HANYA di potongan
     terakhir, dan lebar SETIAP kolom eksplisit supaya kolom antar potongan tetap lurus. --}}
@php
    $pertama = $bagianPertama ?? true;
    $terakhir = $bagianTerakhir ?? true;
    $st = (int) $h->SUSTATUS;
    $W = \App\Services\PerubahanHargaWriter::class;
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '-';
@endphp
@if ($pertama)
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 9.5pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        h1.judul { font-size: 13pt; text-align: center; margin: 0 0 12pt; }

        .info td { vertical-align: top; padding: 1.5pt 0; font-size: 9.5pt; }
        .info .lbl { font-weight: bold; width: 72pt; }
        .info .lbl-r { font-weight: bold; width: 78pt; }

        .status { margin-top: 6pt; padding: 4pt 6pt; border: .8pt solid #000; font-size: 9pt; }
        .status.draft { border-color: #b00; color: #b00; font-weight: bold; }

        table.items { margin-top: 8pt; }
        table.items th { border-top: .8pt solid #000; border-bottom: .8pt solid #000;
                         padding: 3pt 2pt; font-size: 9pt; }
        table.items td { padding: 2.5pt 2pt; font-size: 9pt; }
        table.items tbody tr { border-bottom: .4pt dotted #666; }
        .r { text-align: right; } .c { text-align: center; }
        .turun { color: #b00; }
        table.items tr.grup td { font-weight: bold; padding: 6pt 2pt 2pt; border-bottom: .6pt solid #000; }
        table.items tr.grup .lanjut { font-weight: normal; font-style: italic; }
        table.items tr.subtotal td { font-weight: bold; border-top: .4pt solid #000; padding-bottom: 4pt; }

        .bawah { margin-top: 10pt; font-size: 9.5pt; }
        .bawah td { vertical-align: top; }
        .ttd-nama { padding-top: 38pt; font-size: 9.5pt; }
    </style>
</head>
<body>

<htmlpagefooter name="kaki">
    <table style="border-top: .5pt solid #000; font-size: 8pt; padding-top: 2pt">
        <tr>
            <td>{{ $h->SUNOTRANSAKSI }} — Halaman {PAGENO} dari {nbpg}</td>
            <td style="text-align: right">Tanggal Print&nbsp;&nbsp;{{ now()->format('d/m/Y H:i:s') }}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="kaki" value="on" />

<h1 class="judul">Perubahan Harga</h1>

<table class="info">
    <tr>
        <td class="lbl">Kontak :</td>
        <td style="width: 38%">{{ $h->kontak ?: '-' }}</td>
        <td class="lbl-r">No Transaksi :</td>
        <td>{{ $h->SUNOTRANSAKSI }}</td>
    </tr>
    <tr>
        <td class="lbl">Keterangan :</td>
        <td>{{ $h->SUURAIAN ?: '-' }}</td>
        <td class="lbl-r">Tanggal :</td>
        <td>{{ $tgl($h->SUTANGGAL) }}</td>
    </tr>
    <tr>
        <td class="lbl">Kenaikan :</td>
        <td>{{ (float) $h->SUNILAI != 0 ? rtrim(rtrim(number_format((float) $h->SUNILAI, 2, ',', '.'), '0'), ',') . '%' : '-' }}</td>
        <td class="lbl-r">Tgl Berlaku :</td>
        <td><strong>{{ $tgl($h->SUTANGGALBERLAKU) }}</strong></td>
    </tr>
    <tr>
        <td class="lbl">Jumlah Item :</td>
        <td>{{ number_format($total['item'], 0, ',', '.') }}
            ({{ $total['naik'] }} naik{{ $total['turun'] ? ', ' . $total['turun'] . ' turun' : '' }}{{ $total['tetap'] ? ', ' . $total['tetap'] . ' tetap' : '' }})</td>
        <td colspan="2"></td>
    </tr>
</table>

@if ($st === $W::STATUS_DITERAPKAN)
    <div class="status">
        Status: <strong>DITERAPKAN</strong>
        @if ($terapkan) — harga baru berlaku di sistem sejak {{ \Carbon\Carbon::parse($terapkan->created_at)->format('d/m/Y H:i') }}{{ $terapkan->user ? ' oleh ' . $terapkan->user : '' }}@endif.
    </div>
@elseif ($st === $W::STATUS_DIKEMBALIKAN)
    <div class="status">
        Status: <strong>DIKEMBALIKAN</strong> — harga lama sudah dipulihkan
        @if ($log && $log->aksi === 'kembalikan') pada {{ \Carbon\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}{{ $log->user ? ' oleh ' . $log->user : '' }}{{ $log->jumlah_dilewati ? ' (' . $log->jumlah_dilewati . ' item dilewati karena harganya sudah diubah lagi)' : '' }}@endif.
        Harga di bawah ini TIDAK berlaku.
    </div>
@else
    <div class="status draft">
        DRAFT — BELUM DITERAPKAN. Harga jual di sistem belum berubah.
    </div>
@endif

{{-- Bukti audit: daftar lampiran surat/IOM (file aslinya tersimpan di sistem). --}}
<table class="info" style="margin-top: 5pt">
    <tr>
        <td class="lbl">Lampiran :</td>
        <td>
            @forelse ($lampiran as $lp)
                {{ $loop->iteration }}. {{ $lp->nama_asli }}
                ({{ \Carbon\Carbon::parse($lp->created_at)->format('d/m/Y H:i') }}{{ $lp->user ? ', ' . $lp->user : '' }})@if (! $loop->last)<br>@endif
            @empty
                <span style="color: #b00">tidak ada</span>
            @endforelse
        </td>
    </tr>
</table>
@else
<pagebreak />
@endif

<table class="items">
    <thead>
        <tr>
            {{-- Susunan = grid form Perubahan Harga (tanpa "Harga Sekarang"). Kolom
                 "Setelah Pembulatan" hanya kalau $adaPembulatan (lihat controller); kalau
                 tidak, lebarnya (15%) diberikan ke Nama. --}}
            <th style="width: 4%" class="c">No</th>
            <th style="width: 20%" align="left">Kode</th>
            <th style="width: {{ $adaPembulatan ? 31 : 46 }}%" align="left">Nama</th>
            <th style="width: 11%" class="r">Harga<br>Lama</th>
            <th style="width: 11%" class="r">Harga<br>Baru</th>
            @if ($adaPembulatan)
                <th style="width: 15%" class="r">Setelah<br>Pembulatan</th>
            @endif
            <th style="width: 8%" class="r">Naik</th>
        </tr>
    </thead>
    <tbody>
        {{-- Dikelompokkan per Kelompok 2020, nomor reset per kelompok - penanda & subtotal
             dihitung di controller (lihat sana). Kolom: 7 dgn pembulatan, 6 tanpa. --}}
        @php $kolom = $adaPembulatan ? 7 : 6; @endphp
        @forelse ($rows as $r)
            @if ($r->awalGrup || $loop->first)
                <tr class="grup">
                    <td colspan="{{ $kolom }}">
                        {{ $r->grup }}@if (! $r->awalGrup) <span class="lanjut">(lanjutan)</span>@endif
                    </td>
                </tr>
            @endif
            <tr>
                <td class="c">{{ $r->no }}</td>
                <td>{{ $r->kode }}</td>
                <td>{{ $r->nama }}</td>
                <td class="r">{{ $rp($r->lama) }}</td>
                @if ($adaPembulatan)
                    <td class="r">{{ $rp($r->baru) }}</td>
                    <td class="r"><strong>{{ $rp($r->akhir) }}</strong></td>
                @else
                    {{-- Tanpa pembulatan Harga Baru = harga yg dipasang, jadi ditebalkan. --}}
                    <td class="r"><strong>{{ $rp($r->akhir) }}</strong></td>
                @endif
                <td class="r {{ $r->selisih < 0 ? 'turun' : '' }}">{{ $r->persen === null ? '-' : number_format($r->persen, 1, ',', '.') . '%' }}</td>
            </tr>
            {{-- Subtotal kelompok - hanya kalau dokumennya punya >1 kelompok (kalau cuma satu,
                 subtotalnya = Total di bawah, jadi cuma pengulangan). --}}
            @if ($r->akhirGrup && $jmlGrup > 1)
                <tr class="subtotal">
                    <td colspan="3" class="r">Subtotal {{ $r->grup }} ({{ $r->subtotal['item'] }} item)</td>
                    <td class="r">{{ $rp($r->subtotal['lama']) }}</td>
                    @if ($adaPembulatan)
                        <td class="r">{{ $rp($r->subtotal['baru']) }}</td>
                    @endif
                    <td class="r">{{ $rp($r->subtotal['akhir']) }}</td>
                    <td></td>
                </tr>
            @endif
        @empty
            <tr><td colspan="{{ $adaPembulatan ? 7 : 6 }}" class="c" style="padding: 10pt">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
    @if ($terakhir && $total['item'])
        <tfoot>
            <tr>
                <td colspan="3" class="r" style="border-top: .8pt solid #000; padding: 3pt 2pt"><strong>Total</strong></td>
                <td class="r" style="border-top: .8pt solid #000"><strong>{{ $rp($total['lama']) }}</strong></td>
                @if ($adaPembulatan)
                    <td class="r" style="border-top: .8pt solid #000"><strong>{{ $rp($total['baru']) }}</strong></td>
                @endif
                <td class="r" style="border-top: .8pt solid #000"><strong>{{ $rp($total['akhir']) }}</strong></td>
                <td style="border-top: .8pt solid #000"></td>
            </tr>
        </tfoot>
    @endif
</table>

@if ($terakhir)
<table class="bawah">
    <tr>
        <td style="width: 33%" class="c">Dibuat Oleh</td>
        <td style="width: 34%" class="c">Diperiksa Oleh</td>
        <td style="width: 33%" class="c">Disetujui / Diterapkan Oleh</td>
    </tr>
    <tr>
        @php
            $kosong = str_repeat('&nbsp;', 24);
            $penerap = $st === $W::STATUS_DITERAPKAN && $terapkan && $terapkan->user ? $terapkan->user : null;
        @endphp
        <td class="c ttd-nama">( {!! $h->pembuat ? e($h->pembuat) : $kosong !!} )</td>
        <td class="c ttd-nama">( {!! $kosong !!} )</td>
        <td class="c ttd-nama">( {!! $penerap ? e($penerap) : $kosong !!} )</td>
    </tr>
</table>

</body>
</html>
@endif
