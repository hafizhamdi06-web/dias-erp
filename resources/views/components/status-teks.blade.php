{{-- Status di layar DAFTAR: teks berwarna, bukan badge.

     Permintaan user 2026-10-10 ("font disamakan untuk semua kolom"): badge punya ukuran
     font sendiri (0.75em) + latar blok, jadi merusak keseragaman kolom. Di grid VB6 pun
     Status cuma teks biasa - yang membedakan kolom di sana adalah WARNA, bukan ukuran
     huruf.

     Pemetaannya EKSPLISIT, bukan sekadar membuang "-bg-": `text-warning`/`text-info`
     kuning-cyan pucat di atas putih, dan `text-light` malah tidak terbaca sama sekali.
     Varian `-emphasis` Bootstrap 5.3 kontras di tema terang MAUPUN gelap.

     Dipakai bersama semua layar daftar supaya pemetaan warnanya cuma ada SATU. --}}
@props(['bg' => 'text-bg-light', 'teks' => '-'])
@php
    $warna = match ($bg) {
        'text-bg-danger'    => 'text-danger-emphasis',
        'text-bg-success'   => 'text-success-emphasis',
        'text-bg-warning'   => 'text-warning-emphasis',
        'text-bg-info'      => 'text-info-emphasis',
        'text-bg-primary'   => 'text-primary-emphasis',
        'text-bg-secondary' => 'text-secondary',
        default             => 'text-body-secondary',
    };
@endphp
{{-- `$attributes->merge` supaya pemanggil bisa menambah kelas jarak (mis. `class="ms-1"`
     di modal Histori Penerimaan PO) tanpa menimpa kelas warnanya. Tanpa ini kelas
     tambahan hilang diam-diam. --}}
<span {{ $attributes->merge(['class' => 'status ' . $warna]) }}>{{ $teks }}</span>
