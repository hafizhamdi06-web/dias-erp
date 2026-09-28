<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PurchaseOrderWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Purchase Order (PO)" - daftar esalesorderu (SOUSUMBER='PO'). Dokumen komitmen
 * ke supplier eksternal, TIDAK menyentuh stok - lihat docblock `PurchaseOrderWriter`.
 */
class PoList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fFrom = '';
    public string $fTo = '';

    // modal "Histori Penerimaan" (permintaan user 2026-09-26) - PO -> PB per baris item.
    public bool $showHistory = false;
    public ?string $historyNomor = null;
    public array $historyLines = [];

    public function mount(): void
    {
        $this->fFrom = now()->startOfMonth()->toDateString();
        $this->fTo = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->fCabang = Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

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
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }

    public function newPo(): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.po-form', args: ['poId' => null],
            label: 'PO Baru', icon: 'fas fa-file-signature');
    }

    public function editPo(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.po-form', args: ['poId' => $id],
            label: 'PO: ' . $nomor, icon: 'fas fa-file-signature');
    }

    #[On('po-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Modal "Histori Penerimaan": PO ini sudah diterima lewat PB mana saja, berapa qty per
     * baris item. Lihat `PurchaseOrderWriter::historiPenerimaan()` utk rantai FK-nya.
     */
    public function openHistory(int $id, PurchaseOrderWriter $writer): void
    {
        if (! can_do('purchase/po', 'view')) {
            return;
        }
        $h = $writer->header($id);
        if (! $h) {
            return;
        }

        $this->historyNomor = $h->SOUNOTRANSAKSI;
        $this->historyLines = $writer->historiPenerimaan($id);
        $this->showHistory = true;
    }

    public function closeHistory(): void
    {
        $this->showHistory = false;
        $this->historyLines = [];
    }

    /** Batalkan PO - SOFT (SOUSTATUS=9), TIDAK PERNAH hard-delete, lihat PurchaseOrderWriter::cancel(). */
    public function cancel(int $id, PurchaseOrderWriter $writer): void
    {
        if (! can_do('purchase/po', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SOUSTATUS !== PurchaseOrderWriter::STATUS_AKTIF) {
            session()->flash('error', 'PO ini sudah dibatalkan.');

            return;
        }

        // Tombol Batalkan memang disembunyikan utk PO yg sudah ada penerimaannya, TAPI
        // `wire:click` bisa dipanggil paksa dari sisi klien - dicek ulang di sini. Tanpa ini
        // PO yg barangnya SUDAH diterima bisa ditandai batal (penerimaannya TIDAK ikut
        // dibatalkan), jadi dokumen & stok bertentangan.
        $terima = (float) DB::table('esalesorderd')->where('SODIDSOU', $id)->sum('SODMASUK');
        if ($terima > 0) {
            session()->flash('error', 'PO ini sudah ada penerimaannya - batalkan dulu PB terkait.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'purchase/po', $h->SOUNOTRANSAKSI, 'Batalkan PO ' . $h->SOUNOTRANSAKSI);
            session()->flash('status', 'PO dibatalkan.');
        }
    }

    /**
     * Status PO **DIHITUNG dari data penerimaan**, BUKAN dibaca dari `esalesorderu.SOUSTATUS`
     * (permintaan user 2026-09-26):
     *   Batal (9)            -> `SOUSTATUS = 9` (satu2nya yg memang ditulis modul kita)
     *   Selesai (3)          -> semua baris sudah diterima penuh (`SUM(SODORDER-SODMASUK) <= 0`)
     *   Ditarik Sebagian (2) -> sudah ada yg diterima tapi belum semua
     *   Aktif (0)            -> belum ada penerimaan sama sekali
     *
     * **Kenapa tidak baca kolomnya**: `SOUSTATUS` TERBUKTI tidak pernah diperbarui sistem -
     * dari 224 PO berstatus tersimpan 0 ("Aktif"), **168 sebenarnya sudah diterima PENUH**
     * dan 10 sebagian; cuma 46 yg benar2 belum ditarik. Tidak ada trigger yg memutakhirkannya
     * (sudah dicek `information_schema.TRIGGERS`) dan mekanisme aslinya tidak diketahui -
     * lihat docblock `PurchaseOrderWriter`/`PbWriter`. Sesuai aturan proyek: kode BARU membaca
     * ground truth, kolom turunan yg terbukti buggy TIDAK dipercaya & TIDAK ditulis ulang.
     *
     * PO tanpa baris detail -> `total_order` NULL -> jatuh ke Aktif (memang tidak ada yg
     * bisa diterima).
     */
    private static function statusExpr(): string
    {
        return 'CASE
            WHEN u.SOUSTATUS = ' . PurchaseOrderWriter::STATUS_BATAL . ' THEN ' . PurchaseOrderWriter::STATUS_BATAL . '
            WHEN COALESCE(x.total_order, 0) > 0 AND COALESCE(x.total_terima, 0) >= x.total_order
                THEN ' . PurchaseOrderWriter::STATUS_SELESAI_DITERIMA . '
            WHEN COALESCE(x.total_terima, 0) > 0 THEN ' . PurchaseOrderWriter::STATUS_SEBAGIAN_DITERIMA . '
            ELSE ' . PurchaseOrderWriter::STATUS_AKTIF . '
        END';
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        // Agregat penerimaan per PO - dasar status yg DIHITUNG (lihat $statusExpr).
        $agg = DB::table('esalesorderd')
            ->selectRaw('SODIDSOU, SUM(SODORDER) AS total_order, SUM(SODMASUK) AS total_terima')
            ->groupBy('SODIDSOU');

        $rows = DB::table('esalesorderu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SOUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SOUCABANG')
            ->leftJoinSub($agg, 'x', 'x.SODIDSOU', '=', 'u.SOUID')
            ->where('u.SOUSUMBER', PurchaseOrderWriter::SUMBER)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SOUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SOUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.SOUNOREF', 'like', "%{$q}%")))
            // Filter status ikut memakai status HITUNG, bukan kolom tersimpan - kalau tidak,
            // memilih "Selesai" hampir selalu nihil (cuma 9 baris yg kolomnya benar).
            ->when($this->fStatus !== '', fn ($b) => $b->whereRaw(self::statusExpr() . ' = ?', [(int) $this->fStatus]))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SOUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SOUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SOUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SOUID')
            ->paginate(20, [
                'u.SOUID as id', 'u.SOUNOTRANSAKSI as nomor', 'u.SOUTANGGAL as tanggal',
                'u.SOUTOTALTRANSAKSI as total', 'k.KNAMA as vendor', 'g.GNAMA as cabang',
                'u.SOUSTATUS as statusTersimpan',
                DB::raw(self::statusExpr() . ' AS status'),
            ]);

        return view('livewire.purchase.po-list', [
            'rows'     => $rows,
            'branches' => $this->branchOptions(),
        ]);
    }
}
