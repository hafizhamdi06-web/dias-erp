<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $poId ? 'PO ' . $nomor : 'PO Baru' }}
                    @if ($locked) <span class="badge text-bg-danger ms-2">dibatalkan</span> @endif
                </span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @if ($poId && can_do('purchase/po', 'print'))
                        <a href="{{ route('purchase.po.print', $poId) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-1"></i> Cetak
                        </a>
                    @endif
                    @unless ($locked)
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endunless
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                {{-- Tata letak header MENGIKUTI VB6 `dFrmOrderPembelian` (permintaan user
                     2026-09-26): blok KIRI = Vendor/Kontak/Alamat dgn label di samping,
                     blok KANAN = 2 baris field dgn label DI ATAS input. Catatan & Gudang
                     pindah ke bawah (dekat blok total), sama seperti VB6. --}}
                <style>
                    .po-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .po-field > label { flex: 0 0 90px; max-width: 90px; padding-top: .3rem; margin-bottom: 0; }
                    .po-field > .po-input { flex: 1 1 auto; min-width: 0; }
                    .po-top label.form-label { margin-bottom: .15rem; font-size: .8rem; }
                </style>

                <div class="row g-3">
                    {{-- ===== KIRI: Vendor / Kontak / Alamat ===== --}}
                    <div class="col-xl-5">
                        <fieldset @disabled($locked)>
                            <div class="po-field">
                                <label class="form-label">Vendor <span class="text-danger">*</span></label>
                                <div class="po-input">
                                    <x-search-select model="vendor" :value="$vendor" :selected-text="$vendorLabel"
                                                      :endpoint="route('lookup.vendor')" placeholder="cari supplier…" :live="true" />
                                    @error('vendor') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="form-label">Kontak</label>
                                <div class="po-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="attention"
                                           placeholder="nama kontak (teks bebas)">
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="form-label">Alamat</label>
                                <div class="po-input">
                                    <textarea class="form-control form-control-sm" rows="3" wire:model="alamat"></textarea>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== KANAN: 2 baris, label di atas input (persis VB6) ===== --}}
                    <div class="col-xl-7 po-top">
                        <fieldset @disabled($locked)>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Pajak</label>
                                    <select class="form-select form-select-sm" wire:model.live="pajak">
                                        <option value="0">Tanpa Pajak</option>
                                        <option value="1">Harga Belum Termasuk Pajak</option>
                                        <option value="2">Harga Sudah Termasuk Pajak</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" wire:model="tanggal">
                                    @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">No Transaksi</label>
                                    <input type="text" class="form-control form-control-sm bg-body-secondary"
                                           value="{{ $nomor ?: '[otomatis]' }}" disabled>
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-2">
                                    <label class="form-label">Kurs</label>
                                    <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                           wire:model="kurs">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Uang</label>
                                    <select class="form-select form-select-sm" wire:model="uang">
                                        <option value="">—</option>
                                        @foreach ($uangList as $u)
                                            {{-- "RP - RP" mengulang: di `buang` UKODE & UNAMA
                                                 memang sering sama, jadi tampilkan kodenya saja. --}}
                                            <option value="{{ $u->UID }}">
                                                {{ $u->UNAMA && $u->UNAMA !== $u->UKODE ? $u->UKODE . ' - ' . $u->UNAMA : $u->UKODE }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    {{-- Selalu tampil (spt VB6) supaya kolomnya tidak loncat-loncat;
                                         dimatikan saja kalau Tanpa Pajak. --}}
                                    <label class="form-label">Nilai Pajak</label>
                                    <select class="form-select form-select-sm" wire:model.live="nilaiPajak"
                                            @disabled($locked || $pajak === 0)>
                                        @foreach ($nilaiPajakOpt as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}%</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bag Pembelian</label>
                                    <x-search-select model="karyawan" :value="$karyawan" :selected-text="$karyawanLabel"
                                                      :endpoint="route('lookup.karyawan')" placeholder="cari karyawan…" />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Termin <span class="text-danger">*</span></label>
                                    <x-search-select model="termin" :value="$termin" :selected-text="$terminLabel"
                                                      :endpoint="route('lookup.termin')" placeholder="cari termin…" />
                                    @error('termin') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </fieldset>
                    </div>
                </div>

                <hr class="my-3">

                @unless ($locked)
                    <div class="position-relative mb-2" style="max-width: 420px">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari item (kode/nama internal atau kode/nama supplier)…"
                                   wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        </div>
                        @if (count($itemResults))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                @foreach ($itemResults as $r)
                                    <button type="button" wire:key="ir-{{ $r->id }}"
                                            class="list-group-item list-group-item-action small"
                                            wire:click="addItem({{ $r->id }})">
                                        <span class="fw-semibold">{{ $r->ICODING ?: $r->kode }}</span> — {{ $r->IPONAMA ?: $r->nama }}
                                        @if ($r->ICODING || $r->IPONAMA)
                                            <span class="text-muted small d-block">internal: {{ $r->kode }} — {{ $r->nama }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 18%">Item</th>
                                <th class="text-center" style="width: 80px">Qty</th>
                                <th style="width: 70px">Satuan</th>
                                <th style="width: 100px">Kemasan</th>
                                <th class="text-end" style="width: 100px">Harga</th>
                                <th class="text-center" style="width: 65px">Disc%1</th>
                                <th class="text-center" style="width: 65px">Disc%2</th>
                                <th class="text-center" style="width: 65px">Disc%3</th>
                                <th class="text-end" style="width: 90px">Disc Rp</th>
                                <th class="text-end" style="width: 100px">Total</th>
                                <th>Catatan</th>
                                @unless ($locked) <th style="width: 36px"></th> @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $l['nama'] }}</div>
                                        <div class="text-muted small">{{ $l['kode'] }}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.kemasan" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live="lines.{{ $i }}.harga" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $i }}.disc1" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $i }}.disc2" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $i }}.disc3" @disabled($locked)>
                                    </td>
                                    <td class="text-end text-muted small">{{ number_format($l['discRp'], 0, ',', '.') }}</td>
                                    <td class="text-end fw-semibold small">{{ number_format($l['total'], 0, ',', '.') }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    @unless ($locked)
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeLine({{ $i }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr><td colspan="12" class="text-center text-muted py-4">Belum ada item.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Bawah: Catatan + Gudang di kiri, total di kanan - susunan VB6. --}}
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="po-field">
                                <label class="form-label">Catatan</label>
                                <div class="po-input">
                                    <textarea class="form-control form-control-sm" rows="2" wire:model="catatan"></textarea>
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="form-label">No Ref</label>
                                <div class="po-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="noRef">
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="form-label">Gudang <span class="text-danger">*</span></label>
                                <div class="po-input">
                                    <select class="form-select form-select-sm" wire:model="gudang" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Otomatis cabang Anda, tidak bisa diubah.</div>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col-md-6">
                        <table class="table table-sm mb-0 align-middle">
                            <tr>
                                <td style="width: 45%">Sub Total</td>
                                <td class="text-end">{{ number_format($subtotal, 0, ',', '.') }}</td>
                            </tr>
                            {{-- Diskon tambahan: isi SALAH SATU, pasangannya dihitung otomatis. --}}
                            <tr>
                                <td>Diskon</td>
                                <td>
                                    @if ($locked)
                                        <div class="text-end">
                                            {{ number_format($diskon, 0, ',', '.') }}
                                            @if ((float) $diskonPersen > 0)
                                                <span class="text-muted small">
                                                    ({{ rtrim(rtrim(number_format($diskonPersen, 2, ',', '.'), '0'), ',') }}%)
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="d-flex align-items-center gap-1 justify-content-end">
                                            <input type="number" min="0" max="100" step="any"
                                                   class="form-control form-control-sm text-end" style="width: 80px"
                                                   wire:model.live.debounce.500ms="diskonPersen" title="Diskon dalam persen">
                                            <span class="text-muted">%</span>
                                            <input type="number" min="0" step="any"
                                                   class="form-control form-control-sm text-end" style="width: 130px"
                                                   wire:model.live.debounce.500ms="diskon" title="Diskon dalam rupiah">
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            <tr><td>Pajak</td><td class="text-end">{{ number_format($totalPajak, 0, ',', '.') }}</td></tr>
                            <tr class="fw-bold">
                                <td>Total Transaksi</td>
                                <td class="text-end">{{ number_format($totalTransaksi, 0, ',', '.') }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
