<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PurchaseRequestWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Permintaan Barang" - daftar fpermintaanbarangu. Buka form sebagai tab terpisah.
 */
class PrList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    /**
     * Filter "Cabang Tujuan" = `PBUGUDANGSUMBER` (permintaan user 2026-09-25) - gudang/
     * cabang yg DIMINTA MENGIRIM (di form PR dilabeli "Gudang Tujuan (PO Ke)", di cetakan
     * "Gudang / Supplier Tujuan", di kolom list ini dilabeli "Gudang Asal" - SATU kolom
     * yg sama, label memang beda-beda antar tampilan sejak awal). BUKAN `$fCabang` (itu
     * `PBUGUDANG` = cabang PEMINTA) & BUKAN kolom "Tujuan" di list (itu KATEGORI dari
     * `blain` via `PBUTIPEPERMINTAAN`, mis. Cabang/Depo/Farmasi - bukan cabang spesifik).
     * TIDAK dibatasi `branchIds()` user - tujuan bisa cabang manapun, tidak terkait hak
     * akses cabang si peminta.
     */
    public string $fGudangTujuan = '';
    /** Default AWAL s/d AKHIR BULAN BERJALAN (konvensi PromoManager/PaketManager, per
     *  permintaan user 2026-09-17) - beda dari "Data POS" yg default hari-ini-saja krn
     *  volume PR jauh lebih rendah dari transaksi kasir harian. */
    public string $fFrom = '';
    public string $fTo = '';

    // modal verifikasi
    public bool $showVerify = false;
    public ?int $verifyId = null;
    public ?string $verifyNomor = null;
    public int $verifyStatus = 2;
    public ?string $verifyCatatan = null;

    // modal histori (permintaan user 2026-09-24) - 2 alur TERPISAH sesuai PBUJENIS:
    // 'pembelian' (jenis=1): PR->PKB->SJ->PBC | 'mutasi' (jenis=0): PR->KMB->TMB
    public bool $showHistory = false;
    public ?string $historyNomor = null;
    public string $historyMode = 'pembelian';
    public array $historyLines = [];

    public function mount(): void
    {
        $this->fFrom = now()->startOfMonth()->toDateString();
        $this->fTo = now()->endOfMonth()->toDateString();

        // Default cabang = cabang aktif user (auser.UCABANG) - resolusi SAMA PERSIS spt
        // PosDataList::mount() ("konsep cabang seperti di data transaksi POS", permintaan
        // user 2026-09-17).
        /** @var User $user */
        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->fCabang = Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

    /**
     * Cabang yg boleh dipilih user ini (`auser.UCABANGPILIH` via `User::branchIds()`) - KOSONG
     * (NULL di DB) berarti TIDAK dibatasi, boleh semua cabang aktif - PERSIS pola
     * `PosDataList::allowedBranchIds()`.
     */
    private function allowedBranchIds(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->branchIds();
    }

    private function branchOptions()
    {
        $allowed = $this->allowedBranchIds();

        return $allowed !== []
            ? Branch::active()->whereIn('GID', $allowed)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFStatus(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFGudangTujuan(): void { $this->resetPage(); }
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }

    public function newPr(): void
    {
        if (! can_do('inventory/pr', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'purchase.pr-form', args: ['prId' => null],
            label: 'Permintaan Baru', icon: 'fas fa-clipboard-list');
    }

    public function editPr(int $id, string $nomor): void
    {
        if (! can_do('inventory/pr', 'view')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'purchase.pr-form', args: ['prId' => $id],
            label: 'PR: ' . $nomor, icon: 'fas fa-clipboard-list');
    }

    /** Cetak "Surat Permintaan Pelanggan" langsung dari list, tanpa buka form dulu. */
    public function printPr(int $id): void
    {
        if (! can_do('inventory/pr', 'print')) {
            return;
        }
        $this->dispatch('report-pdf-ready', url: route('inventory.pr.print', $id));
    }

    #[On('pr-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan permintaan - SOFT status (PBUSTATUS=9), TIDAK PERNAH hard-delete, persis
     * pola POS ("samakan dengan POS, tidak ada hapus, adanya rubah status" - permintaan
     * user 2026-09-17). Aturan kelayakan TETAP SAMA spt hapus lama (cuma boleh kalau
     * belum diverifikasi) - yg berubah cuma MEKANISME-nya, bukan siapa/kapan bolehnya.
     */
    public function cancel(int $id, PurchaseRequestWriter $writer): void
    {
        if (! can_do('inventory/pr', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->PBUSTATUS !== 0) {
            session()->flash('error', 'Hanya permintaan yang belum diverifikasi bisa dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'inventory/pr', $h->PBUNOTRANSAKSI, 'Batalkan permintaan ' . $h->PBUNOTRANSAKSI);
            session()->flash('status', 'Permintaan dibatalkan.');
        }
    }

    /**
     * Batalkan verifikasi - balik PR "Disetujui" (2) ke "Belum Verifikasi" (0), utk
     * verifikator yg mau tinjau ulang (permintaan user 2026-09-24). Validasi ELIGIBILITY
     * (status persis 2 + belum ada PKB yg menarik) dilakukan PENUH di
     * `PurchaseRequestWriter::unverify()` - lihat docblock method itu.
     */
    public function unverifyPr(int $id, PurchaseRequestWriter $writer): void
    {
        if (! can_do('inventory/pr', 'approve')) {
            return;
        }

        $h = $writer->header($id);
        $res = $writer->unverify($id);
        if (! $res['ok']) {
            session()->flash('error', $res['error']);

            return;
        }

        activity_log('unverify', 'inventory/pr', $h->PBUNOTRANSAKSI, 'Batalkan verifikasi ' . $h->PBUNOTRANSAKSI);
        session()->flash('status', 'Verifikasi ' . $h->PBUNOTRANSAKSI . ' dibatalkan, kembali ke Belum Verifikasi.');
    }

    /**
     * Histori penarikan per baris item - alur DIPILIH sesuai `PBUJENIS`: jenis=1
     * (Permintaan Pembelian) -> PR->PKB->SJ->PBC (`history()`), jenis=0 (Permintaan
     * Barang/mutasi) -> PR->KMB->TMB (`historyMutasi()`). Lihat docblock kedua method
     * itu di `PurchaseRequestWriter` utk detail rantai FK masing2.
     */
    public function openHistory(int $id, PurchaseRequestWriter $writer): void
    {
        if (! can_do('inventory/pr', 'view')) {
            return;
        }
        $h = $writer->header($id);
        if (! $h) {
            return;
        }
        $this->historyNomor = $h->PBUNOTRANSAKSI;
        $this->historyMode = (int) $h->PBUJENIS === 1 ? 'pembelian' : 'mutasi';
        $this->historyLines = $this->historyMode === 'pembelian'
            ? $writer->history($id)
            : $writer->historyMutasi($id);
        $this->showHistory = true;
    }

    public function closeHistory(): void
    {
        $this->showHistory = false;
    }

    public function openVerify(int $id): void
    {
        if (! can_do('inventory/pr', 'approve')) {
            return;
        }
        $h = DB::table('fpermintaanbarangu')->where('PBUID', $id)->first(['PBUID', 'PBUNOTRANSAKSI']);
        $this->verifyId = $id;
        $this->verifyNomor = $h->PBUNOTRANSAKSI ?? null;
        $this->verifyStatus = 2;
        $this->verifyCatatan = null;
        $this->resetErrorBag();
        $this->showVerify = true;
    }

    public function saveVerify(PurchaseRequestWriter $writer): void
    {
        if (! can_do('inventory/pr', 'approve') || ! $this->verifyId) {
            return;
        }

        $res = $writer->verify($this->verifyId, $this->verifyStatus, $this->verifyCatatan);
        if (! $res['ok']) {
            $this->addError('verifyCatatan', $res['error']);

            return;
        }

        activity_log('approve', 'inventory/pr', $this->verifyNomor,
            'Verifikasi ' . $this->verifyNomor . ' -> ' . ($this->verifyStatus === 2 ? 'Disetujui' : 'Pending'));
        session()->flash('status', 'Verifikasi disimpan.');
        $this->showVerify = false;
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fpermintaanbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PBUKARYAWAN')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PBUGUDANG')
            ->leftJoin('bgudang as ga', 'ga.GID', '=', 'u.PBUGUDANGSUMBER')
            ->leftJoin('blain as l', 'l.lid', '=', 'u.PBUTIPEPERMINTAAN')
            // KMB hidup terkait (jenis=0, alur RS->KMB->TMB TANPA verifikasi) - dipakai
            // pr-list.blade.php utk badge status PENGGANTI (BUKAN PBUSTATUS 0-9) krn
            // PBUSTATUS memang TIDAK PERNAH berubah di jalur ini, lihat docblock
            // `KmbWriter`/`PurchaseRequestWriter::pullableForKmb()`.
            ->leftJoin('fstoku as kmb', fn ($j) => $j->on('kmb.SUPBUID', '=', 'u.PBUID')->where('kmb.SUSUMBER', 'KMB')->where('kmb.SUSTATUS', '<>', 9))
            ->where('u.PBUSUMBER', PurchaseRequestWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user (defense-in-depth, bukan cuma
            // batasan tampilan dropdown $fCabang) - kosong ($allowed=[]) = tidak dibatasi.
            // PBUGUDANG = Depo/Farmasi (cabang peminta), konsep sama spt SUCABANG di
            // PosDataList - per permintaan user 2026-09-17.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.PBUGUDANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.PBUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.PBUURAIAN', 'like', "%{$q}%")))
            // Filter status (dropdown "Belum Verifikasi"/"Pending"/dst) - nilai 0-7 (progresi
            // verifikasi/kirim) HANYA konsep jenis=1 (Permintaan Pembelian), krn jenis=0
            // (Permintaan Barang, RS->KMB->TMB) PBUSTATUS-nya TIDAK PERNAH berubah dari 0
            // (progres dilacak via badge KMB/TMB terpisah, lihat `$kmTmbBadge` di blade) -
            // TANPA batasan jenis, filter "Belum Verifikasi" salah ikut menjaring SEMUA
            // baris jenis=0 apapun progresnya (2026-09-24, ditemukan dari screenshot user).
            // **PENGECUALIAN: status=9 "Batal" BERLAKU utk KEDUA jenis** - `cancel()`
            // (`PurchaseRequestWriter`) set `PBUSTATUS=9` TANPA syarat jenis, jenis=0 JUGA
            // bisa dibatalkan (tombol Batalkan di list tidak exclude jenis=0) - kalau resep
            // "0-7 = jenis=1 only" di atas diterapkan MENTAH ke 9 jg, filter "Batal" jadi
            // salah SEBALIKNYA (menyembunyikan PR jenis=0 yg beneran dibatalkan, bug KEDUA
            // ditemukan user tepat setelah fix pertama). Jadi batasan jenis HANYA utk
            // status 0-7, BUKAN 9.
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.PBUSTATUS', (int) $this->fStatus)
                ->when((int) $this->fStatus !== PurchaseRequestWriter::STATUS_BATAL, fn ($b2) => $b2->where('u.PBUJENIS', 1)))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.PBUGUDANG', $this->fCabang))
            ->when($this->fGudangTujuan !== '', fn ($b) => $b->where('u.PBUGUDANGSUMBER', $this->fGudangTujuan))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.PBUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.PBUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.PBUID')
            ->paginate(20, [
                'u.PBUID as id', 'u.PBUNOTRANSAKSI as nomor', 'u.PBUTANGGAL as tanggal',
                'u.PBUSTATUS as status', 'u.PBUJENIS as jenis', 'u.PBUURAIAN as uraian',
                'u.PBUSTATUSKM as statusKm', 'kmb.SUSTATUS as kmbStatus',
                'k.KNAMA as karyawan', 'g.GNAMA as cabang', 'l.lnama as tujuan', 'ga.GKODE as gudangAsal',
                // Dipakai tombol "Batalkan Verifikasi" - sembunyikan kalau sudah ada PKB yg
                // menarik dari PR ini. GROUND TRUTH via EXISTS ke fperintahkirimbarangd
                // (BUKAN SUM(PBDQTYPAKAI) - kolom itu TERBUKTI TIDAK RELIABEL, lihat gotcha
                // panjang di docblock PurchaseRequestWriter::unverify()).
                DB::raw("(select exists(select 1 from fperintahkirimbarangd pkd where pkd.PKBDRSIDD in (select PBDID from fpermintaanbarangd d where d.PBDIDSU = u.PBUID))) as hasPkb"),
            ]);

        return view('livewire.purchase.pr-list', [
            'rows'     => $rows,
            'branches' => $this->branchOptions(),
            // Dropdown "Cabang Tujuan" pakai SEMUA cabang aktif (bukan `branchOptions()` yg
            // dibatasi hak akses) - lihat docblock properti `$fGudangTujuan`.
            'branchesTujuan' => Branch::options(),
        ]);
    }
}
