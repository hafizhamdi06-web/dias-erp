{{-- Dialog "Cari Barang" - port VB6 `bFrmCariItem` (caption disetel saat berjalan oleh
     `bMod_Cari.bas::CariBarang()`). Keadaannya dipegang trait
     `App\Livewire\Concerns\CariBarang`, jadi `wire:model` di sini menempel ke komponen
     Livewire pemanggilnya.

     Susunan ikut VB6: kotak **Data** di atas, lalu grid, lalu tombol di bawah.
     Kolom grid VB6 `ID|<Kode|<Nama|>Harga Jual|>Stok|>Stok Minimum|<Jenis Produk`;
     yg ditampilkan hanya **Kode | Nama | Stok** - permintaan user 2026-10-10
     ("harga jual tidak perlu"). Stok = stok gudang user login.

     TIDAK ditiru: tombol **Hitung Ulang Stok**. Di VB6 tombol itu menjalankan
     `UPDATE bitem SET <ISTOKxx> = <hitung ulang>` - menulis ke master data yg dipakai
     seluruh modul. Perlu keputusan terpisah, bukan dibawa diam-diam lewat perubahan
     tampilan. --}}
@props(['hasil', 'pilih' => null, 'data' => '', 'total' => 0, 'batas' => 200, 'ditambah' => 0])

<x-lw-modal :show="true" title="Cari Barang" close="tutupCariBarang">
    <div class="modal-body vb-form">
        <div class="vb-row">
            <label class="form-label">Data</label>
            {{-- Menyaring langsung saat diketik - VB6 `txtData_Change` memanggil `IsiData`,
                 dialog ini memang tidak punya tombol "Tampilkan". --}}
            <input type="text" class="form-control form-control-sm" style="flex:1 1 auto"
                   wire:model.live.debounce.300ms="cbData"
                   placeholder="ketik kode / nama barang…" autocomplete="off">
        </div>

        <div class="table-responsive vb-grid-area mt-2" style="min-height: 20rem; max-height: 24rem; overflow-y:auto">
            <table class="table table-sm table-bordered align-middle vb-grid">
                <thead>
                    <tr>
                        <th style="width: 32%">Kode</th>
                        <th>Nama</th>
                        <th class="text-end" style="width: 6rem">Stok</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hasil as $r)
                        <tr wire:key="cb-{{ $r['id'] }}" style="cursor:pointer"
                            class="{{ (int) $pilih === (int) $r['id'] ? 'table-primary' : '' }}"
                            wire:click="sorotBarang({{ $r['id'] }})"
                            wire:dblclick="ambilBarang({{ $r['id'] }})">
                            <td>{{ $r['kode'] }}</td>
                            <td>{{ $r['nama'] }}</td>
                            {{-- Stok minus itu nyata di data (mis. -69) dan justru penting
                                 dilihat - ditandai merah, bukan disembunyikan. --}}
                            <td class="text-end {{ (float) $r['stok'] < 0 ? 'text-danger-emphasis fw-semibold' : '' }}">
                                {{ number_format((float) $r['stok'], 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">Tidak ada barang yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($total > $batas)
            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                <i class="fas fa-circle-info me-1"></i>
                Ditampilkan {{ number_format($batas, 0, ',', '.') }} dari
                {{ number_format($total, 0, ',', '.') }} barang. Ketik di kotak Data untuk mempersempit.
            </div>
        @endif
    </div>
    <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-primary" wire:click="pilihBarang" @disabled($pilih === null)>
            <u>P</u>ilih
        </button>

        {{-- Grid di belakang tertutup modal, jadi tanpa penanda ini user tidak tahu
             klik "Pilih"-nya berhasil atau tidak. --}}
        <span class="text-success {{ $ditambah ? '' : 'invisible' }}">
            <i class="fas fa-circle-check me-1"></i>{{ $ditambah }} item ditambahkan
        </span>

        <button type="button" class="btn btn-secondary" wire:click="tutupCariBarang"><u>K</u>eluar</button>
    </div>
</x-lw-modal>
