<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Jumlah DP Pertanggal</strong>
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                <i class="fas fa-xmark me-1"></i> Tutup
            </button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal <span class="text-muted small">(posisi s/d)</span></label>
                    <input type="date" class="form-control @error('tanggal') is-invalid @enderror" wire:model="tanggal">
                    @error('tanggal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cabang <span class="text-muted small">(asal DP)</span></label>
                    <select class="form-select" wire:model="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="dp-rinci" wire:model="rinci">
                        <label class="form-check-label" for="dp-rinci">Rinci per mutasi</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="dp-nol" wire:model="sembunyikanNol"
                               @disabled($rinci)>
                        <label class="form-check-label" for="dp-nol">Sembunyikan saldo 0</label>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="button" class="btn btn-primary" wire:click="tampilkanPdf">
                    <span wire:loading wire:target="tampilkanPdf" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="fas fa-file-pdf me-1"></i> Tampilkan PDF
                </button>
                <button type="button" class="btn btn-outline-success ms-2" wire:click="unduhExcel">
                    <i class="fas fa-file-excel me-1"></i> Excel
                </button>
            </div>

            <div class="alert alert-light border mt-3 mb-0 small">
                <strong>Posisi saldo DP</strong> sampai dengan tanggal yang dipilih (bukan rentang).
                Sumbernya buku besar DP: <strong>bertambah</strong> saat DP dibeli,
                <strong>berkurang</strong> saat DP dipakai di transaksi lain.
                <br>
                <strong>Cabang</strong> yang ditampilkan adalah cabang <em>asal DP</em>, bukan cabang
                tempat DP dipakai.
                <br>
                <span class="text-danger">Catatan:</span> di data saat ini hampir semua DP langsung
                terpakai habis, sehingga saldonya 0. Karena itu <em>Sembunyikan saldo 0</em>
                sengaja tidak dinyalakan secara bawaan — kalau dinyalakan, laporannya bisa kosong.
            </div>
        </div>
    </div>
</div>
