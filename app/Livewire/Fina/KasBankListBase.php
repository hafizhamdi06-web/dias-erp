<?php

namespace App\Livewire\Fina;

use App\Models\Branch;
use App\Models\User;
use App\Services\KasBankWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Base abstrak list Kas/Bank Masuk/Keluar - 4 subclass tipis (`KasMasukList` dkk) cuma
 * beda `sumber()`/`judul()`/`abilityPath()`/`formComponent()`. Lihat docblock
 * `KasBankWriter` utk detail modul.
 */
abstract class KasBankListBase extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fFrom = '';
    public string $fTo = '';
    public string $fCabang = '';

    abstract public function sumber(): string;

    abstract public function judul(): string;

    abstract public function icon(): string;

    abstract public function abilityPath(): string;

    abstract public function formComponent(): string;

    /** Idem `KasBankFormBase::printRoute()` - `null` = tombol Cetak disembunyikan. */
    public function printRoute(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $this->fFrom = now()->startOfMonth()->toDateString();
        $this->fTo = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = auth()->user();
        $this->fCabang = (string) ($user->UCABANG ?? '');
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }

    public function newTransaksi(): void
    {
        if (! can_do($this->abilityPath(), 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: $this->formComponent(), args: [],
            label: $this->judul() . ' Baru', icon: $this->icon());
    }

    public function editTransaksi(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: $this->formComponent(), args: ['id' => $id],
            label: $this->judul() . ': ' . $nomor, icon: $this->icon());
    }

    #[On('kasbank-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    public function hapus(int $id, KasBankWriter $writer): void
    {
        if (! can_do($this->abilityPath(), 'delete')) {
            return;
        }

        $h = $writer->header($this->sumber(), $id);
        if (! $h) {
            session()->flash('error', 'Transaksi tidak ditemukan.');

            return;
        }

        if ($writer->delete($this->sumber(), $id)) {
            activity_log('delete', $this->abilityPath(), $h->CUNOTRANSAKSI, 'Hapus ' . $this->judul() . ' ' . $h->CUNOTRANSAKSI);
            session()->flash('status', $this->judul() . ' ' . $h->CUNOTRANSAKSI . ' dihapus.');
        }
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('ctransaksiu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.CUKONTAK')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'u.CUREKKAS')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.CUCABANG')
            ->where('u.CUSUMBER', $this->sumber())
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.CUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.CUURAIAN', 'like', "%{$q}%")))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.CUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.CUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.CUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.CUID')
            ->paginate(20, [
                'u.CUID as id', 'u.CUNOTRANSAKSI as nomor', 'u.CUTANGGAL as tanggal',
                'k.KNAMA as kontak', 'c.CNAMA as rekening', 'u.CUTOTALTRANS as total', 'g.GNAMA as cabang',
            ]);

        return view('livewire.fina.kas-bank-list', [
            'rows'     => $rows,
            'branches' => Branch::options(),
            'judul'    => $this->judul(),
            'abilityPath' => $this->abilityPath(),
            'printRoute' => $this->printRoute(),
        ]);
    }
}
