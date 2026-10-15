{{-- Bilah kemajuan + layar "belum ditampilkan" + pesan galat - pola Dashboard Penjualan.
     Butuh: $tampil, $saringanBerubah, $galat, $target (aksi yg menarik data). --}}
<div wire:loading.flex wire:target="{{ $target }}" class="dsb-progres">
    <div class="bar"><span></span></div>
    <div class="ket">Sedang menarik data…</div>
</div>

@unless ($tampil)
    <div class="dsb-kosong dsb-mulai">
        <i class="fas fa-chart-line fa-2x mb-2 d-block"></i>
        @if ($saringanBerubah)
            <div class="fw-semibold mb-1">Saringan sudah diubah.</div>
            Klik <strong>Tampilkan</strong> untuk menarik data dengan saringan baru.
        @else
            <div class="fw-semibold mb-1">Siap menarik data.</div>
            Atur tanggal &amp; cabang di atas, lalu klik <strong>Tampilkan</strong>.
        @endif
    </div>
@endunless

@if ($tampil && $galat)
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i> Data gagal ditarik.</div>
        <div style="font-size:.8rem">{{ $galat }}</div>
        <div class="mt-1" style="font-size:.75rem">Galatnya sudah dicatat di <code>storage/logs/laravel.log</code>.</div>
    </div>
@endif
