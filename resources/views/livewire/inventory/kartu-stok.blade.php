@php
    $ang = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    // Kolom tabel: 7 kolom dasar, +1 kolom Gudang kalau mode "Semua Gudang".
    $kolom = $semuaGudang ? 8 : 7;
@endphp

<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1 small text-muted">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px"
                           wire:model.live="dari">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 155px"
                           wire:model.live="sampai">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Cabang</label>
                    <select class="form-select form-select-sm" style="width: 190px" wire:model.live="cabang">
                        <option value="">Semua Gudang</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->GID }}">{{ $b->GNAMA }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="width: 330px">
                    <label class="form-label mb-1 small text-muted">
                        Nama Item <span class="text-danger">*</span>
                    </label>
                    {{-- live: item wajib & jadi penentu seluruh laporan, jadi begitu dipilih
                         kartunya langsung dimuat (tanpa tombol "Tampilkan"). --}}
                    <x-search-select model="item" :value="$item" :selected-text="$itemLabel"
                                     :endpoint="route('lookup.item-id')" :live="true"
                                     placeholder="cari kode / nama item…" />
                </div>
            </div>
        </div>

        @if (! $item)
            {{-- Nama Item WAJIB - tanpa item, kartu stok tidak punya arti. --}}
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-boxes-stacked fa-2x mb-2 d-block opacity-50"></i>
                Pilih <strong>Nama Item</strong> dulu untuk menampilkan kartu stoknya.
            </div>
        @else
            <div class="card-body pb-2">
                {{-- ===== RINGKASAN ===== --}}
                <div class="row g-2 mb-1">
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Saldo Awal</div>
                            <div class="fs-5 fw-semibold">{{ $ang($saldoAwal) }} <span class="fs-6 text-muted">{{ $satuan }}</span></div>
                            <div class="small text-muted">per {{ \Carbon\Carbon::parse($dari)->format('d/m/Y') }}</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Total Masuk</div>
                            <div class="fs-5 fw-semibold text-success">{{ $ang($totalMasuk) }}</div>
                            <div class="small text-muted">{{ count($rows) }} baris mutasi</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">Total Keluar</div>
                            <div class="fs-5 fw-semibold text-danger">{{ $ang($totalKeluar) }}</div>
                            <div class="small text-muted">s/d {{ \Carbon\Carbon::parse($sampai)->format('d/m/Y') }}</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-2 h-100 bg-body-secondary">
                            <div class="small text-muted">Saldo Akhir</div>
                            <div class="fs-5 fw-bold">{{ $ang($saldoAkhir) }} <span class="fs-6 text-muted">{{ $satuan }}</span></div>
                            @if ($stokSistem['nilai'] !== null)
                                @php $selisih = (float) $stokSistem['nilai'] - (float) $saldoAkhir; @endphp
                                <div class="small {{ abs($selisih) < 0.001 ? 'text-success' : 'text-warning-emphasis' }}">
                                    Stok Sistem: {{ $ang($stokSistem['nilai']) }}
                                    @if (abs($selisih) >= 0.001)
                                        (selisih {{ $selisih > 0 ? '+' : '' }}{{ $ang($selisih) }})
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- ===== CATATAN KETERBATASAN DATA (jangan disembunyikan) ===== --}}
                @if ($stokSistem['nilai'] !== null && abs((float) $stokSistem['nilai'] - (float) $saldoAkhir) >= 0.001)
                    <div class="alert alert-warning py-2 px-3 small mb-2">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        <strong>Saldo Akhir beda dari Stok Sistem</strong> ({{ $stokSistem['kolom'] }} di master item).
                        Saldo kartu ini dihitung murni dari mutasi <em>fstoku/fstokd</em>, dan data mutasi di
                        database ini paling awal <strong>01/06/2026</strong> — stok yang sudah ada sebelum
                        tanggal itu tidak punya jejak mutasi, jadi Saldo Awal-nya tidak terhitung.
                        @if ($stokSistem['gabungan'] !== [])
                            Selain itu kolom <code>{{ $stokSistem['kolom'] }}</code> dipakai bersama gudang:
                            <strong>{{ implode(', ', $stokSistem['gabungan']) }}</strong> — jadi angka Stok
                            Sistem itu bukan milik gudang ini saja.
                        @endif
                    </div>
                @elseif ($stokSistem['gabungan'] !== [])
                    <div class="alert alert-secondary py-2 px-3 small mb-2">
                        <i class="fas fa-circle-info me-1"></i>
                        Kolom stok <code>{{ $stokSistem['kolom'] }}</code> dipakai bersama gudang:
                        <strong>{{ implode(', ', $stokSistem['gabungan']) }}</strong>. Mutasi di kartu ini
                        tetap milik gudang terpilih saja (dari <code>SDGUDANG</code>).
                    </div>
                @endif

                @if ($kepenuhan)
                    <div class="alert alert-danger py-2 px-3 small mb-2">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Mutasi terpotong di {{ number_format($maksBaris, 0, ',', '.') }} baris — persempit
                        rentang tanggalnya supaya Saldo Akhir tidak menyesatkan.
                    </div>
                @endif
            </div>

            {{-- ===== TABEL MUTASI ===== --}}
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th style="width: 100px">Tanggal</th>
                            <th style="width: 150px">No Transaksi</th>
                            <th style="width: 165px">Jenis</th>
                            @if ($semuaGudang)
                                <th style="width: 140px">Gudang</th>
                            @endif
                            <th>Keterangan</th>
                            <th class="text-end" style="width: 100px">Masuk</th>
                            <th class="text-end" style="width: 100px">Keluar</th>
                            <th class="text-end" style="width: 110px">Saldo</th>
                        </tr></thead>
                        <tbody>
                            {{-- Baris pembuka: titik tolak saldo berjalan di kolom Saldo. --}}
                            <tr class="table-light">
                                <td colspan="{{ $kolom - 1 }}" class="fw-semibold">
                                    Saldo Awal per {{ \Carbon\Carbon::parse($dari)->format('d/m/Y') }}
                                </td>
                                <td class="text-end fw-semibold">{{ $ang($saldoAwal) }}</td>
                            </tr>

                            @forelse ($rows as $r)
                                <tr wire:key="ks-{{ $r->sdid }}">
                                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                                    <td class="font-monospace small">{{ $r->nomor }}</td>
                                    <td class="small">
                                        {{ $r->jenisNama }}
                                        <span class="badge text-bg-light border ms-1">{{ $r->sumber }}</span>
                                    </td>
                                    @if ($semuaGudang)
                                        <td class="text-muted small">{{ $r->gudang ?: '—' }}</td>
                                    @endif
                                    <td class="text-muted small">
                                        {{ $r->uraian ?: '—' }}
                                        @if ($r->kontak)
                                            <span class="d-block">{{ $r->kontak }}</span>
                                        @endif
                                        @if ($r->catatan)
                                            <span class="d-block fst-italic">{{ $r->catatan }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-success">
                                        {{ (float) $r->masuk > 0 ? $ang($r->masuk) : '' }}
                                    </td>
                                    <td class="text-end text-danger">
                                        {{ (float) $r->keluar > 0 ? $ang($r->keluar) : '' }}
                                    </td>
                                    <td class="text-end fw-semibold">{{ $ang($r->saldo) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $kolom }}" class="text-center text-muted py-4">
                                    Tidak ada mutasi stok untuk item ini pada rentang tanggal
                                    @if (! $semuaGudang) dan gudang @endif
                                    yang dipilih.
                                </td></tr>
                            @endforelse
                        </tbody>
                        @if ($rows !== [])
                            <tfoot>
                                <tr class="table-light fw-semibold">
                                    <td colspan="{{ $kolom - 3 }}" class="text-end">Jumlah</td>
                                    <td class="text-end text-success">{{ $ang($totalMasuk) }}</td>
                                    <td class="text-end text-danger">{{ $ang($totalKeluar) }}</td>
                                    <td class="text-end">{{ $ang($saldoAkhir) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
