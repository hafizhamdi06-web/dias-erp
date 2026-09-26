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

        if ($writer->cancel($id)) {
            activity_log('cancel', 'purchase/po', $h->SOUNOTRANSAKSI, 'Batalkan PO ' . $h->SOUNOTRANSAKSI);
            session()->flash('status', 'PO dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('esalesorderu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SOUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SOUCABANG')
            ->where('u.SOUSUMBER', PurchaseOrderWriter::SUMBER)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SOUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SOUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.SOUNOREF', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SOUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SOUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SOUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SOUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SOUID')
            ->paginate(20, [
                'u.SOUID as id', 'u.SOUNOTRANSAKSI as nomor', 'u.SOUTANGGAL as tanggal',
                'u.SOUSTATUS as status', 'u.SOUTOTALTRANSAKSI as total', 'k.KNAMA as vendor',
                'g.GNAMA as cabang',
            ]);

        return view('livewire.purchase.po-list', [
            'rows'     => $rows,
            'branches' => $this->branchOptions(),
        ]);
    }
}
