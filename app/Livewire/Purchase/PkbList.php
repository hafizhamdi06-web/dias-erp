<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PkbWriter;
use App\Services\PurchaseRequestWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Perintah Kirim Barang (PKB)" - daftar fperintahkirimbarangu. PKB "menarik" data
 * dari PR (Permintaan Barang) yg sudah Disetujui - lihat modal picker di bawah, pola
 * sama VB6 asli `fFrmPerintahkirimBarang.frm::cmdCariPermintaan_Click`.
 */
class PkbList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fFrom = '';
    public string $fTo = '';

    // modal picker "Tarik dari PR"
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
        if (! can_do('purchase/pkb', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickPr(int $prId, string $nomor, PurchaseRequestWriter $writer): void
    {
        if (! can_do('purchase/pkb', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.pkb-form', args: ['prId' => $prId],
            label: 'PKB dari ' . $nomor, icon: 'fas fa-dolly-flatbed');
    }

    public function editPkb(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.pkb-form', args: ['pkbId' => $id],
            label: 'PKB: ' . $nomor, icon: 'fas fa-dolly-flatbed');
    }

    #[On('pkb-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan PKB - SOFT status (PKBUSTATUS=9), mengembalikan qty yg sempat ditarik ke
     * PR asal (lihat PkbWriter::cancel()/PurchaseRequestWriter::releaseOnCancel()).
     */
    public function cancel(int $id, PkbWriter $writer): void
    {
        if (! can_do('purchase/pkb', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->PKBUSTATUS !== 0) {
            session()->flash('error', 'Hanya PKB yang masih baru bisa dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'purchase/pkb', $h->PKBUNOTRANSAKSI, 'Batalkan PKB ' . $h->PKBUNOTRANSAKSI);
            session()->flash('status', 'PKB dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();
        $writer = app(PurchaseRequestWriter::class);

        $rows = DB::table('fperintahkirimbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PKBUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PKBUGUDANG')
            ->leftJoin('fpermintaanbarangu as pr', 'pr.PBUID', '=', 'u.PKBUNORS')
            ->where('u.PKBUSUMBER', PkbWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user (defense-in-depth) - PKBUGUDANG
            // = cabang PEMBUAT PKB (gudang pengirim), konsep sama SUCABANG PosDataList.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.PKBUGUDANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.PKBUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('pr.PBUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.PKBUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.PKBUGUDANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.PKBUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.PKBUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.PKBUID')
            ->paginate(20, [
                'u.PKBUID as id', 'u.PKBUNOTRANSAKSI as nomor', 'u.PKBUTANGGAL as tanggal',
                'u.PKBUSTATUS as status', 'k.KNAMA as diperintah', 'g.GNAMA as cabang',
                'pr.PBUNOTRANSAKSI as noPr',
            ]);

        return view('livewire.purchase.pkb-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'pullable'  => $this->showPicker
                ? $writer->pullableForPkb()->when($this->pickerQ !== '', fn ($c) => $c->filter(
                    fn ($r) => str_contains(strtolower($r->nomor), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->karyawan), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->cabang), strtolower($this->pickerQ))
                ))
                : collect(),
        ]);
    }
}
