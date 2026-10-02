<div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $kmbId ? 'KMB ' . $nomor : ($jopId ? 'KMB Baru dari JOP ' . $noJop : 'KMB Baru dari PR ' . $noPr) }}
                    @if ($locked) <span class="badge text-bg-success ms-2">terkunci</span> @endif
                </span>
                <div>
                    @if ($kmbId && can_do('inventory/kmb', 'print'))
                        <a href="{{ route('inventory.kmb.print', $kmbId) }}" target="_blank"
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
                        3 => ['text-bg-primary', 'Diterima'],
                        9 => ['text-bg-danger', 'Batal'],
                        default => ['text-bg-success', 'Aktif'],
                    };
                @endphp

                <style>
                    .kmb-field { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .5rem; }
                    .kmb-field > label { flex: 0 0 40%; max-width: 40%; padding-top: .3rem; margin-bottom: 0; }
                    .kmb-field > .kmb-input { flex: 1 1 60%; min-width: 0; }
                </style>

                <div class="row g-3">
                    {{-- ===== Kolom kiri: isi KMB ===== --}}
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            {{-- Label ikut sumbernya: KMB bisa lahir dari PR ATAU dari JOP
                                 (2026-10-03), tidak pernah keduanya. --}}
                            <div class="kmb-field">
                                <label class="form-label">{{ $jopId ? 'No JOP Asal' : 'No PR Asal' }}</label>
                                <div class="kmb-input">
                                    <span class="badge text-bg-info fs-6">{{ ($jopId ? $noJop : $noPr) ?: '—' }}</span>
                                </div>
                            </div>
                            <div class="kmb-field">
                                <label class="form-label">Diperintah Oleh</label>
                                <div class="kmb-input">
                                    <span class="badge text-bg-secondary fs-6"><i class="fas fa-user-tie me-1"></i>{{ $kontakLabel ?: '—' }}</span>
                                    <div class="form-text">{{ $kmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            <div class="kmb-field">
                                <label class="form-label">Cabang Pengirim</label>
                                <div class="kmb-input">
                                    <select class="form-select form-select-sm" wire:model="cabang" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">{{ $kmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}</div>
                                </div>
                            </div>
                            <div class="kmb-field">
                                <label class="form-label">Cabang Tujuan</label>
                                <div class="kmb-input">
                                    <select class="form-select form-select-sm" wire:model="cabangTujuan" disabled>
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Cabang peminta (dari PR asal), tidak bisa diubah.</div>
                                </div>
                            </div>
                            <div class="kmb-field">
                                <label class="form-label">Keterangan</label>
                                <div class="kmb-input">
                                    <input type="text" class="form-control form-control-sm" wire:model="uraian">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    {{-- ===== Kolom kanan: info dokumen ===== --}}
                    <div class="col-md-6">
                        <div class="kmb-field">
                            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <div class="kmb-input">
                                <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="kmb-field">
                            <label class="form-label">No. Transaksi</label>
                            <div class="kmb-input">
                                <input type="text" class="form-control form-control-sm bg-body-secondary" value="{{ $nomor ?: '[otomatis]' }}" disabled>
                            </div>
                        </div>
                        <div class="kmb-field">
                            <label class="form-label">Status</label>
                            <div class="kmb-input">
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
                                <th style="width: 30%">Item</th>
                                <th class="text-end" style="width: 90px">Qty Diminta</th>
                                <th class="text-center" style="width: 110px">Qty Kirim</th>
                                <th style="width: 80px">Satuan</th>
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
                                    <td class="text-end">{{ rtrim(rtrim(number_format($l['qtyMinta'], 2), '0'), '.') }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" max="{{ $l['qtyMinta'] }}"
                                               class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    <td class="text-center"></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada item dari PR ini.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td class="text-end">Total Qty Kirim</td>
                                    <td></td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>
