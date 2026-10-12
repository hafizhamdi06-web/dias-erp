<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\KmbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Kirim Mutasi Barang (KMB)" - daftar fstoku (SUSUMBER='KMB'). Tahap PERTAMA alur
 * PARALEL PR jenis=0 (Permintaan Barang, TANPA verifikasi): RS -> KMB -> TMB. Dibuat
 * cabang PENGIRIM (yg py stok) - lihat picker "Tarik dari PR" di bawah, pola sama VB6
 * asli `fFrmKirimMutasiBarang.frm` (jalur Job-Order/`fproduksiu` di file yg sama
 * SENGAJA tidak direplikasi, di luar cakupan alur ini).
 */
class KmbList extends Component
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

    /** Satu-satunya tombol di daftar: buka form KMB KOSONG. Rujukannya (PR atau JOP)
     *  dipilih DI DALAM form lewat tombol cari di kotak "No PO"/"No Job Order" -
     *  persis VB6, yg kedua tombol carinya memang menempel di form (permintaan user
     *  2026-10-10: "di data KMB, cukup 1 tombol yaitu INPUT BARU"). */
    public function inputBaru(): void
    {
        if (! can_do('inventory/kmb', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'purchase.kmb-form', args: [],
            label: 'KMB Baru', icon: 'fas fa-truck-ramp-box');
    }

    public function editKmb(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.kmb-form', args: ['kmbId' => $id],
            label: 'KMB: ' . $nomor, icon: 'fas fa-truck-ramp-box');
    }

    #[On('kmb-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan KMB - SOFT (fstokd.SDCANCEL=1 + fstoku.SUSTATUS=9 + reset PBUSTATUSKM
     * PR=0, lihat KmbWriter::cancel()).
     */
    public function cancel(int $id, KmbWriter $writer): void
    {
        if (! can_do('inventory/kmb', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS !== 1) {
            session()->flash('error', 'KMB ini sudah diterima atau sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'inventory/kmb', $h->SUNOTRANSAKSI, 'Batalkan KMB ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'KMB dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'u.SUPBUID')
            ->where('u.SUSUMBER', KmbWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user - SUCABANG = cabang PENGIRIM.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('pr.PBUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as cabang',
                'gt.GNAMA as cabangTujuan', 'pr.PBUNOTRANSAKSI as noPr',
            ]);

        return view('livewire.purchase.kmb-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
        ]);
    }
}
