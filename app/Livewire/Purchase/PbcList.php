<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PbcWriter;
use App\Services\SjWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Penerimaan Barang Cabang (PBC)" - daftar fstoku (SUSUMBER='PBC'). Tahap TERAKHIR
 * alur PR/RS → PKB → SJ → PBC. PBC "menerima" SELURUH isi 1 SJ yg belum diterima - lihat
 * modal picker di bawah, pola sama VB6 asli
 * `fFrmPenerimaanBarangDariSJ.frm::cmdCariNoSJApotik_Click` (jalur TSJ/`fstoksju` di file
 * yg sama SENGAJA tidak direplikasi, mekanisme terpisah di luar cakupan alur ini).
 */
class PbcList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fFrom = '';
    public string $fTo = '';

    // modal picker "Terima dari SJ"
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
        if (! can_do('purchase/pbc', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickSj(int $sjId, string $nomor): void
    {
        if (! can_do('purchase/pbc', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.pbc-form', args: ['sjId' => $sjId],
            label: 'PBC dari ' . $nomor, icon: 'fas fa-box-open');
    }

    public function editPbc(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.pbc-form', args: ['pbcId' => $id],
            label: 'PBC: ' . $nomor, icon: 'fas fa-box-open');
    }

    #[On('pbc-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan PBC - SOFT (fstokd.SDCANCEL=1 + fstoku.SUSTATUS=9, lihat PbcWriter::cancel()
     * utk detail pembalikan stok/status SJ/status PR).
     */
    public function cancel(int $id, PbcWriter $writer): void
    {
        if (! can_do('purchase/pbc', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS === PbcWriter::STATUS_BATAL) {
            session()->flash('error', 'PBC ini sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'purchase/pbc', $h->SUNOTRANSAKSI, 'Batalkan PBC ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'PBC dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();
        $sjWriter = app(SjWriter::class);

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('fstoku as sj', 'sj.SUID', '=', 'u.SUNOSJAPOTIK')
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'u.SUPBUID')
            ->where('u.SUSUMBER', PbcWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user (defense-in-depth) - SUCABANG
            // = cabang PENERIMA (pola sama SUCABANG PosDataList/SjList).
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('sj.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('pr.PBUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as cabang',
                'sj.SUNOTRANSAKSI as noSj', 'pr.PBUNOTRANSAKSI as noPr',
            ]);

        return view('livewire.purchase.pbc-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'pullable'  => $this->showPicker
                ? $sjWriter->pullableForPbc()->when($this->pickerQ !== '', fn ($c) => $c->filter(
                    fn ($r) => str_contains(strtolower($r->nomor), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->karyawan), strtolower($this->pickerQ))
                ))
                : collect(),
        ]);
    }
}
