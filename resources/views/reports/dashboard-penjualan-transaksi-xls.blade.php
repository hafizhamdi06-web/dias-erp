{{-- Ekspor Excel tingkat PER TRANSAKSI Dashboard Penjualan.

     Angkanya datang dari `DashboardPenjualan` yg SAMA dgn yg dipakai layar
     (`ReportController::dashboardPenjualanExcel()`) - sengaja tidak dihitung ulang di
     sini supaya tidak bisa menyimpang diam-diam dari dashboardnya. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .num { mso-number-format: "#,##0"; text-align: right; }
        .num2 { mso-number-format: "#,##0.00"; text-align: right; }
        {{-- `mso-number-format:"\@"` = paksa Excel memperlakukannya sbg TEKS. Tanpa ini
             nomor spt `PG-IP26080031` masih aman, tapi kode yg seluruhnya angka akan
             kehilangan nol di depannya. --}}
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        .peringatan { color: #a00; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>Periode : {{ $periode }}</p>
    @if ($subtitle)
        <p>Saringan : {{ $subtitle }}</p>
    @endif

    @if ($terpotong)
        {{-- Berkas yg terpotong diam-diam lebih berbahaya daripada yg mengaku terpotong:
             user akan menjumlahkannya dan mengira itu angka sebulan penuh. --}}
        <p class="peringatan">
            PERHATIAN: data {{ number_format($terpotong, 0, ',', '.') }} baris, hanya
            {{ number_format($batas, 0, ',', '.') }} baris pertama yg dimuat.
            Persempit saringan (per cabang / per COA) untuk mendapatkan seluruhnya.
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>No Transaksi</th>
                <th>Tanggal</th>
                <th>Nama Pasien</th>
                <th>Item</th>
                <th>Qty</th>
                <th>Penjualan</th>
                <th>Penjualan Produk</th>
                <th>Penjualan Alkes</th>
                <th>PPN 11%</th>
                <th>Pendapatan Klinik</th>
                <th>COGS Produk</th>
                <th>COGS Alkes</th>
                <th>Laba Kotor</th>
                <th>Margin %</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="txt">{{ $r->nama }}</td>
                    <td class="txt">
                        @php
                            // Tanggal legacy bisa '0000-00-00' - satu baris cacat tidak
                            // boleh menggagalkan seluruh berkas.
                            try {
                                $tgl = $r->tanggal ? \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') : '';
                            } catch (\Throwable $e) {
                                $tgl = '';
                            }
                        @endphp
                        {{ $tgl }}
                    </td>
                    <td class="txt">{{ $r->pasien }}</td>
                    <td class="txt">{{ $r->item }}</td>
                    <td class="num2">{{ $r->qty }}</td>
                    <td class="num">{{ $r->penjualan }}</td>
                    <td class="num">{{ $r->penjProduk }}</td>
                    <td class="num">{{ $r->penjAlkes }}</td>
                    <td class="num">{{ $r->ppn }}</td>
                    <td class="num">{{ $r->pendapatanKlinik }}</td>
                    <td class="num">{{ $r->cogsProduk }}</td>
                    <td class="num">{{ $r->cogsAlkes }}</td>
                    <td class="num">{{ $r->labaKotor }}</td>
                    <td class="num2">{{ round($r->marginPersen, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="14">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            {{-- TOTAL diambil dari `total()` atas SELURUH saringan, bukan dijumlah dari
                 baris di atas - kalau datanya terpotong, jumlah baris TIDAK sama dgn
                 totalnya, dan yg benar adalah totalnya. --}}
            <tr class="tot">
                <td colspan="4">TOTAL (seluruh saringan)</td>
                <td class="num2">{{ $total->qty }}</td>
                <td class="num">{{ $total->penjualan }}</td>
                <td class="num">{{ $total->penjProduk }}</td>
                <td class="num">{{ $total->penjAlkes }}</td>
                <td class="num">{{ $total->ppn }}</td>
                <td class="num">{{ $total->pendapatanKlinik }}</td>
                <td class="num">{{ $total->cogsProduk }}</td>
                <td class="num">{{ $total->cogsAlkes }}</td>
                <td class="num">{{ $total->labaKotor }}</td>
                <td class="num2">{{ round($total->marginPersen, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
