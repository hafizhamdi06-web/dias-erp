<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Laporan IP Tindakan/Produk Per Bulan</strong>
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
                <div class="col-md-4">
                    <label class="form-label">Cabang</label>
                    <select class="form-select" wire:model="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
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
                <strong>Isi laporan:</strong> rekap Qty, Nilai &amp; Pasien dikelompokkan
                <em>Cabang → Bulan → Tindakan/Produk</em>, dari transaksi POS (IP) dan Alkes Depo (AL),
                di luar dokumen yang dibatalkan.
                <br>
                <strong>Catatan kolom Pasien:</strong> per baris hanya menghitung transaksi yang ada
                harganya. Total pasien hanya muncul di baris <em>Total &lt;Bulan&gt;</em> — satu pasien
                per hari dihitung sekali walau bertransaksi berkali-kali, jadi angkanya wajar lebih kecil
                daripada jumlah kolom di atasnya.
            </div>
        </div>
    </div>
</div>
