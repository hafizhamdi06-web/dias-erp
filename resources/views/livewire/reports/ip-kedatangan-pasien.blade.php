<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>IP Kedatangan Pasien</strong>
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
                    <label class="form-label">Jenis Kunjungan</label>
                    {{-- Nilai = fstoku.SUDKKWALKIN (0/1/2), label mengikuti VB6 baris 2574-2576. --}}
                    <select class="form-select" wire:model="jenisKunjungan">
                        <option value="">Semua</option>
                        <option value="0">Hanya Beli</option>
                        <option value="1">DKK</option>
                        <option value="2">Walk In</option>
                    </select>
                </div>

                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ikp-tindakan" wire:model="tindakanSaja">
                        <label class="form-check-label" for="ikp-tindakan">Tindakan Saja</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ikp-dokter" wire:model="adaDokterSaja">
                        <label class="form-check-label" for="ikp-dokter">Yang Ada Dokter Saja</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ikp-nh" wire:model="tanpaNh">
                        <label class="form-check-label" for="ikp-nh">Tanpa National Hospital</label>
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
                <strong>Satu baris per pasien.</strong> Yang dihitung <em>kedatangan</em>, bukan transaksi:
                satu pasien pada satu <strong>tanggal</strong> dihitung <strong>satu kali</strong>,
                berapa pun jumlah transaksinya hari itu.
                <br>
                <strong>Berbayar</strong> = kedatangan yang nilainya di atas nol &middot;
                <strong>Dgn Dokter</strong> = kedatangan berbayar yang ada dokternya dan bukan resep itter.
                <br>
                <strong>Nilai</strong> memakai rumus khusus warisan: kelompok 8 dikurangi bayar DP,
                kelompok 10 (surgery) diprorata terhadap piutang &amp; DP-nya, selain itu
                qty &times; (harga &minus; diskon).
            </div>
        </div>
    </div>
</div>
