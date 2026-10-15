{{-- Pilihan CABANG GANDA (trait `MarkomFilter`). Dropdown Alpine, bukan Bootstrap: state
     buka/tutupnya milik Alpine sehingga TIDAK ikut tertutup tiap kali Livewire me-render
     ulang sesudah satu kotak dicentang. Butuh: $branches, $labelCabang. --}}
<div class="position-relative" x-data="{ buka: false }" @click.outside="buka = false">
    <button type="button" class="form-select form-select-sm text-start text-truncate" style="width: 14rem"
            @click="buka = !buka">
        <i class="fas fa-store me-1 text-muted"></i>{{ $labelCabang }}
    </button>
    <div x-show="buka" x-cloak class="card shadow-sm position-absolute end-0 mt-1 p-2"
         style="z-index: 1050; width: 18rem; max-height: 22rem; overflow-y: auto">
        <button type="button" class="btn btn-sm btn-link p-0 mb-1 text-decoration-none" wire:click="pilihSemuaCabang">
            <i class="fas fa-check-double me-1"></i>Semua cabang
        </button>
        @foreach ($branches as $b)
            <label class="form-check small mb-1" wire:key="cab-{{ $b->GID }}">
                <input type="checkbox" class="form-check-input" value="{{ $b->GID }}" wire:model.live="cabangPilih">
                <span class="form-check-label">{{ $b->GNAMA }}</span>
            </label>
        @endforeach
    </div>
</div>
