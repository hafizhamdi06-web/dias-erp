{{-- Dialog "Cari Kontak" - port VB6 `bFrmCariKontak`. Keadaannya dipegang trait
     `App\Livewire\Concerns\CariKontak`, jadi `wire:model` di sini menempel ke komponen
     Livewire pemanggilnya.

     Susunan ikut VB6: baris Kategori (+ centang "Aktif Saja"), baris Field + Data +
     tombol Tampilkan, baris Cabang, lalu grid, lalu Pilih / Keluar di bawah.

     Kolom grid VB6 `ID|<Kode|<Nama|<Kategori|<ID Pasien|<Cabang`; yg ditampilkan hanya
     **Kode | Nama | Jenis** - permintaan user 2026-10-11. "Jenis" = `bkontaktipe.KTNAMA`,
     kolom yg di VB6 berjudul "Kategori".

     Saringannya lewat tombol **Tampilkan**, bukan saat diketik: `bkontak` berisi 321.768
     baris, jadi menyaring tiap ketikan berarti memindainya berulang kali. Enter di kotak
     Data juga menjalankan pencarian, spt petunjuk di layar VB6 ("Ketik Di Data, Setelah
     itu Tekan Enter/Klik Tampilkan"). --}}
@props([
    'hasil', 'pilih' => null, 'total' => 0, 'batas' => 20,
    'kategoriList' => [], 'fieldOpsi' => [], 'branches' => [],
])

<x-lw-modal :show="true" title="Cari Kontak" close="tutupCariKontak">
    <div class="modal-body vb-form">
        <div class="vb-row">
            <label class="form-label">Kategori</label>
            <select class="form-select form-select-sm" style="flex:1 1 auto" wire:model="ckKategori">
                <option value="">&lt;Semua&gt;</option>
                @foreach ($kategoriList as $k)
                    <option value="{{ $k->KTID }}">{{ $k->KTNAMA }}</option>
                @endforeach
            </select>
            <div class="form-check mb-0 ms-2 text-nowrap">
                <input class="form-check-input" type="checkbox" id="ck-aktif" wire:model="ckAktifSaja">
                <label class="form-check-label" for="ck-aktif">Aktif Saja</label>
            </div>
        </div>

        {{-- Data DI BAWAH Field, baris sendiri - permintaan user 2026-10-11. Di VB6
             keduanya berdempet di satu baris (`cboField` 1260, `txtData` 3810), tapi di
             sini kotak Data jadi lebih lega & sejajar dgn kontrol lain. Tombol Tampilkan
             ikut ke baris Data krn itu yg dikenainya. --}}
        <div class="vb-row">
            <label class="form-label">Field</label>
            <select class="form-select form-select-sm vb-ctl" wire:model="ckField">
                @foreach ($fieldOpsi as $kunci => $opsi)
                    <option value="{{ $kunci }}">{{ $opsi[0] }}</option>
                @endforeach
            </select>
        </div>

        <div class="vb-row">
            <label class="form-label">Data</label>
            <input type="text" class="form-control form-control-sm" style="flex:1 1 auto"
                   wire:model="ckData" wire:keydown.enter.prevent="cariKontak" autocomplete="off">
            <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" wire:click="cariKontak">
                Tampilkan
            </button>
        </div>

        <div class="vb-row">
            <label class="form-label">Cabang</label>
            <select class="form-select form-select-sm" style="flex:1 1 auto" wire:model="ckCabang">
                <option value="">Semua</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive vb-grid-area mt-2" style="min-height: 18rem; max-height: 22rem; overflow-y:auto">
            <table class="table table-sm table-bordered align-middle vb-grid">
                <thead>
                    <tr>
                        <th style="width: 28%">Kode</th>
                        <th>Nama</th>
                        <th style="width: 22%">Jenis</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hasil as $r)
                        <tr wire:key="ck-{{ $r['id'] }}" style="cursor:pointer"
                            class="{{ (int) $pilih === (int) $r['id'] ? 'table-primary' : '' }}"
                            wire:click="sorotKontak({{ $r['id'] }})"
                            wire:dblclick="ambilKontak({{ $r['id'] }})">
                            <td>{{ $r['kode'] }}</td>
                            <td>{{ $r['nama'] }}</td>
                            <td>{{ $r['jenis'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">Tidak ada kontak yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($total > $batas)
            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                <i class="fas fa-circle-info me-1"></i>
                Ditampilkan {{ number_format($batas, 0, ',', '.') }} dari
                {{ number_format($total, 0, ',', '.') }} kontak. Isi kotak Data lalu klik
                Tampilkan untuk mempersempit.
            </div>
        @endif
    </div>
    <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-primary" wire:click="pilihKontak" @disabled($pilih === null)>
            <u>P</u>ilih
        </button>
        <button type="button" class="btn btn-secondary" wire:click="tutupCariKontak"><u>K</u>eluar</button>
    </div>
</x-lw-modal>
