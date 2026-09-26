@props(['title' => null, 'subtitle' => null, 'company' => []])
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #222; }
        .header { text-align: center; margin-bottom: 6px; border-bottom: 2px solid #333; padding-bottom: 6px; }
        .header .company { font-size: 14px; font-weight: bold; }
        .header .addr { font-size: 9px; color: #555; }
        .title { text-align: center; font-size: 12px; font-weight: bold; margin: 8px 0 2px; }
        .subtitle { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 5px; font-size: 9px; }
        th { background: #eee; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        footer { text-align: center; font-size: 8px; color: #777; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company['nama'] ?? '' }}</div>
        @if (($company['alamat'] ?? '') !== '')
            <div class="addr">{{ $company['alamat'] }}</div>
        @endif
    </div>

    @if ($title)
        <div class="title">{{ $title }}</div>
    @endif
    @if ($subtitle)
        <div class="subtitle">{{ $subtitle }}</div>
    @endif

    {{ $slot }}

    <htmlpagefooter name="footer-page">
        <footer>Dicetak {{ now()->format('d/m/Y H:i') }} — Halaman {PAGENO}/{nbpg}</footer>
    </htmlpagefooter>
    <sethtmlpagefooter name="footer-page" value="on" />
</body>
</html>
