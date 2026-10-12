<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\TmbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Terima Mutasi Barang (TMB)" - daftar fstoku (SUSUMBER='TMB'). Tahap TERAKHIR
 * alur PARALEL PR jenis=0: RS -> KMB -> TMB. TMB "menerima" SELURUH isi 1 KMB yg belum
 * diterima - lihat modal picker di bawah, pola sama VB6 asli `fFrmPenerimaanMutasi.frm`.
 */
class TmbList extends Component
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

    /** Satu-satunya tombol di daftar: buka form TMB KOSONG. KMB sumbernya dipilih DI
     *  DALAM form lewat tombol cari di kotak "No Kirim Mutasi Barang" - persis VB6, yg
     *  `fFrmPenerimaanMutasi::cmdCariNoReff` memang menempel di form (permintaan user
     *  2026-10-10, sama spt yg sudah dilakukan di KMB). */
    public function inputBaru(): void
    {
        if (! can_do('inventory/tmb', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'purchase.tmb-form', args: [],
            label: 'TMB Baru', icon: 'fas fa-dolly');
    }

    public function editTmb(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.tmb-form', args: ['tmbId' => $id],
            label: 'TMB: ' . $nomor, icon: 'fas fa-dolly');
    }

    #[On('tmb-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan TMB - SOFT (fstokd.SDCANCEL=1 + fstoku.SUSTATUS=9, lihat
     * TmbWriter::cancel() utk detail pembalikan stok/status KMB).
     */
    public function cancel(int $id, TmbWriter $writer): void
    {
        if (! can_do('inventory/tmb', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS === TmbWriter::STATUS_BATAL) {
            session()->flash('error', 'TMB ini sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'inventory/tmb', $h->SUNOTRANSAKSI, 'Batalkan TMB ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'TMB dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('fstoku as kmb', 'kmb.SUID', '=', 'u.SUPRUID')
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'kmb.SUPBUID')
            ->where('u.SUSUMBER', TmbWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user - SUCABANG = cabang PENERIMA.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('kmb.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('pr.PBUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as cabang',
                'kmb.SUNOTRANSAKSI as noKmb', 'pr.PBUNOTRANSAKSI as noPr',
            ]);

        return view('livewire.purchase.tmb-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
        ]);
    }
}
