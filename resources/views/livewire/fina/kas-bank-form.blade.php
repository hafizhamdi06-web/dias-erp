<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>{{ $judul }}</strong>
                @if ($nomor)
                    <span class="text-muted">— {{ $nomor }}</span>
                @endif
                @if ($locked)
                    <span class="badge text-bg-success ms-2">Tersimpan</span>
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

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control @error('tanggal') is-invalid @enderror" wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kontak</label>
                    @if ($locked)
                        <input type="text" class="form-control" value="{{ $kontakLabel }}" disabled>
                    @else
                        <x-search-select model="kontak" :endpoint="route('lookup.kontak')"
                                          :value="$kontak" :selectedText="$kontakLabel" placeholder="cari kontak…" />
                        @error('kontak') <div class="text-danger small">{{ $message }}</div> @enderror
                    @endif
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rekening ({{ $isBank ? 'Bank' : 'Kas' }})</label>
                    @if ($locked)
                        <input type="text" class="form-control" value="{{ $rekeningLabel }}" disabled>
                    @else
                        <x-search-select model="rekening" :endpoint="route('lookup.coa.rekening', ['tipe' => $isBank ? 'bank' : 'kas'])"
                                          :value="$rekening" :selectedText="$rekeningLabel" placeholder="cari akun…" />
                        @error('rekening') <div class="text-danger small">{{ $message }}</div> @enderror
                    @endif
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cabang</label>
                    <select class="form-select" wire:model="cabang" disabled>
                        <option value="{{ $cabang }}">{{ \App\Models\Branch::query()->where('GID', $cabang)->value('GNAMA') }}</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Uraian</label>
                    <input type="text" class="form-control" wire:model="uraian" {{ $locked ? 'disabled' : '' }}>
                </div>

                @if ($isBank)
                    <div class="col-md-3">
                        <label class="form-label">Tipe Pembayaran</label>
                        <select class="form-select @error('tipeBayar') is-invalid @enderror" wire:model.live="tipeBayar" {{ $locked ? 'disabled' : '' }}>
                            <option value="0">Tunai</option>
                            <option value="1">Giro</option>
                            <option value="2">Transfer</option>
                        </select>
                    </div>
                    @if ((int) $tipeBayar === 2)
                        <div class="col-md-4">
                            <label class="form-label">Bank</label>
                            @if ($locked)
                                <input type="text" class="form-control" value="{{ $bankLabel }}" disabled>
                            @else
                                <x-search-select model="bank" :endpoint="route('lookup.bank')"
                                                  :value="$bank" :selectedText="$bankLabel" placeholder="cari bank…" />
                                @error('bank') <div class="text-danger small">{{ $message }}</div> @enderror
                            @endif
                        </div>
                    @elseif ((int) $tipeBayar === 1)
                        <div class="col-md-2">
                            <label class="form-label">No. Cek/Giro</label>
                            <input type="text" class="form-control @error('noGiro') is-invalid @enderror" wire:model="noGiro" {{ $locked ? 'disabled' : '' }}>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tgl Jatuh Tempo</label>
                            <input type="date" class="form-control @error('tglGiro') is-invalid @enderror" wire:model="tglGiro" {{ $locked ? 'disabled' : '' }}>
                        </div>
                    @endif
                @endif
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Detail Biaya {{ $isMasuk ? '(Kredit)' : '(Debit)' }}</strong>
                @unless ($locked)
                    <button type="button" class="btn btn-outline-primary btn-sm" wire:click="addLine">
                        <i class="fas fa-plus me-1"></i> Tambah Baris
                    </button>
                @endunless
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:35%">Akun (COA)</th>
                            <th class="text-end" style="width:160px">Jumlah</th>
                            <th>Catatan</th>
                            @unless ($locked) <th style="width:40px"></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            <tr wire:key="kb-line-{{ $i }}">
                                <td>
                                    @if ($locked)
                                        <span class="small">{{ $l['coaLabel'] }}</span>
                                    @else
                                        <x-search-select :model="'lines.'.$i.'.coa'" :endpoint="route('lookup.coa.biaya', ['arah' => $isMasuk ? 'masuk' : 'keluar'])"
                                                          :value="$l['coa']" :selectedText="$l['coaLabel']" placeholder="cari akun biaya…" />
                                    @endif
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                           wire:model="lines.{{ $i }}.jumlah" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" wire:model="lines.{{ $i }}.catatan" {{ $locked ? 'disabled' : '' }}>
                                </td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">Belum ada baris biaya.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td class="text-end fw-semibold">Total</td>
                            <td class="text-end fw-semibold">{{ number_format($total, 0, ',', '.') }}</td>
                            <td colspan="{{ $locked ? 1 : 2 }}"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @unless ($locked)
                <div class="mt-3 text-end">
                    <button type="button" class="btn btn-success" wire:click="save">
                        <i class="fas fa-save me-1"></i> Simpan
                    </button>
                </div>
            @endunless
        </div>
    </div>
</div>
