{{-- IP Kedatangan Pasien (VB6 menu 531) - lihat ReportController::dataIpKedatanganPasien().
     Kode jenis kelamin & baru/lama SENGAJA tidak ditampilkan di PDF: di master nilainya angka
     (0/1/2) tanpa arti yg terdokumentasi, jadi menampilkannya hanya membingungkan. Keduanya
     tetap ikut di versi Excel sbg kode mentah utk keperluan analisa. --}}
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <table>
        <thead>
            <tr>
                <th style="width:9%">ID Pasien</th>
                <th>Nama Pasien</th>
                <th style="width:10%">No. Telp</th>
                <th style="width:7%">Cabang</th>
                <th style="width:11%">Kecamatan</th>
                <th style="width:10%">Kota</th>
                <th class="text-end" style="width:7%">Kedatangan</th>
                <th class="text-end" style="width:7%">Berbayar</th>
                <th class="text-end" style="width:7%">Dgn Dokter</th>
                <th class="text-end" style="width:11%">Nilai</th>
                <th style="width:8%">Terakhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $x)
                <tr>
                    <td>{{ $x->idPasien ?: '—' }}</td>
                    <td>{{ $x->nama }}</td>
                    <td>{{ $x->telp ?: '—' }}</td>
                    <td>{{ $x->cabang ?: '—' }}</td>
                    <td>{{ $x->kecamatan ?: '—' }}</td>
                    <td>{{ $x->kota ?: '—' }}</td>
                    <td class="text-end">{{ number_format((int) $x->kedatangan, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((int) $x->berbayar, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((int) $x->denganDokter, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $x->nilai, 0, ',', '.') }}</td>
                    <td>{{ $x->terakhir ? \Carbon\Carbon::parse($x->terakhir)->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center">Tidak ada data.</td></tr>
            @endforelse
            <tr>
                <th colspan="6" class="text-end">TOTAL ({{ number_format($total['pasien'], 0, ',', '.') }} pasien)</th>
                <th class="text-end">{{ number_format($total['kedatangan'], 0, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['berbayar'], 0, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['denganDokter'], 0, ',', '.') }}</th>
                <th class="text-end">{{ number_format($total['nilai'], 0, ',', '.') }}</th>
                <th></th>
            </tr>
        </tbody>
    </table>
</x-reports.layout>
