@props([
    'model',                // properti Livewire yang menyimpan ANGKA-nya
    'value' => 0,           // nilai awal (dari properti Livewire)
    'desimal' => 0,         // jumlah angka di belakang koma saat ditampilkan
    'disabled' => false,
    'placeholder' => '0',
])

{{-- Isian rupiah berformat ribuan. `type="number"` TIDAK BISA menampilkan pemisah ribuan,
     jadi ini `type="text"` + `inputmode="decimal"` (papan ketik angka di ponsel).
     Nilai yang dikirim ke Livewire tetap ANGKA - lihat `uangInput()` di dias-helpers.js. --}}
<div
    x-data="uangInput({ initial: @js((float) $value), desimal: @js((int) $desimal) })"
    x-modelable="value"
    {{-- SENGAJA tanpa `.live`, dan prop `live` SENGAJA tidak disediakan: `.live` memicu
         round-trip Livewire TIAP KETIKAN - mengetik jadi tersendat & kursor bisa meloncat
         (dilaporkan user 2026-09-30). Komponen ini mengirim sendiri tepat sekali lewat
         `$commit()` saat isian ditinggalkan, jadi hitungan di server tetap ikut terbarui. --}}
    wire:model="{{ $model }}"
>
    {{-- TIDAK ada `@input`: selama mengetik tidak ada yg dikirim maupun diformat ulang,
         supaya kursor tidak meloncat & tidak ada round-trip per ketikan. Semua terjadi
         di `keluar()` saat isian ditinggalkan. --}}
    <input type="text" inputmode="decimal" autocomplete="off"
           {{ $attributes->merge(['class' => 'form-control form-control-sm text-end']) }}
           placeholder="{{ $placeholder }}"
           x-model="tampil"
           @focus="fokus($event)"
           @blur="keluar()"
           @keydown.enter.prevent="$event.target.blur()"
           @disabled($disabled)>
</div>
