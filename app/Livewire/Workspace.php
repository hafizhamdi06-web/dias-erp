<?php

namespace App\Livewire;

use App\Services\PurchaseRequestWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Shell aplikasi: navbar + sidebar + strip tab + panel tab.
 *
 * Tiap "tab" merender satu komponen Livewire anak (list modul atau form).
 * Tab yang tidak aktif tetap ter-mount (hanya disembunyikan `d-none`), jadi
 * isian form tidak hilang saat berpindah tab.
 *
 * Kontrak event dari komponen anak:
 *   dispatch('open-tab', cmp:'master.item-form', args:['itemId'=>7], label:'…', icon:'…')
 *   dispatch('close-active-tab')                         -> tutup tab yang aktif
 *   dispatch('close-tab', key:'…')                       -> tutup tab tertentu
 *   dispatch('tab-label', key:'…', label:'…')            -> ganti judul tab sendiri
 *
 * CATATAN: JANGAN pakai nama argumen `component`/`to`/`self` di dispatch() —
 * itu kata-kunci Livewire untuk merutekan event ke komponen tertentu.
 */
#[Layout('layouts.app')]
class Workspace extends Component
{
    /** @var array<int,array{key:string,component:string,params:array,label:string,icon:?string,closable:bool}> */
    public array $tabs = [];

    public ?string $activeKey = null;

    /** ?open=master.item saat pertama load (dari link luar). */
    #[Url(as: 'open')]
    public ?string $openOnLoad = null;

    /** Daftar komponen list yang boleh dibuka dari sidebar: segment_key => [component, label, icon]. */
    private function listRegistry(): array
    {
        return [
            'admin.menu'        => ['admin.menu-manager', 'Administrasi Menu', 'fas fa-sitemap'],
            'admin.user'        => ['admin.user-manager', 'Administrasi User', 'fas fa-user'],
            'admin.user-access' => ['admin.user-access', 'Hak Akses Menu', 'fas fa-user-lock'],
            'admin.activity'    => ['admin.activity-log-view', 'Log Aktivitas', 'fas fa-clock-rotate-left'],
            'master.gudang'     => ['master.gudang-manager', 'Data Gudang', 'fas fa-warehouse'],
            'master.coa'        => ['master.coa-manager', 'Chart of Account', 'fas fa-list-ol'],
            'master.kelompok'   => ['master.kelompok-manager', 'Kelompok Item', 'fas fa-layer-group'],
            'master.satuan'     => ['master.unit-manager', 'Satuan', 'fas fa-ruler'],
            'master.item'       => ['master.item-manager', 'Data Item POS', 'fas fa-box'],
            'master.karyawan'   => ['master.karyawan-manager', 'Data Karyawan', 'fas fa-user-nurse'],
            'master.pasien'     => ['master.pasien-manager', 'Data Pasien', 'fas fa-bed-pulse'],
            'master.promo'      => ['master.promo-manager', 'Master Promo', 'fas fa-tag'],
            'master.paket'      => ['master.paket-manager', 'Master Paket', 'fas fa-boxes-packing'],
            'sales.pos'         => ['sales.pos-terminal', 'Kasir / POS', 'fas fa-cash-register'],
            'sales.pos-data'    => ['sales.pos-data-list', 'Data Transaksi POS', 'fas fa-table-list'],
            'purchase.pkb'      => ['purchase.pkb-list', 'Perintah Kirim Barang', 'fas fa-dolly-flatbed'],
            'purchase.pbc'      => ['purchase.pbc-list', 'Penerimaan Barang Cabang', 'fas fa-box-open'],
            'purchase.po'       => ['purchase.po-list', 'Purchase Order', 'fas fa-file-signature'],
            'purchase.receipt'  => ['purchase.pb-list', 'Penerimaan Barang', 'fas fa-dolly'],
            'sales.sj'          => ['purchase.sj-list', 'Surat Jalan', 'fas fa-truck-fast'],
            'sales.invoice'     => ['sales.invoice-list', 'Invoice Penjualan', 'fas fa-file-invoice-dollar'],
            'sales.invoice-mutasi' => ['sales.invoice-mutasi-list', 'Invoice Penjualan Mutasi', 'fas fa-truck-arrow-right'],
            'sales.alkes'       => ['sales.alkes-list', 'Input Alkes Depo', 'fas fa-syringe'],
            'inventory.pr'      => ['purchase.pr-list', 'Permintaan Barang', 'fas fa-clipboard-list'],
            'inventory.stock'   => ['inventory.kartu-stok', 'Kartu Stok', 'fas fa-boxes-stacked'],
            'inventory.kmb'     => ['purchase.kmb-list', 'Kirim Mutasi Barang', 'fas fa-truck-ramp-box'],
            'inventory.tmb'     => ['purchase.tmb-list', 'Terima Mutasi Barang', 'fas fa-dolly'],
            'inventory.serial'  => ['inventory.serial-histori', 'Data Histori Serial', 'fas fa-barcode'],
            'inventory.adjust'  => ['inventory.penyesuaian-list', 'Penyesuaian Barang', 'fas fa-scale-balanced'],
            'inventory.pengeluaran-lain' => ['inventory.pengeluaran-lain-list', 'Pengeluaran Lain', 'fas fa-arrow-right-from-bracket'],
            'pabrik.jop'        => ['pabrik.jop-list', 'Job Order Produksi', 'fas fa-clipboard-list'],
            'pabrik.produksi'   => ['pabrik.produksi-list', 'Produksi', 'fas fa-industry'],
            'finance.kas-masuk'  => ['fina.kas-masuk-list', 'Kas Masuk', 'fas fa-money-bill-trend-up'],
            'finance.kas-keluar' => ['fina.kas-keluar-list', 'Kas Keluar', 'fas fa-money-bill-transfer'],
            'finance.bank-masuk' => ['fina.bank-masuk-list', 'Bank Masuk', 'fas fa-building-columns'],
            'finance.bank-keluar' => ['fina.bank-keluar-list', 'Bank Keluar', 'fas fa-building-columns'],
            'finance.pengajuan-dana' => ['fina.pengajuan-dana-list', 'Pengajuan Dana', 'fas fa-hand-holding-dollar'],
            'laporan.penjualan-per-barang' => ['reports.penjualan-per-barang', 'IP Per Barang', 'fas fa-box'],
            'laporan.ip-tindakan-produk' => ['reports.ip-tindakan-produk', 'IP Tindakan/Produk Per Bulan', 'fas fa-calendar-days'],
            'laporan.penjualan-tunai' => ['reports.daftar-penjualan-tunai', 'Daftar Penjualan Tunai', 'fas fa-money-bill-wave'],
            'laporan.stok-barang' => ['reports.daftar-stok-barang', 'Daftar Stok Barang', 'fas fa-warehouse'],
            'laporan.stok-per-hari' => ['reports.stok-per-hari', 'Stok Per Hari', 'fas fa-calendar-day'],
            'laporan.surat-jalan' => ['reports.daftar-surat-jalan', 'Daftar Surat Jalan Barang', 'fas fa-truck-fast'],
            'laporan.stok-serial' => ['reports.daftar-stok-serial', 'Daftar Stok Barang Serial', 'fas fa-barcode'],
            'laporan.ip-kedatangan' => ['reports.ip-kedatangan-pasien', 'IP Kedatangan Pasien', 'fas fa-user-check'],
        ];
    }

    public function mount(): void
    {
        // Tab dashboard selalu ada.
        $this->tabs[] = [
            'key' => 'dashboard', 'component' => 'dashboard-pane', 'params' => [],
            'label' => 'Dashboard', 'icon' => 'fas fa-gauge-high', 'closable' => false,
        ];
        $this->activeKey = 'dashboard';

        if ($this->openOnLoad && isset($this->listRegistry()[$this->openOnLoad])) {
            [$cmp, $label, $icon] = $this->listRegistry()[$this->openOnLoad];
            $this->openTab($cmp, [], $label, $icon);
        }
    }

    public function openFromSidebar(string $segmentKey): void
    {
        $reg = $this->listRegistry();
        if (! isset($reg[$segmentKey])) {
            return;
        }
        [$cmp, $label, $icon] = $reg[$segmentKey];
        $this->openTab($cmp, [], $label, $icon);
    }

    #[On('open-tab')]
    public function openTab(string $cmp, array $args = [], ?string $label = null, ?string $icon = null): void
    {
        $key = $cmp . '|' . md5(json_encode($args));

        foreach ($this->tabs as $tab) {
            if ($tab['key'] === $key) {
                $this->activeKey = $key;

                return;
            }
        }

        $this->tabs[] = [
            'key'       => $key,
            'component' => $cmp,
            'params'    => $args,
            'label'     => $label ?: $cmp,
            'icon'      => $icon,
            'closable'  => true,
        ];
        $this->activeKey = $key;
    }

    public function activate(string $key): void
    {
        $this->activeKey = $key;
    }

    /**
     * Ganti cabang aktif (navbar dropdown user, HANYA muncul kalau `branchIds()` user >1 -
     * permintaan user 2026-09-24). Reload PENUH ke `workspace` (bukan cuma re-render) -
     * SEMUA tab yg sedang terbuka bisa py default cabang/gudang lama (di-set saat
     * mount()), reload bersih lebih aman drpd coba re-sync tiap komponen anak satu2.
     */
    public function switchCabang(int $gid): void
    {
        if (auth()->user()->switchActiveCabang($gid)) {
            $this->redirect(route('workspace'));
        }
    }

    /**
     * PR (jenis=1 "Permintaan Pembelian") yg masih "Belum Verifikasi" (`PBUSTATUS=0`) -
     * pengingat navbar utk "User Bagian Verifikasi" (permintaan user 2026-09-24). HANYA
     * dihitung/ditampilkan kalau user py hak `approve` di menu PR (SAMA persis gate yg
     * sudah dipakai tombol Verifikasi di `PrList` - "Bagian Verifikasi" = pemegang ability
     * itu, bukan konsep baru). Filter `PBUJENIS=1` WAJIB (pola sama fix filter status PR
     * sebelumnya - jenis=0 "Permintaan Barang" PBUSTATUS-nya TIDAK PERNAH berarti "belum
     * verifikasi", lihat docblock `PrList::render()`). Dibatasi `branchIds()` user (defense-
     * in-depth konsisten modul lain - user verifikasi TANPA batasan cabang lihat SEMUA).
     * Query ONE-SHOT per load halaman (BUKAN live/real-time - navbar ini `wire:ignore`,
     * cukup utk "pengingat", bukan notifikasi push).
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function pendingVerifyPr()
    {
        if (! can_do('inventory/pr', 'approve')) {
            return collect();
        }

        $allowed = auth()->user()->branchIds();

        return DB::table('fpermintaanbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PBUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PBUGUDANG')
            ->where('u.PBUSUMBER', PurchaseRequestWriter::SUMBER)
            ->where('u.PBUSTATUS', 0)
            ->where('u.PBUJENIS', 1)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.PBUGUDANG', $allowed))
            ->orderByDesc('u.PBUID')
            ->limit(20)
            ->get(['u.PBUID as id', 'u.PBUNOTRANSAKSI as nomor', 'u.PBUTANGGAL as tanggal',
                'k.KNAMA as karyawan', 'g.GNAMA as cabang']);
    }

    /** Buka PR langsung dari dropdown notifikasi (skip list, hemat 1 langkah). */
    public function openPrFromNotif(int $id, string $nomor): void
    {
        if (! can_do('inventory/pr', 'view')) {
            return;
        }
        $this->openTab('purchase.pr-form', ['prId' => $id], 'PR: ' . $nomor, 'fas fa-clipboard-list');
    }

    #[On('close-tab')]
    public function closeTab(string $key): void
    {
        $this->tabs = array_values(array_filter($this->tabs, fn ($t) => $t['key'] !== $key));

        if ($this->activeKey === $key) {
            $this->activeKey = end($this->tabs)['key'] ?? 'dashboard';
        }
    }

    #[On('close-active-tab')]
    public function closeActiveTab(): void
    {
        if ($this->activeKey && $this->activeKey !== 'dashboard') {
            $this->closeTab($this->activeKey);
        }
    }

    #[On('tab-label')]
    public function setTabLabel(string $key, string $label): void
    {
        foreach ($this->tabs as $i => $tab) {
            if ($tab['key'] === $key) {
                $this->tabs[$i]['label'] = $label;

                return;
            }
        }
    }

    public function render()
    {
        return view('livewire.workspace', [
            'sidebarTree'   => app('acl')->sidebarTree(),
            'pendingVerify' => $this->pendingVerifyPr(),
        ]);
    }
}
