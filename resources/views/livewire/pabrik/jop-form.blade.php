<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $jopId ? 'JOP ' . $nomor : 'JOP Baru' }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci</span> @endif
                </span>
                <div>
                    @if ($jopId && can_do('pabrik/jop', 'print'))
                        <a href="{{ route('pabrik.jop.print', $jopId) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-1"></i> Cetak
                        </a>
                    @endif
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
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

                @php
                    $statusBadge = match ($status) {
                        2 => ['text-bg-info', 'Sebagian Ditarik'],
                        3 => ['text-bg-success', 'Selesai Ditarik'],
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-secondary', 'Belum Ditarik'],
                    };
                @endphp

                <style>
                    .jop-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .jop-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .jop-field > .jop-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="jop-field">
                                <label class="form-label">Diperintah Oleh</label>
                                <div class="jop-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $kontakLabel ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="jop-field">
                                <label class="form-label">Gudang Produksi</label>
                                <div class="jop-input">
                                    <select class="form-select form-select-sm" wire:model="cabang" disabled>
                                        <option value="">—</option>
                                        @foreach (\App\Models\Branch::options() as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Otomatis cabang Anda, tidak bisa diubah.</div>
                                </div>
                            </div>
                            <div class="jop-field">
                                <label class="form-label">Jenis Produksi</label>
                                <div class="jop-input">
                                    <select class="form-select form-select-sm" wire:model="jenis">
                                        <option value="">—</option>
                                        @foreach ($jenisList as $j)
                                            <option value="{{ $j->lid }}">{{ $j->lnama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="jop-field">
                                <label class="form-label">Keterangan</label>
                                <div class="jop-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col-md-6">
                        <div class="jop-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="jop-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="jop-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="jop-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="jop-field">
                            <label class="form-label">Status</label>
                            <div class="jop-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                @unless ($locked)
                    <div class="position-relative mb-2" style="max-width: 420px">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari produk jadi untuk ditambahkan…"
                                   wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        </div>
                        @if (count($itemResults))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1055; max-height: 260px; overflow-y:auto">
                                @foreach ($itemResults as $r)
                                    <button type="button" wire:key="ir-{{ $r->id }}"
                                            class="list-group-item list-group-item-action small"
                                            wire:click="addProdukJadi({{ $r->id }})">
                                        <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
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
                                <th style="width: 28%">Produk Jadi</th>
                                <th class="text-center" style="width: 110px">Qty Jadi</th>
                                <th style="width: 80px">Satuan</th>
                                <th style="width: 90px" class="text-center">Sudah Ditarik</th>
                                <th>Komposisi Bahan Baku</th>
                                <th>Catatan</th>
                                <th style="width: 36px"></th>
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
                                        <input type="number" min="0" step="any"
                                               class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td class="text-center text-muted small">{{ rtrim(rtrim(number_format($l['qtyPakai'] ?? 0, 2), '0'), '.') }}</td>
                                    <td>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="openKomposisi({{ $i }})">
                                            <i class="fas fa-flask me-1"></i>{{ count($l['komposisi']) }} bahan
                                        </button>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeLine({{ $i }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada produk jadi.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>

    @if ($showKomposisi && $komposisiLine !== null)
        <x-lw-modal :show="$showKomposisi" title="Komposisi Bahan Baku - {{ $lines[$komposisiLine]['nama'] ?? '' }}" close="closeKomposisi" size="lg">
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-muted small">Qty Jadi: <span class="fw-semibold">{{ rtrim(rtrim(number_format($lines[$komposisiLine]['qty'] ?? 0, 2), '0'), '.') }}</span></div>
                    @unless ($locked)
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="ambilResepDefault">
                                <i class="fas fa-book me-1"></i>Ambil Resep Default
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="hitungUlangKomposisi">
                                <i class="fas fa-calculator me-1"></i>Hitung Ulang Qty
                            </button>
                        </div>
                    @endunless
                </div>

                @unless ($locked)
                    <div class="position-relative mb-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-plus"></i></span>
                            <input type="text" class="form-control" placeholder="Cari bahan baku untuk ditambahkan…"
                                   wire:model.live.debounce.300ms="komposisiQ" autocomplete="off">
                        </div>
                        @if (count($komposisiResults))
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 1060; max-height: 220px; overflow-y:auto">
                                @foreach ($komposisiResults as $r)
                                    <button type="button" wire:key="kr-{{ $r->id }}"
                                            class="list-group-item list-group-item-action small"
                                            wire:click="addKomposisiItem({{ $r->id }})">
                                        <span class="fw-semibold">{{ $r->kode }}</span> — {{ $r->nama }}
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
                                <th>Bahan Baku</th>
                                <th class="text-center" style="width: 110px">Qty/Unit</th>
                                <th class="text-center" style="width: 110px">Qty Pakai</th>
                                <th style="width: 80px">Satuan</th>
                                <th>Catatan</th>
                                <th style="width: 36px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines[$komposisiLine]['komposisi'] as $k => $row)
                                <tr wire:key="komp-{{ $row['item'] }}">
                                    <td>
                                        <div class="fw-semibold small">{{ $row['nama'] }}</div>
                                        <div class="text-muted small">{{ $row['kode'] }}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model.live="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.qtyDefault" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $row['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $komposisiLine }}.komposisi.{{ $k }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeKomposisiItem({{ $k }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada bahan baku - klik "Ambil Resep Default" atau tambah manual.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" wire:click="closeKomposisi">Selesai</button>
            </div>
        </x-lw-modal>
    @endif
</div>
