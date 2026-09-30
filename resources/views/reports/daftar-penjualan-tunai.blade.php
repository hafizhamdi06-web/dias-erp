{{-- Laporan POS-IP "Daftar Penjualan Tunai" - port dari CI3
     (views/modul/laporan/xlap-daftar-penjualan-tunai.php).
     Rumus tiap kolom & satu perbedaan yang DISENGAJA dari CI3: lihat docblock
     ReportController::dataDaftarPenjualanTunai(). --}}
@php
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp

<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <table style="font-size:7pt">
        <thead>
            <tr>
                <th style="width:6%">Tanggal</th>
                <th style="width:9%">Nomor</th>
                <th>Kontak</th>
                <th class="text-end">Kas Nett</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Kredit</th>
                <th class="text-end">Transfer</th>
                <th class="text-end">Merchant</th>
                <th class="text-end">Total Real</th>
                <th class="text-end">Voucher</th>
                <th class="text-end">Piutang</th>
                <th class="text-end">DP Surgery</th>
                <th class="text-end">Surgery</th>
                <th class="text-end">DP</th>
                <th class="text-end">- Tarik DP</th>
                <th class="text-end">Total Semua</th>
                <th class="text-end">Cash Back</th>
                <th class="text-end">Piutang Bayar</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d-m-Y') }}</td>
                    <td>{{ $r->nomor }}</td>
                    <td>{{ $r->kontak ?: '—' }}</td>
                    <td class="text-end">{{ $n($r->kas) }}</td>
                    <td class="text-end">{{ $n($r->debit) }}</td>
                    <td class="text-end">{{ $n($r->kredit) }}</td>
                    <td class="text-end">{{ $n($r->transfer) }}</td>
                    <td class="text-end">{{ $n($r->merchant) }}</td>
                    <td class="text-end">{{ $n($r->totalReal) }}</td>
                    <td class="text-end">{{ $n($r->voucher) }}</td>
                    <td class="text-end">{{ $n($r->piutang) }}</td>
                    <td class="text-end">{{ $n($r->dpSurgery) }}</td>
                    <td class="text-end">{{ $n($r->surgery) }}</td>
                    <td class="text-end">{{ $n($r->dp) }}</td>
                    {{-- Tarik DP disimpan positif, DITAMPILKAN negatif - persis CI3. --}}
                    <td class="text-end">{{ $n($r->tarikDp * -1) }}</td>
                    <td class="text-end">{{ $n($r->totalSemua) }}</td>
                    <td class="text-end">{{ $n($r->cashback) }}</td>
                    <td class="text-end">{{ $n($r->piutangBayar) }}</td>
                </tr>
            @empty
                <tr><td colspan="18" class="text-center">Tidak ada transaksi pada rentang ini.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top:.5pt solid #333">
                <td colspan="3"><strong>Total Termasuk Piutang Surgery</strong></td>
                <td class="text-end"><strong>{{ $n($total['kas']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['debit']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['kredit']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['transfer']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['merchant']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['totalReal']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['voucher']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['piutang']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['dpSurgery']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['surgery']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['dp']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['tarikDp'] * -1) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['totalSemua']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['cashback']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($total['piutangBayar']) }}</strong></td>
            </tr>
            {{-- Baris kedua: hanya transaksi dgn Piutang Bayar = 0. Dua kolom terakhir
                 sengaja dikosongkan, sama seperti CI3 & PDF contoh. --}}
            <tr>
                <td colspan="3"><strong>Total TANPA Piutang Surgery</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['kas']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['debit']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['kredit']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['transfer']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['merchant']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['totalReal']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['voucher']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['piutang']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['dpSurgery']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['surgery']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['dp']) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['tarikDp'] * -1) }}</strong></td>
                <td class="text-end"><strong>{{ $n($totalTanpa['totalSemua']) }}</strong></td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</x-reports.layout>
