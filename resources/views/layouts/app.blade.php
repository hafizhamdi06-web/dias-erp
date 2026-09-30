<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <script>
        (function () {
            try {
                var t = localStorage.getItem('dias-theme')
                    || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.0/styles/overlayscrollbars.min.css">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/dist/css/adminlte.min.css') }}">
    <style>
        :root { --dias-font: 'Inter', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        /* Tema terang default Bootstrap (--bs-tertiary-bg: #f8f9fa) terlalu pucat/silau -
           digelapkan sedikit ke abu-abu (permintaan user 2026-09-24) supaya lebih nyaman
           di mata, kartu/putih di atasnya (.card dst pakai --bs-body-bg) tetap kontras. */
        :root[data-bs-theme="light"] { --bs-tertiary-bg: #e3e6ea; }
        body, .btn, .form-control, .form-select, .nav-link, .dropdown-menu { font-family: var(--dias-font); }
        /* Angka rata kolom di tabel (harga, stok, qty) supaya gampang dipindai mata. */
        table .text-end, table .text-center { font-variant-numeric: tabular-nums; }

        .ws-tabstrip { display:flex; gap:2px; overflow-x:auto; padding:.35rem .5rem 0; background:var(--bs-tertiary-bg); border-bottom:1px solid var(--bs-border-color); }
        .ws-tab { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .6rem; border:1px solid var(--bs-border-color); border-bottom:none;
                  border-radius:.4rem .4rem 0 0; background:var(--bs-secondary-bg); color:var(--bs-secondary-color); white-space:nowrap; font-size:.85rem; cursor:pointer; }
        .ws-tab.active { background:var(--bs-body-bg); color:var(--bs-body-color); font-weight:600; }
        .ws-tab .ws-close { opacity:.55; border-radius:50%; width:1.1rem; height:1.1rem; line-height:1rem; text-align:center; }
        .ws-tab .ws-close:hover { opacity:1; background:var(--bs-danger); color:#fff; }
        [x-cloak] { display:none !important; }

        /* Header dikembalikan ke warna default AdminLTE (2026-09-24, permintaan user -
           override biru CI3 sebelumnya DIHAPUS, cuma sidebar yg tetap dikustom hitam). */
        .app-sidebar.bg-brand { background-color: #000 !important; }
        .app-sidebar.bg-brand .nav-sidebar > .nav-item > .nav-link.active,
        .app-sidebar.bg-brand .nav-treeview .nav-link.active { background-color: rgba(255,255,255,.12); }
        .app-sidebar .nav-sidebar .nav-link, .app-sidebar .brand-text { font-weight: 600; }
    </style>
    @livewireStyles
    @stack('styles')
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    {{ $slot }}

    {{-- Toast + modal konfirmasi global (lihat diasToast/diasConfirm di dias-helpers.js).
         Ditaruh di layout supaya SEMUA tab Workspace memakai yang sama. --}}
    <div id="dias-toasts" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090"></div>

    <div class="modal fade" id="dias-confirm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" data-role="title">Konfirmasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex gap-3 align-items-start">
                    <i data-role="icon" class="fas fa-2x fa-circle-question text-primary"></i>
                    <div data-role="message" class="pt-1"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" data-role="ok">Ya, lanjutkan</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="{{ asset('vendor/adminlte/dist/js/adminlte.min.js') }}"></script>
    {{-- `?v=<mtime>` WAJIB: tanpa itu peramban menyajikan versi CACHE dan perbaikan JS apa pun
         tidak pernah sampai ke user - sudah kejadian 2026-09-30, dua perbaikan berturut-turut
         (panel `<x-search-select>` & komponen `uangInput`) dikira gagal padahal berkasnya yg
         lama yang dimuat. Berubah otomatis tiap berkasnya disimpan, jadi tidak perlu diingat. --}}
    <script src="{{ asset('js/dias-helpers.js') }}?v={{ @filemtime(public_path('js/dias-helpers.js')) ?: 1 }}"></script>
    @livewireScripts
    @stack('scripts')
</body>
</html>
