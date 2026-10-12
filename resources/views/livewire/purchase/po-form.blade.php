<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{ $poId ? 'PO ' . $nomor : 'PO Baru' }}
                    @if ($locked) <span class="badge text-bg-danger ms-2">dibatalkan</span> @endif
                </span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
                    @if ($poId && can_do('purchase/po', 'print'))
                        <a href="{{ route('purchase.po.print', $poId) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-1"></i> Cetak
                        </a>
                    @endif
                    @unless ($locked)
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            Simpan
                        </button>
                    @endunless
                </div>
            </div>
            <div class="card-body">
                @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                {{-- ============================================================================
                     Tata letak MENGIKUTI form VB6 `dFrmOrderPembelian.frm` - `amenu` MID **233**
                     "Order Pembelian" -> `aMod_Menu.bas` `Case 233: Set xForm =
                     dFrmOrderPembelian`. MID-nya DIPASTIKAN KE USER 2026-10-10: ada TIGA menu
                     "Order Pembelian" yg sama-sama hidup (233, 734 `_2023`, 816 `_2025`
                     MACTIVE=0), dan 734/816 menambah tarik-dari-PKB/PR yg belum ada di sini.
                     Gaya di `public/css/vb-form.css` - dulu blade ini punya blok gaya sendiri
                     yg ditempel inline. (Jangan tulis nama tag gaya itu apa adanya di komentar:
                     parser HTML editor mengira blok CSS dibuka & seluruh berkas ikut merah.)

                     Koordinat VB6 dibaca dgn sarang kontainer DIPERHITUNGKAN - panel total ada
                     di dalam `Picture1`, jadi Top/Left mentahnya menyesatkan (kelihatan
                     tumpang tindih dgn Vendor padahal tidak).

                     Urutan field kiri menurut Top VB6:  645 Vendor | 990 Kontak | 1290 Alamat
                     Kanan baris 1 (~750): (8145) Pajak | (10095) Tanggal | (12150) No Transaksi
                     Kanan baris 2 (~1610): (4665) Kurs | (6555) Uang | (8310) Nilai Pajak |
                                            (10140) Bag Pembelian | (12150) Termin
                     Grid Top 2490. Bawah: Catatan (255,5895) & Gudang (315,6840) di kiri,
                     panel total di kanan (Picture1 9735,5955).

                     TIDAK ikut krn di VB6 pun `Visible = 0` (sisa fitur mati, sudah dicek satu
                     per satu): **No. Permintaan**, **Departemen**, **Nama**, **Periode Awal**,
                     **Periode Akhir**. `No. Reff` JUGA tersembunyi di VB6, tapi di sini
                     DIPERTAHANKAN krn `SOUNOREFF` memang dipakai & tampil di daftar PO.
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== KIRI: Vendor / Kontak / Alamat ===== --}}
                        <div class="col-lg-6">
                            <fieldset @disabled($locked)>
                                {{-- Vendor dicari lewat MODAL "Cari Kontak" (permintaan user
                                     2026-10-11), bukan dropdown ketik-cari. Ini memang pola
                                     VB6-nya: `cmdCariVendor_Click` membuka `bFrmCariKontak`
                                     dgn kategori SUPPLIER. Kotaknya read-only - vendor hanya
                                     bisa datang dari dialog, spt `txtVendor` yg Locked. --}}
                                <div class="vb-row">
                                    <label class="form-label">Vendor <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm vb-ctl-wide">
                                        <input type="text" class="form-control" readonly
                                               value="{{ $vendorLabel ?: '' }}" placeholder="belum dipilih">
                                        <button type="button" class="btn btn-outline-secondary"
                                                wire:click="bukaCariKontak('vendor')" title="Cari kontak" @disabled($locked)>
                                            <i class="fas fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                </div>
                                @error('vendor') <div class="text-danger small vb-err">{{ $message }}</div> @enderror
                                <div class="vb-row">
                                    <label class="form-label">Kontak</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl-wide" wire:model="attention"
                                           placeholder="nama kontak (teks bebas)">
                                </div>
                                <div class="vb-row align-items-start">
                                    <label class="form-label">Alamat</label>
                                    <textarea class="form-control form-control-sm vb-ctl-wide" rows="3" wire:model="alamat"></textarea>
                                </div>
                            </fieldset>
                        </div>

                        {{-- ===== KANAN: kotak ber-caption, spt SSPanel VB6 ===== --}}
                        <div class="col-lg-6">
                            <fieldset @disabled($locked)>
                                <div class="row g-2">
                                    <div class="col-5">
                                        <div class="vb-box">
                                            <div class="cap">Pajak</div>
                                            <div class="isi">
                                                <select class="form-select form-select-sm" wire:model.live="pajak">
                                                    <option value="0">Tanpa Pajak</option>
                                                    <option value="1">Harga Belum Termasuk Pajak</option>
                                                    <option value="2">Harga Sudah Termasuk Pajak</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="vb-box">
                                            <div class="cap">Tanggal</div>
                                            <div class="isi">
                                                <input type="date" class="form-control form-control-sm" wire:model="tanggal">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="vb-box">
                                            <div class="cap">No Transaksi</div>
                                            <div class="isi">
                                                <input type="text" class="form-control form-control-sm"
                                                       value="{{ $nomor ?: '[otomatis]' }}" disabled>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror

                                <div class="row g-2">
                                    <div class="col-3">
                                        <div class="vb-box">
                                            <div class="cap">Kurs</div>
                                            <div class="isi">
                                                <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                                       wire:model="kurs">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="vb-box">
                                            <div class="cap">Uang</div>
                                            <div class="isi">
                                                <select class="form-select form-select-sm" wire:model="uang">
                                                    <option value="">—</option>
                                                    @foreach ($uangList as $u)
                                                        {{-- "RP - RP" mengulang: di `buang` UKODE & UNAMA
                                                             memang sering sama, jadi tampilkan kodenya saja. --}}
                                                        <option value="{{ $u->UID }}">
                                                            {{ $u->UNAMA && $u->UNAMA !== $u->UKODE ? $u->UKODE . ' - ' . $u->UNAMA : $u->UKODE }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="vb-box">
                                            {{-- Selalu tampil (spt VB6) supaya kolomnya tidak loncat-loncat;
                                                 dimatikan saja kalau Tanpa Pajak. --}}
                                            <div class="cap">Nilai Pajak</div>
                                            <div class="isi">
                                                <select class="form-select form-select-sm" wire:model.live="nilaiPajak"
                                                        @disabled($locked || $pajak === 0)>
                                                    @foreach ($nilaiPajakOpt as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}%</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Termin DULU, baru Bag Pembelian - permintaan user
                                         2026-10-10. Ini SENGAJA beda dari VB6, yg menaruh Bag
                                         Pembelian (Left 10140) di kiri Termin (Left 12150).
                                         Jangan "dibetulkan" balik ke urutan VB6. --}}
                                    <div class="col-3">
                                        <div class="vb-box">
                                            <div class="cap">Termin</div>
                                            <div class="isi">
                                                <x-search-select model="termin" :value="$termin" :selected-text="$terminLabel"
                                                                  :endpoint="route('lookup.termin')" placeholder="cari termin…" />
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="vb-box">
                                            <div class="cap">Bag Pembelian</div>
                                            <div class="isi">
                                                {{-- Dialog yg SAMA dgn Vendor, kategori KARYAWAN -
                                                     VB6 `cmdCariSales_Click` juga membuka
                                                     `bFrmCariKontak`, cuma beda cboKategori. --}}
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control" readonly
                                                           value="{{ $karyawanLabel ?: '' }}" placeholder="belum dipilih">
                                                    <button type="button" class="btn btn-outline-secondary"
                                                            wire:click="bukaCariKontak('karyawan')"
                                                            title="Cari karyawan" @disabled($locked)>
                                                        <i class="fas fa-magnifying-glass"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @error('termin') <div class="text-danger small">{{ $message }}</div> @enderror
                            </fieldset>
                        </div>
                    </div>

                @unless ($locked)
                    {{-- Item dicari lewat MODAL "Cari Barang" (permintaan user 2026-10-11),
                         bukan kotak ketik-cari dgn daftar melayang. Ini pola VB6-nya:
                         `IG_CellButtonClick` Case 0 / F12 -> `CariBarang` (`bFrmCariItem`),
                         dialog yg SAMA dgn form Permintaan Barang. --}}
                    <button type="button" class="btn btn-outline-primary btn-sm mb-2"
                            wire:click="bukaCariBarang">
                        <i class="fas fa-plus me-1"></i> Tambah Item
                    </button>
                @endunless

                {{-- Susunan kolom = `FormatString` VB6:
                     <Kode|<Nama|>Qty|<Satuan|<Kemasan|>Harga|>Disc|>Disc % 1|>Disc % 2|
                     >Disc % 3|>Total|<Jenis|QtyDasar|SatuanDasar|Konversi|idpermintaan|
                     QTYSISA|<Cabang|<Catatan|>Harga Klinik|>Satuan Klinik|>Qty Klinik|<Kode

                     **Disc Rp DIPINDAH ke SEBELUM tiga kolom persen**, mengikuti VB6 - dulu
                     di sini ditaruh sesudahnya.

                     Tidak ikut: kolom simpanan tak kasatmata (QtyDasar/SatuanDasar/Konversi/
                     idpermintaan/QTYSISA/Kode kedua), dan **Jenis / Cabang / Harga Klinik /
                     Satuan Klinik / Qty Klinik** yg belum ada padanannya di app ini.

                     Kode & Nama digabung satu kolom (`x-nama-produk`) spt form PKB/SJ. --}}
                <div class="table-responsive vb-grid-area">
                    <table class="table table-sm table-bordered align-middle vb-grid">
                        <thead>
                            <tr>
                                <th>Nama Produk</th>
                                <th class="text-end" style="width: 80px">Qty</th>
                                <th style="width: 70px">Satuan</th>
                                <th style="width: 100px">Kemasan</th>
                                <th class="text-end" style="width: 100px">Harga</th>
                                <th class="text-end" style="width: 90px">Disc</th>
                                <th class="text-end th-wrap" style="width: 65px">Disc % 1</th>
                                <th class="text-end th-wrap" style="width: 65px">Disc % 2</th>
                                <th class="text-end th-wrap" style="width: 65px">Disc % 3</th>
                                <th class="text-end" style="width: 100px">Total</th>
                                <th>Catatan</th>
                                @unless ($locked) <th style="width: 36px"></th> @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['item'] }}">
                                    <td><x-nama-produk :kode="$l['kode']" :nama="$l['nama']" /></td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                    </td>
                                    <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.kemasan" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live="lines.{{ $i }}.harga" @disabled($locked)>
                                    </td>
                                    <td class="text-end">{{ number_format($l['discRp'], 0, ',', '.') }}</td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live="lines.{{ $i }}.disc1" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live="lines.{{ $i }}.disc2" @disabled($locked)>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" class="form-control form-control-sm text-end"
                                               wire:model.live="lines.{{ $i }}.disc3" @disabled($locked)>
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($l['total'], 0, ',', '.') }}</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                    </td>
                                    @unless ($locked)
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeLine({{ $i }})">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr><td colspan="{{ $locked ? 11 : 12 }}" class="text-center text-muted py-4">Belum ada item.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Bawah: Catatan (255,5895) + Gudang (315,6840) di kiri, panel total di
                     kanan (Picture1 di 9735,5955) - susunan VB6. --}}
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <fieldset @disabled($locked)>
                            <div class="vb-row align-items-start">
                                <label class="form-label">Catatan</label>
                                <textarea class="form-control form-control-sm vb-ctl-full" rows="2" wire:model="catatan"></textarea>
                            </div>
                            <div class="vb-row">
                                <label class="form-label">No Ref</label>
                                <input type="text" class="form-control form-control-sm vb-ctl" wire:model="noRef">
                            </div>
                            <div class="vb-row">
                                <label class="form-label">Gudang <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm vb-ctl" wire:model="gudang" disabled>
                                    <option value="">—</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-text vb-err">Gudang otomatis cabang Anda, tidak bisa diubah.</div>
                        </fieldset>
                    </div>

                    <div class="col-md-6">
                        <table class="table table-sm mb-0 align-middle vb-grid">
                            {{-- Baris PERTAMA panel total VB6 = `lblTotalQty`, di atas Sub Total. --}}
                            <tr>
                                <td style="width: 45%">Total Qty</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($totalQty, 2, ',', '.'), '0'), ',') }}</td>
                            </tr>
                            <tr>
                                <td>Sub Total</td>
                                <td class="text-end">{{ number_format($subtotal, 0, ',', '.') }}</td>
                            </tr>
                            {{-- Diskon tambahan: isi SALAH SATU, pasangannya dihitung otomatis. --}}
                            <tr>
                                <td>Diskon</td>
                                <td>
                                    @if ($locked)
                                        <div class="text-end">
                                            {{ number_format($diskon, 0, ',', '.') }}
                                            @if ((float) $diskonPersen > 0)
                                                <span class="text-muted small">
                                                    ({{ rtrim(rtrim(number_format($diskonPersen, 2, ',', '.'), '0'), ',') }}%)
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="d-flex align-items-center gap-1 justify-content-end">
                                            <input type="number" min="0" max="100" step="any"
                                                   class="form-control form-control-sm text-end" style="width: 80px"
                                                   wire:model.live.debounce.500ms="diskonPersen" title="Diskon dalam persen">
                                            <span class="text-muted">%</span>
                                            <input type="number" min="0" step="any"
                                                   class="form-control form-control-sm text-end" style="width: 130px"
                                                   wire:model.live.debounce.500ms="diskon" title="Diskon dalam rupiah">
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            <tr><td>Pajak</td><td class="text-end">{{ number_format($totalPajak, 0, ',', '.') }}</td></tr>
                            <tr class="fw-bold">
                                <td>Total Transaksi</td>
                                <td class="text-end">{{ number_format($totalTransaksi, 0, ',', '.') }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                </div>{{-- /.vb-form --}}
            </div>
        </div>
    </form>

    {{-- Dialog - di LUAR <form> supaya tombol di dalamnya tidak ikut men-submit PO. --}}
    @if ($cbShow)
        <x-cari-barang :hasil="$cbHasil" :pilih="$cbPilih" :data="$cbData"
                       :total="$cbTotal" :batas="$cbBatas" :ditambah="$cbDitambah" />
    @endif

    @if ($ckShow)
        <x-cari-kontak :hasil="$ckHasil" :pilih="$ckPilih" :total="$ckTotal" :batas="$ckBatas"
                       :kategori-list="$kategoriList" :field-opsi="$this->ckFieldOpsi()"
                       :branches="$branches" />
    @endif
</div>
