@props([
    'model',              // nama properti Livewire yang menyimpan id terpilih
    'endpoint',           // URL lookup JSON (mengembalikan [{id,text}])
    'value' => null,      // nilai id saat ini (dari properti Livewire)
    'selectedText' => null, // label awal untuk nilai yang sudah ada
    'placeholder' => 'Pilih…',
    'live' => false,      // true = wire:model.live (round-trip server segera saat pilih/hapus - dipakai kalau ada kalkulasi lain yg butuh nilai ini langsung)
])

<div
    x-data="searchSelect({
        endpoint: @js($endpoint),
        initialId: @js($value),
        initialText: @js($selectedText),
    })"
    x-modelable="value"
    @if ($live) wire:model.live="{{ $model }}" @else wire:model="{{ $model }}" @endif
    class="position-relative"
    @click.outside="open = false"
>
    <div class="form-control form-control-sm d-flex justify-content-between align-items-center"
         role="button" x-ref="pemicu" @click="toggle()">
        <span x-text="label || @js($placeholder)" :class="{ 'text-muted': !label }"></span>
        <span class="d-flex gap-1">
            <i class="fas fa-xmark small text-muted" x-show="value" @click.stop="clear()" role="button"></i>
            <i class="fas fa-angle-down small text-muted"></i>
        </span>
    </div>

    {{-- `position: fixed` (lewat :style dari `_ukur()`), BUKAN `position-absolute` - panel
         absolute TERPOTONG oleh induk ber-overflow, mis. `.table-responsive` di form
         Kas/Bank (dilaporkan user 2026-09-30). Lihat catatan di `searchSelect()`. --}}
    <div x-show="open" x-transition.opacity :style="gaya"
         class="bg-body border rounded shadow">
        <div class="p-1">
            <input type="text" class="form-control form-control-sm" placeholder="ketik untuk cari…"
                   x-model="q" @input.debounce.300ms="search()" x-ref="q">
        </div>
        <div style="max-height: var(--ss-maks, 220px); overflow-y: auto">
            <template x-if="loading">
                <div class="px-2 py-1 small text-muted">memuat…</div>
            </template>
            <template x-for="opt in options" :key="opt.id">
                <button type="button" class="dropdown-item small text-wrap"
                        @click="pick(opt)" x-text="opt.text"></button>
            </template>
            <template x-if="!loading && options.length === 0">
                <div class="px-2 py-1 small text-muted">tidak ada hasil</div>
            </template>
        </div>
    </div>
</div>
{{-- fungsi searchSelect() didefinisikan global di layouts/app.blade.php --}}
