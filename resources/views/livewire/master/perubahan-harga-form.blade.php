@php
    $W = \App\Services\PerubahanHargaWriter::class;
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $diterapkan = $status === $W::STATUS_DITERAPKAN;
    $bolehApprove = can_do('master/perubahan-harga', 'approve');
    $berlakuFmt = $berlaku ? \Carbon\Carbon::parse($berlaku)->format('d/m/Y') : '—';
    $belumTiba = $berlaku && $berlaku > $hariIni;
    $terakhir = collect($riwayat)->first();
@endphp
<div>
    {{-- Tata letak STANDAR aplikasi (kartu Bootstrap, label di atas isian - pola
         `kas-bank-form`), BUKAN gaya VB6 `vb-form` spt Permintaan Barang: permintaan user
         2026-10-17 khusus form ini. Urutan isinya tetap dari VB6 `fFrmPerubahanHarga`
         (MID 517): kolom grid = FormatString <Kode|<Nama|>Harga Lama|>Harga Baru|>Setelah Pembulatan. --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong>Perubahan Harga</strong>
                @if ($nomor) <span class="text-muted">— {{ $nomor }}</span> @endif
                @if ($phId)
                    <span class="badge ms-2 {{ match ($status) { 1 => 'text-bg-success', 2 => 'text-bg-secondary', default => 'text-bg-warning' } }}">
                        {{ $W::STATUS_LABEL[$status] ?? $status }}
                    </span>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-1">
                @unless ($locked)
                    <button type="button" class="btn btn-success btn-sm" wire:click="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                @endunless
                @if ($phId && $bolehApprove && ! $diterapkan)
                    <button type="button" class="btn btn-primary btn-sm" wire:click="terapkan"
                            data-confirm="Terapkan harga baru untuk {{ count($lines) }} item sekarang? Harga jual di kasir langsung berubah.{{ $belumTiba ? ' PERHATIAN: tanggal berlakunya baru ' . $berlakuFmt . '.' : '' }}"
                            data-confirm-title="Terapkan Perubahan Harga" data-confirm-ok="Ya, terapkan">
                        <span wire:loading wire:target="terapkan" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="fas fa-play me-1"></i> Terapkan Harga
                    </button>
                @endif
                @if ($phId && $bolehApprove && $diterapkan)
                    <button type="button" class="btn btn-warning btn-sm" wire:click="kembalikan"
                            data-confirm="Batalkan pergantian & kembalikan harga lama {{ count($lines) }} item? Item yang harganya sudah diubah lagi sesudah dokumen ini diterapkan akan dilewati."
                            data-confirm-title="Kembalikan Harga Lama" data-confirm-ok="Ya, kembalikan" data-confirm-danger="1">
                        <span wire:loading wire:target="kembalikan" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="fas fa-rotate-left me-1"></i> Batalkan Pergantian
                    </button>
                @endif
                @if ($phId && $status === $W::STATUS_DRAFT && ! $pernahDiterapkan && can_do('master/perubahan-harga', 'delete'))
                    <button type="button" class="btn btn-outline-danger btn-sm" wire:click="hapus"
                            data-confirm="Hapus draft {{ $nomor }}? Harga jual tidak terpengaruh.">
                        <i class="fas fa-trash me-1"></i> Hapus
                    </button>
                @endif
                @if ($phId && can_do('master/perubahan-harga', 'print'))
                    <a href="{{ route('master.perubahan-harga.print', $phId) }}" target="_blank"
                       class="btn btn-outline-primary btn-sm"
                       title="{{ $dirty ? 'Yang dicetak isi dokumen TERSIMPAN - simpan dulu perubahan di layar' : 'Cetak dokumen' }}">
                        <i class="fas fa-print me-1"></i> Cetak
                    </a>
                @endif
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                    <i class="fas fa-xmark me-1"></i> Tutup
                </button>
            </div>
        </div>

        <div class="card-body">
            {{-- ===== Status berlaku: kapan harga berubah ===== --}}
            @if ($diterapkan)
                <div class="alert alert-success py-2 small mb-2">
                    <i class="fas fa-circle-check me-1"></i>
                    Harga baru <b>sudah berlaku</b> sejak diterapkan
                    {{ $terakhir ? \Carbon\Carbon::parse($terakhir->created_at)->format('d/m/Y H:i') : '' }}
                    {{ $terakhir && $terakhir->user ? 'oleh ' . $terakhir->user : '' }}
                    (tanggal berlaku dokumen {{ $berlakuFmt }}). Untuk membatalkan, tekan <b>Batalkan Pergantian</b>.
                </div>
            @elseif ($phId)
                <div class="alert {{ $belumTiba ? 'alert-info' : 'alert-warning' }} py-2 small mb-2">
                    <i class="fas fa-clock me-1"></i>
                    Harga jual <b>belum berubah</b>. Rencana berlaku: <b>{{ $berlakuFmt }}</b>
                    @if (! $belumTiba) — <b>tanggalnya sudah tiba</b>@endif.
                    Harga baru dipakai kasir setelah tombol <b>Terapkan Harga</b> ditekan
                    {{ $bolehApprove ? '' : '(oleh user yang berhak menyetujui)' }}.
                    @unless (count($lampiran))
                        <br><i class="fas fa-paperclip me-1"></i> <b>Lampiran surat kenaikan belum ada</b> — wajib
                        sebelum diterapkan (lihat bagian Lampiran di bawah).
                    @endunless
                </div>
            @endif
            @if ($dirty && ! $locked)
                <div class="alert alert-secondary py-1 small mb-2">
                    <i class="fas fa-pen me-1"></i> Ada perubahan yang belum disimpan.
                </div>
            @endif
            @if ($jmlBerubah && ! $diterapkan)
                <div class="alert alert-danger py-2 small mb-2">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Harga jual <b>{{ $jmlBerubah }} item</b> sudah berubah sejak dokumen ini disusun (kolom Harga Sekarang
                    berwarna merah). Dokumen tidak bisa diterapkan sampai Harga Lama disegarkan.
                </div>
            @endif
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            {{-- ===== Data dokumen ===== --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">No Transaksi</label>
                    <input type="text" class="form-control" disabled value="{{ $nomor ?: '[otomatis]' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control" wire:model="tanggal" @disabled($locked)>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tgl Berlaku <span class="text-danger">*</span></label>
                    <input type="date" class="form-control @error('berlaku') is-invalid @enderror"
                           wire:model="berlaku" @disabled($locked)>
                    @error('berlaku') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kontak</label>
                    @if ($locked)
                        <input type="text" class="form-control" disabled value="{{ $kontakLabel ?: '—' }}">
                    @else
                        <x-search-select model="kontak" :value="$kontak" :selected-text="$kontakLabel"
                                         :endpoint="route('lookup.kontak')" placeholder="cari kontak…" />
                    @endif
                </div>
                <div class="col-12">
                    <label class="form-label">Keterangan</label>
                    <input type="text" class="form-control" wire:model="uraian" @disabled($locked)>
                </div>
            </div>

            @unless ($locked)
                {{-- ===== Isi massal (VB6: Tampilkan Jenis ... Nilai % ... Proses) ===== --}}
                <div class="border rounded p-3 mb-3 bg-body-tertiary">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Tambah semua item jenis</label>
                            <select class="form-select form-select-sm" wire:model="jenis">
                                <option value="">Semua jenis</option>
                                @foreach ($jenisList as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="tambahJenis"
                                    title="Tambahkan semua item aktif berharga jual > 0 dari jenis ini">
                                <span wire:loading wire:target="tambahJenis" class="spinner-border spinner-border-sm me-1"></span>
                                <i class="fas fa-list me-1"></i> Tampilkan
                            </button>
                        </div>
                        <div class="col-md-2 ms-md-auto">
                            <label class="form-label small mb-1">Kenaikan</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" class="form-control text-end @error('persen') is-invalid @enderror"
                                       wire:model="persen" title="Persen kenaikan; minus = penurunan">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Pembulatan</label>
                            <select class="form-select form-select-sm" wire:model="pembulatan"
                                    title="Dibulatkan ke kelipatan terdekat">
                                @foreach ($W::PEMBULATAN as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="proses" @disabled(! count($lines))
                                    title="Hitung Harga Baru semua baris dari Harga Lama + %">
                                <i class="fas fa-calculator me-1"></i> Proses
                            </button>
                        </div>
                    </div>
                </div>
            @endunless

            {{-- ===== Daftar item ===== --}}
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <strong>Daftar Item</strong>
                @unless ($locked)
                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-outline-success btn-sm" wire:click="bukaImport"
                                title="Isi harga baru dari file Excel/CSV: Kode, Nama, Harga Baru">
                            <i class="fas fa-file-import me-1"></i> Import
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="segarkanLama"
                                @disabled(! count($lines))
                                title="Ambil ulang harga jual sekarang sebagai Harga Lama">
                            <i class="fas fa-arrows-rotate me-1"></i> Segarkan Harga Lama
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="kosongkan"
                                @disabled(! count($lines)) data-confirm="Kosongkan semua item?">
                            <i class="fas fa-eraser me-1"></i> Kosongkan
                        </button>
                    </div>
                @endunless
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                @unless ($locked)
                    <div class="position-relative" style="max-width: 420px; flex: 1 1 260px">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari item untuk ditambahkan…"
                                   wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        </div>
                        @if (count($items))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                @foreach ($items as $it)
                                    <button type="button" wire:key="ph-it-{{ $it->IID }}"
                                            class="list-group-item list-group-item-action small d-flex justify-content-between"
                                            wire:click="addItem({{ $it->IID }})">
                                        <span><span class="fw-semibold">{{ $it->IKODE }}</span> — {{ $it->INAMA }}</span>
                                        <span class="text-muted">{{ $rp($it->IHARGAJUAL1) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless

                @if (count($lines) > \App\Livewire\Master\PerubahanHargaForm::PER_HALAMAN || $cariBaris !== '')
                    <div class="input-group input-group-sm" style="width: 240px">
                        <span class="input-group-text"><i class="fas fa-filter"></i></span>
                        <input type="text" class="form-control" placeholder="saring baris: kode / nama…"
                               wire:model.live.debounce.400ms="cariBaris">
                    </div>
                    <span class="small text-muted">{{ number_format($jmlCocok, 0, ',', '.') }} baris</span>
                    <div class="btn-group btn-group-sm ms-auto">
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('hal', {{ $hal - 1 }})" @disabled($hal <= 1)>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="btn btn-outline-secondary disabled">Hal {{ $hal }} / {{ $jmlHal }}</span>
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('hal', {{ $hal + 1 }})" @disabled($hal >= $jmlHal)>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                @endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%">Kode</th>
                            <th>Nama</th>
                            <th class="text-end" style="width: 110px">Harga Lama</th>
                            <th class="text-end" style="width: 110px">Harga Baru</th>
                            <th class="text-end" style="width: 150px">Setelah Pembulatan</th>
                            <th class="text-end" style="width: 70px">Naik</th>
                            <th class="text-end" style="width: 110px" title="Harga jual di master item saat ini">Harga Sekarang</th>
                            @unless ($locked) <th style="width: 40px"></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tampil as $i => $l)
                            @php
                                $naik = $l['lama'] > 0 ? ($l['akhir'] - $l['lama']) / $l['lama'] * 100 : null;
                                // Sebelum diterapkan: harus = Harga Lama. Sesudah: harus = Setelah Pembulatan.
                                $acuan = $diterapkan ? $l['akhir'] : $l['lama'];
                                $beda = abs($l['sekarang'] - $acuan) >= 0.005;
                            @endphp
                            <tr wire:key="ph-line-{{ $l['item'] }}-{{ $i }}">
                                <td>{{ $l['kode'] }}</td>
                                <td>{{ $l['nama'] }}</td>
                                <td class="text-end">{{ $rp($l['lama']) }}</td>
                                <td class="text-end">{{ $rp($l['baru']) }}</td>
                                <td>
                                    @if ($locked)
                                        <div class="text-end fw-semibold">{{ $rp($l['akhir']) }}</div>
                                    @else
                                        <x-uang-input :model="'lines.'.$i.'.akhir'" :value="$l['akhir']" />
                                        @error('lines.'.$i.'.akhir') <div class="text-danger small">{{ $message }}</div> @enderror
                                    @endif
                                </td>
                                <td class="text-end {{ $naik === null ? '' : ($naik < 0 ? 'text-danger' : ($naik > 0 ? 'text-success' : 'text-muted')) }}">
                                    {{ $naik === null ? '—' : number_format($naik, 1, ',', '.') . '%' }}
                                </td>
                                <td class="text-end {{ $beda ? 'text-danger fw-semibold' : 'text-muted' }}"
                                    @if ($beda) title="{{ $diterapkan ? 'Sudah diubah lagi setelah diterapkan - tidak akan dikembalikan' : 'Berbeda dari Harga Lama - segarkan dulu' }}" @endif>
                                    {{ $rp($l['sekarang']) }}
                                </td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                wire:click="removeLine({{ $i }})" title="Hapus baris">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="{{ $locked ? 7 : 8 }}" class="text-center text-muted py-4">
                                {{ count($lines) ? 'Tidak ada baris yang cocok dengan saringan.' : 'Belum ada item — cari item di atas, Import file, atau pilih jenis lalu tekan Tampilkan.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end flex-wrap gap-4 mt-2 small">
                <div>Jumlah Item: <strong>{{ number_format(count($lines), 0, ',', '.') }}</strong></div>
                <div>Total Harga Baru: <strong>{{ $rp($totalBaru) }}</strong></div>
                <div>Total Setelah Pembulatan: <strong>{{ $rp($totalAkhir) }}</strong></div>
            </div>

            {{-- ===== Lampiran bukti untuk audit (surat/IOM kenaikan harga) =====
                 Aturan di PerubahanHargaWriter: wajib >= 1 sebelum Terapkan; tidak bisa dihapus
                 setelah dokumen pernah diterapkan. --}}
            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong><i class="fas fa-paperclip me-1"></i> Lampiran Surat Kenaikan Harga</strong>
                    <span class="small text-muted">wajib untuk audit sebelum harga diterapkan</span>
                </div>

                @if (count($lampiran))
                    <ul class="list-group mb-2">
                        @foreach ($lampiran as $lp)
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2"
                                wire:key="ph-lamp-{{ $lp->id }}">
                                <div class="text-truncate">
                                    <i class="fas {{ $lp->mime === 'application/pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary' }} me-2"></i>
                                    <a href="{{ route('master.perubahan-harga.lampiran', $lp->id) }}" target="_blank"
                                       title="Buka di tab baru">{{ $lp->nama_asli }}</a>
                                    <span class="small text-muted ms-2">
                                        {{ \App\Livewire\Master\PerubahanHargaForm::ukuran((int) $lp->ukuran) }}
                                        · {{ \Carbon\Carbon::parse($lp->created_at)->format('d/m/Y H:i') }}
                                        {{ $lp->user ? '· ' . $lp->user : '' }}
                                    </span>
                                </div>
                                @if ($bolehLampir && $bolehHapusLampiran)
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 flex-shrink-0"
                                            wire:click="hapusLampiran({{ $lp->id }})"
                                            data-confirm="Hapus lampiran {{ $lp->nama_asli }}?" title="Hapus lampiran">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                @elseif (! $bolehHapusLampiran)
                                    <i class="fas fa-lock text-muted flex-shrink-0" title="Dokumen sudah pernah diterapkan - lampiran dikunci untuk audit"></i>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="alert {{ $diterapkan ? 'alert-secondary' : 'alert-warning' }} py-2 small mb-2">
                        <i class="fas fa-triangle-exclamation me-1"></i> Belum ada lampiran.
                        @unless ($diterapkan) Harga tidak bisa diterapkan sebelum surat kenaikan dilampirkan. @endunless
                    </div>
                @endif

                @if ($bolehLampir)
                    @if ($phId)
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
                                   wire:model="fileLampiran" wire:key="ph-file-lampiran"
                                   class="form-control form-control-sm @error('fileLampiran') is-invalid @enderror" style="max-width: 360px">
                            {{-- Tanpa @disabled server - digabung wire:loading.attr membuat tombol mati
                                 selamanya (lihat CLAUDE.md, gotcha unggah file). --}}
                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="unggahLampiran"
                                    wire:loading.attr="disabled" wire:target="fileLampiran,unggahLampiran">
                                <span wire:loading wire:target="fileLampiran,unggahLampiran" class="spinner-border spinner-border-sm me-1"></span>
                                <i class="fas fa-upload me-1"></i> Unggah Lampiran
                            </button>
                            <span class="small text-muted">PDF / JPG / PNG / WEBP, maks {{ $batasLampiran }}</span>
                        </div>
                        @if ($fileLampiran && ! $errors->has('fileLampiran'))
                            <div class="small text-success mt-1" wire:loading.remove wire:target="fileLampiran">
                                <i class="fas fa-circle-check me-1"></i> File siap: <b>{{ $fileLampiran->getClientOriginalName() }}</b> — tekan <b>Unggah Lampiran</b>.
                            </div>
                        @endif
                    @else
                        <div class="small text-muted">Simpan dokumen dulu, baru lampiran bisa diunggah.</div>
                    @endif
                @endif
                @error('fileLampiran') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>

            @if (count($riwayat))
                <div class="mt-3">
                    <div class="fw-semibold small mb-1"><i class="fas fa-clock-rotate-left me-1"></i> Riwayat</div>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr>
                            <th style="width: 140px">Waktu</th><th style="width: 160px">Aksi</th>
                            <th>Oleh</th><th class="text-end" style="width: 90px">Item</th>
                            <th class="text-end" style="width: 90px">Dilewati</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($riwayat as $r)
                                <tr wire:key="ph-log-{{ $r->id }}">
                                    <td>{{ \Carbon\Carbon::parse($r->created_at)->format('d/m/Y H:i') }}</td>
                                    <td>{{ $r->aksi === 'terapkan' ? 'Harga diterapkan' : 'Harga lama dikembalikan' }}</td>
                                    <td>{{ $r->user ?: '—' }}</td>
                                    <td class="text-end">{{ $r->jumlah_item - $r->jumlah_dilewati }}</td>
                                    <td class="text-end {{ $r->jumlah_dilewati ? 'text-danger' : '' }}">{{ $r->jumlah_dilewati }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ===== Dialog Import Kode / Nama / Harga Baru =====
         WAJIB dibungkus @if: komponen lw-modal memakai wire:ignore.self (lihat CLAUDE.md). --}}
    @if ($showImport)
        <x-lw-modal :show="true" title="Import Harga Baru" close="tutupImport">
            <div class="modal-body small">
                <p class="mb-2">
                    File <b>.xlsx</b> atau <b>.csv</b> dengan kolom <b>Kode</b>, <b>Nama</b>, <b>Harga Baru</b>
                    (baris pertama judul). Item dicocokkan lewat <b>Kode</b>; harga dari file dipakai apa adanya,
                    tanpa pembulatan. Item yang sudah ada di daftar diperbarui, yang belum ada ditambahkan.
                </p>
                <button type="button" class="btn btn-link btn-sm p-0 mb-3" wire:click="unduhTemplate">
                    <i class="fas fa-download me-1"></i> Unduh template
                    @if (count($lines)) (berisi {{ number_format(count($lines), 0, ',', '.') }} item di daftar ini) @endif
                </button>

                <input type="file" accept=".xlsx,.csv,.txt" wire:model="fileImport" wire:key="ph-file-import"
                       class="form-control form-control-sm @error('fileImport') is-invalid @enderror">
                <div wire:loading wire:target="fileImport" class="text-muted mt-1">
                    <span class="spinner-border spinner-border-sm me-1"></span> Mengunggah…
                </div>
                @error('fileImport') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                @if ($fileImport && ! $errors->has('fileImport'))
                    <div class="text-success mt-1" wire:loading.remove wire:target="fileImport">
                        <i class="fas fa-circle-check me-1"></i> File siap: <b>{{ $fileImport->getClientOriginalName() }}</b>
                        — tekan <b>Proses Import</b>.
                    </div>
                @endif

                @if ($hasilImport)
                    <div class="alert {{ $hasilImport['masalah'] ? 'alert-warning' : 'alert-success' }} mt-3 mb-2 py-2">
                        <b>{{ $hasilImport['file'] }}</b>:
                        {{ $hasilImport['diperbarui'] }} item diperbarui, {{ $hasilImport['ditambah'] }} item ditambahkan
                        @if ($hasilImport['masalah']), <b>{{ count($hasilImport['masalah']) }} baris dilewati</b>@endif.
                        Periksa daftarnya lalu tekan <b>Simpan</b>.
                    </div>
                    @foreach (['masalah' => ['Dilewati', 'text-danger'], 'catatan' => ['Catatan (tetap diimpor)', 'text-muted']] as $k => [$jdl, $warna])
                        @if ($hasilImport[$k])
                            <div class="fw-semibold mt-2">{{ $jdl }}</div>
                            <ul class="mb-0 ps-3 {{ $warna }}" style="max-height: 160px; overflow-y: auto">
                                @foreach (array_slice($hasilImport[$k], 0, 100) as $m)
                                    <li>{{ $m }}</li>
                                @endforeach
                                @if (count($hasilImport[$k]) > 100)
                                    <li>… dan {{ count($hasilImport[$k]) - 100 }} lagi</li>
                                @endif
                            </ul>
                        @endif
                    @endforeach
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="tutupImport">
                    {{ $hasilImport ? 'Selesai' : 'Batal' }}
                </button>
                {{-- JANGAN tambahkan @disabled(! $fileImport) di sini. Digabung dgn
                     wire:loading.attr="disabled", Livewire MENGEMBALIKAN keadaan "disabled" yg ia
                     catat saat unggah dimulai, menimpa render server yg sudah mengaktifkan tombol -
                     tombol mati selamanya walau file sudah terunggah (dilaporkan user 2026-10-17).
                     Tanpa file, prosesImport() sendiri menolak dgn pesan "Pilih file dulu". --}}
                <button type="button" class="btn btn-success btn-sm" wire:click="prosesImport"
                        wire:loading.attr="disabled" wire:target="fileImport,prosesImport">
                    <span wire:loading wire:target="prosesImport" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="fas fa-file-import me-1"></i> Proses Import
                </button>
            </div>
        </x-lw-modal>
    @endif
</div>
