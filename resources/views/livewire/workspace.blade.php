<div class="app-wrapper">

    {{-- ===== NAVBAR ===== --}}
    {{-- `data-bs-theme="dark"` DIHAPUS (2026-09-24) - dulu perlu krn header dipaksa biru
         custom (teks putih kontras di atas biru), skrg header sudah balik ke default
         AdminLTE (terang) tapi teks masih dipaksa putih = tidak kelihatan. Tanpa atribut
         ini, header ikut tema dokumen (`<html data-bs-theme>`) spt biasa - teks gelap di
         tema terang, teks terang di tema gelap. --}}
    <nav class="app-header navbar navbar-expand bg-brand" wire:ignore>
        <div class="container-fluid">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button"><i class="fas fa-bars"></i></a>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link" href="#" role="button" title="Ganti tema"
                       onclick="event.preventDefault(); window.diasToggleTheme && window.diasToggleTheme()">
                        <i class="fas fa-circle-half-stroke"></i>
                    </a>
                </li>
                {{-- Pengingat "PR Belum Diverifikasi" utk User Bagian Verifikasi (permintaan
                     user 2026-09-24) - HANYA muncul kalau user py hak `approve` di menu PR
                     (lihat `Workspace::pendingVerifyPr()`). Query one-shot per load halaman,
                     BUKAN live/real-time (navbar ini `wire:ignore`). --}}
                @if (can_do('inventory/pr', 'approve'))
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" data-bs-toggle="dropdown" href="#" title="PR Belum Diverifikasi">
                            <i class="fas fa-bell"></i>
                            @if ($pendingVerify->count() > 0)
                                <span class="badge text-bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size:.6rem">
                                    {{ $pendingVerify->count() > 20 ? '20+' : $pendingVerify->count() }}
                                </span>
                            @endif
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" style="min-width: 320px">
                            <span class="dropdown-item-text small text-muted fw-semibold">PR Belum Diverifikasi</span>
                            <div class="dropdown-divider"></div>
                            <div style="max-height: 320px; overflow-y: auto">
                                @forelse ($pendingVerify as $pr)
                                    <button type="button" class="dropdown-item small"
                                            wire:click="openPrFromNotif({{ $pr->id }}, @js($pr->nomor))">
                                        <div class="d-flex justify-content-between">
                                            <span class="fw-semibold">{{ $pr->nomor }}</span>
                                            <span class="text-muted">{{ \Carbon\Carbon::parse($pr->tanggal)->format('d/m/Y') }}</span>
                                        </div>
                                        <div class="text-muted">{{ $pr->karyawan ?: '—' }} &middot; {{ $pr->cabang ?: '—' }}</div>
                                    </button>
                                @empty
                                    <span class="dropdown-item-text small text-muted">Tidak ada PR menunggu verifikasi.</span>
                                @endforelse
                            </div>
                        </div>
                    </li>
                @endif
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#">
                        <i class="fas fa-user-circle me-1"></i>{{ auth()->user()->displayName() }}
                        <span class="small opacity-75">&middot; {{ auth()->user()->activeBranchName() }}</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <span class="dropdown-item-text small text-muted">
                            {{ auth()->user()->UKODE }} &middot; {{ auth()->user()->activeBranchName() }}
                            @if (app('acl')->isSuper()) &middot; <span class="badge text-bg-danger">super</span> @endif
                        </span>
                        {{-- Ganti Cabang Aktif - HANYA muncul kalau user punya >1 pilihan cabang
                             (`UCABANGPILIH` multi-GID, permintaan user 2026-09-24). Session-only
                             (lihat `User::switchActiveCabang()`), reload penuh setelah pilih. --}}
                        @php $branchChoices = \App\Models\Branch::active()->whereIn('GID', auth()->user()->branchIds())->orderBy('GNAMA')->get(['GID', 'GNAMA']); @endphp
                        @if ($branchChoices->count() > 1)
                            <div class="dropdown-divider"></div>
                            <span class="dropdown-item-text small text-muted mb-0 pb-0">Ganti Cabang Aktif</span>
                            <div style="max-height: 220px; overflow-y: auto">
                                @foreach ($branchChoices as $b)
                                    <button type="button" class="dropdown-item small d-flex align-items-center justify-content-between"
                                            wire:click="switchCabang({{ $b->GID }})" @disabled($b->GID == auth()->user()->UCABANG)>
                                        {{ $b->GNAMA }}
                                        @if ($b->GID == auth()->user()->UCABANG)
                                            <i class="fas fa-check text-success"></i>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="fas fa-right-from-bracket me-2"></i>Keluar
                            </button>
                        </form>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    {{-- ===== SIDEBAR ===== --}}
    <aside class="app-sidebar bg-brand shadow" data-bs-theme="dark" wire:ignore>
        <div class="sidebar-brand">
            <a href="#" class="brand-link" onclick="event.preventDefault()">
                <i class="fas fa-hospital brand-image opacity-75 ms-3"></i>
                <span class="brand-text fw-light">{{ config('app.name') }}</span>
            </a>
        </div>
        <div class="sidebar-wrapper">
            <nav class="mt-2">
                <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">
                    <li class="nav-item">
                        <a href="#" class="nav-link" onclick="event.preventDefault()" wire:click="activate('dashboard')">
                            <i class="nav-icon fas fa-gauge-high"></i><p>Dashboard</p>
                        </a>
                    </li>
                    @foreach ($sidebarTree as $node)
                        @include('livewire.partials.ws-sidebar-node', ['node' => $node])
                    @endforeach
                </ul>
            </nav>
        </div>
    </aside>

    {{-- ===== MAIN ===== --}}
    <main class="app-main">
        {{-- Tab strip --}}
        <div class="ws-tabstrip">
            @foreach ($tabs as $tab)
                <div class="ws-tab {{ $activeKey === $tab['key'] ? 'active' : '' }}"
                     wire:key="tab-{{ $tab['key'] }}"
                     wire:click="activate('{{ $tab['key'] }}')">
                    @if ($tab['icon'])<i class="{{ $tab['icon'] }}"></i>@endif
                    <span>{{ $tab['label'] }}</span>
                    @if ($tab['closable'])
                        <span class="ws-close"
                              wire:click.stop="closeTab('{{ $tab['key'] }}')"
                              onclick="event.stopPropagation()">&times;</span>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Panes: semua tetap ter-mount, yang tidak aktif disembunyikan --}}
        <div class="app-content pt-3">
            <div class="container-fluid">
                @foreach ($tabs as $tab)
                    <div wire:key="pane-{{ $tab['key'] }}" @class(['d-none' => $activeKey !== $tab['key']])>
                        @livewire(
                            $tab['component'],
                            array_merge($tab['params'], ['tabKey' => $tab['key']]),
                            key('cmp-' . $tab['key'])
                        )
                    </div>
                @endforeach
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <strong>&copy; {{ date('Y') }} {{ config('app.name') }}</strong>
    </footer>
</div>
