<?php

namespace App\Livewire\Pabrik;

use App\Models\Branch;
use App\Models\User;
use App\Services\JopWriter;
use App\Services\ProduksiWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Produksi (PRO)" - daftar fstoku (SUSUMBER='PRO'). EKSEKUSI NYATA hasil manufaktur
 * - lihat docblock `ProduksiWriter`. Bisa ditarik dari Job Order (opsional, picker
 * "Tarik dari Job Order") ATAU dibuat BEBAS (freeform, tombol "Buat Bebas").
 */
class ProduksiList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fFrom = '';
    public string $fTo = '';

    // modal picker "Tarik dari Job Order"
    public bool $showPicker = false;
    public string $pickerQ = '';

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

    public function openPicker(): void
    {
        if (! can_do('pabrik/produksi', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickJop(int $jopId, string $nomor): void
    {
        if (! can_do('pabrik/produksi', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'pabrik.produksi-form', args: ['jopId' => $jopId],
            label: 'Produksi dari ' . $nomor, icon: 'fas fa-industry');
    }

    public function newFreeform(): void
    {
        if (! can_do('pabrik/produksi', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'pabrik.produksi-form', args: [],
            label: 'Produksi Baru', icon: 'fas fa-industry');
    }

    public function editProduksi(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'pabrik.produksi-form', args: ['produksiId' => $id],
            label: 'Produksi: ' . $nomor, icon: 'fas fa-industry');
    }

    #[On('produksi-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /** Batalkan Produksi - SOFT (fstokd.SDCANCEL=1+fstoku.SUSTATUS=9), lihat ProduksiWriter::cancel(). */
    public function cancel(int $id, ProduksiWriter $writer): void
    {
        if (! can_do('pabrik/produksi', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS === ProduksiWriter::STATUS_BATAL) {
            session()->flash('error', 'Produksi ini sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'pabrik/produksi', $h->SUNOTRANSAKSI, 'Batalkan Produksi ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'Produksi dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();
        $jopWriter = app(JopWriter::class);
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            ->leftJoin('fproduksiu as jop', 'jop.PUID', '=', 'u.SUIDJOP')
            ->where('u.SUSUMBER', ProduksiWriter::SUMBER)
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('jop.PUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as cabang',
                'gt.GNAMA as cabangJadi', 'jop.PUNOTRANSAKSI as noJop',
            ]);

        return view('livewire.pabrik.produksi-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'pullable'  => $this->showPicker && $ucabang
                ? $jopWriter->pullableForPro($ucabang)->when($this->pickerQ !== '', fn ($c) => $c->filter(
                    fn ($r) => str_contains(strtolower($r->nomor), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->karyawan), strtolower($this->pickerQ))
                ))
                : collect(),
        ]);
    }
}
