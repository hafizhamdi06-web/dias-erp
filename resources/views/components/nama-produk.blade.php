{{-- Satu sel untuk Kode + Nama item.

     Alasannya data, bukan selera: **74% item aktif (2.059 dari 2.764) punya `IKODE`
     persis sama dengan `INAMA`**. Dua kolom terpisah berarti teks yang sama ditampilkan
     dua kali di tiga dari empat baris, dan keduanya saling menyempitkan - di form PKB
     kolom Nama sampai pecah jadi tiga baris (permintaan user 2026-10-10).

     Kalau berbeda (26% sisanya) keduanya tetap ditampilkan, dipisah em-dash, dengan kode
     ditebalkan supaya tetap bisa dipindai mata seperti saat masih berkolom sendiri.

     VB6 `FormatString` memang memisah `<Kode|<Nama`; penggabungan ini sadar menyimpang -
     lihat catatan di grid form yang memakainya. --}}
@props(['kode' => '', 'nama' => ''])
@php
    $k = trim((string) $kode);
    $n = trim((string) $nama);
    $sama = $k !== '' && mb_strtolower($k) === mb_strtolower($n);
@endphp
@if ($sama || $k === '')
    {{ $n !== '' ? $n : $k }}
@elseif ($n === '')
    {{ $k }}
@else
    <span class="fw-semibold">{{ $k }}</span> — {{ $n }}
@endif
