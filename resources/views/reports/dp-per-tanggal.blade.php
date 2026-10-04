{{-- Jumlah DP Pertanggal (VB6 menu 627) - lihat ReportController::dataDpPerTanggal(). --}}
<x-reports.layout :title="$title"
                  :subtitle="'Posisi s/d ' . $tanggal . ($subtitle ? ' — ' . $subtitle : '') . ($rinci ? ' — rinci per mutasi' : '')"
                  :company="$company">
    @if ($rinci)
        <table>
            <thead>
                <tr>
                    <th style="width:12%">Kode Pasien</th>
                    <th>Nama Pasien</th>
                    <th style="width:11%">Cabang</th>
                    <th style="width:8%">Tanggal</th>
                    <th style="width:8%">Tgl DP</th>
                    <th style="width:13%">No Transaksi</th>
                    <th style="width:13%">No DP</th>
                    <th class="text-end" style="width:12%">Nilai</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $x)
                    <tr>
                        <td>{{ $x->kodePasien ?: '—' }}</td>
                        <td>{{ $x->namaPasien ?: '—' }}</td>
                        <td>{{ $x->cabang ?: '—' }}</td>
                        <td>{{ $x->tanggal ? \Carbon\Carbon::parse($x->tanggal)->format('d/m/Y') : '—' }}</td>
                        <td>{{ $x->tanggalDp ? \Carbon\Carbon::parse($x->tanggalDp)->format('d/m/Y') : '—' }}</td>
                        <td>{{ $x->nomor }}</td>
                        <td>{{ $x->noDp }}</td>
                        <td class="text-end">{{ number_format((float) $x->nilai, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center">Tidak ada data.</td></tr>
                @endforelse
                <tr>
                    <th colspan="7" class="text-end">SALDO ({{ number_format($total['baris'], 0, ',', '.') }} mutasi)</th>
                    <th class="text-end">{{ number_format($total['nilai'], 0, ',', '.') }}</th>
                </tr>
            </tbody>
        </table>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width:14%">Kode Pasien</th>
                    <th>Nama Pasien</th>
                    <th style="width:14%">Cabang Asal DP</th>
                    <th class="text-end" style="width:14%">DP Masuk</th>
                    <th class="text-end" style="width:14%">DP Terpakai</th>
                    <th class="text-end" style="width:14%">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $x)
                    <tr>
                        <td>{{ $x->kodePasien ?: '—' }}</td>
                        <td>{{ $x->namaPasien ?: '—' }}</td>
                        <td>{{ $x->cabang ?: '—' }}</td>
                        <td class="text-end">{{ number_format((float) $x->masuk, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float) $x->terpakai, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float) $x->saldo, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center">Tidak ada data.</td></tr>
                @endforelse
                <tr>
                    <th colspan="3" class="text-end">TOTAL ({{ number_format($total['baris'], 0, ',', '.') }} pasien)</th>
                    <th class="text-end">{{ number_format($total['masuk'], 0, ',', '.') }}</th>
                    <th class="text-end">{{ number_format($total['terpakai'], 0, ',', '.') }}</th>
                    <th class="text-end">{{ number_format($total['saldo'], 0, ',', '.') }}</th>
                </tr>
            </tbody>
        </table>
    @endif
</x-reports.layout>
