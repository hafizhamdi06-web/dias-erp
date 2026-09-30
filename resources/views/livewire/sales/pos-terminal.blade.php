<div wire:key="pos-terminal"
     {{-- F1-F7 punya arti GANDA.
          Di LUAR dialog bayar : F1 pelanggan, F2 item, F5 voucher, F6 DP.
                                 (F3 & F4 SENGAJA kosong sejak 2026-09-30 - promo/paket
                                  pindah ke F10/F11 atas permintaan user.)
          Di DALAM dialog bayar: F1 Tunai, F2 Debit, F3 Kredit, F4 Transfer, F5 DP,
                                 F6 Merchant, F7 Voucher.
          Pengikatnya HARUS satu per tombol dgn percabangan di PHP (`hotkeyFn`) - kalau dipasang
          dua `wire:keydown.fN.window` (satu di root, satu di dalam dialog), KEDUANYA ikut jalan
          dan modal pencarian ikut terbuka bersamaan dgn terisinya nilai bayar. --}}
     wire:keydown.f1.window.prevent="hotkeyF1"
     wire:keydown.f2.window.prevent="hotkeyF2"
     wire:keydown.f3.window.prevent="hotkeyF3"
     wire:keydown.f4.window.prevent="hotkeyF4"
     wire:keydown.f5.window.prevent="hotkeyF5"
     wire:keydown.f6.window.prevent="hotkeyF6"
     wire:keydown.f7.window.prevent="hotkeyF7"
     {{-- F10 Harga Khusus & F11 Daftar Paket - HANYA berlaku di luar dialog bayar
          (percabangannya di `hotkeyF10`/`hotkeyF11`). `.prevent` WAJIB: F11 = layar penuh,
          F10 = bilah menu di Firefox. Keduanya masih bisa dicegah - beda dari F12 (DevTools)
          yg TIDAK bisa, lihat catatan di `hotkeyCtrlEnter()`. --}}
     wire:keydown.f10.window.prevent="hotkeyF10"
     wire:keydown.f11.window.prevent="hotkeyF11"
     {{-- F8 & Ctrl+Enter BUKA dialog bayar, BUKAN langsung menyimpan - mengikuti alur VB6:
          susun keranjang, buka dialog, baru OK. Pemicu simpan (F12) sengaja dipasang DI DALAM
          dialog, jadi hanya hidup selama dialog terbuka - supaya tidak ada transaksi tersimpan
          tanpa kasir sempat melihat rincian bayarnya. --}}
     wire:keydown.f8.window.prevent="hotkeyF8"
     wire:keydown.enter.ctrl.window.prevent="hotkeyCtrlEnter">
    @if ($lastReceipt)
        {{-- ====== STRUK / KONFIRMASI ====== --}}
        <div class="card border-success">
            <div class="card-body text-center py-4">
                <i class="fas fa-circle-check text-success fa-3x mb-2"></i>
                <h4 class="mb-1">Transaksi tersimpan</h4>
                <div class="text-muted mb-3">No. <span class="fw-semibold">{{ $lastReceipt['nomor'] }}</span> &middot; {{ $lastReceipt['items'] }} item</div>
                <div class="row justify-content-center g-2 mb-3">
                    <div class="col-auto"><div class="border rounded p-2"><div class="small text-muted">Total</div><div class="fw-bold">{{ number_format($lastReceipt['total'], 0, ',', '.') }}</div></div></div>
                    <div class="col-auto"><div class="border rounded p-2"><div class="small text-muted">Bayar</div><div class="fw-bold">{{ number_format($lastReceipt['bayar'], 0, ',', '.') }}</div></div></div>
                    <div class="col-auto"><div class="border rounded p-2 bg-body-secondary"><div class="small text-muted">Kembalian</div><div class="fw-bold">{{ number_format($lastReceipt['kembalian'], 0, ',', '.') }}</div></div></div>
                </div>
                {{-- Tombol utama memakai ukuran struk PREFERENSI user; dua tombol kecil di
                     sebelahnya untuk memaksa ukuran lain sekali jalan (`?struk=`) tanpa
                     mengubah setelan - mis. kertas termal habis. --}}
                <a href="{{ route('sales.pos.receipt', $lastReceipt['id']) }}" target="_blank" class="btn btn-outline-secondary">
                    <i class="fas fa-print me-1"></i> Cetak struk
                </a>
                @foreach (config('pos.struk_pilihan', []) as $kode => $label)
                    <a href="{{ route('sales.pos.receipt', ['id' => $lastReceipt['id'], 'struk' => $kode]) }}"
                       target="_blank" class="btn btn-outline-secondary btn-sm" title="Cetak sebagai {{ $label }}">
                        {{-- `(string)` WAJIB: PHP mengubah kunci config '58' jadi integer 58. --}}
                        {{ (string) $kode === '58' ? '58 mm' : '½ A4' }}
                    </a>
                @endforeach
                <button class="btn btn-primary" wire:click="newTransaction">
                    <i class="fas fa-plus me-1"></i> Transaksi baru
                </button>
            </div>
        </div>
    @else
        <div class="row g-3">
            {{-- ============ KIRI: item + keranjang ============ --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge text-bg-secondary">
                            <i class="fas fa-shop me-1"></i>{{ $branch->GNAMA ?? '—' }}
                        </span>
                        <span class="badge text-bg-secondary">
                            <i class="fas fa-user-tie me-1"></i>{{ $kasirLabel ?? '—' }}
                        </span>
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1 text-start" wire:click="openItemModal">
                            <i class="fas fa-barcode me-1"></i> Cari item / scan barcode…
                            <kbd class="float-end">F2</kbd>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="openHistoryModal">
                            <i class="fas fa-clock-rotate-left me-1"></i> Riwayat Hari Ini
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="openOtherDataModal">
                            <i class="fas fa-ellipsis me-1"></i> Data Lainnya
                        </button>
                        {{-- Tombol "Data Transaksi" DIHAPUS (permintaan user 2026-09-28) - sekarang
                             jadi menu sidebar sendiri: Penjualan > Data Transaksi POS
                             (`sales.pos-data`), supaya bisa dibuka tanpa lewat layar kasir dan
                             hak aksesnya bisa diatur terpisah dari `sales/pos`. --}}
                    </div>
                    <div class="card-body p-0">
                        @if ($editingFromNomor)
                            <div class="alert alert-warning m-2 py-2 mb-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fas fa-pen me-1"></i>
                                    Mengedit transaksi <strong>{{ $editingFromNomor }}</strong> — transaksi lama masih AKTIF selama belum disimpan. Tekan Bayar &amp; Simpan untuk membatalkan yang lama dan menerbitkan nomor baru.
                                </span>
                            </div>
                        @endif
                        @error('cart') <div class="alert alert-danger m-2 py-2">{{ $message }}</div> @enderror
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:28%">Item</th>
                                    <th class="text-center" style="width:80px">Qty</th>
                                    <th class="text-end" style="width:110px">Harga</th>
                                    <th class="text-center" style="width:70px">Disc 1 %</th>
                                    <th class="text-center" style="width:70px">Disc 2 %</th>
                                    <th class="text-end" style="width:130px">Subtotal</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($cart as $i => $line)
                                    <tr wire:key="cart-{{ $line['item'] }}">
                                        <td>
                                            <div class="fw-semibold small">{{ $line['nama'] }}</div>
                                            @if ($line['kode'] !== $line['nama'])
                                                <div class="text-muted small">{{ $line['kode'] }}</div>
                                            @endif
                                            @if (! empty($line['sdidpromo']))
                                                <div class="small text-success mt-1">
                                                    <i class="fas fa-tag me-1"></i>Promo: {{ $line['promo_label'] ?? '—' }}
                                                    @if (! empty($line['is_bonus']))
                                                        <span class="badge text-bg-info ms-1">Bonus</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if (! empty($line['sdidpotongstok']))
                                                <div class="small text-info mt-1">
                                                    <i class="fas fa-boxes-packing me-1"></i>Paket: {{ $line['paket_label'] ?? '—' }}
                                                    @if (! empty($line['sddaripaket']))
                                                        &middot; No {{ $line['sdcatatankoli'] }} &middot; kedatangan ke-{{ $line['sdkedatangan'] }}
                                                        @if ((float) $line['harga'] === 0.0)
                                                            <span class="badge text-bg-info ms-1">Gratis - Tarik Paket</span>
                                                        @endif
                                                    @endif
                                                </div>
                                            @endif
                                            @if (in_array((int) ($line['jenisitem'] ?? -1), [1, 4, 6, 10], true))
                                                @if ($line['sdkaryawan'] || $line['sddokter'])
                                                    <div class="small text-muted mt-1 lh-sm">
                                                        <div><i class="fas fa-user-nurse me-1 text-secondary"></i>{{ $line['sdkaryawan_label'] ?: '—' }}</div>
                                                        <div><i class="fas fa-user-doctor me-1 text-secondary"></i>{{ $line['sddokter_label'] ?: '—' }}</div>
                                                        @if ($line['sdnoref'] || $line['sdlantai2'])
                                                            <div>Ref {{ $line['sdnoref'] ?: '—' }} &middot; IC {{ $line['sdlantai2'] ?: '—' }}</div>
                                                        @endif
                                                    </div>
                                                    <a href="#" class="small" wire:click.prevent="editOperatorDokter({{ $i }})">
                                                        <i class="fas fa-pen small"></i> ubah
                                                    </a>
                                                @else
                                                    <div class="small text-danger mt-1">
                                                        <i class="fas fa-triangle-exclamation me-1"></i>Operator/Dokter belum diisi
                                                    </div>
                                                    <a href="#" class="small" wire:click.prevent="editOperatorDokter({{ $i }})">isi sekarang</a>
                                                @endif
                                            @endif
                                        </td>
                                        <td>
                                            <input type="number" min="0" step="1" class="form-control form-control-sm text-center"
                                                   wire:model.live.debounce.400ms="cart.{{ $i }}.qty" onkeydown="posFieldKey(event)">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn btn-sm py-0 px-1 {{ ! empty($unlockedLines[$i]) ? 'btn-outline-success' : 'btn-outline-secondary' }}"
                                                        wire:click="{{ ! empty($unlockedLines[$i]) ? 'lockLine(' . $i . ')' : 'openUnlockModal(' . $i . ')' }}"
                                                        title="{{ ! empty($unlockedLines[$i]) ? 'Terbuka - klik utk kunci lagi' : 'Terkunci - klik utk buka pakai password' }}">
                                                    <i class="fas {{ ! empty($unlockedLines[$i]) ? 'fa-unlock' : 'fa-lock' }}"></i>
                                                </button>
                                                <input type="number" min="0" class="form-control form-control-sm text-end"
                                                       wire:model.live.debounce.500ms="cart.{{ $i }}.harga" onkeydown="posFieldKey(event)"
                                                       @disabled(empty($unlockedLines[$i]))>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" min="0" max="100" class="form-control form-control-sm text-center"
                                                   wire:model.live.debounce.400ms="cart.{{ $i }}.dis1" onkeydown="posFieldKey(event)"
                                                   @disabled(empty($unlockedLines[$i]))>
                                        </td>
                                        <td>
                                            <input type="number" min="0" max="100" class="form-control form-control-sm text-center"
                                                   wire:model.live.debounce.400ms="cart.{{ $i }}.dis2" onkeydown="posFieldKey(event)"
                                                   @disabled(empty($unlockedLines[$i]))>
                                        </td>
                                        <td class="text-end">{{ number_format($lineTotals[$i] ?? 0, 0, ',', '.') }}</td>
                                        <td class="text-center">
                                            <button class="btn btn-outline-danger btn-sm py-0 px-1" data-remove-line wire:click="removeLine({{ $i }})"
                                                    title="Hapus baris (Ctrl+Delete)">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-5">Keranjang kosong — tekan <kbd>F2</kbd> untuk cari item.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (count($cart))
                        <div class="card-footer d-flex justify-content-between">
                            <button class="btn btn-outline-secondary btn-sm" wire:click="clearCart"
                                    data-confirm="Kosongkan keranjang?">Kosongkan</button>
                            <span class="text-muted small">{{ count($cart) }} baris</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ============ KANAN: pelanggan + bayar ============ --}}
            <div class="col-lg-4">
                {{-- Pelanggan --}}
                <div class="card mb-3">
                    <div class="card-body">
                        @if ($custId)
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold">{{ $custLabel }}</div>
                                    <div class="small {{ $memberActive ? 'text-success' : 'text-muted' }}">
                                        <i class="fas fa-id-card me-1"></i>{{ $memberInfo }}
                                    </div>
                                </div>
                                {{-- Langsung buka pencarian (permintaan user 2026-09-29), bukan
                                     `clearCustomer()` yg memaksa 2 langkah. Efek samping yg
                                     justru lebih baik: batal mencari = pelanggan lama TETAP
                                     terpilih, dulu sudah terlanjur terhapus. Voucher/DP milik
                                     pelanggan lama tetap dibersihkan oleh `pickCustomer()`
                                     begitu ada pelanggan baru yg dipilih. --}}
                                <button class="btn btn-sm btn-outline-secondary" wire:click="openCustModal">Ganti</button>
                            </div>
                        @else
                            <button type="button" class="btn btn-outline-danger btn-sm w-100 text-start" wire:click="openCustModal">
                                <i class="fas fa-user me-1"></i> Cari pelanggan <span class="text-danger">*</span> (wajib)
                                <kbd class="float-end">F1</kbd>
                            </button>
                        @endif
                        @error('cust') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

                        {{-- Catatan Rekam Medis -> fstoku.SUREKAMMEDIS. WAJIB, diperiksa saat
                             Simpan Transaksi (permintaan user 2026-09-29). Bukan field baru:
                             kolomnya terisi di 99,97% transaksi POS produksi. --}}
                        <div class="mt-3">
                            <label class="form-label small fw-semibold mb-1" for="pos-rekam-medis">
                                Catatan Rekam Medis <span class="text-danger">*</span>
                            </label>
                            {{-- Isi awal HARUS dicetak di antara tag: Livewire tidak mengisi
                                 sendiri `value` <textarea> saat render pertama, jadi tanpa ini
                                 catatan lama tampak KOSONG waktu transaksi dibuka lewat Edit.
                                 `.live.debounce` (bukan `.blur`) supaya isian sudah tersinkron
                                 saat tombol Simpan diklik - ini field WAJIB, satu ketikan yg
                                 belum terkirim akan jadi penolakan palsu. --}}
                            <textarea id="pos-rekam-medis" rows="2" maxlength="255"
                                      class="form-control form-control-sm @error('rekamMedis') is-invalid @enderror"
                                      placeholder="wajib diisi…"
                                      wire:model.live.debounce.400ms="rekamMedis">{{ $rekamMedis }}</textarea>
                            @error('rekamMedis')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- "Harga Khusus" & "Daftar Paket" (caption diganti dari "Cari Promo"/"Cari Paket"
                     atas permintaan user 2026-09-30; nama variabel & tabelnya TETAP promo/paket).
                     Daftar Paket DI BAWAH Harga Khusus (permintaan user 2026-09-28) - dipindah
                     dari toolbar header kiri supaya kedua pencarian "non-item" berkumpul di satu
                     tempat. Pemicunya (F10/F11) global di <div> paling luar. --}}
                <div class="card mb-3">
                    <div class="card-body">
                        @if ($promos !== [])
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                @foreach ($promos as $i => $kode)
                                    <span class="badge text-bg-success">
                                        <i class="fas fa-tag me-1"></i>{{ $kode }}
                                        <a href="#" class="text-white ms-1" wire:click.prevent="removePromo({{ $i }})"
                                           title="Hapus dari daftar label (baris keranjang yg sudah pakai promo ini TIDAK ikut terhapus)">
                                            <i class="fas fa-xmark"></i>
                                        </a>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        <button type="button" class="btn btn-outline-success btn-sm w-100 text-start" wire:click="openPromoModal">
                            <i class="fas fa-tag me-1"></i> Harga Khusus{{ $promos !== [] ? ' (tambah lagi)' : ' (opsional)' }}
                            <kbd class="float-end">F10</kbd>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start mt-2"
                                wire:click="openPaketModal">
                            <i class="fas fa-boxes-packing me-1"></i> Daftar Paket
                            <kbd class="float-end">F11</kbd>
                        </button>
                    </div>
                </div>

                {{-- Total --}}
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total</span>
                            <span class="fs-3 fw-bold">{{ number_format($grand, 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Pembayaran - RINGKASAN saja. Isiannya pindah ke dialog (lihat modal di bawah),
                     mengikuti alur VB6: susun keranjang dulu, baru buka dialog bayar. --}}
                <div class="card mb-3">
                    <div class="card-header py-2 small fw-semibold">Pembayaran</div>
                    <div class="card-body">
                        @php
                            $rincianBayar = collect([
                                'Tunai'        => (float) $pay['tunai'],
                                'Kartu Debit'  => (float) $pay['debit']['jumlah'],
                                'Kartu Kredit' => (float) $pay['kredit']['jumlah'],
                                'Transfer'     => (float) $pay['transfer']['jumlah'],
                                'Merchant'     => (float) $pay['merchant']['jumlah'],
                                'Voucher'      => (float) $pay['voucher']['jumlah'],
                                'DP'           => (float) $pay['dp']['jumlah'],
                            ])->filter(fn ($v) => $v > 0);
                        @endphp

                        @forelse ($rincianBayar as $label => $nilai)
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">{{ $label }}</span>
                                <span>{{ number_format($nilai, 0, ',', '.') }}</span>
                            </div>
                        @empty
                            <p class="small text-muted mb-0">Belum ada pembayaran.</p>
                        @endforelse

                        @if ($rincianBayar->isNotEmpty())
                            <hr class="my-2">
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Dibayar</span>
                                <span>{{ number_format($bayar, 0, ',', '.') }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">{{ $kurang > 0 ? 'Kurang' : 'Kembalian' }}</span>
                                <span class="fw-semibold {{ $kurang > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format(abs($kurang), 0, ',', '.') }}
                                </span>
                            </div>
                        @endif

                        {{-- Kesalahan yg muncul SEBELUM dialog dibuka (keranjang kosong / pelanggan
                             belum dipilih / cabang tidak valid). Kesalahan isian bayar sendiri
                             tampil di dalam dialog, di sebelah kolomnya. --}}
                        @error('cart') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        @error('pay') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        @error('branch') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- DUA tombol terpisah (permintaan user 2026-09-28): mengisi pembayaran dan
                     MENYIMPAN transaksi bukan lagi satu tindakan. Dialog bayar hanya mengisi;
                     yang menyimpan cuma tombol di bawahnya. --}}
                <button class="btn btn-outline-primary w-100 mb-2" wire:click="openPayModal"
                        @disabled(count($cart) === 0 || ! $custId)>
                    <i class="fas fa-money-bill-wave me-1"></i>
                    {{ $bayar > 0 ? 'Ubah Pembayaran' : 'Isi Pembayaran' }}
                    <kbd class="ms-1">F8</kbd>
                </button>

                <button class="btn btn-success btn-lg w-100" wire:click="checkout"
                        wire:loading.attr="disabled" wire:target="checkout"
                        @disabled(count($cart) === 0 || ! $custId)>
                    <span wire:loading wire:target="checkout" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="fas fa-cash-register me-1"></i> Simpan Transaksi
                    <kbd class="ms-1">Ctrl+Enter</kbd>
                </button>
            </div>
        </div>

        {{-- ============ MODAL PEMBAYARAN (F8) ============
             Tata letak DUA KOLOM mengikuti dialog Pembayaran VB6 (screenshot user 2026-09-28).
             Jenis bayarnya PERSIS yg sudah ada - tidak ada yg ditambah: kiri tunai + dua kartu,
             kanan transfer/DP/merchant/voucher. Field & `wire:model` sama persis dgn panel lama,
             jadi perilaku & validasinya tidak berubah, hanya tempatnya.
             Dialog ini HANYA MENGISI pembayaran - tidak ada jalur simpan transaksi di dalamnya
             (tombol OK -> `simpanPembayaran()`). Dialog tidak ditutup kalau isiannya salah. --}}
        @if ($showPayModal)
            {{-- F12 & Esc hanya hidup selama dialog terbuka (elemen ini memang cuma ada saat itu).
                 F1-F7 TIDAK dipasang di sini - lihat catatan di <div> root: pengikatnya
                 satu per tombol supaya tidak jalan dobel dgn pintasan global. --}}
            <div wire:key="pay-modal" x-data
                 @fokus-bayar.window="$nextTick(() => {
                     const ref = { tunai: 'bayarTunai', debit: 'bayarDebitNo', kredit: 'bayarKreditNo',
                                   transfer: 'bayarTransferNo', merchant: 'bayarMerchantNo' }[$event.detail.key];
                     if (ref && $refs[ref]) { $refs[ref].focus(); $refs[ref].select?.(); }
                 })"
                 {{-- Escape SENGAJA TIDAK ditutupkan ke dialog ini (permintaan user 2026-09-28):
                      isian bayar terlalu mahal untuk hilang gara-gara Esc kepencet. Menutupnya
                      lewat tombol Batal / (x) saja. Esc TETAP menutup sub-dialog di atasnya
                      (cari DP/voucher) - pengikatnya ada di badan masing-masing modal dan
                      BUKAN `.window`, jadi tidak saling mengganggu. --}}
                 wire:keydown.f12.window.prevent="simpanPembayaran">
            <x-lw-modal :show="$showPayModal" size="lg" title="Pembayaran" close="closePayModal">
                <div class="modal-body">
                    <p class="small text-muted border-bottom pb-2 mb-3">
                        <kbd>F1</kbd> Tunai &middot; <kbd>F2</kbd> Debit &middot; <kbd>F3</kbd> Kredit &middot;
                        <kbd>F4</kbd> Transfer &middot; <kbd>F6</kbd> Merchant —
                        <strong>mengosongkan semua nilai bayar</strong> lalu mengisi penuh di kolom itu.<br>
                        <kbd>F5</kbd> DP &middot; <kbd>F7</kbd> Voucher — <strong>membuka pilihannya</strong>
                        (nilainya dari saldo yang dipilih, metode lain tidak dikosongkan).<br>
                        <kbd>Ctrl</kbd>+<kbd>Enter</kbd> = OK. <strong>OK hanya mengisi pembayaran,
                        transaksi belum tersimpan</strong> — simpan lewat tombol
                        <em>Simpan Transaksi</em> di layar utama. <kbd>Esc</kbd> tidak menutup dialog.
                    </p>
                    <div class="row g-3">
                        {{-- ---------- KOLOM KIRI: tunai + kartu ---------- --}}
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label small mb-1 fw-semibold">Tunai <kbd>F1</kbd></label>
                                <input type="number" min="0" class="form-control text-end" x-ref="bayarTunai"
                                       wire:model.live.debounce.400ms="pay.tunai">
                            </div>

                            @foreach (['debit' => ['Kartu Debit', 'F2'], 'kredit' => ['Kartu Kredit', 'F3']] as $key => [$label, $tombol])
                                <div class="border rounded p-2 mb-3">
                                    <div class="small fw-semibold mb-1">{{ $label }} <kbd>{{ $tombol }}</kbd></div>
                                    <input type="number" min="0" class="form-control form-control-sm text-end mb-1"
                                           placeholder="jumlah" wire:model.live.debounce.400ms="pay.{{ $key }}.jumlah">
                                    <div class="row g-1">
                                        <div class="col-12">
                                            <input type="text" class="form-control form-control-sm @error("pay.{$key}.no") is-invalid @enderror"
                                                   x-ref="bayar{{ ucfirst($key) }}No"
                                                   placeholder="no. kartu/ref" wire:model="pay.{{ $key }}.no">
                                            @error("pay.{$key}.no") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12">
                                            <select class="form-select form-select-sm @error("pay.{$key}.bank") is-invalid @enderror"
                                                    wire:model="pay.{{ $key }}.bank">
                                                <option value="">bank…</option>
                                                @foreach ($banks as $bk)
                                                    <option value="{{ $bk->BID }}">{{ $bk->BNAMA }}</option>
                                                @endforeach
                                            </select>
                                            @error("pay.{$key}.bank") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12">
                                            <input type="text" class="form-control form-control-sm" placeholder="nama di kartu"
                                                   wire:model="pay.{{ $key }}.nama">
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm" wire:model="pay.{{ $key }}.jenis">
                                                <option value="">jenis kartu…</option>
                                                @foreach (($key === 'debit' ? $jenisKartuDebit : $jenisKartuKredit) as $jk)
                                                    <option value="{{ $jk }}">{{ $jk }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" class="form-control form-control-sm" placeholder="bank lain…"
                                                   wire:model="pay.{{ $key }}.bank_lain">
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- ---------- KOLOM KANAN: transfer, DP, merchant, voucher ---------- --}}
                        <div class="col-md-6">
                            <div class="border rounded p-2 mb-3">
                                <div class="small fw-semibold mb-1">Transfer <kbd>F4</kbd></div>
                                <input type="number" min="0" class="form-control form-control-sm text-end mb-1"
                                       placeholder="jumlah" wire:model.live.debounce.400ms="pay.transfer.jumlah">
                                <div class="row g-1">
                                    <div class="col-12">
                                        <input type="text" class="form-control form-control-sm @error('pay.transfer.no') is-invalid @enderror"
                                               x-ref="bayarTransferNo"
                                               placeholder="no. ref" wire:model="pay.transfer.no">
                                        @error('pay.transfer.no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-12">
                                        <select class="form-select form-select-sm @error('pay.transfer.bank') is-invalid @enderror"
                                                wire:model="pay.transfer.bank">
                                            <option value="">bank…</option>
                                            @foreach ($banks as $bk)
                                                <option value="{{ $bk->BID }}">{{ $bk->BNAMA }}</option>
                                            @endforeach
                                        </select>
                                        @error('pay.transfer.bank') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-12">
                                        <input type="text" class="form-control form-control-sm" placeholder="nama pengirim"
                                               wire:model="pay.transfer.nama">
                                    </div>
                                </div>
                            </div>

                            <div class="border rounded p-2 mb-3">
                                {{-- F6 tetap membuka rincian DP dari dalam dialog ini (tidak bentrok
                                     dgn pintasan bayar), baik saat DP belum dipilih maupun saat
                                     mau menggantinya - karena pengikatnya di <div> root. --}}
                                <span class="small fw-semibold">DP <kbd>F5</kbd></span>
                                @if ($pay['dp']['sdid'])
                                    <div class="d-flex justify-content-between align-items-start border rounded p-2 my-1">
                                        <div class="small">
                                            <div class="fw-semibold">No. {{ $pay['dp']['no'] }}</div>
                                            <div class="text-muted">{{ $pay['dp']['nama_item'] }}</div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="openDpModal">Ganti</button>
                                    </div>
                                    <input type="number" min="0" class="form-control form-control-sm text-end mb-1 @error('pay.dp.jumlah') is-invalid @enderror"
                                           placeholder="nilai DP" wire:model.live.debounce.400ms="pay.dp.jumlah">
                                    @error('pay.dp.jumlah') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <select class="form-select form-select-sm" wire:model="pay.dp.jenis">
                                        <option value="">jenis DP…</option>
                                        @foreach ($merchants as $mc)
                                            <option value="{{ $mc->MCKODE }}">{{ $mc->MCNAMA }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100 text-start mt-1" wire:click="openDpModal">
                                        <i class="fas fa-money-bill-wave me-1"></i> Cari DP pelanggan…
                                        <kbd class="float-end">F5</kbd>
                                    </button>
                                    @error('pay.dp.jumlah') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                @endif
                            </div>

                            <div class="border rounded p-2 mb-3">
                                <div class="small fw-semibold mb-1">Merchant <kbd>F6</kbd></div>
                                <input type="number" min="0" class="form-control form-control-sm text-end mb-1"
                                       placeholder="nilai" wire:model.live.debounce.400ms="pay.merchant.jumlah">
                                <div class="row g-1">
                                    <div class="col-12">
                                        <input type="text" class="form-control form-control-sm" placeholder="no. merchant"
                                               x-ref="bayarMerchantNo" wire:model="pay.merchant.no">
                                    </div>
                                    <div class="col-12">
                                        <select class="form-select form-select-sm" wire:model="pay.merchant.jenis">
                                            <option value="">jenis merchant…</option>
                                            @foreach ($merchants as $mc)
                                                <option value="{{ $mc->MCKODE }}">{{ $mc->MCNAMA }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="border rounded p-2">
                                <span class="small fw-semibold">Voucher <kbd>F7</kbd></span>
                                @if ($pay['voucher']['vid'])
                                    <div class="d-flex justify-content-between align-items-start border rounded p-2 my-1">
                                        <div class="small">
                                            <div class="fw-semibold">No. {{ $pay['voucher']['no'] }}</div>
                                            <div class="text-muted">{{ $pay['voucher']['program'] ?: '—' }} &middot; {{ $pay['voucher']['nama'] }}</div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearVoucher">Ganti</button>
                                    </div>
                                    <input type="number" min="0" class="form-control form-control-sm text-end @error('pay.voucher.jumlah') is-invalid @enderror"
                                           placeholder="jumlah dipakai" wire:model.live.debounce.400ms="pay.voucher.jumlah">
                                    @error('pay.voucher.jumlah') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @else
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100 text-start mt-1" wire:click="openVoucherModal">
                                        <i class="fas fa-ticket me-1"></i> Cari voucher pelanggan…
                                        <kbd class="float-end">F7</kbd>
                                    </button>
                                    @error('pay.voucher.jumlah') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Ringkasan bawah - istilahnya mengikuti dialog VB6: Sub Total / Total Bayar / Sisa --}}
                    <hr class="my-3">
                    <div class="row">
                        <div class="col-md-6 ms-auto">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Sub Total</span>
                                <span>{{ number_format($grand, 0, ',', '.') }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Total Bayar</span>
                                <span>{{ number_format($bayar, 0, ',', '.') }}</span>
                            </div>
                            <div class="d-flex justify-content-between fs-5 fw-bold">
                                <span>{{ $kurang > 0 ? 'Sisa' : 'Kembalian' }}</span>
                                <span class="{{ $kurang > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format(abs($kurang), 0, ',', '.') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    @error('pay') <div class="alert alert-danger py-2 small mt-2 mb-0">{{ $message }}</div> @enderror
                    @error('cart') <div class="alert alert-danger py-2 small mt-2 mb-0">{{ $message }}</div> @enderror
                    @error('cust') <div class="alert alert-danger py-2 small mt-2 mb-0">{{ $message }}</div> @enderror
                    @error('branch') <div class="alert alert-danger py-2 small mt-2 mb-0">{{ $message }}</div> @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closePayModal">
                        Batal <span class="small opacity-75">(kembalikan seperti semula)</span>
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="simpanPembayaran">
                        <i class="fas fa-check me-1"></i> OK
                        <kbd class="ms-1">Ctrl+Enter</kbd>
                    </button>
                </div>
            </x-lw-modal>
            </div>
        @endif

        {{-- ============ MODAL CARI ITEM (F2) ============ --}}
        @if ($showItemModal)
            <x-lw-modal :show="$showItemModal" title="Cari Item" close="closeItemModal">
                <div class="modal-body" wire:key="item-modal-body" x-data
                     x-init="$nextTick(() => $refs.itemSearch.focus())"
                     @refocus-item.window="$nextTick(() => $refs.itemSearch.focus())">
                    <input type="text" class="form-control mb-2" x-ref="itemSearch" wire:key="item-search-input"
                           placeholder="ketik kode / nama / barcode…" autocomplete="off"
                           wire:model.live.debounce.200ms="itemQ"
                           wire:keydown.arrow-down.prevent="moveItemHighlight(1)"
                           wire:keydown.arrow-up.prevent="moveItemHighlight(-1)"
                           wire:keydown.enter.prevent="pickItemHighlighted"
                           wire:keydown.escape="closeItemModal">
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                @forelse ($itemResults as $i => $r)
                                    @php $habis = (int) $r->tipe === 0 && (float) $r->stok <= 0; @endphp
                                    <tr wire:key="ir-{{ $r->id }}"
                                        class="{{ $i === $itemHighlight ? 'table-active' : '' }} {{ $habis ? 'text-muted' : '' }}"
                                        style="cursor: {{ $habis ? 'not-allowed' : 'pointer' }}"
                                        @if (! $habis) wire:click="pickItemById({{ $r->id }})" @endif>
                                        <td>
                                            <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
                                        </td>
                                        <td class="text-end text-nowrap">
                                            {{ number_format((float) $r->harga, 0, ',', '.') }}
                                            @if ((int) $r->tipe === 1)
                                                <span class="badge text-bg-light ms-1">non-stok</span>
                                            @elseif ($habis)
                                                <span class="badge text-bg-danger ms-1">stok 0</span>
                                            @else
                                                <span class="badge text-bg-secondary ms-1">stok {{ rtrim(rtrim(number_format((float) $r->stok, 2), '0'), '.') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-4">
                                        {{ trim($itemQ) === '' ? 'Ketik untuk mencari item…' : 'Tidak ada hasil.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> tambah &amp; cari lagi &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeItemModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL CARI PELANGGAN (F1) ============ --}}
        @if ($showCustModal)
            <x-lw-modal :show="$showCustModal" title="Cari Pelanggan / Pasien" close="closeCustModal">
                <div class="modal-body" wire:key="cust-modal-body" x-data
                     x-init="$nextTick(() => $refs.custSearch.focus())"
                     @refocus-cust.window="$nextTick(() => $refs.custSearch.focus())">
                    <input type="text" class="form-control mb-2" x-ref="custSearch" wire:key="cust-search-input"
                           placeholder="nama / kode / member / telp…" autocomplete="off"
                           wire:model.live.debounce.300ms="custQ"
                           wire:keydown.arrow-down.prevent="moveCustHighlight(1)"
                           wire:keydown.arrow-up.prevent="moveCustHighlight(-1)"
                           wire:keydown.enter.prevent="pickCustHighlighted"
                           wire:keydown.escape="closeCustModal">
                    <div class="table-responsive" style="max-height: 320px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Nama</th>
                                    <th>Tipe</th>
                                    <th>Cabang</th>
                                    <th>Telp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($custResults as $i => $c)
                                    <tr wire:key="cr-{{ $c->id }}"
                                        class="{{ $i === $custHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickCustomer({{ $c->id }})">
                                        <td class="fw-semibold">{{ $c->kode }}</td>
                                        <td>{{ $c->nama }}</td>
                                        <td class="text-muted small">{{ $c->tipe }}</td>
                                        <td class="text-muted small">{{ $c->cabang ?: '—' }}</td>
                                        <td class="text-muted small">{{ $c->telp ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">
                                        {{ mb_strlen(trim($custQ)) < 2 ? 'Ketik minimal 2 huruf…' : 'Tidak ada hasil.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeCustModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL PILIH OPERATOR (item tindakan/perawatan) ============ --}}
        @if ($showOperatorModal)
            <x-lw-modal :show="$showOperatorModal" title="Pilih Operator" close="closeOperatorModal">
                <div class="modal-body" wire:key="od-modal-body-operator" x-data
                     x-init="$nextTick(() => $refs.odSearch.focus())"
                     @refocus-od.window="$nextTick(() => $refs.odSearch.focus())">
                    @if ($odLine !== null && isset($cart[$odLine]))
                        <div class="small text-muted mb-2">
                            Item: <span class="fw-semibold">{{ $cart[$odLine]['nama'] }}</span>
                            @if (count($odQueue) > 0)
                                &middot; {{ count($odQueue) }} baris lagi menyusul
                            @endif
                        </div>
                    @endif
                    <input type="text" class="form-control mb-2" x-ref="odSearch" wire:key="od-search-input-operator"
                           placeholder="ketik nama / kode operator…" autocomplete="off"
                           wire:model.live.debounce.250ms="odQ"
                           wire:keydown.arrow-down.prevent="moveOdHighlight(1)"
                           wire:keydown.arrow-up.prevent="moveOdHighlight(-1)"
                           wire:keydown.enter.prevent="pickOdHighlighted"
                           wire:keydown.escape="closeOperatorModal">
                    <div class="table-responsive" style="max-height: 320px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                @forelse ($operatorResults as $i => $r)
                                    <tr wire:key="or-{{ $r->id }}"
                                        class="{{ $i === $odHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickOperator({{ $r->id }})">
                                        <td><span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-4">
                                        {{ mb_strlen(trim($odQ)) < 2 ? 'Ketik minimal 2 huruf…' : 'Tidak ada hasil.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> lewati
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeOperatorModal">Lewati</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL PILIH DOKTER ============ --}}
        @if ($showDokterModal)
            <x-lw-modal :show="$showDokterModal" title="Pilih Dokter" close="closeDokterModal">
                <div class="modal-body" wire:key="od-modal-body-dokter" x-data
                     x-init="$nextTick(() => $refs.odSearch.focus())"
                     @refocus-od.window="$nextTick(() => $refs.odSearch.focus())">
                    @if ($odLine !== null && isset($cart[$odLine]))
                        <div class="small text-muted mb-2">
                            Item: <span class="fw-semibold">{{ $cart[$odLine]['nama'] }}</span>
                            @if (count($odQueue) > 0)
                                &middot; {{ count($odQueue) }} baris lagi menyusul
                            @endif
                        </div>
                    @endif
                    <input type="text" class="form-control mb-2" x-ref="odSearch" wire:key="od-search-input-dokter"
                           placeholder="ketik nama / kode dokter…" autocomplete="off"
                           wire:model.live.debounce.250ms="odQ"
                           wire:keydown.arrow-down.prevent="moveOdHighlight(1)"
                           wire:keydown.arrow-up.prevent="moveOdHighlight(-1)"
                           wire:keydown.enter.prevent="pickOdHighlighted"
                           wire:keydown.escape="closeDokterModal">
                    <div class="table-responsive" style="max-height: 320px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                @forelse ($dokterResults as $i => $r)
                                    <tr wire:key="dr-{{ $r->id }}"
                                        class="{{ $i === $odHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickDokter({{ $r->id }})">
                                        <td><span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-4">
                                        {{ mb_strlen(trim($odQ)) < 2 ? 'Ketik minimal 2 huruf…' : 'Tidak ada hasil.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> lewati
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeDokterModal">Lewati</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL NO REF / NO IC (wajib diisi, tidak bisa dilewati) ============ --}}
        @if ($showRefModal)
            <x-lw-modal :show="$showRefModal" title="No Ref / No IC" :closable="false">
                <div class="modal-body" wire:key="ref-modal-body" x-data
                     x-init="$nextTick(() => $refs.refNo.focus())">
                    @if ($odLine !== null && isset($cart[$odLine]))
                        <div class="small text-muted mb-1">
                            Item: <span class="fw-semibold">{{ $cart[$odLine]['nama'] }}</span>
                            @if (count($odQueue) > 0)
                                &middot; {{ count($odQueue) }} baris lagi menyusul
                            @endif
                        </div>
                    @endif
                    <div class="text-muted small mb-2">Kedua isian wajib dilengkapi sebelum lanjut.</div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">No Ref</label>
                        <input type="text" class="form-control @error('refNo') is-invalid @enderror"
                               x-ref="refNo" wire:key="ref-no-input"
                               wire:model="refNo" @keydown.enter.prevent="$nextTick(() => $refs.refIc.focus())">
                        @error('refNo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">No IC</label>
                        <input type="text" class="form-control @error('refIc') is-invalid @enderror"
                               x-ref="refIc" wire:key="ref-ic-input"
                               wire:model="refIc" wire:keydown.enter.prevent="saveRef">
                        @error('refIc') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" wire:click="saveRef">Simpan</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL BUKA KUNCI HARGA & DISKON (re-auth username+password) ============ --}}
        @if ($showUnlockModal)
            <x-lw-modal :show="$showUnlockModal" title="Buka Kunci Harga & Diskon" close="closeUnlockModal">
                <form wire:submit="submitUnlock" autocomplete="off">
                    <div class="modal-body" wire:key="unlock-modal-body" x-data
                         x-init="$nextTick(() => $refs.unlockUser.focus())">
                        @if ($unlockLine !== null && isset($cart[$unlockLine]))
                            <div class="small text-muted mb-2">
                                Baris: <span class="fw-semibold">{{ $cart[$unlockLine]['nama'] }}</span>
                            </div>
                        @endif
                        <div class="text-muted small mb-2">
                            Harga Jual, Disc 1, dan Disc 2 baris ini terkunci - masukkan username &amp; password user
                            yang punya hak "Approve" di menu Kasir/POS utk membukanya.
                        </div>
                        @error('unlock') <div class="alert alert-danger py-2 small mb-2">{{ $message }}</div> @enderror
                        {{-- readonly-sampai-fokus + autocomplete non-standar ("off" sering diabaikan browser
                             modern utk pasangan username+password) - cegah browser auto-isi kredensial
                             tersimpan begitu modal muncul, WAJIB kosong sampai user benar2 mengetik. --}}
                        <div class="mb-2">
                            <label class="form-label small mb-1">Username</label>
                            <input type="text" class="form-control" x-ref="unlockUser" wire:key="unlock-user-input"
                                   wire:model="unlockUser" autocomplete="off" readonly onfocus="this.removeAttribute('readonly')"
                                   @keydown.enter.prevent="$nextTick(() => $refs.unlockPassword.focus())">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Password</label>
                            <input type="password" class="form-control" x-ref="unlockPassword" wire:key="unlock-password-input"
                                   wire:model="unlockPassword" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly')"
                                   wire:keydown.enter.prevent="submitUnlock">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeUnlockModal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-unlock me-1"></i> Buka Kunci</button>
                    </div>
                </form>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL RIWAYAT HARI INI (cetak ulang / edit) ============ --}}
        @if ($showHistoryModal)
            <x-lw-modal :show="$showHistoryModal" title="Riwayat Transaksi Hari Ini" size="lg" close="closeHistoryModal">
                <div class="modal-body">
                    <div class="text-muted small mb-2">
                        Transaksi POS yang Anda input hari ini. <strong>Edit</strong> memuat ulang isiannya ke keranjang —
                        transaksi lama tetap AKTIF sampai Anda menyimpan lagi, baru saat itu dibatalkan (stok &amp; jurnal ikut kebalik).
                    </div>
                    <div class="table-responsive" style="max-height: 420px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Waktu</th>
                                    <th>Pelanggan</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($historyResults as $h)
                                    <tr wire:key="hist-{{ $h->SUID }}">
                                        <td class="fw-semibold">{{ $h->SUNOTRANSAKSI }}</td>
                                        <td class="text-muted small">{{ \Illuminate\Support\Carbon::parse($h->SUCREATED)->format('H:i') }}</td>
                                        <td>{{ $h->pelanggan ?: '—' }}</td>
                                        <td class="text-end">{{ number_format((float) $h->SUTOTALTRANSAKSI, 0, ',', '.') }}</td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('sales.pos.receipt', $h->SUID) }}" target="_blank"
                                               class="btn btn-outline-secondary btn-sm" title="Cetak">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-warning btn-sm" title="Edit"
                                                    wire:click="editTransaction({{ $h->SUID }})"
                                                    data-confirm="Transaksi {{ $h->SUNOTRANSAKSI }} akan dimuat ke keranjang untuk diedit (transaksi lama tetap aktif sampai Anda menyimpan lagi). Keranjang saat ini akan digantikan. Lanjutkan?">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">Belum ada transaksi hari ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeHistoryModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL DATA LAINNYA (field header fstoku tambahan) ============ --}}
        @if ($showOtherDataModal)
            <x-lw-modal :show="$showOtherDataModal" title="Data Lainnya" close="closeOtherDataModal">
                <div class="modal-body">
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Catatan</label>
                        <div class="col-8"><input type="text" class="form-control form-control-sm" wire:model="catatan"></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Training</label>
                        <div class="col-8"><x-search-select model="suTraining" :value="$suTraining" :selected-text="$suTrainingLabel" :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" /></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Farmasi</label>
                        <div class="col-8"><x-search-select model="suFarmasi" :value="$suFarmasi" :selected-text="$suFarmasiLabel" :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" /></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Farmasi Asisten</label>
                        <div class="col-8"><x-search-select model="suFarmasiAsisten" :value="$suFarmasiAsisten" :selected-text="$suFarmasiAsistenLabel" :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" /></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Sales Marketing</label>
                        <div class="col-8"><x-search-select model="suSalesMarketing" :value="$suSalesMarketing" :selected-text="$suSalesMarketingLabel" :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" /></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Klinik Luar</label>
                        <div class="col-8"><x-search-select model="suKlinikLain" :value="$suKlinikLain" :selected-text="$suKlinikLainLabel" :endpoint="route('lookup.kontak')" placeholder="cari kontak…" /></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Kode Tele</label>
                        <div class="col-8"><input type="text" class="form-control form-control-sm" wire:model="suKodeTele"></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">ID Medlib</label>
                        <div class="col-8"><input type="text" class="form-control form-control-sm" value="{{ $suIdMedlib }}" disabled></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">ID PRO</label>
                        <div class="col-8"><input type="text" class="form-control form-control-sm" value="{{ $suLmcId }}" disabled></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Review Nilai</label>
                        <div class="col-8"><input type="number" step="0.01" class="form-control form-control-sm text-end" wire:model="suReviewNilai"></div>
                    </div>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-4 col-form-label small">Review Catatan</label>
                        <div class="col-8"><input type="text" class="form-control form-control-sm" wire:model="suReviewCatatan"></div>
                    </div>
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-4"></div>
                        <div class="col-8">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pos-od-konsulsaja" wire:model="suKonsulSaja">
                                <label class="form-check-label small" for="pos-od-konsulsaja">Konsul Saja</label>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="text-muted small text-uppercase mb-2">Tambahan</div>
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-4"></div>
                        <div class="col-8">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pos-od-konsulkemarin" wire:model="suKonsulKemarin">
                                <label class="form-check-label small" for="pos-od-konsulkemarin">Konsul Kemarin</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 align-items-center">
                        <label class="col-4 col-form-label small">Teman</label>
                        <div class="col-8"><x-search-select model="suTeman" :value="$suTeman" :selected-text="$suTemanLabel" :endpoint="route('lookup.kontak')" placeholder="cari kontak…" /></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" wire:click="closeOtherDataModal">OK</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL DAFTAR PAKET (F11) ============ --}}
        @if ($showPaketModal)
            <x-lw-modal :show="$showPaketModal" title="Daftar Paket" close="closePaketModal">
                <div class="modal-body" wire:key="paket-modal-body" x-data
                     x-init="$nextTick(() => $refs.paketSearch.focus())"
                     @refocus-paket.window="$nextTick(() => $refs.paketSearch.focus())">
                    <input type="text" class="form-control mb-2" x-ref="paketSearch" wire:key="paket-search-input"
                           placeholder="ketik kode / nama paket…" autocomplete="off"
                           wire:model.live.debounce.250ms="paketQ"
                           wire:keydown.arrow-down.prevent="movePaketHighlight(1)"
                           wire:keydown.arrow-up.prevent="movePaketHighlight(-1)"
                           wire:keydown.enter.prevent="pickPaketHighlighted"
                           wire:keydown.escape="closePaketModal">
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                @forelse ($paketResults as $i => $p)
                                    <tr wire:key="pkr-{{ $p->id }}"
                                        class="{{ $i === $paketHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickPaket({{ $p->id }})">
                                        <td>
                                            <span class="fw-semibold">{{ $p->kode }}</span> — {{ $p->nama }}
                                        </td>
                                        <td class="text-end text-nowrap">
                                            @if ((float) $p->jumlah > 1)
                                                <span class="badge text-bg-secondary">{{ (int) $p->jumlah }}x kedatangan</span>
                                            @else
                                                <span class="badge text-bg-light">sekali pakai</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-4">
                                        {{ mb_strlen(trim($paketQ)) < 2 ? 'Ketik minimal 2 huruf…' : 'Tidak ada hasil.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closePaketModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL NOMER PAKET (paket PUJUMLAH>1) ============ --}}
        @if ($showPaketNomorModal)
            <x-lw-modal :show="$showPaketNomorModal" title="Nomer Paket" close="closePaketNomorModal">
                <form wire:submit="lookupPaketInstance">
                    <div class="modal-body" wire:key="paket-nomor-modal-body" x-data
                         x-init="$nextTick(() => $refs.paketNomor.focus())">
                        <div class="small text-muted mb-2">
                            Paket: <span class="fw-semibold">{{ $paketKode }}</span>
                            @if ($paketNama && $paketNama !== $paketKode)
                                — {{ $paketNama }}
                            @endif
                        </div>
                        <div class="text-muted small mb-2">
                            Masukkan Nomer Paket dari tiket kunjungan (sama dgn yg diisi saat pertama beli, kalau
                            ini kunjungan lanjutan) - kosongkan/isi baru kalau ini pembelian pertama.
                        </div>
                        <label class="form-label small mb-1">Nomer Paket</label>
                        <input type="text" class="form-control @error('paket') is-invalid @enderror"
                               x-ref="paketNomor" wire:key="paket-nomor-input"
                               wire:model="paketNomor" wire:keydown.enter.prevent="lookupPaketInstance">
                        @error('paket') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closePaketNomorModal">Batal</button>
                        <button type="submit" class="btn btn-primary">Cek</button>
                    </div>
                </form>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL TARIK PAKET - checklist baris yg masih ada sisa kuota ============ --}}
        @if ($showPaketTarikModal)
            <x-lw-modal :show="$showPaketTarikModal" title="Tarik Paket" size="lg" close="closePaketTarikModal">
                <div class="modal-body" wire:key="paket-tarik-modal-body">
                    <div class="small text-muted mb-2">
                        Paket: <span class="fw-semibold">{{ $paketKode }}</span>
                        @if ($paketNama && $paketNama !== $paketKode)
                            — {{ $paketNama }}
                        @endif
                        &middot; Nomer {{ $paketNomor }} &middot; Pasien {{ $custLabel }}
                    </div>
                    <div class="text-muted small mb-2">
                        Centang item yang diambil hari ini (tidak harus semua). Item yang sudah habis kuotanya
                        tidak ditampilkan lagi.
                    </div>
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Item</th>
                                    <th class="text-center">Sisa Kuota</th>
                                    <th class="text-center">Kedatangan ke-</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($paketRows as $row)
                                    <tr wire:key="pkt-row-{{ $row['pdid'] }}">
                                        <td>
                                            <input type="checkbox" class="form-check-input"
                                                   wire:model="paketChecked.{{ $row['pdid'] }}">
                                        </td>
                                        <td><span class="fw-semibold">{{ $row['kode'] }}</span> — {{ $row['nama'] }}</td>
                                        <td class="text-center">{{ $row['sisa'] }}</td>
                                        <td class="text-center">{{ $row['kedatangan'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada item tersedia.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closePaketTarikModal">Batal</button>
                    <button type="button" class="btn btn-primary" wire:click="addPaketLines">
                        <i class="fas fa-plus me-1"></i> Tambahkan ke Keranjang
                    </button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL CARI VOUCHER (F5) - voucher milik pelanggan terpilih ============ --}}
        @if ($showVoucherModal)
            <x-lw-modal :show="$showVoucherModal" title="Voucher Pelanggan" close="closeVoucherModal">
                <div class="modal-body" wire:key="voucher-modal-body" x-data tabindex="-1"
                     x-init="$nextTick(() => $el.focus())"
                     wire:keydown.arrow-down.prevent="moveVoucherHighlight(1)"
                     wire:keydown.arrow-up.prevent="moveVoucherHighlight(-1)"
                     wire:keydown.enter.prevent="pickVoucherHighlighted"
                     wire:keydown.escape="closeVoucherModal">
                    <div class="text-muted small mb-2">Voucher milik <strong>{{ $custLabel }}</strong> yang masih ada sisa saldo.</div>
                    <div class="table-responsive" style="max-height: 320px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>No. Voucher</th>
                                    <th>Program</th>
                                    <th class="text-end">Sisa Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($voucherResults as $i => $v)
                                    <tr wire:key="vr-{{ $v->id }}"
                                        class="{{ $i === $voucherHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickVoucher({{ $v->id }})">
                                        <td class="fw-semibold">{{ $v->nomor }}</td>
                                        <td class="text-muted small">{{ $v->program ?: '—' }}</td>
                                        <td class="text-end">{{ number_format((float) $v->sisa, 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-4">
                                        Tidak ada voucher dengan sisa saldo utk pelanggan ini.
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeVoucherModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL CARI DP (F6) - baris item DP milik pelanggan terpilih, hari ini ============ --}}
        @if ($showDpModal)
            <x-lw-modal :show="$showDpModal" title="DP Pelanggan" close="closeDpModal">
                <div class="modal-body" wire:key="dp-modal-body" x-data tabindex="-1"
                     x-init="$nextTick(() => $el.focus())"
                     wire:keydown.arrow-down.prevent="moveDpHighlight(1)"
                     wire:keydown.arrow-up.prevent="moveDpHighlight(-1)"
                     wire:keydown.enter.prevent="pickDpHighlighted"
                     wire:keydown.escape="closeDpModal">
                    <div class="text-muted small mb-2">DP yang dibeli <strong>{{ $custLabel }}</strong> hari ini dan masih ada sisa saldo.</div>
                    <div class="table-responsive" style="max-height: 320px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Item</th>
                                    <th class="text-end">Sisa Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($dpResults as $i => $d)
                                    <tr wire:key="dpr-{{ $d->id }}"
                                        class="{{ $i === $dpHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickDp({{ $d->id }})">
                                        <td class="fw-semibold">{{ $d->nomor }}</td>
                                        <td class="text-muted small">{{ $d->nama_item }}</td>
                                        <td class="text-end">{{ number_format((float) $d->sisa, 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-4">
                                        Tidak ada DP dengan sisa saldo utk pelanggan ini hari ini.
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeDpModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL CARI PROMO (F7) - referensi/label, bukan hitung diskon otomatis ============ --}}
        @if ($showPromoModal)
            <x-lw-modal :show="$showPromoModal" title="Harga Khusus" close="closePromoModal">
                <div class="modal-body" wire:key="promo-modal-body" x-data
                     x-init="$nextTick(() => $refs.promoSearch.focus())">
                    <input type="text" class="form-control mb-2" x-ref="promoSearch" wire:key="promo-search-input"
                           placeholder="ketik kode / nama promo…" autocomplete="off"
                           wire:model.live.debounce.250ms="promoQ"
                           wire:keydown.arrow-down.prevent="movePromoHighlight(1)"
                           wire:keydown.arrow-up.prevent="movePromoHighlight(-1)"
                           wire:keydown.enter.prevent="pickPromoHighlighted"
                           wire:keydown.escape="closePromoModal">
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                @forelse ($promoResults as $i => $p)
                                    <tr wire:key="pr-{{ $p->id }}"
                                        class="{{ $i === $promoHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickPromo({{ $p->id }})">
                                        <td>
                                            <span class="fw-semibold">{{ $p->kode }}</span>
                                            @if ($p->nama !== $p->kode)
                                                <span class="text-muted"> — {{ $p->nama }}</span>
                                            @endif
                                            <div class="text-muted small">
                                                berlaku {{ \Illuminate\Support\Carbon::parse($p->tgl1)->format('d/m/Y') }}
                                                &ndash; {{ \Illuminate\Support\Carbon::parse($p->tgl2)->format('d/m/Y') }}
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-4">
                                        {{ mb_strlen(trim($promoQ)) < 2 ? 'Ketik minimal 2 huruf…' : 'Tidak ada promo aktif yang cocok.' }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; <kbd>Esc</kbd> tutup
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closePromoModal">Tutup</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL PILIH BARIS KOMBINASI (promo Kombinasi 1 dgn >1 baris) ============ --}}
        @if ($showKombinasiModal)
            <x-lw-modal :show="$showKombinasiModal" title="Pilih Baris Kombinasi - {{ $activeKombinasiKode }}" close="closeKombinasiModal">
                <div class="modal-body" wire:key="kombinasi-modal-body" x-data
                     @keydown.arrow-down.prevent="$wire.moveKombinasiHighlight(1)"
                     @keydown.arrow-up.prevent="$wire.moveKombinasiHighlight(-1)"
                     @keydown.enter.prevent="$wire.pickKombinasiHighlighted()">
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item 1</th>
                                    <th>Item 2</th>
                                    <th>Item 3</th>
                                    <th>Item 4</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($kombinasiRows as $i => $r)
                                    <tr wire:key="komb-{{ $r->MPDID }}"
                                        class="{{ $i === $kombinasiHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickKombinasiBaris({{ $r->MPDID }})">
                                        <td class="text-muted">{{ $r->MPDURUTAN }}</td>
                                        <td class="small">{{ $r->MPDKELITEM1 ?: '—' }}</td>
                                        <td class="small">{{ $r->MPDKELITEM2 ?: '—' }}</td>
                                        <td class="small">{{ $r->MPDKELITEM3 ?: '—' }}</td>
                                        <td class="small">{{ $r->MPDKELITEM4 ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">Promo ini tidak punya baris kombinasi.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; klik baris jg bisa
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeKombinasiModal">Batal</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL TERAPKAN KOMBINASI - resolve item/qty per slot, lalu tambah ke keranjang sekaligus ============ --}}
        @if ($showKombinasiApplyModal)
            <x-lw-modal :show="$showKombinasiApplyModal" title="Terapkan Kombinasi - {{ $activeKombinasiKode }}" size="lg" close="closeKombinasiApplyModal">
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end" style="width:120px">Harga Jual</th>
                                    <th style="width:90px">Qty</th>
                                    <th class="text-end" style="width:80px">Disk 1</th>
                                    <th class="text-end" style="width:80px">Disk 2</th>
                                    <th class="text-end" style="width:130px">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($kombApply['slots'] ?? [] as $n => $slot)
                                    <tr wire:key="komb-apply-{{ $n }}">
                                        <td>
                                            <div class="text-muted small mb-1">Item {{ $n }}</div>
                                            @if (count($slot['options']) > 1)
                                                <select class="form-select form-select-sm" wire:model.live="kombApply.slots.{{ $n }}.chosen">
                                                    @foreach ($slot['options'] as $opt)
                                                        <option value="{{ $opt['kode'] }}">{{ $opt['nama'] }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <div class="small fw-semibold">{{ $slot['options'][0]['nama'] ?? $slot['chosen'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end small text-nowrap">{{ number_format($kombApplyRows[$n]['harga'] ?? 0, 0, ',', '.') }}</td>
                                        <td>
                                            <input type="number" min="0.01" step="0.01" class="form-control form-control-sm"
                                                   wire:model.live.debounce.400ms="kombApply.slots.{{ $n }}.qty">
                                        </td>
                                        <td class="text-end small">{{ $slot['d1'] }}%</td>
                                        <td class="text-end small">{{ $slot['d2'] }}%</td>
                                        <td class="text-end small text-nowrap fw-semibold">{{ number_format($kombApplyRows[$n]['subtotal'] ?? 0, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <th colspan="5" class="text-end">Total</th>
                                    <th class="text-end text-nowrap">{{ number_format($kombApplyTotal, 0, ',', '.') }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        Kalau ada pilihan alternatif ("Item N Pilihan"), tukar lewat dropdown di atas. Diskon &amp; qty bawaan promo - masih bisa diedit lagi di baris keranjang setelah ditambahkan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeKombinasiApplyModal">Batal</button>
                    <button type="button" class="btn btn-success" wire:click="applyKombinasi">
                        <i class="fas fa-cart-plus me-1"></i> Tambahkan ke Keranjang
                    </button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL PILIH BARIS PROMO (promo Biasa dgn >1 baris) ============ --}}
        @if ($showBiasaModal)
            <x-lw-modal :show="$showBiasaModal" title="Pilih Baris Promo - {{ $activeBiasaKode }}" close="closeBiasaModal">
                <div class="modal-body" wire:key="biasa-modal-body" x-data
                     @keydown.arrow-down.prevent="$wire.moveBiasaHighlight(1)"
                     @keydown.arrow-up.prevent="$wire.moveBiasaHighlight(-1)"
                     @keydown.enter.prevent="$wire.pickBiasaHighlighted()">
                    <div class="table-responsive" style="max-height: 360px; overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Jenis Item</th>
                                    <th>Item Bonus</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($biasaRows as $i => $r)
                                    <tr wire:key="biasa-{{ $r->MPDID }}"
                                        class="{{ $i === $biasaHighlight ? 'table-active' : '' }}"
                                        style="cursor: pointer" wire:click="pickBiasaBaris({{ $r->MPDID }})">
                                        <td class="text-muted">{{ $r->MPDURUTAN }}</td>
                                        <td class="small">{{ $r->MPDKELITEM1 ?: '—' }}</td>
                                        <td class="small text-muted">
                                            {{ collect([$r->MPDITEM1, $r->MPDITEM2, $r->MPDITEM3, $r->MPDITEM4, $r->MPDITEM5])->filter()->count() ?: '—' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-4">Promo ini tidak punya baris.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2">
                        <kbd>&uarr;</kbd> <kbd>&darr;</kbd> pilih baris &middot; <kbd>Enter</kbd> pilih &middot; klik baris jg bisa
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeBiasaModal">Batal</button>
                </div>
            </x-lw-modal>
        @endif

        {{-- ============ MODAL TERAPKAN PROMO (Biasa) - diskon item + item bonus gratis ============ --}}
        @if ($showBiasaApplyModal)
            <x-lw-modal :show="$showBiasaApplyModal" title="Terapkan Promo - {{ $activeBiasaKode }}" size="lg" close="closeBiasaApplyModal">
                <div class="modal-body">
                    @if ($biasaApply['item'] ?? null)
                        <h6 class="text-muted small text-uppercase mb-2">Item &amp; Diskon</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-end" style="width:110px">Harga Jual</th>
                                        <th style="width:80px">Qty</th>
                                        <th class="text-end" style="width:70px">Disk 1</th>
                                        <th class="text-end" style="width:70px">Disk 2</th>
                                        <th class="text-end" style="width:120px">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            @if (count($biasaApply['item']['options']) > 1)
                                                <select class="form-select form-select-sm" wire:model.live="biasaApply.item.chosen">
                                                    @foreach ($biasaApply['item']['options'] as $opt)
                                                        <option value="{{ $opt['kode'] }}">{{ $opt['nama'] }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <div class="small fw-semibold">{{ $biasaApply['item']['options'][0]['nama'] ?? $biasaApply['item']['chosen'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end small text-nowrap">{{ number_format($biasaApplyItem['harga'] ?? 0, 0, ',', '.') }}</td>
                                        <td>
                                            <input type="number" min="0.01" step="0.01" class="form-control form-control-sm"
                                                   wire:model.live.debounce.400ms="biasaApply.item.qty">
                                        </td>
                                        <td class="text-end small">{{ $biasaApply['item']['d1'] }}%</td>
                                        <td class="text-end small">{{ $biasaApply['item']['d2'] }}%</td>
                                        <td class="text-end small text-nowrap fw-semibold">{{ number_format($biasaApplyItem['subtotal'] ?? 0, 0, ',', '.') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($biasaApply['bonus'] ?? [])
                        <h6 class="text-muted small text-uppercase mb-2">Item Bonus (ditambahkan gratis)</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    @foreach ($biasaApply['bonus'] as $n => $b)
                                        <tr wire:key="biasa-bonus-{{ $n }}">
                                            <td style="width:36px">
                                                <input type="checkbox" class="form-check-input" wire:model.live="biasaApply.bonus.{{ $n }}.include">
                                            </td>
                                            <td class="small">{{ $b['nama'] }}</td>
                                            <td style="width:90px">
                                                <input type="number" min="0.01" step="0.01" class="form-control form-control-sm"
                                                       wire:model="biasaApply.bonus.{{ $n }}.qty" @disabled(! $b['include'])>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <h6 class="text-muted small text-uppercase mb-2">Syarat (info saja - TIDAK dicek otomatis, kasir yg menilai)</h6>
                    <div class="row g-2 small text-muted">
                        @if ($biasaApply['syarat']['minbelanja'] ?? null)
                            <div class="col-md-4">Min. Belanja: <span class="fw-semibold text-body">{{ number_format($biasaApply['syarat']['minbelanja'], 0, ',', '.') }}</span></div>
                        @endif
                        @if ($biasaApply['syarat']['minqty'] ?? null)
                            <div class="col-md-4">Min. Qty: <span class="fw-semibold text-body">{{ $biasaApply['syarat']['minqty'] }}</span></div>
                        @endif
                        @if ($biasaApply['syarat']['maxpasien'] ?? null)
                            <div class="col-md-4">Max Pasien: <span class="fw-semibold text-body">{{ $biasaApply['syarat']['maxpasien'] }}</span></div>
                        @endif
                        @if (($biasaApply['syarat']['pakaitanggal'] ?? false) && ($biasaApply['syarat']['tanggal1'] ?? null))
                            <div class="col-md-4">
                                Berlaku:
                                <span class="fw-semibold text-body">
                                    {{ \Illuminate\Support\Carbon::parse($biasaApply['syarat']['tanggal1'])->format('d/m/Y') }}
                                    @if ($biasaApply['syarat']['tanggal2'] ?? null)
                                        &ndash; {{ \Illuminate\Support\Carbon::parse($biasaApply['syarat']['tanggal2'])->format('d/m/Y') }}
                                    @endif
                                </span>
                            </div>
                        @endif
                        @if ($biasaApply['syarat']['jam1'] ?? null)
                            <div class="col-md-4">
                                Jam: <span class="fw-semibold text-body">{{ $biasaApply['syarat']['jam1'] }}@if ($biasaApply['syarat']['jam2'] ?? null) &ndash; {{ $biasaApply['syarat']['jam2'] }}@endif</span>
                            </div>
                        @endif
                        @if ($biasaApply['syarat']['seluruhinvoice'] ?? false)
                            <div class="col-12">
                                <i class="fas fa-triangle-exclamation text-warning me-1"></i>
                                Diskon promo ini seharusnya berlaku ke SELURUH invoice - tapi diterapkan ke baris item ini saja (tidak ada mekanisme diskon level-invoice), sesuaikan manual kalau perlu.
                            </div>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeBiasaApplyModal">Batal</button>
                    <button type="button" class="btn btn-success" wire:click="applyBiasa">
                        <i class="fas fa-cart-plus me-1"></i> Tambahkan ke Keranjang
                    </button>
                </div>
            </x-lw-modal>
        @endif
    @endif
</div>
