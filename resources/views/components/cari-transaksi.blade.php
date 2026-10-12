{{-- Dialog "Cari Transaksi" - port `bFrmCariTransaksi` VB6.

     SATU dialog untuk semua sumber, persis VB6 yg membedakannya lewat `lblSumber`
     (di sini `$ctSumber`, lihat trait `App\Livewire\Concerns\CariTransaksi`). Susunan &
     nama ikut VB6: saringan Cabang / No Transaksi / Nama / Tanggal Transaksi (sd) /
     Kode Produk, tombol Tampilkan, grid 3 kolom sesuai `xFormatString` sumbernya, lalu
     tombol Pilih & Keluar.

     TIDAK ditiru: centang "Semua/Tidak Semua" - itu utk sumber yg boleh pilih banyak
     baris sekaligus, sedangkan di sini satu dokumen hanya lahir dari SATU rujukan.
     Centang yg tidak berfungsi lebih menyesatkan drpd tidak ada. --}}
@props([
    'judul' => 'Cari Transaksi',
    'branches',
    'kolom',                      // 3 judul kolom grid
    'hasil',
    'pilih' => null,
    'totalTanpaSaringan' => 0,
    'batas' => 200,
    'cabang' => '',
    'cabangTerkunci' => true,
    'pakaiKodeProduk' => true,
])

<x-lw-modal :show="true" :title="$judul" close="tutupCari">
    <div class="modal-body vb-form">
        <div class="vb-row">
            <label class="form-label">Cabang</label>
            @if ($cabangTerkunci)
                {{-- Mati by design - lihat `CariTransaksi::ctCabangTerkunci()`. --}}
                <select class="form-select form-select-sm vb-ctl-wide" disabled
                        title="Dokumen ini selalu dikirim dari cabang Anda sendiri, jadi yang dicari hanya yang menyebut cabang ini sebagai pengirim.">
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}" @selected((string) $b->GID === (string) $cabang)>{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
            @else
                <select class="form-select form-select-sm vb-ctl-wide" wire:model="ctCabang">
                    <option value="">— semua cabang —</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="vb-row">
            <label class="form-label">No Transaksi</label>
            <input type="text" class="form-control form-control-sm vb-ctl-wide"
                   wire:model="ctNomor" wire:keydown.enter.prevent="tampilkan">
        </div>
        <div class="vb-row">
            <label class="form-label">Nama</label>
            <input type="text" class="form-control form-control-sm vb-ctl-wide"
                   wire:model="ctNama" wire:keydown.enter.prevent="tampilkan">
        </div>
        <div class="vb-row">
            <label class="form-label">Tanggal Transaksi</label>
            <input type="date" class="form-control form-control-sm" style="width:9.5rem" wire:model="ctFrom">
            <span>sd</span>
            <input type="date" class="form-control form-control-sm" style="width:9.5rem" wire:model="ctTo">
        </div>

        <div class="vb-sela"></div>

        @if ($pakaiKodeProduk)
            <div class="vb-row">
                <label class="form-label">Kode Produk</label>
                <input type="text" class="form-control form-control-sm vb-ctl-wide"
                       wire:model="ctKode" wire:keydown.enter.prevent="tampilkan"
                       placeholder="kode item persis">
            </div>
        @endif

        <div class="vb-row">
            <label class="form-label"></label>
            <button type="button" class="btn btn-outline-secondary btn-sm vb-ctl-wide" wire:click="tampilkan">
                <span wire:loading wire:target="tampilkan" class="spinner-border spinner-border-sm me-1"></span>
                <u>T</u>ampilkan
            </button>
        </div>

        <div class="table-responsive vb-grid-area mt-2" style="min-height: 18rem; max-height: 22rem; overflow-y:auto">
            <table class="table table-sm table-bordered align-middle vb-grid">
                <thead>
                    <tr>
                        <th style="width: 32%">{{ $kolom[0] }}</th>
                        <th style="width: 22%">{{ $kolom[1] }}</th>
                        <th>{{ $kolom[2] }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hasil as $r)
                        <tr wire:key="ct-{{ $r['id'] }}" style="cursor:pointer"
                            class="{{ (int) $pilih === (int) $r['id'] ? 'table-primary' : '' }}"
                            wire:click="sorotBaris({{ $r['id'] }})"
                            wire:dblclick="ambilBaris({{ $r['id'] }})">
                            <td>{{ $r['nomor'] }}</td>
                            <td>{{ $r['tanggal'] ? \Carbon\Carbon::parse($r['tanggal'])->format('d/m/Y') : '—' }}</td>
                            <td>
                                {{ $r['karyawan'] ?: '—' }}
                                @if (! empty($r['cabang'])) <span class="text-muted">&middot; {{ $r['cabang'] }}</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Rentang tanggal defaultnya bulan berjalan (ikut VB6). Tanpa peringatan ini,
             dokumen bulan lalu yg masih bisa ditarik seolah-olah hilang. --}}
        @if (count($hasil) === 0 && $totalTanpaSaringan > 0)
            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                <i class="fas fa-circle-info me-1"></i>
                Tidak ada yang cocok dengan saringan ini, padahal masih ada
                <strong>{{ $totalTanpaSaringan }}</strong> yang bisa ditarik —
                coba lebarkan rentang tanggalnya lalu tekan Tampilkan lagi.
            </div>
        @elseif (count($hasil) >= $batas)
            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                <i class="fas fa-circle-info me-1"></i>
                Ditampilkan {{ $batas }} teratas. Persempit saringannya kalau yang Anda cari belum terlihat.
            </div>
        @endif
    </div>
    <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-primary" wire:click="pilihBaris" @disabled($pilih === null)>
            <u>P</u>ilih
        </button>
        <button type="button" class="btn btn-secondary" wire:click="tutupCari"><u>K</u>eluar</button>
    </div>
</x-lw-modal>
