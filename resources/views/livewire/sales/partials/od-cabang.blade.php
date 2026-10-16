{{-- Filter cabang dialog Pilih Operator / Pilih Dokter (permintaan user 2026-10-16).
     Default cabang aktif kasir (diset ulang tiap dialog dibuka), bisa diganti ke cabang lain
     atau "Semua cabang" - mis. dokter/perawat tamu. Butuh: $peran ('operator'|'dokter'),
     $odCabangList, $branch. --}}
<div class="d-flex align-items-center gap-2 mb-2 small">
    <i class="fas fa-filter text-muted"></i>
    <span class="text-muted text-nowrap">{{ ucfirst($peran) }} cabang</span>
    <select class="form-select form-select-sm" wire:model.live="odCabang" wire:key="od-cabang-{{ $peran }}">
        <option value="">Semua cabang</option>
        @foreach ($odCabangList as $c)
            <option value="{{ $c->GID }}">{{ $c->GNAMA }}{{ $branch && (int) $c->GID === (int) $branch->GID ? ' (cabang aktif)' : '' }}</option>
        @endforeach
    </select>
</div>
