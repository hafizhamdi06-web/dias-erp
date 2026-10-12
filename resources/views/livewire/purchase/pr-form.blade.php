<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $prId ? 'Permintaan ' . $nomor : 'Permintaan Barang Baru' }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci (sudah verifikasi)</span> @endif
                </span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @if ($prId && can_do('inventory/pr', 'print'))
                        <button type="button" class="btn btn-outline-primary btn-sm" wire:click="printPr">
                            <i class="fas fa-print me-1"></i> Cetak
                        </button>
                    @endif
                    @if (! $locked && can_do('inventory/pr', $prId ? 'edit' : 'add'))
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                {{-- Wording 3-7 disamakan PERSIS dgn label legacy VB6
                     (fFrmPermintaanbarangData.frm::IsiData(), CASE PBUSTATUS) per
                     permintaan user 2026-09-18 - status 4-7 blm pernah tampil di v1
                     sblm ini (baru kelihatan skrg krn data hasil import beneran
                     mengandung status lanjutan itu, v1 sblmnya cuma pernah test 0/1/2/9). --}}
                @php
                    // [teks, warna titik]. VB6 menampilkan status sbg combo polos tanpa warna;
                    // titik berwarna ini satu-satunya isyarat warna yg dipertahankan dari versi
                    // badge sebelumnya, krn cukup berguna dan tidak mengganggu tata letak.
                    [$statusTeks, $statusDot] = match ($status) {
                        1 => ['Pending', '#f0ad4e'],
                        2 => ['Disetujui', '#198754'],
                        3 => ['Perintah Kirim', '#0dcaf0'],
                        4 => ['Sedang Dikirim', '#0dcaf0'],
                        5 => ['Progress Diterima Cabang', '#0d6efd'],
                        6 => ['Selesai Diterima Cabang', '#198754'],
                        7 => ['Konfirmasi Bag Pembelian', '#0d6efd'],
                        9 => ['Batal', '#dc3545'],
                        default => ['Belum Verifikasi', '#6c757d'],
                    };
                @endphp

                {{-- ============================================================================
                     Tata letak MENGIKUTI form VB6 `fFrmPermintaanBarang2.frm` (caption
                     "Permintaan Barang"), permintaan user 2026-10-10: beberapa user menilai
                     tampilan sistem baru "sangat berbeda" dgn yg sedang berjalan.

                     Yg ditiru: urutan & nama label, label di KIRI field (bukan di atas), field
                     berlebar TETAP (VB6: 3090 twip ~ 206px; Keterangan 5625 twip ~ 375px) bukan
                     melar seluruh kolom, kotak ber-caption di kanan (Tanggal/No PO/Status/
                     Catatan Verifikasi), dan susunan kolom grid persis `FormatString` VB6.

                     WARNA LATAR juga ikut disamakan (susulan user 2026-10-10: "warna latar
                     belakangnya masih kurang sama") - abu-abu COLOR_BTNFACE #F0F0F0 utk badan
                     form, abu-abu sedang utk area kosong grid, dan strip judul abu bergradasi.
                     Yg TIDAK ditiru: bingkai 3D cekung tiap kontrol - kontrolnya tetap kontrol
                     Bootstrap biasa, sesuai pilihan "tata letak VB6, gaya tetap bersih".

                     Teks bantuan di bawah field DIHAPUS semua (VB6 tidak punya, dan itu yg bikin
                     form ini jadi 2x lebih tinggi) - keterangannya dipindah ke `title=` supaya
                     tetap bisa dibaca saat kursor ditahan di atas field.

                     Gaya-nya ada di `public/css/vb-form.css` (dipakai bersama form lain yg
                     ikut pola ini).
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== Kolom kiri: urutan PERSIS VB6 =====
                             Nama Karyawan > Depo/Farmasi > (sela) > Tujuan > Tipe Permintaan >
                             PO Ke > (sela) > Keterangan --}}
                        <div class="col-lg-7">
                            <fieldset @disabled($locked)>
                                <div class="vb-row">
                                    <label class="form-label">Nama Karyawan</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                           value="{{ $karyawanLabel ?: '—' }}" disabled
                                           title="{{ $prId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}">
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">Depo / Farmasi</label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model="gudang" disabled
                                            title="{{ $prId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}">
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @error('gudang') <div class="vb-err text-danger small">{{ $message }}</div> @enderror

                                <div class="vb-sela"></div>

                                <div class="vb-row">
                                    <label class="form-label">Tujuan <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model.live="tujuan">
                                        <option value="">— pilih —</option>
                                        @foreach ($tujuanList as $t)
                                            <option value="{{ $t->lid }}">{{ $t->lnama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @error('tujuan') <div class="vb-err text-danger small">{{ $message }}</div> @enderror

                                {{-- VB6 menaruh Tipe Permintaan SEBELUM PO Ke. Di sini isinya
                                     tetap ikut Tujuan (tidak bisa diubah manual), spt sebelumnya. --}}
                                <div class="vb-row">
                                    <label class="form-label">Tipe Permintaan</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                           value="{{ $jenis === 1 ? 'Permintaan Pembelian' : 'Permintaan Barang' }}" disabled
                                           title="Otomatis mengikuti Tujuan yang dipilih, tidak bisa diubah manual.">
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">PO Ke <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model="gudangSumber"
                                            @disabled($locked || $gudangSumberLocked)
                                            title="{{ $gudangSumberLocked && ! $locked ? 'Otomatis sesuai Tujuan yang dipilih, tidak bisa diubah.' : 'Gudang tujuan PO.' }}">
                                        <option value="">— pilih —</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @error('gudangSumber') <div class="vb-err text-danger small">{{ $message }}</div> @enderror

                                <div class="vb-sela"></div>

                                <div class="vb-row">
                                    <label class="form-label">Keterangan</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl-wide" wire:model="uraian">
                                </div>
                            </fieldset>
                        </div>

                        {{-- ===== Kolom kanan: kotak ber-caption, spt VB6 ===== --}}
                        <div class="col-lg-5">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">Tanggal</div>
                                        <div class="isi">
                                            <input type="date" class="form-control form-control-sm border-0 p-0 px-1"
                                                   wire:model="tanggal" @disabled($locked)>
                                        </div>
                                    </div>
                                    @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">No PO</div>
                                        <div class="isi">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $nomor ?: '[otomatis]' }}" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="vb-box">
                                <div class="cap">Status</div>
                                <div class="isi d-flex align-items-center gap-2 px-2">
                                    <span style="width:.6rem; height:.6rem; border-radius:50%; background:{{ $statusDot }}"></span>
                                    <span>{{ $statusTeks }}</span>
                                </div>
                            </div>

                            <div class="vb-box">
                                <div class="cap">Catatan Verifikasi</div>
                                <div class="isi">
                                    <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                           value="{{ $catatanVerifikasi ?: '' }}" disabled>
                                </div>
                            </div>
                            @if ($status !== 0 && $verifiedBy)
                                <div class="text-muted small">
                                    <i class="fas fa-user-check me-1"></i>
                                    Diverifikasi oleh <span class="fw-semibold">{{ $verifiedBy }}</span>
                                    @if ($verifiedAt)
                                        pada {{ \Carbon\Carbon::parse($verifiedAt)->translatedFormat('d F Y') }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- ---- Baris item ---- --}}
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-3 mb-1">
                        {{-- Pencarian item lewat dialog "Cari Barang" (port VB6 `bFrmCariItem`,
                             permintaan user 2026-10-10) - menggantikan kotak sebaris + daftar
                             gantung. Di VB6 dialognya dibuka dari tombol di sel Kode grid;
                             di sini tombolnya ditaruh di atas grid karena baris kosong untuk
                             diklik memang tidak ada. --}}
                        @unless ($locked)
                            <button type="button" class="btn btn-primary btn-sm" wire:click="bukaCariBarang">
                                <i class="fas fa-magnifying-glass me-1"></i> Cari Barang
                            </button>
                        @else
                            <div></div>
                        @endunless
                        {{-- Real Stok default DISEMBUNYIKAN - user isi Qty dari hitungan manual
                             sendiri, bukan nyontek stok sistem. Toggle ini KHUSUS verifikator
                             (`can_do('inventory/pr','approve')` - SAMA persis hak yg dipakai
                             tombol Verifikasi/notif lonceng, permintaan user 2026-09-24, BUKAN
                             "siapa pun" lagi spt niat awal). SENGAJA TIDAK diseragamkan dgn VB6
                             yg menampilkannya ke semua orang. --}}
                        @if (can_do('inventory/pr', 'approve'))
                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="toggleStok">
                                <i class="fas fa-{{ $showStok ? 'eye-slash' : 'eye' }} me-1"></i>
                                {{ $showStok ? 'Sembunyikan' : 'Tampilkan' }} Stok Sistem
                            </button>
                        @endif
                    </div>

                    {{-- Susunan kolom = `FormatString` VB6:
                         <Kode|<Nama|>Qty|<Satuan|<Catatan|…|>Real Stok|>Stok Akhir|<Refresh Stok
                         QtyDasar/SatuanDasar/Konversi/Stok Konfirmasi lebarnya 0 di VB6 (tidak
                         pernah tampil), jadi tidak ikut. "Refresh Stok" juga tidak ikut: di VB6
                         itu tombol per baris utk membaca ulang stok, di sini stoknya sudah ikut
                         terbaca saat item ditambahkan. --}}
                    <div class="table-responsive vb-grid-area">
                        <table class="table table-sm table-bordered align-middle vb-grid mb-1">
                            <thead>
                                <tr>
                                    <th style="width: 20%">Kode</th>
                                    <th>Nama</th>
                                    <th class="text-end" style="width: 100px">Qty</th>
                                    <th style="width: 80px">Satuan</th>
                                    <th style="width: 22%">Catatan</th>
                                    @if ($showStok)
                                        <th class="text-end" style="width: 100px">Real Stok</th>
                                    @endif
                                    <th class="text-end" style="width: 110px">Stok Akhir</th>
                                    <th style="width: 32px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $i => $l)
                                    <tr wire:key="line-{{ $l['item'] }}">
                                        <td>{{ $l['kode'] }}</td>
                                        <td>{{ $l['nama'] }}</td>
                                        <td>
                                            <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                                   wire:model.live.debounce.400ms="lines.{{ $i }}.qty" @disabled($locked)>
                                        </td>
                                        <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                        </td>
                                        @if ($showStok)
                                            <td class="text-end">{{ number_format($l['stok'], 2, ',', '.') }}</td>
                                        @endif
                                        <td>
                                            <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                                   wire:model.live.debounce.400ms="lines.{{ $i }}.stokManual" @disabled($locked)>
                                        </td>
                                        <td class="text-center">
                                            @unless ($locked)
                                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" wire:click="removeLine({{ $i }})">
                                                    <i class="fas fa-xmark"></i>
                                                </button>
                                            @endunless
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $showStok ? 8 : 7 }}" class="text-center text-muted py-4">Belum ada item.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- VB6 menaruh Total Qty di TENGAH bawah grid, bukan di kaki tabel. --}}
                    <div class="vb-total">
                        <span>Total Qty</span>
                        <span class="nilai">{{ number_format($totalQty, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </form>
    {{-- Dialog "Cari Barang" (port VB6 `bFrmCariItem`) - markup & logikanya dipakai
         bersama, lihat trait `App\Livewire\Concerns\CariBarang`. --}}
    @if ($cbShow)
        <x-cari-barang :hasil="$cbHasil" :pilih="$cbPilih" :data="$cbData"
                       :total="$cbTotal" :batas="$cbBatas" :ditambah="$cbDitambah" />
    @endif
</div>
