{{-- Pengganti cetakan saat barisnya melebihi `ReportController::BATAS_BARIS_PDF`.
     Lebih baik satu halaman yang menjelaskan daripada galat mpdf yang tidak bisa dimengerti
     user (atau PDF ratusan halaman yang tidak terpakai). --}}
<x-reports.layout :title="$title" :subtitle="$subtitle" :company="$company">
    <div style="border:1px solid #999; padding:14px; margin-top:20px">
        <div style="font-size:13px; font-weight:bold; margin-bottom:8px">
            Data terlalu banyak untuk dicetak
        </div>
        <div style="font-size:11px; line-height:1.6">
            Filter yang Anda pilih menghasilkan
            <strong>{{ number_format($jumlah, 0, ',', '.') }} baris</strong>, sedangkan batas
            cetakan PDF <strong>{{ number_format($batas, 0, ',', '.') }} baris</strong>.
            <br><br>
            Dua cara melanjutkan:
            <br>
            1. <strong>Persempit rentang tanggalnya</strong> (misalnya per bulan), lalu cetak lagi.
            <br>
            2. <strong>Pakai tombol Excel</strong> — versi Excel tidak dibatasi dan memang lebih
            cocok untuk data sebanyak ini.
            <br><br>
            Batas ini ada supaya Anda tidak menunggu lama hanya untuk mendapat berkas ratusan
            halaman yang sulit dipakai.
        </div>
    </div>
</x-reports.layout>
