<div>
    {{-- Pesan sukses/gagal modul ini pakai TOAST (event `toast` -> diasToast di
         dias-helpers.js), bukan alert bar di dalam halaman. --}}

    {{-- ============ PERLU DIINPUT ALKES ============
         Aturan sama dgn Closing Harian IP (App\Services\ClosingHarianIp). Tampil otomatis
         begitu menu dibuka; nota hilang sendiri setelah alkesnya disimpan (event alkes-saved). --}}
    <div class="card mb-3 {{ $perlu->isEmpty() ? 'border-success' : 'border-warning' }}">
        <div class="card-header d-flex justify-content-between align-items-center {{ $perlu->isEmpty() ? '' : 'bg-warning-subtle' }}">
            <span class="fw-semibold">
                <i class="fas fa-triangle-exclamation me-1 {{ $perlu->isEmpty() ? 'text-success' : 'text-warning' }}"></i>
                Perlu diinput alkes
                <span class="text-muted small fw-normal">&middot; {{ \App\Livewire\Sales\AlkesList::HARI_PERLU }} hari terakhir, cabang Anda</span>
            </span>
            <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="width: 300px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="no IP / pasien / tindakan / no ref…"
                           wire:model.live.debounce.400ms="perluQ">
                </div>
                @if ($perlu->isEmpty() && $perluQ === '')
                    <span class="badge text-bg-success"><i class="fas fa-check me-1"></i>Semua sudah diinput</span>
                @elseif ($perlu->isNotEmpty())
                    <span class="badge text-bg-warning text-nowrap">{{ $perlu->count() }} transaksi IP &middot; {{ $perlu->sum(fn ($p) => count($p->tindakan)) }} tindakan</span>
                @endif
            </div>
        </div>
        @if ($perlu->isEmpty() && $perluQ !== '')
            <div class="card-body small text-muted py-2">Tidak ada yang cocok dengan "{{ $perluQ }}".</div>
        @endif
        @if ($perlu->isNotEmpty())
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th style="width: 110px">Tanggal</th>
                            <th style="width: 160px">No IP</th>
                            <th>Pasien</th>
                            <th>Tindakan yang wajib alkes</th>
                            <th class="text-end" style="width: 140px">Aksi</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($perlu as $p)
                                @php $hariIni = \Carbon\Carbon::parse($p->tanggal)->isToday(); @endphp
                                <tr wire:key="perlu-{{ $p->ipId }}">
                                    <td class="{{ $hariIni ? '' : 'text-danger fw-semibold' }}" title="{{ $hariIni ? '' : 'Terlewat dari hari sebelumnya' }}">
                                        {{ \Carbon\Carbon::parse($p->tanggal)->format('d/m/Y') }}
                                    </td>
                                    <td class="font-monospace small fw-semibold">{{ $p->noIp }}</td>
                                    <td>{{ $p->pasien ?: '—' }}</td>
                                    <td class="small">
                                        @foreach ($p->tindakan as $t)
                                            <div>
                                                {{ $t['nama'] }}
                                                @if ($t['noref']) <span class="font-monospace text-muted">[{{ $t['noref'] }}]</span> @endif
                                                @if ($t['qty'] != 1) <span class="text-muted">&times;{{ $t['qty'] + 0 }}</span> @endif
                                                @if ($t['kedatangan'] !== null)
                                                    <span class="badge text-bg-info">paket &middot; kedatangan ke-{{ $t['kedatangan'] }}</span>
                                                @endif
                                                @if ($t['dokter']) <span class="text-muted">&middot; {{ $t['dokter'] }}</span> @endif
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="text-end">
                                        @if (can_do('sales/alkes', 'add'))
                                            <button class="btn btn-primary btn-sm text-nowrap" wire:click="pilihIp({{ $p->ipId }})">
                                                <i class="fas fa-syringe me-1"></i> Input Alkes
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">
                    Tanggal <span class="text-danger fw-semibold">merah</span> = transaksi hari sebelumnya yang alkesnya belum diinput.
                    Daftar ini sama dengan menu <strong>Closing Harian IP</strong>.
                </div>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1 small text-muted">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 160px" wire:model.live="dari">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" style="width: 160px" wire:model.live="sampai">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Status</label>
                    <select class="form-select form-select-sm" style="width: 130px" wire:model.live="fStatus">
                        <option value="">Semua</option>
                        <option value="0">Aktif</option>
                        <option value="9">Batal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">Cari</label>
                    <div class="input-group input-group-sm" style="width: 260px">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="no alkes / no IP / pelanggan…"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>
            </div>
            @if (can_do('sales/alkes', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="openPicker">
                    <i class="fas fa-plus me-1"></i> Input Alkes
                </button>
            @endif
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th style="width: 160px">No Alkes</th>
                        <th style="width: 110px">Tanggal</th>
                        <th style="width: 160px">No IP</th>
                        <th>Pelanggan</th>
                        <th>Keterangan</th>
                        <th class="text-center" style="width: 70px">Item</th>
                        <th class="text-center" style="width: 90px">Status</th>
                        <th class="text-end" style="width: 130px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="alkes-{{ $r->SUID }}">
                                <td class="font-monospace small fw-semibold">{{ $r->SUNOTRANSAKSI }}</td>
                                <td>{{ \Carbon\Carbon::parse($r->SUTANGGAL)->format('d/m/Y') }}</td>
                                <td class="font-monospace small">{{ $r->SUNOREF ?: '—' }}</td>
                                <td>{{ $r->pelanggan ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->SUURAIAN ?: '—' }}</td>
                                <td class="text-center">{{ $r->n_item }}</td>
                                <td class="text-center">
                                    @if ((int) $r->SUSTATUS === 9)
                                        <span class="badge text-bg-danger">Batal</span>
                                    @else
                                        <span class="badge text-bg-success">Aktif</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="lihat({{ $r->SUID }})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    @if ((int) $r->SUSTATUS !== 9 && can_do('sales/alkes', 'delete'))
                                        <button class="btn btn-outline-danger btn-sm"
                                                wire:click="batalkan({{ $r->SUID }})"
                                                data-confirm="Batalkan {{ $r->SUNOTRANSAKSI }}? Stok alkes akan dikembalikan dan tindakannya bisa diinput ulang.">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                Tidak ada data alkes pada rentang tanggal ini.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showPicker)
        <x-lw-modal :show="$showPicker" title="Cari Transaksi IP (yang punya baris tindakan)" close="closePicker">
            <div class="modal-body">
                <input type="text" class="form-control form-control-sm mb-2" placeholder="cari no IP / nama pasien / nama tindakan / no ref tindakan…"
                       wire:model.live.debounce.300ms="pickerQ">
                <div class="form-text mb-2">
                    Mengikuti rentang tanggal di filter: {{ $dari ?: '—' }} s/d {{ $sampai ?: '—' }}
                </div>
                <div class="list-group" style="max-height: 380px; overflow-y:auto">
                    @forelse ($pullable as $ip)
                        <button type="button" class="list-group-item list-group-item-action"
                                wire:click="pilihIp({{ $ip->id }})">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold font-monospace">{{ $ip->nomor }}</span>
                                <span class="text-muted small">{{ \Carbon\Carbon::parse($ip->tanggal)->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-muted small">{{ $ip->pelanggan ?: '—' }}</div>
                            @if ($ip->tindakan)
                                <div class="small"><i class="fas fa-syringe me-1 text-secondary"></i>{{ $ip->tindakan }}</div>
                            @endif
                        </button>
                    @empty
                        <div class="text-center text-muted py-4">
                            Tidak ada transaksi IP dengan baris tindakan pada rentang tanggal ini.
                        </div>
                    @endforelse
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closePicker">Tutup</button>
            </div>
        </x-lw-modal>
    @endif
</div>
