<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width: 260px">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="Cari nomor / nama…"
                           wire:model.live.debounce.400ms="search">
                </div>
                <select class="form-select form-select-sm" style="max-width: 210px" wire:model.live="fTipe">
                    <option value="">Semua tipe</option>
                    @foreach ($tipeList as $i => $nama)
                        <option value="{{ $i }}">{{ $nama }}</option>
                    @endforeach
                </select>
                <select class="form-select form-select-sm" style="max-width: 140px" wire:model.live="fAktif">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif</option>
                    <option value="">Semua</option>
                </select>
            </div>
            @if (can_do('master/coa', 'add'))
                <button class="btn btn-primary btn-sm" wire:click="create"><i class="fas fa-plus me-1"></i> Baru</button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th style="width: 170px">No COA</th>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th class="text-center" style="width: 60px">D/K</th>
                        <th class="text-center" style="width: 80px">Grup</th>
                        <th style="width: 150px">Induk</th>
                        <th style="width: 110px">Divisi</th>
                        <th class="text-center" style="width: 70px">Aktif</th>
                        <th class="text-end" style="width: 70px">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="coa-{{ $r->CID }}">
                                <td class="font-monospace small">{{ $r->CNOCOA }}</td>
                                <td style="padding-left: {{ 0.75 + (max(1, (int) $r->CLEVEL) - 1) * 1.25 }}rem">
                                    <span class="{{ $r->CGD === 'G' ? 'fw-semibold' : '' }}">{{ $r->CNAMA ?: '—' }}</span>
                                </td>
                                <td class="text-muted small">{{ $tipeList[(int) $r->CTIPE] ?? ('Tipe #' . $r->CTIPE) }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $r->CDC === 'D' ? 'text-bg-primary' : 'text-bg-warning' }}">{{ $r->CDC }}</span>
                                </td>
                                <td class="text-center">
                                    @if ($r->CGD === 'G')
                                        <span class="badge text-bg-secondary">Grup</span>
                                    @else
                                        <span class="text-muted small">Detail</span>
                                    @endif
                                </td>
                                <td class="font-monospace small text-muted">{{ $r->induk ?: '—' }}</td>
                                <td class="text-muted small">{{ $r->divisi ?: '—' }}</td>
                                <td class="text-center">
                                    @if ((int) $r->CACTIVE === 1)
                                        <i class="fas fa-check text-success"></i>
                                    @else
                                        <span class="badge text-bg-secondary">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if (can_do('master/coa', 'edit'))
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $r->CID }})">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">{{ $rows->links() }}</div>
    </div>

    @if ($showModal)
        <x-lw-modal :show="$showModal" :title="$editingId ? 'Data COA' : 'COA Baru'">
            <form wire:submit="save">
                <div class="modal-body">
                    <style>
                        .coa-field { display: flex; align-items: flex-start; gap: .75rem; margin-bottom: .6rem; }
                        .coa-field > label { flex: 0 0 120px; max-width: 120px; padding-top: .35rem; margin-bottom: 0; }
                        .coa-field > .coa-input { flex: 1 1 auto; min-width: 0; }
                    </style>

                    <div class="coa-field">
                        <label class="form-label">Nomor <span class="text-danger">*</span></label>
                        <div class="coa-input" style="max-width: 260px">
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   wire:model="CNOCOA" placeholder="1-01-01-00-00">
                            @error('CNOCOA') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <div class="coa-input">
                            <input type="text" class="form-control form-control-sm" wire:model="CNAMA">
                            @error('CNAMA') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Tipe <span class="text-danger">*</span></label>
                        <div class="coa-input">
                            <select class="form-select form-select-sm" wire:model.live="CTIPE">
                                <option value="">— pilih —</option>
                                @foreach ($tipeList as $i => $nama)
                                    <option value="{{ $i }}">{{ $nama }}</option>
                                @endforeach
                            </select>
                            @error('CTIPE') <div class="text-danger small">{{ $message }}</div> @enderror
                            @if ($CTIPE !== null && $CTIPE !== '')
                                <div class="form-text">
                                    Saldo normal:
                                    <strong>{{ in_array((int) $CTIPE, \App\Livewire\Master\CoaManager::TIPE_DEBIT, true) ? 'Debit (D)' : 'Kredit (C)' }}</strong>
                                    — ditentukan otomatis dari tipe.
                                </div>
                            @endif
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="coa-field">
                        <label class="form-label">Sub Dari</label>
                        <div class="coa-input">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="coa-subdari" wire:model.live="CSUBDARI">
                                <label class="form-check-label" for="coa-subdari">COA ini anak dari COA lain</label>
                            </div>
                            @if ($CSUBDARI)
                                <x-search-select model="CPARENT" :value="$CPARENT" :selected-text="$parentLabel"
                                                 :endpoint="route('lookup.coa.semua', ['exclude' => $editingId])"
                                                 placeholder="cari induk COA…" />
                                @error('CPARENT') <div class="text-danger small">{{ $message }}</div> @enderror
                                <div class="form-text">Induk otomatis ditandai sebagai Grup, dan level COA ini = level induk + 1.</div>
                            @endif
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Grup / Detail</label>
                        <div class="coa-input" style="max-width: 200px">
                            <select class="form-select form-select-sm" wire:model="CGD">
                                <option value="D">D — Detail</option>
                                <option value="G">G — Grup</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="coa-field">
                        <label class="form-label">Mata Uang</label>
                        <div class="coa-input" style="max-width: 240px">
                            <select class="form-select form-select-sm" wire:model="CUANG">
                                <option value="">— pilih —</option>
                                @foreach ($uangs as $u)
                                    <option value="{{ $u->UID }}">{{ $u->UKODE }} — {{ $u->UNAMA }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Divisi</label>
                        <div class="coa-input" style="max-width: 280px">
                            <select class="form-select form-select-sm" wire:model="CDIVISI">
                                <option value="">— pilih —</option>
                                @foreach ($divisis as $d)
                                    <option value="{{ $d->DID }}">{{ $d->DNAMA ?: $d->DKODE }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Bank</label>
                        <div class="coa-input" style="max-width: 280px">
                            <select class="form-select form-select-sm" wire:model="CBANK">
                                <option value="">— pilih —</option>
                                @foreach ($banks as $b)
                                    <option value="{{ $b->BID }}">{{ $b->BKODE }} — {{ $b->BNAMA }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Diisi kalau COA ini rekening bank.</div>
                        </div>
                    </div>
                    <div class="coa-field">
                        <label class="form-label">Status</label>
                        <div class="coa-input">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="coa-aktif" wire:model="CACTIVE">
                                <label class="form-check-label" for="coa-aktif">Aktif</label>
                            </div>
                            <div class="form-text">COA nonaktif tidak muncul lagi di pilihan rekening modul Finance.</div>

                            {{-- Dua ceklist ini menyaring AKUN LAWAN (baris detail) di form
                                 Kas/Bank Masuk & Keluar - BUKAN penanda akun kas/bank.
                                 Akun kas/bank ditentukan Tipe (Kas/Bank). --}}
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="coa-kasmasuk" wire:model="CKASMASUK">
                                <label class="form-check-label" for="coa-kasmasuk">Dipakai di Kas Masuk</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="coa-kaskeluar" wire:model="CKASKELUAR">
                                <label class="form-check-label" for="coa-kaskeluar">Dipakai di Kas Keluar</label>
                            </div>
                            <div class="form-text">
                                Menentukan COA ini muncul atau tidak sebagai <strong>akun lawan</strong>
                                (baris detail) di form Kas/Bank Masuk &amp; Keluar — bukan sebagai
                                rekening kas/bank-nya.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">Batal</button>
                </div>
            </form>
        </x-lw-modal>
    @endif
</div>
