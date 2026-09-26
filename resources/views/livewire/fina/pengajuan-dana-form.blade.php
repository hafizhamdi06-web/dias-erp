<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Pengajuan Dana</strong>
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
            @if (session('info'))
                <div class="alert alert-info">{{ session('info') }}</div>
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-2">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control @error('tanggal') is-invalid @enderror" wire:model="tanggal" {{ $locked ? 'disabled' : '' }}>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kontak (Keuangan)</label>
                    @if ($locked)
                        <input type="text" class="form-control" value="{{ $kontakLabel }}" disabled>
                    @else
                        <x-search-select model="kontak" :endpoint="route('lookup.kontak-keuangan')"
                                          :value="$kontak" :selectedText="$kontakLabel" placeholder="cari kontak…" />
                        @error('kontak') <div class="text-danger small">{{ $message }}</div> @enderror
                    @endif
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rekening (COA)</label>
                    @if ($locked)
                        <input type="text" class="form-control" value="{{ $rekeningLabel }}" disabled>
                    @else
                        <x-search-select model="rekening" :live="true" :endpoint="route('lookup.coa.rekening')"
                                          :value="$rekening" :selectedText="$rekeningLabel" placeholder="cari akun…" />
                        @error('rekening') <div class="text-danger small">{{ $message }}</div> @enderror
                    @endif
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cabang</label>
                    <input type="text" class="form-control" value="{{ \App\Models\Branch::query()->where('GID', $cabang)->value('GNAMA') }}" disabled>
                    @error('cabang') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Uraian</label>
                    <input type="text" class="form-control" wire:model="uraian" {{ $locked ? 'disabled' : '' }}>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Baris Kas Keluar Ditarik</strong>
                @unless ($locked)
                    <button type="button" class="btn btn-primary btn-sm" wire:click="tarikData">
                        <i class="fas fa-file-import me-1"></i> Tarik Data
                    </button>
                @endunless
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No. KK</th>
                            <th>Akun (COA)</th>
                            <th class="text-end" style="width:160px">Jumlah</th>
                            <th>Catatan</th>
                            @unless ($locked) <th style="width:40px"></th> @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $l)
                            <tr wire:key="pdn-line-{{ $i }}">
                                <td class="text-muted small">{{ $l['noKk'] }}</td>
                                <td class="small">{{ $l['coaLabel'] }}</td>
                                <td class="text-end">{{ number_format($l['jumlah'], 0, ',', '.') }}</td>
                                <td class="small">{{ $l['catatan'] }}</td>
                                @unless ($locked)
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeLine({{ $i }})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data. Pilih rekening lalu klik "Tarik Data".</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="2" class="text-end fw-semibold">Total</td>
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
