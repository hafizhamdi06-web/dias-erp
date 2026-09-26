<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\KmbWriter;
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

    // modal picker "Terima dari KMB"
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
        if (! can_do('inventory/tmb', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickKmb(int $kmbId, string $nomor): void
    {
        if (! can_do('inventory/tmb', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.tmb-form', args: ['kmbId' => $kmbId],
            label: 'TMB dari ' . $nomor, icon: 'fas fa-dolly');
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
        $kmbWriter = app(KmbWriter::class);

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

        // KMB yg boleh ditarik = SUGUDANGTUJUAN (cabang peminta/tujuan mutasi) SAMA
        // dgn cabang user login SEKARANG - PERSIS UCABANG yg dipakai TmbForm sbg
        // SUCABANG TMB (single, tidak bisa dipilih), lihat docblock
        // `KmbWriter::pullableForTmb()`.
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);

        return view('livewire.purchase.tmb-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'pullable'  => $this->showPicker && $ucabang
                ? $kmbWriter->pullableForTmb($ucabang)->when($this->pickerQ !== '', fn ($c) => $c->filter(
                    fn ($r) => str_contains(strtolower($r->nomor), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->karyawan), strtolower($this->pickerQ))
                ))
                : collect(),
        ]);
    }
}
