{{-- Excel "IP Kedatangan Pasien" - data SAMA PERSIS versi PDF, plus kolom kode mentah
     (JK, Baru/Lama, Tgl Lahir, Kode) yg sengaja tidak dimuat di PDF. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 6px; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
        th { background: #eee; font-weight: bold; }
        .int { mso-number-format: "#,##0"; text-align: right; }
        .rp { mso-number-format: "#,##0"; text-align: right; }
        /* ID pasien & no telp WAJIB teks - angka panjang akan dibulatkan / 0 di depan hilang. */
        .txt { mso-number-format: "\@"; }
        .tot { background: #f4f4f4; font-weight: bold; }
        h2, h4, p { margin: 2px 0; font-family: Calibri, Arial, sans-serif; }
    </style>
</head>
<body>
    <h2>{{ $company['nama'] ?? '' }}</h2>
    <h4>{{ $title }}</h4>
    <p>{{ $subtitle }}</p>

    <table>
        <thead>
            <tr>
                <th>ID Pasien</th><th>Kode</th><th>Nama Pasien</th><th>Tgl Lahir</th><th>No. Telp</th>
                <th>JK (kode)</th><th>Baru/Lama (kode)</th><th>Cabang</th><th>Kecamatan</th><th>Kota</th>
                <th>Kedatangan</th><th>Berbayar</th><th>Dgn Dokter</th><th>Nilai</th><th>Terakhir</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $x)
                <tr>
                    <td class="txt">{{ $x->idPasien }}</td>
                    <td class="txt">{{ $x->kode }}</td>
                    <td class="txt">{{ $x->nama }}</td>
                    <td class="txt">{{ $x->lahir ? \Carbon\Carbon::parse($x->lahir)->format('d/m/Y') : '' }}</td>
                    <td class="txt">{{ $x->telp }}</td>
                    <td class="txt">{{ $x->jk }}</td>
                    <td class="txt">{{ $x->baruLama }}</td>
                    <td class="txt">{{ $x->cabang }}</td>
                    <td class="txt">{{ $x->kecamatan }}</td>
                    <td class="txt">{{ $x->kota }}</td>
                    <td class="int">{{ (int) $x->kedatangan }}</td>
                    <td class="int">{{ (int) $x->berbayar }}</td>
                    <td class="int">{{ (int) $x->denganDokter }}</td>
                    <td class="rp">{{ (float) $x->nilai }}</td>
                    <td class="txt">{{ $x->terakhir ? \Carbon\Carbon::parse($x->terakhir)->format('d/m/Y') : '' }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="10">TOTAL ({{ $total['pasien'] }} pasien)</td>
                <td class="int">{{ $total['kedatangan'] }}</td>
                <td class="int">{{ $total['berbayar'] }}</td>
                <td class="int">{{ $total['denganDokter'] }}</td>
                <td class="rp">{{ $total['nilai'] }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
