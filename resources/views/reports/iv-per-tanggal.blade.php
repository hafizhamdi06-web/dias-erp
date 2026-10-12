{{-- IP IV Per Tanggal (VB6 menu 796) - bentuknya BEDA dari lima saudaranya: banyak kolom
     header invoice. Lihat ReportController::dataIvPerTanggal().
     Ditulis BERTAHAP spt `iv-daftar.blade.php` - alasan & kontraknya di
     `PdfReport::tulisBertahap()`. --}}
@php
    $bagianPertama ??= true;
    $bagianTerakhir ??= true;
    $lebarNama = 100 - 76;   // 76% terpakai kolom tetap di bawah.
@endphp
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company"
                  :pertama="$bagianPertama" :terakhir="$bagianTerakhir">
    @unless ($bagianPertama)<pagebreak />@endunless
    <table>
        <thead>
            <tr>
                <th style="width:9%">No Invoice</th>
                <th style="width:5%">Tanggal</th>
                <th style="width:5%">J. Tempo</th>
                <th style="width:12%">Pelanggan</th>
                <th style="width:8%">Kode</th>
                <th style="width:{{ $lebarNama }}%">Nama Item</th>
                <th class="text-end" style="width:4%">Qty</th>
                <th class="text-end" style="width:6%">Harga</th>
                <th class="text-end" style="width:5%">Disc</th>
                <th class="text-end" style="width:7%">Subtotal</th>
                <th style="width:5%">Termin</th>
                <th style="width:5%">Gd Asal</th>
                <th style="width:5%">Gd Tujuan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->nomor }}</td>
                    <td>{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '—' }}</td>
                    <td>{{ $x->jatuhTempo ? \Carbon\Carbon::parse($x->jatuhTempo)->format('d/m/Y') : '—' }}</td>
                    <td>{{ $x->pelanggan ?: '—' }}</td>
                    <td>{{ $x->kode }}</td>
                    <td>{{ $x->nama }}</td>
                    <td class="text-end">{{ number_format((float) $x->qty, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->harga, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->diskon, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->subtotal, 0, ',', '.') }}</td>
                    <td>{{ $x->termin ?: '—' }}</td>
                    <td>{{ $x->gudangSumber ?: '—' }}</td>
                    <td>{{ $x->gudangTujuan ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="13" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            @if ($bagianTerakhir)
                <tr>
                    <th colspan="6" class="text-end">TOTAL ({{ number_format($total['baris'], 0, ',', '.') }} baris)</th>
                    <th class="text-end">{{ number_format($total['qty'], 2, ',', '.') }}</th>
                    <th colspan="2"></th>
                    <th class="text-end">{{ number_format($total['subtotal'], 0, ',', '.') }}</th>
                    <th colspan="3"></th>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($bagianTerakhir)
        <p style="font-size:8px; color:#777; margin-top:6px">
            Ongkir, diskon invoice, dan pajak ada di tingkat <em>dokumen</em> — tidak dijumlahkan di
            tabel ini (yang per-baris adalah item). Keduanya tersedia di versi Excel.
        </p>
    @endif
</x-reports.layout>
