@props(['show' => false, 'title' => '', 'size' => '', 'close' => null, 'closable' => true])

{{-- Modal Bootstrap 5 yang dikendalikan Livewire (tanpa JS tambahan).
     `close`: ekspresi wire:click untuk tombol (x) - default "$set('showModal', false)".
     WAJIB di-isi eksplisit kalau properti boolean modal-nya bukan bernama $showModal
     (mis. $showItemModal, $showVerify, $showPwModal) - kalau tidak, tombol (x) tak berfungsi.
     `closable=false`: sembunyikan tombol (x) - dipakai utk modal yang isiannya wajib
     dilengkapi (mis. No Ref/No IC di POS), tanpa jalan pintas menutup begitu saja. --}}
<div>
    <div class="modal fade {{ $show ? 'show d-block' : '' }}" tabindex="-1"
         @style(['display: block' => $show])
         wire:ignore.self>
        <div class="modal-dialog {{ $size ? 'modal-' . $size : '' }} modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $title }}</h5>
                    @if ($closable)
                        <button type="button" class="btn-close" wire:click="{{ $close ?? "\$set('showModal', false)" }}"></button>
                    @endif
                </div>
                {{ $slot }}
            </div>
        </div>
    </div>
    @if ($show)
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
