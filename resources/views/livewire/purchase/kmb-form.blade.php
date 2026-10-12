@php
    // Dipakai badge di JUDUL, jadi harus dihitung sebelum header dirender.
    [$statusTeks, $statusKelas] = match ($status) {
        3 => ['Diterima', 'text-bg-primary'],
        9 => ['Batal', 'text-bg-danger'],
        default => ['Aktif', 'text-bg-success'],
    };
@endphp
<div>
    <form wire:submit="save">
        <div class="card vb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="card-title mb-0">
                    {{-- Sejak rujukan dipilih DI FORM, judulnya harus tahan keadaan "belum
                         ada rujukan" - dulu berbunyi "KMB Baru dari PR " dgn nomor kosong. --}}
                    @if ($kmbId)
                        KMB {{ $nomor }}
                    @elseif ($jopId)
                        KMB Baru dari JOP {{ $noJop }}
                    @elseif ($prId)
                        KMB Baru dari PR {{ $noPr }}
                    @else
                        KMB Baru
                    @endif
                    {{-- Status pindah ke SINI dari kotak ber-caption di badan form
                         (permintaan user 2026-10-10). Kotak itu tambahan saya, BUKAN dari
                         VB6 - `fFrmKirimMutasiBarang` tidak punya field Status. Sekalian
                         membetulkan badge lama yg SELALU hijau "terkunci" walau dokumennya
                         batal. Cuma utk dokumen tersimpan: KMB baru selalu "Aktif". --}}
                    @if ($kmbId)
                        <span class="badge {{ $statusKelas }} ms-2">{{ $statusTeks }}</span>
                    @endif
                </span>
                <div>
                    @if ($kmbId && can_do('inventory/kmb', 'print'))
                        <a href="{{ route('inventory.kmb.print', $kmbId) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-1"></i> Cetak
                        </a>
                    @endif
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="closeTab">Tutup</button>
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
                     Tata letak MENGIKUTI form VB6 `fFrmKirimMutasiBarang.frm` (menu 573),
                     pola SAMA dgn form Permintaan Barang - gaya di `public/css/vb-form.css`.

                     Urutan field kiri menurut koordinat Top VB6:
                       675 Kontak | 1020 Gudang | 1320 Gudang Tujuan | 2550 Keterangan
                     Kotak kanan menurut Left/Top:
                       (10080,750) Tanggal | (12135,750) No Transaksi
                       (8025,1590) No Job Order | (10080,1590) No PO | (12135,1590) No. Reff

                     **Caption VB6 di atas kotak Tanggal salah ketik**: `ShortcutCaption1(1)`
                     berbunyi "No Transaksi", SAMA dgn index 0 di sebelahnya - sisa salin-tempel,
                     padahal isinya `txtTanggal`. Di form Permintaan Barang posisi yg sama
                     captionnya "Tanggal". Di sini ditulis **Tanggal** (yg benar), BUKAN ditiru
                     salahnya - kalau user lebih suka persis spt layar lama, tinggal diganti.
                     ============================================================================ --}}
                <div class="vb-form">
                    <div class="row g-3">
                        {{-- ===== Kolom kiri ===== --}}
                        <div class="col-lg-7">
                            <fieldset @disabled($locked)>
                                <div class="vb-row">
                                    <label class="form-label">Kontak</label>
                                    <input type="text" class="form-control form-control-sm vb-ctl bg-body-secondary"
                                           value="{{ $kontakLabel ?: '—' }}" disabled
                                           title="{{ $kmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis user yang login, tidak bisa diubah.' }}">
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">Gudang</label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model="cabang" disabled
                                            title="{{ $kmbId ? 'Data tersimpan, tidak bisa diubah.' : 'Otomatis cabang Anda, tidak bisa diubah.' }}">
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="vb-row">
                                    <label class="form-label">Gudang Tujuan</label>
                                    <select class="form-select form-select-sm vb-ctl" wire:model="cabangTujuan" disabled
                                            title="Cabang peminta (dari {{ $jopId ? 'JOP' : 'PR' }} asal), tidak bisa diubah.">
                                        <option value="">—</option>
                                        @foreach ($branches as $b)
                                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </fieldset>
                        </div>

                        {{-- ===== Kolom kanan: kotak ber-caption ===== --}}
                        <div class="col-lg-5">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">Tanggal</div>
                                        <div class="isi">
                                            <input type="date" class="form-control form-control-sm border-0 p-0 px-1"
                                                   wire:model="tanggal" @disabled($locked)>
                                        </div>
                                    </div>
                                    @error('tanggal') <div class="text-danger small">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">No Transaksi</div>
                                        <div class="isi">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $nomor ?: '[otomatis]' }}" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- VB6 punya TIGA kotak rujukan berdampingan: No Job Order | No PO |
                                 No. Reff. KMB di sini lahir dari PR ATAU dari JOP (2026-10-03),
                                 tidak pernah keduanya - jadi yg kosong tetap ditampilkan sbg "—"
                                 supaya letaknya tidak bergeser-geser. "No. Reff" (SJ Online di
                                 VB6) belum ada padanannya, jadi tidak ikut. --}}
                            {{-- Tombol cari menempel di KEDUA kotak rujukan - persis VB6:
                                 `cmdCariNoReff` di "No Job Order" dan `cmdCariSJOnline` di
                                 "No PO". Sejak daftar KMB disederhanakan jadi satu tombol
                                 "Input Baru" (2026-10-10), DI SINILAH satu-satunya tempat
                                 memilih rujukan. Hanya muncul saat KMB belum tersimpan:
                                 KMB tersimpan selalu read-only. --}}
                            @php
                                $peringatanGanti = count($lines)
                                    ? 'Ganti rujukan? Baris yang sekarang — termasuk Qty Keluar yang sudah Anda ubah — akan diganti dengan isi rujukan yang baru.'
                                    : null;
                            @endphp
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">No Job Order</div>
                                        <div class="isi d-flex align-items-center gap-1">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $noJop ?: '—' }}" disabled>
                                            @unless ($locked)
                                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                                        wire:click="bukaCari('{{ $this::SUMBER_JOP }}')"
                                                        title="Cari JOP / JOT — baris di bawah akan diisi dari bahan yang masih bersisa"
                                                        @if ($peringatanGanti) data-confirm="{{ $peringatanGanti }}" @endif>
                                                    <i class="fas fa-magnifying-glass"></i>
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="vb-box">
                                        <div class="cap">No PO</div>
                                        <div class="isi d-flex align-items-center gap-1">
                                            <input type="text" class="form-control form-control-sm border-0 p-0 px-1"
                                                   value="{{ $noPr ?: '—' }}" disabled>
                                            @unless ($locked)
                                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                                        wire:click="bukaCari('{{ $this::SUMBER_PR }}')"
                                                        title="Cari PR — baris di bawah akan diisi dari PR yang dipilih"
                                                        @if ($peringatanGanti) data-confirm="{{ $peringatanGanti }}" @endif>
                                                    <i class="fas fa-magnifying-glass"></i>
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @unless ($locked || $prId || $jopId)
                                <div class="alert alert-info py-2 px-3 small mb-2">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Pilih dulu rujukannya lewat tombol <i class="fas fa-magnifying-glass"></i>
                                    di <strong>No PO</strong> (dari Permintaan Barang) atau
                                    <strong>No Job Order</strong> (dari JOP/JOT).
                                </div>
                            @endunless

                        </div>
                    </div>

                    {{-- Keterangan: di VB6 lebarnya 12060 twip - hampir selebar form, membentang
                         di bawah kedua kolom. --}}
                    <fieldset @disabled($locked)>
                        <div class="vb-row mt-2">
                            <label class="form-label">Keterangan</label>
                            <input type="text" class="form-control form-control-sm vb-ctl-full" wire:model="uraian">
                        </div>
                    </fieldset>

                    {{-- ---- Baris item (ditarik dari PR/JOP, tidak bisa tambah bebas) ----
                         Susunan kolom = `FormatString` VB6 (diambil dari .frx offset 0x0E28):
                         <Kode|<Nama|>Qty Masuk|>Qty Keluar|<Satuan|>Harga Masuk|<Catatan|
                         QtyDasar|SatuanDasar|Konversi|<IDd_PO|<Pakai Batch|<No Batch|<Id JOP

                         Yg tidak ikut: QtyDasar/SatuanDasar/Konversi/IDd_PO/Id JOP (kolom
                         simpanan tak kasatmata), "Harga Masuk" (KMB di sini tidak memegang
                         harga), dan "Pakai Batch"/"No Batch" (batch belum ditangani modul ini).
                         "Qty Masuk" juga tidak ikut: di dokumen KIRIM nilainya selalu 0 -
                         barangnya keluar, bukan masuk.
                         TAMBAHAN dari saya: kolom "Qty Diminta" - tidak ada di VB6, tapi tanpa
                         itu petugas tidak tahu batas kirimnya berapa. --}}
                    <div class="table-responsive vb-grid-area mt-3">
                        <table class="table table-sm table-bordered align-middle vb-grid">
                            <thead>
                                <tr>
                                    <th style="width: 20%">Kode</th>
                                    <th>Nama</th>
                                    <th class="text-end" style="width: 100px">Qty Diminta</th>
                                    <th class="text-end" style="width: 110px">Qty Keluar</th>
                                    <th style="width: 80px">Satuan</th>
                                    <th style="width: 24%">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $i => $l)
                                    <tr wire:key="line-{{ $l['item'] }}">
                                        <td>{{ $l['kode'] }}</td>
                                        <td>{{ $l['nama'] }}</td>
                                        <td class="text-end">{{ number_format($l['qtyMinta'], 2, ',', '.') }}</td>
                                        <td>
                                            <input type="number" min="0" step="any" max="{{ $l['qtyMinta'] }}"
                                                   class="form-control form-control-sm text-end"
                                                   wire:model="lines.{{ $i }}.qty" @disabled($locked)>
                                        </td>
                                        <td>{{ $l['satuanKode'] ?: '—' }}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   wire:model="lines.{{ $i }}.catatan" @disabled($locked)>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada item dari {{ $jopId ? 'JOP' : 'PR' }} ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- VB6 menampilkan DUA total berdampingan (Total Qty Masuk & Total Qty
                         Keluar). Di sini cuma yg Keluar yg punya arti - lihat catatan kolom
                         "Qty Masuk" di atas. --}}
                    <div class="vb-total">
                        <span>Total Qty Keluar</span>
                        <span class="nilai">{{ number_format($totalQty, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Dialog "Cari Transaksi" - SATU dialog untuk kedua sumber, dibedakan `$ctSumber`
         persis spt `lblSumber` di VB6. Lihat trait `App\Livewire\Concerns\CariTransaksi`. --}}
    @if ($ctShow)
        <x-cari-transaksi :branches="$branches" :kolom="$this->ctKolom()" :hasil="$ctHasil"
                          :pilih="$ctPilih" :total-tanpa-saringan="$ctTotalTanpaSaringan"
                          :batas="$ctBatas" :cabang="$ctCabang"
                          :cabang-terkunci="$this->ctCabangTerkunci()"
                          :pakai-kode-produk="$this->ctPakaiKodeProduk()" />
    @endif
</div>
