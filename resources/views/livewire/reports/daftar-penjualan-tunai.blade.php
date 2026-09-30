<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Daftar Penjualan Tunai (POS - IP)</strong>
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closeTab">
                <i class="fas fa-xmark me-1"></i> Tutup
            </button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control @error('from') is-invalid @enderror" wire:model="from">
                    @error('from') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control @error('to') is-invalid @enderror" wire:model="to">
                    @error('to') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cabang</label>
                    <select class="form-select" wire:model="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jenis Merchant</label>
                    <select class="form-select" wire:model="merchant">
                        <option value="">Semua merchant</option>
                        @foreach ($merchants as $m)
                            <option value="{{ $m->MCKODE }}">{{ $m->MCNAMA }}</option>
                        @endforeach
                    </select>
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
                <strong>Isi laporan:</strong> satu baris per transaksi POS (IP), di luar dokumen yang
                dibatalkan, dengan rincian tiap cara bayar.
                <br>
                <strong>Kas Nett</strong> = Kas &minus; Cash Back &middot;
                <strong>Total Real</strong> = Kas Nett + Debit + Kredit + Transfer + Merchant &middot;
                <strong>Total Semua</strong> = Total Real + DP + Voucher + Piutang + DP Surgery
                + Surgery &minus; Tarik DP.
                <br>
                Baris <em>Total TANPA Piutang Surgery</em> hanya menjumlah transaksi yang
                <strong>Piutang Bayar</strong>-nya nol.
            </div>
        </div>
    </div>
</div>
