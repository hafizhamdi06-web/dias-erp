{{-- Cetakan BERSAMA lima laporan IV/IVM (menu 780/788/819/821/822) - kolom PT & Pelanggan
     muncul kondisional sesuai menunya. Lihat ReportController::dataIvDaftar().

     Ditulis BERTAHAP (lihat `PdfReport::tulisBertahap()`): laporan ini wajar beribu baris
     (satu bulan data nyata = 2.851 baris), dan sekali render mpdf-nya OOM. Karena tiap
     potongan jadi `<table>` sendiri, lebar SEMUA kolom harus eksplisit - termasuk Nama Item,
     yg di sini dihitung dari sisa lebar supaya nilainya sama di setiap potongan. --}}
@php
    $bagianPertama ??= true;
    $bagianTerakhir ??= true;
    // 71% terpakai kolom tetap di bawah; sisanya milik Nama Item.
    $lebarNama = 100 - 71 - ($pakaiPelanggan ? 10 : 0) - ($pakaiPt ? 4 : 0);
    $kolom = 14 + ($pakaiPelanggan ? 1 : 0) + ($pakaiPt ? 1 : 0);
@endphp
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company"
                  :pertama="$bagianPertama" :terakhir="$bagianTerakhir">
    {{-- Tanpa ini `<thead>` potongan berikutnya nyangkut di tengah halaman. --}}
    @unless ($bagianPertama)<pagebreak />@endunless
    <table>
        <thead>
            <tr>
                <th style="width:9%">No Invoice</th>
                <th style="width:5%">Tanggal</th>
                @if ($pakaiPelanggan)<th style="width:10%">Pelanggan</th>@endif
                <th style="width:8%">Kode</th>
                <th style="width:{{ $lebarNama }}%">Nama Item</th>
                <th style="width:3%">Sat</th>
                <th class="text-end" style="width:4%">Qty</th>
                <th class="text-end" style="width:6%">Harga</th>
                <th class="text-end" style="width:5%">Diskon</th>
                <th class="text-end" style="width:7%">Subtotal</th>
                <th style="width:5%">Cab Trx</th>
                <th style="width:5%">Cab Plg</th>
                <th style="width:7%">No SJ</th>
                <th style="width:7%">No PKB</th>
                @if ($pakaiPt)<th style="width:4%">PT</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->nomor }}</td>
                    <td>{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '—' }}</td>
                    @if ($pakaiPelanggan)<td>{{ $x->pelanggan ?: '—' }}</td>@endif
                    <td>{{ $x->kode }}</td>
                    <td>{{ $x->nama }}</td>
                    <td>{{ $x->satuan }}</td>
                    <td class="text-end">{{ number_format((float) $x->qty, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->harga, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->diskon, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->subtotal, 0, ',', '.') }}</td>
                    <td>{{ $x->cabangTransaksi ?: '—' }}</td>
                    <td>{{ $x->cabangPelanggan ?: '—' }}</td>
                    <td>{{ $x->noSj ?: '—' }}</td>
                    <td>{{ $x->noPkb ?: '—' }}</td>
                    @if ($pakaiPt)<td>{{ $x->pt ?: '—' }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="{{ $kolom }}" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            {{-- `$total` SELALU total seluruh laporan, bukan potongan ini. --}}
            @if ($bagianTerakhir)
                <tr>
                    <th colspan="{{ $pakaiPelanggan ? 6 : 5 }}" class="text-end">TOTAL ({{ number_format($total['baris'], 0, ',', '.') }} baris)</th>
                    <th class="text-end">{{ number_format($total['qty'], 2, ',', '.') }}</th>
                    <th colspan="2"></th>
                    <th class="text-end">{{ number_format($total['subtotal'], 0, ',', '.') }}</th>
                    <th colspan="{{ 4 + ($pakaiPt ? 1 : 0) }}"></th>
                </tr>
            @endif
        </tbody>
    </table>
</x-reports.layout>
