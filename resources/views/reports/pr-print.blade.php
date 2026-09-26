<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #222; }
        .company { font-size: 13px; font-weight: bold; }
        .title { text-align: center; font-size: 12px; font-weight: bold; margin: 6px 0 10px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.info td { border: none; padding: 1px 4px; font-size: 10px; vertical-align: top; }
        table.info .label { width: 165px; font-weight: bold; white-space: nowrap; text-align: left; }
        table.info .colon { width: 8px; font-weight: bold; }
        table.info .sep { width: 10px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { border: 1px solid #333; padding: 3px 5px; font-size: 9px; }
        table.items th { background: #eee; text-align: left; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        table.items tfoot td { font-weight: bold; }
        table.signature { width: 100%; border-collapse: collapse; margin-top: 40px; }
        table.signature td { border: none; text-align: center; font-size: 10px; padding-top: 50px; }
        footer { font-size: 8px; color: #555; }
        footer .pageno { float: left; }
        footer .printed { float: right; }
    </style>
</head>
<body>
    <div class="company">{{ $company['nama'] ?? '' }}</div>
    <div class="title">{{ $title }}</div>

    <table class="info">
        <tr>
            <td class="label">Nama Karyawan</td>
            <td class="colon">:</td>
            <td>{{ $karyawan ?: '—' }}</td>
            <td class="sep"></td>
            <td class="label">No PO</td>
            <td class="colon">:</td>
            <td>{{ $nomor }}</td>
        </tr>
        <tr>
            <td class="label">Dari Cabang</td>
            <td class="colon">:</td>
            <td>{{ $dariCabang ?: '—' }}</td>
            <td class="sep"></td>
            <td class="label">Tanggal</td>
            <td class="colon">:</td>
            <td>{{ \Carbon\Carbon::parse($tanggal)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Tipe</td>
            <td class="colon">:</td>
            <td>{{ $tipe ?: '—' }}</td>
            <td class="sep"></td>
            <td class="label">No Ref</td>
            <td class="colon">:</td>
            <td>{{ $noRef ?: '' }}</td>
        </tr>
        <tr>
            <td class="label">Gudang / Supplier Tujuan</td>
            <td class="colon">:</td>
            <td>{{ $gudangTujuan ?: '—' }}</td>
            <td class="sep"></td>
            <td class="label"></td>
            <td class="colon"></td>
            <td></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">No</th>
                <th>Nama Item</th>
                <th class="text-end" style="width:60px">Qty</th>
                <th class="text-end" style="width:60px">Real Stok</th>
                <th style="width:60px">Satuan</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td>{{ $l->IKODE }}</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format($l->PBDQTY, 2), '0'), '.') ?: '0' }}</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format($l->PBDSTOKREAL, 2), '0'), '.') ?: '0' }}</td>
                    <td>{{ $l->satuan_kode }}</td>
                    <td>{{ $l->PBDCATATAN }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="text-end">Total Qty</td>
                <td class="text-end">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') ?: '0' }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>

    <table class="signature">
        <tr>
            <td>Dibuat Oleh</td>
            <td>Diketahui Oleh</td>
            <td>Disetujui Oleh</td>
        </tr>
        <tr>
            <td>( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )</td>
            <td>( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )</td>
            <td>( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )</td>
        </tr>
    </table>

    <htmlpagefooter name="footer-pr">
        <footer>
            <span class="pageno">Page {PAGENO} of {nbpg}</span>
            <span class="printed">Tanggal Print {{ now()->format('d/m/Y H:i:s') }}</span>
        </footer>
    </htmlpagefooter>
    <sethtmlpagefooter name="footer-pr" value="on" />
</body>
</html>
