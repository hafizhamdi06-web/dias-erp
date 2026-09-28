{{-- Laporan IP Tindakan/Produk Per Bulan - port dari CI3
     (views/modul/laporan/laporan-ip-tindakan-produk-perbulan.php).
     Lihat docblock ReportController::ipTindakanProduk() untuk 3 aturan hitungnya. --}}
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <p style="font-size:8pt;color:#555;margin:0 0 6pt">
        *Kolom Pasien per baris hanya menghitung transaksi yang ada harganya. Total pasien hanya
        di baris <b>Total &lt;Bulan&gt;</b> — satu pasien per hari dihitung 1x walau bertransaksi
        berkali-kali, jadi angkanya wajar lebih kecil dari jumlah kolom di atasnya.
    </p>

    <table>
        <thead>
            <tr>
                <th>Tindakan / Produk</th>
                <th class="text-end" style="width:16%">Qty Transaksi</th>
                <th class="text-end" style="width:20%">Nilai</th>
                <th class="text-end" style="width:12%">Pasien</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($grup as $cabang)
                <tr><td colspan="4" style="background:#eee"><strong>{{ $cabang['nama'] }}</strong></td></tr>

                @foreach ($cabang['bulan'] as $bulan)
                    <tr><td colspan="4" style="padding-left:12pt"><em>{{ $bulan['label'] }}</em></td></tr>

                    @foreach ($bulan['baris'] as $r)
                        <tr>
                            <td style="padding-left:24pt">{{ $r->barang }}</td>
                            <td class="text-end">{{ number_format((float) $r->qty, 2, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $r->nilai, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((int) $r->pasien, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach

                    <tr style="border-top:.5pt dashed #999">
                        <td style="padding-left:12pt"><strong>Total {{ $bulan['label'] }}</strong></td>
                        <td class="text-end"><strong>{{ number_format((float) $bulan['qty'], 2, ',', '.') }}</strong></td>
                        <td class="text-end"><strong>{{ number_format((float) $bulan['nilai'], 0, ',', '.') }}</strong></td>
                        <td class="text-end"><strong>{{ number_format((int) $bulan['pasien'], 0, ',', '.') }}</strong></td>
                    </tr>
                @endforeach

                {{-- Kolom Pasien SENGAJA kosong di level cabang & grand total - lihat
                     docblock controller (menjumlahkannya akan menghitung pasien 2x). --}}
                <tr style="border-top:.5pt dashed #333">
                    <td><strong>Total Cabang {{ $cabang['nama'] }}</strong></td>
                    <td class="text-end"><strong>{{ number_format((float) $cabang['qty'], 2, ',', '.') }}</strong></td>
                    <td class="text-end"><strong>{{ number_format((float) $cabang['nilai'], 0, ',', '.') }}</strong></td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center">Tidak ada transaksi pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if ($grup !== [])
            <tfoot>
                <tr style="border-top:1pt solid #000">
                    <td><strong>Grand Total</strong></td>
                    <td class="text-end"><strong>{{ number_format((float) $totalQty, 2, ',', '.') }}</strong></td>
                    <td class="text-end"><strong>{{ number_format((float) $totalNilai, 0, ',', '.') }}</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</x-reports.layout>
