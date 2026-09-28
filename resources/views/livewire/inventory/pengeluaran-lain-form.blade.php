<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Pengeluaran Lain</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge {{ $status === 9 ? 'text-bg-danger' : 'text-bg-success' }} ms-2">
                        {{ $status === 9 ? 'Batal' : 'Aktif' }}
                    </span>
                @endif
            </div>
            <div>
                @unless ($locked)
                    <button type="button" class="btn btn-success btn-sm" wire:click="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                @endunless
                @if ($plId && can_do('inventory/pengeluaran-lain', 'print'))
                    <a href="{{ route('inventory.pengeluaran-lain.print', $plId) }}" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-print me-1"></i> Cetak
                    </a>
                @endif
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                    <i class="fas fa-xmark me-1"></i> Tutup
                </button>
            </div>
        </div>

        <div class="card-body">
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            <style>
                .pl-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                .pl-field > label { flex: 0 0 110px; max-width: 110px; padding-top: .35rem; margin-bottom: 0; }
                .pl-field > .pl-input { flex: 1 1 auto; min-width: 0; }
                .pl-top label.form-label { margin-bottom: .15rem; font-size: .8rem; }
            </style>

            {{-- ===== HEADER ===== --}}
            <div class="row g-3 mb-2">
                <div class="col-xl-6">
                    <div class="pl-field">
                        <label class="form-label">Kontak</label>
                        <div class="pl-input">
                            @if ($locked)
                                <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                       value="{{ $kontakLabel ?: '—' }}">
                            @else
                                <x-search-select model="kontak" :value="$kontak" :selected-text="$kontakLabel"
                                                 :endpoint="route('lookup.kontak')" placeholder="cari kontak…" />
                            @endif
                        </div>
                    </div>
                    <div class="pl-field">
                        <label class="form-label">Gudang</label>
                        <div class="pl-input">
                            {{-- Selalu cabang user login, tidak bisa diubah. --}}
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                   value="{{ $gudangLabel ?: '—' }}">
                            @error('gudang') <div class="text-danger small">{{ $message }}</div> @enderror
                            @unless ($locked)
                                <div class="form-text">Otomatis cabang Anda, tidak bisa diubah.</div>
                            @endunless
                        </div>
                    </div>
                    <div class="pl-field">
                        <label class="form-label">Uraian</label>
                        <div class="pl-input">
                            <input type="text" class="form-control form-control-sm" wire:model="uraian"
                                   {{ $locked ? 'disabled' : '' }}>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6 pl-top">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm @error('tanggal') is-invalid @enderror"
                                   wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">No Transaksi</label>
                            <input type="text" class="form-control form-control-sm bg-body-secondary" disabled
                                   value="{{ $nomor ?: '[otomatis]' }}">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-12">
                            <label class="form-label">Jenis <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm @error('jenis') is-invalid @enderror"
                                    wire:model="jenis" {{ $locked ? 'disabled' : '' }}>
                                <option value="">— pilih —</option>
                                @foreach ($jenisList as $j)
                                    <option value="{{ $j->JID }}">{{ $j->JNAMA ?: $j->JKODE }}</option>
                                @endforeach
                            </select>
                            @error('jenis') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== DETAIL ITEM ===== --}}
            <hr class="my-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Item</strong>
                <span class="text-muted small">Semua baris mengurangi stok gudang di atas.</span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 20%">Kode</th>
                            <th>Nama</th>
                            <th class="text-end" style="width: 110px">Keluar</th>
                            <th style="width: 90px">Satuan</th>
                            <th style="width: 26%">Catatan</th>
                            @unless ($locked) <th style="width: 40px"></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            <tr wire:key="pl-line-{{ $i }}">
                                <td class="font-monospace small">{{ $l['kode'] }}</td>
                                <td>{{ $l['nama'] }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           class="form-control form-control-sm text-end"
                                           wire:model="lines.{{ $i }}.keluar" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                <td>
                                    <input type="text" class="form-control form-control-sm"
                                           wire:model="lines.{{ $i }}.catatan" {{ $locked ? 'disabled' : '' }}>
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
                            <tr><td colspan="{{ $locked ? 5 : 6 }}" class="text-center text-muted py-4">
                                Belum ada item — cari dan tambahkan di bawah.
                            </td></tr>
                        @endforelse
                    </tbody>
                    @if (count($lines))
                        <tfoot>
                            <tr class="table-light fw-semibold">
                                <td colspan="2" class="text-end">Jumlah</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($jumlahKeluar, 2, ',', '.'), '0'), ',') }}</td>
                                <td colspan="{{ $locked ? 2 : 3 }}"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            @unless ($locked)
                {{-- tambah item --}}
                <div class="mt-3" style="max-width: 460px">
                    <label class="form-label mb-1 small text-muted">Tambah item</label>
                    <input type="text" class="form-control form-control-sm" placeholder="ketik kode / nama item…"
                           wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                    @if (count($items))
                        <div class="list-group mt-1" style="max-height: 240px; overflow-y:auto">
                            @foreach ($items as $it)
                                <button type="button" class="list-group-item list-group-item-action py-1"
                                        wire:click="addItem({{ $it->IID }})">
                                    <span class="font-monospace small">{{ $it->IKODE }}</span> — {{ $it->INAMA }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endunless

            {{-- ===== RINGKASAN ===== --}}
            <div class="row justify-content-end mt-3">
                <div class="col-md-5">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Jumlah Keluar</td>
                            <td class="text-end fw-semibold text-danger">
                                {{ rtrim(rtrim(number_format($jumlahKeluar, 2, ',', '.'), '0'), ',') }}
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
