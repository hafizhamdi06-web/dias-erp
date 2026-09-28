<?php

namespace App\Livewire\Inventory;

use App\Models\Branch;
use App\Models\User;
use App\Services\PenyesuaianWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar Penyesuaian Barang (`fstoku` SUSUMBER='PY'). Lihat docblock `PenyesuaianWriter`
 * untuk aturan datanya.
 */
#[Layout('layouts.app')]
#[Title('Penyesuaian Barang')]
class PenyesuaianList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fJenis = '';
    public string $fFrom = '';
    public string $fTo = '';

    public function mount(): void
    {
        $this->fFrom = now()->startOfMonth()->toDateString();
        $this->fTo = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = auth()->user();
        $this->fCabang = (int) ($user->UCABANG ?? 0) ? (string) $user->UCABANG : '';
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFStatus(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFJenis(): void { $this->resetPage(); }
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }

    /** Cabang yg boleh dilihat user (pola sama PrList/PbList). */
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

    public function newPy(): void
    {
        abort_unless(can_do('inventory/adjust', 'add'), 403);
        $this->dispatch('open-tab', cmp: 'inventory.penyesuaian-form',
            label: 'Penyesuaian Baru', icon: 'fas fa-scale-balanced');
    }

    public function editPy(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'inventory.penyesuaian-form', args: ['pyId' => $id],
            label: 'PY: ' . $nomor, icon: 'fas fa-scale-balanced');
    }

    #[On('py-saved')]
    public function onSaved(): void
    {
        $this->resetPage();
    }

    public function cancel(int $id, PenyesuaianWriter $writer): void
    {
        abort_unless(can_do('inventory/adjust', 'delete'), 403);

        $nomor = (string) DB::table('fstoku')->where('SUID', $id)->value('SUNOTRANSAKSI');

        if (! $writer->cancel($id)) {
            $this->dispatch('toast', message: 'Gagal membatalkan - mungkin sudah dibatalkan.', type: 'error');

            return;
        }

        activity_log('cancel', 'inventory/adjust', $nomor, 'Batalkan Penyesuaian ' . $nomor);
        $this->dispatch('toast', message: 'Penyesuaian ' . $nomor . ' dibatalkan, stok dikembalikan.', type: 'success');
    }

    public function render()
    {
        abort_unless(can_do('inventory/adjust', 'view'), 403);

        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bjenispenyesuaian as j', 'j.JID', '=', 'u.SUJENISPENYESUAIAN')
            ->where('u.SUSUMBER', PenyesuaianWriter::SUMBER)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('u.SUURAIAN', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fJenis !== '', fn ($b) => $b->where('u.SUJENISPENYESUAIAN', (int) $this->fJenis))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'u.SUURAIAN as uraian',
                'k.KNAMA as kontak', 'g.GNAMA as gudang', 'j.JNAMA as jenis',
                DB::raw('(SELECT COALESCE(SUM(d.SDMASUK),0) FROM fstokd d WHERE d.SDIDSU = u.SUID) as totalMasuk'),
                DB::raw('(SELECT COALESCE(SUM(d.SDKELUAR),0) FROM fstokd d WHERE d.SDIDSU = u.SUID) as totalKeluar'),
            ]);

        return view('livewire.inventory.penyesuaian-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'jenisList' => app(PenyesuaianWriter::class)->jenisOptions(),
        ]);
    }
}
