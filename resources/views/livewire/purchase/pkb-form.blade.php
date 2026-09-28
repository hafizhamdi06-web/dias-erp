<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $pkbId ? 'PKB ' . $nomor : 'PKB Baru dari PR ' . $noPr }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci</span> @endif
                </span>
                <div>
                    @if ($pkbId && can_do('purchase/pkb', 'print'))
                        <a href="{{ route('purchase.pkb.print', $pkbId) }}" target="_blank"
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
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-secondary', 'Baru'],
                    };
                @endphp

                <style>
                    .pkb-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .pkb-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .pkb-field > .pkb-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    {{-- ===== Kolom kiri: isi PKB ===== --}}
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="pkb-field">
                                <label class="form-label">No PR Asal</label>
                                <div class="pkb-input">
                                    <span class="badge text-bg-primary fs-6">{{ $noPr ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="pkb-field">
                                <label class="form-label">Diperintah Oleh</label>
                                <div class="pkb-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $diperintahLabel ?: '—' }}</span>
                                    <div class="form-text">{{ $pkbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            <div class="pkb-field">
                                <label class="form-label">Cabang (Gudang Pengirim)</label>
                                <div class="pkb-input">
                                    <select class="form-select form-select-sm" wire:model="gudang" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">{{ $pkbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}</div>
                                    @error('gudang') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="pkb-field">
                                <label class="form-label">Kirim Ke</label>
                                <div class="pkb-input">
                                    <span class="badge text-bg-info fs-6">{{ $tujuanCabangLabel ?: '—' }}</span>
                                    <span class="text-muted small ms-1">({{ $tujuanKategoriLabel ?: '—' }})</span>
                                    <div class="form-text">Otomatis dari PR sumber, tidak bisa diubah.</div>
                                </div>
                            </div>
                            <div class="pkb-field">
                                <label class="form-label">Keterangan</label>
                                <div class="pkb-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== Kolom kanan: info dokumen ===== --}}
                    <div class="col-md-6">
                        <div class="pkb-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="pkb-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="pkb-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="pkb-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="pkb-field">
                            <label class="form-label">Status</label>
                            <div class="pkb-input">
                                <span class="badge {{ $statusBadge[0] }} fs-6">{{ $statusBadge[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---- Baris item (ditarik dari PR, tidak bisa tambah bebas) ---- --}}
                <hr class="my-3">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 28%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Diminta</th>
                                <th class="text-center" style="width: 110px">Qty Dikirim</th>
                                <th style="width: 80px">Satuan</th>
                                <th>Catatan</th>
                                <th class="text-end" style="width: 90px">Real Stok</th>
                                <th class="text-end" style="width: 80px">Saldo 3 Bln</th>
                                <th class="text-end" style="width: 80px">Saldo 1 Bln</th>
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
                                    <td class="text-end">{{ rtrim(rtrim(number_format($l['sisa'], 2), '0'), '.') }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" max="{{ $l['sisa'] }}"
                                               class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format($l['stok'], 2), '0'), '.') }}</td>
                                    <td class="text-end text-muted small">{{ rtrim(rtrim(number_format($l['saldo3'], 2), '0'), '.') }}</td>
                                    <td class="text-end text-muted small">{{ rtrim(rtrim(number_format($l['saldo1'], 2), '0'), '.') }}</td>
                                    <td class="text-center">
                                        @unless ($locked)
                                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" wire:click="removeLine({{ $i }})" title="Jangan kirim item ini sekarang">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada item tersisa dari PR ini.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end">Total Qty Dikirim</td>
                                    <td></td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="6"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>
