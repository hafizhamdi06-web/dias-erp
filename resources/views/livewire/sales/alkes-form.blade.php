<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Input Alkes Depo</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge {{ $status === 9 ? 'text-bg-danger' : 'text-bg-success' }} ms-2">
                        {{ $status === 9 ? 'Batal' : 'Aktif' }}
                    </span>
                @endif
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                <i class="fas fa-xmark me-1"></i> Tutup
            </button>
        </div>

        <div class="card-body">
            @if ($errors->has('lines'))
                <div class="alert alert-danger">{{ $errors->first('lines') }}</div>
            @endif

            {{-- ---- Header: data dari IP ---- --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">No Transaksi IP</label>
                    <div><span class="badge text-bg-primary fs-6 font-monospace">{{ $noIp ?: '—' }}</span></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Pelanggan</label>
                    <div class="fw-semibold">{{ $pelanggan ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cabang / Gudang</label>
                    <div class="fw-semibold">{{ $cabangLabel ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control form-control-sm" wire:model="tanggal" @disabled($locked)>
                </div>
            </div>

            {{-- ---- Grid atas: baris tindakan di IP ---- --}}
            @unless ($locked)
                <hr class="my-3">
                <div class="mb-2"><strong>Tindakan di transaksi ini</strong>
                    <span class="text-muted small ms-1">— pilih satu untuk diinput alkesnya</span>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th style="width: 140px">Kode</th>
                            <th>Nama Tindakan</th>
                            <th class="text-end" style="width: 80px">Qty</th>
                            <th style="width: 150px">No Ref</th>
                            <th class="text-center" style="width: 180px">Alkes</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($tindakan as $t)
                                <tr wire:key="tind-{{ $t['sdid'] }}"
                                    class="{{ $tindakanSdid === $t['sdid'] ? 'table-primary' : '' }}">
                                    <td class="font-monospace small">{{ $t['kode'] }}</td>
                                    <td>{{ $t['nama'] }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format($t['qty'], 2), '0'), '.') }}</td>
                                    <td class="text-muted small">{{ $t['noRef'] ?: '—' }}</td>
                                    <td class="text-center">
                                        @if ($t['alkesSuid'])
                                            <span class="badge text-bg-secondary font-monospace">{{ $t['alkesNomor'] }}</span>
                                        @elseif ($tindakanSdid === $t['sdid'])
                                            <span class="badge text-bg-primary"><i class="fas fa-pen me-1"></i>sedang diinput</span>
                                        @else
                                            <button type="button" class="btn btn-outline-primary btn-sm"
                                                    wire:click="pilihTindakan({{ $t['sdid'] }})">
                                                <i class="fas fa-syringe me-1"></i> Input Alkes
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">
                                    Transaksi ini tidak punya baris tindakan.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endunless

            {{-- ---- Grid bawah: alkes ---- --}}
            @if ($tindakanSdid)
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <div>
                        <strong>Alkes Dipakai</strong>
                        <span class="badge text-bg-info ms-1">{{ $tindakanNama }}</span>
                    </div>
                    @unless ($locked)
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="pilihSemua(true)">
                                Centang semua
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="pilihSemua(false)">
                                Kosongkan
                            </button>
                        </div>
                    @endunless
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light"><tr>
                            @unless ($locked) <th class="text-center" style="width: 50px">Pakai</th> @endunless
                            <th style="width: 150px">Kode</th>
                            <th>Nama Alkes</th>
                            <th class="text-center" style="width: 110px">Qty</th>
                            <th style="width: 90px">Satuan</th>
                            <th class="text-end" style="width: 110px">Qty Resep</th>
                            @unless ($locked) <th style="width: 40px"></th> @endunless
                        </tr></thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="alkes-line-{{ $i }}" class="{{ ! $locked && ! $l['pilih'] ? 'opacity-50' : '' }}">
                                    @unless ($locked)
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input" @checked($l['pilih'])
                                                   wire:click="togglePilih({{ $i }})">
                                        </td>
                                    @endunless
                                    <td class="font-monospace small">{{ $l['kode'] }}</td>
                                    <td>{{ $l['nama'] }}</td>
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                               class="form-control form-control-sm text-center"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked || ! $l['pilih'])>
                                    </td>
                                    <td class="text-muted small">{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td class="text-end text-muted small">
                                        @if ((float) $l['qtyDefault'] > 0)
                                            {{ rtrim(rtrim(number_format((float) $l['qtyDefault'], 2), '0'), '.') }}
                                        @else
                                            <span title="Di luar resep">manual</span>
                                        @endif
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
                                <tr><td colspan="{{ $locked ? 5 : 7 }}" class="text-center text-muted py-3">
                                    Tindakan ini belum punya resep alkes — tambahkan manual di bawah.
                                </td></tr>
                            @endforelse
                        </tbody>
                        @if (count($lines))
                            <tfoot>
                                <tr class="table-light fw-semibold">
                                    <td class="text-end" colspan="{{ $locked ? 2 : 3 }}">Total Qty</td>
                                    <td class="text-center">{{ rtrim(rtrim(number_format($totalQty, 2), '0'), '.') }}</td>
                                    <td colspan="{{ $locked ? 2 : 3 }}"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @unless ($locked)
                    {{-- tambah alkes di luar resep --}}
                    <div class="mt-3" style="max-width: 420px">
                        <label class="form-label mb-1 small text-muted">Tambah alkes di luar resep</label>
                        <input type="text" class="form-control form-control-sm" placeholder="ketik kode / nama item…"
                               wire:model.live.debounce.300ms="itemQ" autocomplete="off">
                        @if (count($items))
                            <div class="list-group mt-1" style="max-height: 220px; overflow-y:auto">
                                @foreach ($items as $it)
                                    <button type="button" class="list-group-item list-group-item-action py-1"
                                            wire:click="tambahItem({{ $it->IID }})">
                                        <span class="font-monospace small">{{ $it->IKODE }}</span> — {{ $it->INAMA }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="mt-3 text-end">
                        <button type="button" class="btn btn-success" wire:click="save">
                            <i class="fas fa-save me-1"></i> Simpan
                        </button>
                    </div>
                @endunless
            @endif
        </div>
    </div>
</div>
