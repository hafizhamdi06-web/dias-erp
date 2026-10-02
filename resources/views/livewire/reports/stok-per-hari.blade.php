<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Stok Per Hari</strong>
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
                    <label class="form-label">Jenis Item</label>
                    <select class="form-select" wire:model="jenisItem">
                        <option value="">Semua</option>
                        @foreach (\App\Livewire\Reports\DaftarStokBarang::JENIS_ITEM as $k => $v)
                            <option value="{{ $k }}">{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jenis Produk</label>
                    <select class="form-select" wire:model="jenisProduk">
                        <option value="">Semua</option>
                        @foreach (\App\Livewire\Reports\DaftarStokBarang::JENIS_PRODUK as $k => $v)
                            <option value="{{ $k }}">{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Item <span class="text-muted small">(kosongkan = semua)</span></label>
                    <x-search-select model="item" :endpoint="route('lookup.item-id')"
                                      :value="$item" :selectedText="$itemLabel" placeholder="semua item…" />
                </div>
                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sph-stok" wire:model="stokSaja">
                        <label class="form-check-label" for="sph-stok">Stok Saja</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sph-aktif" wire:model="aktifSaja">
                        <label class="form-check-label" for="sph-aktif">Aktif Saja</label>
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
                <strong>Isi laporan:</strong> per item — <strong>Saldo Awal</strong> (dari master item),
                <strong>Masuk</strong> &amp; <strong>Keluar</strong> dalam rentang tanggal, lalu
                <strong>Stok Akhir</strong> = Saldo Awal + Masuk &minus; Keluar.
                <br>
                Item <strong>tetap tampil walau tidak ada mutasi</strong> di rentang itu — daftarnya
                mengikuti cabang pada master item, bukan hanya yang bergerak.
                <br>
                Saldo Awal di master <strong>mayoritas masih 0</strong> (105 dari 5.580 item), jadi
                untuk sebagian besar item Stok Akhir sama dengan mutasi bersihnya.
            </div>
        </div>
    </div>
</div>
