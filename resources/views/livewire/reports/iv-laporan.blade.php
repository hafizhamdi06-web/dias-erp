{{-- Layar filter BERSAMA enam laporan keluarga "IV Penjualan" (menu 780/788/796/819/821/822).
     VB6 memakai blok filter yang sama untuk keenamnya - lihat IvLaporanBase. --}}
<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>{{ $judul }}</strong>
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
                    <label class="form-label">Cabang <span class="text-muted small">(gudang invoice)</span></label>
                    <select class="form-select" wire:model="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kelompok 2020</label>
                    <select class="form-select" wire:model="kelompok">
                        <option value="">Semua</option>
                        @foreach ($kelompokList as $k)
                            <option value="{{ $k->IK2ID }}">{{ $k->IK2KODE }}</option>
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
                <strong>Satu baris per item invoice</strong> (bukan per dokumen).
                <strong>Subtotal</strong> = Qty &times; (Harga &minus; Diskon) — kolom ini
                tambahan, di cetakan lama penjumlahannya dikerjakan oleh pencetaknya.
                <br>
                Cabang yang disaring adalah <strong>gudang invoice</strong>; kolom
                <em>Cabang Pelanggan</em> diambil dari surat jalan terkait.
            </div>
        </div>
    </div>
</div>
