<?php

namespace App\Livewire\Pabrik;

use App\Models\Branch;
use App\Models\User;
use App\Services\JopWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Job Order Produksi (JOP)" - daftar fproduksiu (PUSUMBER='JOP'). Dokumen rencana
 * produksi, TIDAK menyentuh stok - lihat docblock `JopWriter`.
 */
class JopList extends Component
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

    public function newJop(): void
    {
        $this->dispatch('open-tab', cmp: 'pabrik.jop-form', args: ['jopId' => null],
            label: 'JOP Baru', icon: 'fas fa-clipboard-list');
    }

    public function editJop(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'pabrik.jop-form', args: ['jopId' => $id],
            label: 'JOP: ' . $nomor, icon: 'fas fa-clipboard-list');
    }

    #[On('jop-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /** Batalkan JOP - SOFT (PUSTATUS=9), TIDAK PERNAH hard-delete, lihat JopWriter::cancel(). */
    public function cancel(int $id, JopWriter $writer): void
    {
        if (! can_do('pabrik/jop', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->PUSTATUS !== JopWriter::STATUS_AKTIF) {
            session()->flash('error', 'Hanya JOP yang belum pernah ditarik yang bisa dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'pabrik/jop', $h->PUNOTRANSAKSI, 'Batalkan JOP ' . $h->PUNOTRANSAKSI);
            session()->flash('status', 'JOP dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fproduksiu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PUCABANG')
            ->where('u.PUSUMBER', JopWriter::SUMBER)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.PUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.PUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.PUURAIAN', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.PUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.PUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.PUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.PUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.PUID')
            ->paginate(20, [
                'u.PUID as id', 'u.PUNOTRANSAKSI as nomor', 'u.PUTANGGAL as tanggal',
                'u.PUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as cabang',
            ]);

        return view('livewire.pabrik.jop-list', [
            'rows'     => $rows,
            'branches' => $this->branchOptions(),
        ]);
    }
}
