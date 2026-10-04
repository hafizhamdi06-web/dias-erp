<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>IP Penjualan Per Dokter</strong>
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

                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ipd-rinci" wire:model="rinciKelompok">
                        <label class="form-check-label" for="ipd-rinci">Rinci per Kelompok</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ipd-tindakan" wire:model="tindakanSaja">
                        <label class="form-check-label" for="ipd-tindakan">Tindakan Saja</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ipd-nh" wire:model="tanpaNh">
                        <label class="form-check-label" for="ipd-nh">Tanpa National Hospital</label>
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
                <strong>Satu baris per dokter</strong> — centang <em>Rinci per Kelompok</em> untuk
                memecahnya per Kelompok 2020.
                <br>
                <strong>Dokter</strong> yang diakui adalah <em>perujuk</em> bila baris itu punya
                dokter perujuk; selain itu dokter pelaksana.
                <strong>Jumlah Pasien</strong> dihitung per tanggal — satu pasien pada satu
                tanggal dihitung satu kali.
                <br>
                <span class="text-danger">Penting:</span> hanya kontak ber-<strong>jenis karyawan
                Dokter</strong> yang masuk. Kalau hasilnya kosong padahal ada transaksi, besar
                kemungkinan jenis karyawan dokternya belum diisi di master kontak.
            </div>
        </div>
    </div>
</div>
