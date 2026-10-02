<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Daftar Surat Jalan Barang</strong>
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
                    <label class="form-label">No SJ Dari</label>
                    <input type="text" class="form-control" wire:model="noDari" placeholder="kosongkan = semua">
                </div>
                <div class="col-md-3">
                    <label class="form-label">No SJ Sampai</label>
                    <input type="text" class="form-control" wire:model="noSampai" placeholder="kosongkan = cocok persis">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Cabang Pengirim</label>
                    <select class="form-select" wire:model="cabang">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gudang Tujuan</label>
                    <select class="form-select" wire:model="gudangTujuan">
                        <option value="">Semua</option>
                        @foreach ($semuaCabang as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">COA 2021</label>
                    <select class="form-select" wire:model="coa2021">
                        <option value="">Semua</option>
                        @foreach ($coaTipes as $c)
                            <option value="{{ $c->CTTIPEID }}">{{ $c->CTNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">PT</label>
                    <select class="form-select" wire:model="pt">
                        <option value="">Semua</option>
                        @foreach ($ptList as $p)
                            <option value="{{ $p->NPID }}">{{ $p->NPKODE }} — {{ $p->NPNAMA }}</option>
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
                <strong>Isi laporan:</strong> satu baris per <em>item</em> Surat Jalan (bukan per dokumen),
                lengkap dengan pelanggan, sales, gudang asal/tujuan, dan PT gudang tujuan.
                <br>
                <strong>No SJ</strong>: isi keduanya untuk rentang; isi yang kiri saja untuk mencari
                satu nomor persis.
                <br>
                <strong>PT</strong> diambil dari PT <em>gudang tujuan</em>, bukan gudang pengirim.
            </div>
        </div>
    </div>
</div>
