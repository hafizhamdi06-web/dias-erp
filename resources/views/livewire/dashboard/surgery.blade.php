{{-- Dashboard Surgery - pantau transaksi surgery yg dibayar cicil. Gaya `dsb-*` dari
     public/css/dashboard-penjualan.css; angka dari App\Services\DashboardSurgery. --}}
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $tgl = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('d/m/Y') : '—';
    $badge = ['berjalan' => 'text-bg-warning', 'selesai' => 'text-bg-success', 'cek' => 'text-bg-danger'];
@endphp

<div class="dsb">
    <div class="dsb-kepala">
        <div class="me-auto">
            <h2 class="dsb-judul">DASHBOARD SURGERY</h2>
            <div class="dsb-sub">Transaksi surgery &amp; cicilannya &middot; {{ $labelCabang }} &middot; {{ $periode }}</div>
        </div>

        @include('livewire.markom.partials.cabang')

        <div class="d-flex align-items-center gap-1">
            <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="dari">
            <span class="text-muted small">s/d</span>
            <input type="date" class="form-control form-control-sm" style="width: 9.5rem" wire:model.live="sampai">
        </div>

        <select class="form-select form-select-sm" style="width: 9rem" wire:model.live="status">
            <option value="">Semua status</option>
            @foreach (\App\Services\DashboardSurgery::STATUS as $k => $v)
                <option value="{{ $k }}">{{ $v }}</option>
            @endforeach
        </select>

        <button type="button" class="btn btn-sm {{ $tampil ? 'btn-outline-primary' : 'btn-primary' }}"
                wire:click="tampilkan" wire:loading.attr="disabled" wire:target="tampilkan">
            <i class="fas fa-rotate me-1" wire:loading.class="fa-spin" wire:target="tampilkan"></i> Tampilkan
        </button>
    </div>

    @include('livewire.markom.partials.status', ['target' => 'tampilkan,toggle'])

    @if ($tampil && ! $galat)
        <div class="dsb-ringkas">
            <div class="dsb-kartu w-jual">
                <div class="label">Nilai Surgery</div>
                <div class="nilai">{{ $rp($ringkas->nilai) }}</div>
                <div class="kecil">{{ $rp($ringkas->jumlah) }} surgery</div>
            </div>
            <div class="dsb-kartu w-laba">
                <div class="label">Sudah Dibayar</div>
                <div class="nilai">{{ $rp($ringkas->dibayar) }}</div>
                <div class="kecil">{{ $ringkas->nilai > 0 ? number_format($ringkas->dibayar / $ringkas->nilai * 100, 1, ',', '.') : 0 }}% dari nilai surgery</div>
            </div>
            <div class="dsb-kartu w-cogs">
                <div class="label">Sisa Piutang</div>
                <div class="nilai">{{ $rp($ringkas->sisa) }}</div>
                <div class="kecil">{{ $rp($ringkas->berjalan) }} surgery masih berjalan</div>
            </div>
            <div class="dsb-kartu w-klinik">
                <div class="label">Status</div>
                <div class="nilai" style="font-size:1.1rem">
                    <span class="badge text-bg-warning">{{ $ringkas->berjalan }} berjalan</span>
                    <span class="badge text-bg-success">{{ $ringkas->selesai }} selesai</span>
                    @if ($ringkas->cek)<span class="badge text-bg-danger">{{ $ringkas->cek }} perlu dicek</span>@endif
                </div>
                <div class="kecil">selesai = lunas = hari operasi</div>
            </div>
        </div>

        @if ($rantai->isEmpty())
            <div class="dsb-kosong">Tidak ada transaksi surgery pada saringan ini.</div>
        @else
            <div class="table-responsive" style="max-height: 65vh; overflow-y: auto">
                <table class="dsb-tabel">
                    <thead><tr>
                        <th>Pasien</th>
                        <th>Cabang</th>
                        <th>Mulai</th>
                        <th>Nota Terakhir</th>
                        <th class="angka">Bayar</th>
                        <th class="angka">Nilai Surgery</th>
                        <th class="angka">Dibayar</th>
                        <th class="angka">Sisa</th>
                        <th style="width: 9rem">Progres</th>
                        <th>Status</th>
                        <th class="angka">Hari</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rantai as $r)
                            <tr wire:key="sg-{{ $r->id }}">
                                <td class="nama">{{ $r->pasien ?: '—' }}</td>
                                <td class="small">{{ $r->cabang }}</td>
                                <td class="small">{{ $tgl($r->mulai) }}<div class="text-muted font-monospace" style="font-size:.7rem">{{ $r->notaAwal }}</div></td>
                                <td class="small">{{ $tgl($r->terakhir) }}<div class="text-muted font-monospace" style="font-size:.7rem">{{ $r->notaAkhir }}</div></td>
                                <td class="angka">{{ $r->jmlBayar }}x</td>
                                <td class="angka dsb-jual-t">{{ $rp($r->nilai) }}</td>
                                <td class="angka">{{ $rp($r->dibayar) }}</td>
                                <td class="angka {{ $r->sisa > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $rp($r->sisa) }}</td>
                                <td>
                                    <div class="progress" style="height: .55rem" title="{{ number_format($r->persen, 1, ',', '.') }}%">
                                        <div class="progress-bar {{ $r->status === 'selesai' ? 'bg-success' : 'bg-warning' }}" style="width: {{ $r->persen }}%"></div>
                                    </div>
                                    <div class="text-muted" style="font-size:.7rem">{{ number_format($r->persen, 0) }}%</div>
                                </td>
                                <td>
                                    <span class="badge {{ $badge[$r->status] }}">{{ \App\Services\DashboardSurgery::STATUS[$r->status] }}</span>
                                    @foreach ($r->cek as $c)<div class="text-danger" style="font-size:.7rem">{{ $c }}</div>@endforeach
                                </td>
                                <td class="angka small">{{ $r->hari }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" wire:click="toggle({{ $r->id }})">
                                        {{ $dibuka === $r->id ? 'Tutup' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($dibuka === $r->id)
                                <tr wire:key="sg-{{ $r->id }}-detail">
                                    <td colspan="12" class="p-0">
                                        <div class="ps-4 pe-2 py-2 d-flex flex-wrap gap-4" style="background: var(--dsb-latar)">
                                            <div>
                                                <div class="small fw-semibold mb-1">Riwayat pembayaran</div>
                                                <table class="table table-sm mb-0 small">
                                                    <thead><tr><th>#</th><th>Tanggal</th><th>Nota</th>
                                                        <th class="text-end">Dibayar</th><th class="text-end">Kumulatif</th><th class="text-end">Sisa</th></tr></thead>
                                                    <tbody>
                                                        @foreach ($r->nota as $i => $n)
                                                            <tr>
                                                                <td>{{ $i + 1 }}</td>
                                                                <td>{{ $tgl($n->tanggal) }}</td>
                                                                <td class="font-monospace">{{ $n->nomor }}</td>
                                                                <td class="text-end">{{ $rp($n->dibayar) }}</td>
                                                                <td class="text-end">{{ $rp($n->kumulatif) }}</td>
                                                                <td class="text-end">{{ $rp($n->piutang) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="small fw-semibold mb-1">Tindakan di nota {{ $r->notaAkhir }}
                                                    @if ($r->status !== 'selesai')<span class="text-muted fw-normal">(belum operasi - alkes belum wajib)</span>@endif
                                                </div>
                                                <table class="table table-sm mb-0 small">
                                                    <thead><tr><th>Kode</th><th>Tindakan / Item</th><th class="text-end">Qty</th>
                                                        <th class="text-end">Subtotal</th><th>Dokter</th><th>Alkes</th></tr></thead>
                                                    <tbody>
                                                        @forelse ($item as $it)
                                                            <tr>
                                                                <td class="font-monospace">{{ $it->kode }}</td>
                                                                <td>{{ $it->nama }}</td>
                                                                <td class="text-end">{{ (float) $it->qty + 0 }}</td>
                                                                <td class="text-end">{{ $rp(($it->harga - $it->diskon) * $it->qty) }}</td>
                                                                <td>{{ $it->dokter ?: '—' }}</td>
                                                                <td>
                                                                    @if (! $it->wajibAlkes) <span class="text-muted">—</span>
                                                                    @elseif ($it->noAlkes) <span class="badge text-bg-success">{{ $it->noAlkes }}</span>
                                                                    @elseif ($r->status === 'selesai') <span class="badge text-bg-danger">belum diinput</span>
                                                                    @else <span class="text-muted">menunggu operasi</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="6" class="text-muted text-center">Rincian item tidak tersedia.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
