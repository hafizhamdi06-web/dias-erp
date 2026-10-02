<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Daftar Stok Barang Serial</strong>
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

                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ser-nol" wire:model="nolSembunyi">
                        <label class="form-check-label" for="ser-nol">Jumlah 0 Tidak Tampil</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ser-stok" wire:model="stokSaja">
                        <label class="form-check-label" for="ser-stok">Stok Saja</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ser-aktif" wire:model="aktifSaja">
                        <label class="form-check-label" for="ser-aktif">Aktif Saja</label>
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
                <strong>Isi laporan:</strong> hanya item yang <strong>memakai nomor serial</strong>
                (<code>ISERIAL = 1</code>), dirinci sampai tiap nomor serial beserta tanggal
                kedaluwarsanya. Jumlah per serial = masuk &minus; keluar dari riwayat serialnya,
                <em>sampai dengan</em> tanggal yang dipilih.
            </div>
        </div>
    </div>
</div>
