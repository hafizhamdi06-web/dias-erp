/* Helper Alpine dipakai komponen Livewire.
   Harus global (di-load lewat <script src>) karena @push dari komponen yang
   dimuat via Livewire tidak sampai ke @stack di layout. */

/* Listener global utk tombol laporan (PDF/Excel) - generik, dipakai SEMUA laporan
   (bukan cuma Penjualan Per Barang). Komponen Livewire dispatch salah satu event ini
   sesudah validasi + bangun URL; window.open/window.location TIDAK mengganggu tab
   Workspace lain krn cuma buka tab baru (PDF) atau trigger download tanpa navigasi
   (Excel - Content-Disposition: attachment bikin browser download di tempat, tidak
   pindah halaman). */
document.addEventListener('livewire:init', function () {
    Livewire.on('report-pdf-ready', function (e) { window.open(e.url, '_blank'); });
    Livewire.on('report-excel-download', function (e) { window.location = e.url; });
});

/* ===================== Toast & Konfirmasi (2026-09-26) =========================
   Pengganti `window.confirm` bawaan `wire:confirm` yg tampilannya kotak browser
   (dikeluhkan user). Dibangun di ATAS Bootstrap yg SUDAH dimuat layout - sengaja
   TIDAK menambah dependensi baru (toastr/SweetAlert) supaya tidak ada aset lagi
   yg harus di-load & dirawat.

   Markup `#dias-toasts` + `#dias-confirm` ada di `layouts/app.blade.php`. */

/* Toast singkat di kanan atas. `type`: success | error | warning | info. */
window.diasToast = function (message, type) {
    var wrap = document.getElementById('dias-toasts');
    if (!wrap || !window.bootstrap || !message) { return; }

    var warna = {
        success: 'text-bg-success', error: 'text-bg-danger', danger: 'text-bg-danger',
        warning: 'text-bg-warning', info: 'text-bg-primary',
    };
    var ikon = {
        success: 'fa-circle-check', error: 'fa-circle-exclamation', danger: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation', info: 'fa-circle-info',
    };

    var el = document.createElement('div');
    el.className = 'toast align-items-center border-0 ' + (warna[type] || warna.info);
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex">'
        + '<div class="toast-body d-flex align-items-start gap-2">'
        + '<i class="fas ' + (ikon[type] || ikon.info) + ' mt-1"></i><span></span></div>'
        + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>'
        + '</div>';
    // textContent (BUKAN innerHTML) - pesan bisa memuat nama/nomor dari DB, jangan
    // sampai jadi celah injeksi HTML.
    el.querySelector('span').textContent = message;

    wrap.appendChild(el);
    var t = new bootstrap.Toast(el, { delay: type === 'error' || type === 'danger' ? 7000 : 4000 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    t.show();
};

/* Modal konfirmasi. Mengembalikan Promise<boolean>. Kalau Bootstrap/markup belum
   siap, jatuh balik ke `window.confirm` supaya aksi tetap bisa jalan. */
window.diasConfirm = function (opts) {
    opts = opts || {};

    return new Promise(function (resolve) {
        var el = document.getElementById('dias-confirm');
        if (!el || !window.bootstrap) { resolve(window.confirm(opts.message || '')); return; }

        el.querySelector('[data-role="title"]').textContent = opts.title || 'Konfirmasi';
        el.querySelector('[data-role="message"]').textContent = opts.message || '';

        var ikon = el.querySelector('[data-role="icon"]');
        ikon.className = 'fas fa-2x ' + (opts.danger ? 'fa-triangle-exclamation text-danger' : 'fa-circle-question text-primary');

        var ok = el.querySelector('[data-role="ok"]');
        ok.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
        ok.textContent = opts.okText || 'Ya, lanjutkan';

        var modal = bootstrap.Modal.getOrCreateInstance(el);
        var dijawab = false;

        function onOk() { dijawab = true; modal.hide(); resolve(true); }
        function onHidden() {
            ok.removeEventListener('click', onOk);
            el.removeEventListener('hidden.bs.modal', onHidden);
            if (!dijawab) { resolve(false); }
        }

        ok.addEventListener('click', onOk);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
        setTimeout(function () { ok.focus(); }, 300);
    });
};

/* Interceptor tombol ber-`data-confirm`. Dipasang di fase CAPTURE + pakai
   stopImmediatePropagation supaya listener `wire:click` (fase bubble di elemen yg
   sama) TIDAK ikut jalan sebelum user menjawab. Setelah user menekan Ya, tombolnya
   di-klik ULANG dgn penanda `data-confirm-ok` supaya kali ini diloloskan. */
document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (!el || el.dataset.confirmOk === '1') { return; }

    e.preventDefault();
    e.stopImmediatePropagation();

    var pesan = el.getAttribute('data-confirm') || '';
    window.diasConfirm({
        message: pesan,
        title: el.getAttribute('data-confirm-title') || 'Konfirmasi',
        okText: el.getAttribute('data-confirm-ok') || 'Ya, lanjutkan',
        // Aksi merusak (hapus/batal/nonaktif) otomatis bergaya bahaya, tanpa perlu
        // atribut tambahan di tiap tombol.
        danger: el.hasAttribute('data-confirm-danger')
            || /hapus|batal|nonaktif|buang|reset/i.test(pesan),
    }).then(function (setuju) {
        if (!setuju) { return; }
        el.dataset.confirmOk = '1';
        el.click();
        delete el.dataset.confirmOk;
    });
}, true);

document.addEventListener('livewire:init', function () {
    Livewire.on('toast', function (e) {
        var p = Array.isArray(e) ? e[0] : e;
        if (p) { window.diasToast(p.message || '', p.type || 'info'); }
    });

    /* "Sudah tersimpan, cetak sekarang?" - komponen dispatch `confirm-print` dgn
       {message, title, okText, url}. Kalau user menjawab Ya, PDF dibuka di TAB BARU
       supaya tab Workspace yg sedang dipakai tidak ikut berpindah halaman. */
    Livewire.on('confirm-print', function (e) {
        var p = Array.isArray(e) ? e[0] : e;
        if (!p || !p.url) { return; }
        window.diasConfirm({
            message: p.message || 'Cetak dokumen sekarang?',
            title: p.title || 'Cetak Dokumen',
            okText: p.okText || 'Ya, cetak',
        }).then(function (setuju) {
            if (setuju) { window.open(p.url, '_blank'); }
        });
    });
});

window.diasToggleTheme = function () {
    var cur = document.documentElement.getAttribute('data-bs-theme');
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('dias-theme', next); } catch (e) {}
};

/* Navigasi keyboard di baris keranjang POS: Enter -> field berikutnya
   (atau baris berikutnya), Ctrl+Delete -> hapus baris. Murni DOM, tanpa
   round-trip Livewire, supaya terasa responsif untuk entri cepat. */
window.posFieldKey = function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var row = e.target.closest('tr');
        if (!row) { return; }
        var inputs = Array.from(row.querySelectorAll('input'));
        var idx = inputs.indexOf(e.target);
        if (idx > -1 && idx < inputs.length - 1) {
            inputs[idx + 1].focus();
            inputs[idx + 1].select && inputs[idx + 1].select();
        } else {
            var nextRow = row.nextElementSibling;
            var nextInput = nextRow ? nextRow.querySelector('input') : null;
            if (nextInput) { nextInput.focus(); nextInput.select && nextInput.select(); }
        }
    }
    if (e.key === 'Delete' && e.ctrlKey) {
        e.preventDefault();
        var tr = e.target.closest('tr');
        var btn = tr ? tr.querySelector('[data-remove-line]') : null;
        if (btn) { btn.click(); }
    }
};

/* <x-search-select> */
window.searchSelect = function (cfg) {
    return {
        open: false, loading: false, q: '',
        value: cfg.initialId || null,
        label: cfg.initialText || '',
        options: [],
        init() {
            if (this.value && !this.label) { this.resolveLabel(); }
            this.$watch('value', function (v) { if (!v) { this.label = ''; } }.bind(this));
        },
        _url(param, val) {
            return cfg.endpoint + (cfg.endpoint.indexOf('?') !== -1 ? '&' : '?') + param + '=' + encodeURIComponent(val);
        },
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(function () { if (this.$refs.q) { this.$refs.q.focus(); } }.bind(this));
                this.search();
            }
        },
        async search() {
            this.loading = true;
            try {
                const res = await fetch(this._url('q', this.q), { headers: { 'Accept': 'application/json' } });
                this.options = await res.json();
            } catch (e) { this.options = []; }
            this.loading = false;
        },
        async resolveLabel() {
            try {
                const res = await fetch(this._url('id', this.value), { headers: { 'Accept': 'application/json' } });
                const rows = await res.json();
                const hit = rows.find(function (r) { return String(r.id) === String(this.value); }.bind(this));
                if (hit) { this.label = hit.text; }
            } catch (e) {}
        },
        pick(opt) { this.value = opt.id; this.label = opt.text; this.open = false; this.q = ''; },
        clear() { this.value = null; this.label = ''; },
    };
};

/* Draft form (autosave localStorage) untuk form penuh */
window.formDraft = function (key) {
    return {
        hasDraft: false,
        _fields() {
            return this.$root.querySelectorAll('form [wire\\:model], form [wire\\:model\\.live], form [wire\\:model\\.lazy]');
        },
        checkDraft() { try { this.hasDraft = !!localStorage.getItem(key); } catch (e) {} },
        saveDraft() {
            try {
                const data = {};
                this._fields().forEach(function (el) {
                    const m = el.getAttribute('wire:model') || el.getAttribute('wire:model.live') || el.getAttribute('wire:model.lazy');
                    if (!m) { return; }
                    if (el.type === 'checkbox') {
                        if (el.hasAttribute('value')) {
                            data[m] = data[m] || [];
                            if (el.checked) { data[m].push(el.value); }
                        } else {
                            data[m] = el.checked;
                        }
                    } else {
                        data[m] = el.value;
                    }
                });
                localStorage.setItem(key, JSON.stringify({ t: Date.now(), data: data }));
            } catch (e) {}
        },
        restoreDraft() {
            try {
                const raw = JSON.parse(localStorage.getItem(key) || '{}');
                const data = raw.data || {};
                const wire = this.$wire;
                Object.keys(data).forEach(function (m) { wire.set(m, data[m]); });
                this.hasDraft = false;
            } catch (e) {}
        },
        discardDraft() { try { localStorage.removeItem(key); } catch (e) {} this.hasDraft = false; },
        init() {
            const self = this;
            this.$wire.on('item-saved', function () { self.discardDraft(); });
        },
    };
};
