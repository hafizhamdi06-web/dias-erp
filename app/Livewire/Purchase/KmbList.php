<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\KmbWriter;
use App\Services\PurchaseRequestWriter;
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

    // modal picker "Tarik dari PR"
    public bool $showPicker = false;
    public string $pickerQ = '';

    // pemilih "Tarik dari JOP" - terpisah dari pemilih PR, lihat `pickJop()`.
    public bool $showJopPicker = false;
    public string $jopQ = '';

    /** Batas baris di pemilih JOP - lihat pelajaran `SjList::BATAS_PKB`. */
    private const BATAS_JOP = 25;

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
        if (! can_do('inventory/kmb', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickPr(int $prId, string $nomor): void
    {
        if (! can_do('inventory/kmb', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.kmb-form', args: ['prId' => $prId],
            label: 'KMB dari ' . $nomor, icon: 'fas fa-truck-ramp-box');
    }

    /* ---- Tarik dari JOP (port VB6 `fFrmKirimMutasiBarang::cmdCariNoReff_Click`) ----
     | Pemilih TERPISAH dari pemilih PR: sumbernya beda tabel, isi barisnya beda, dan satu
     | KMB hanya boleh lahir dari SALAH SATU. Lihat `KmbWriter::fromJop()`. */

    public function openJopPicker(): void
    {
        if (! can_do('inventory/kmb', 'add')) {
            return;
        }
        $this->jopQ = '';
        $this->showJopPicker = true;
    }

    public function closeJopPicker(): void
    {
        $this->showJopPicker = false;
    }

    public function pickJop(int $jopId, string $nomor): void
    {
        if (! can_do('inventory/kmb', 'add')) {
            return;
        }
        $this->showJopPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.kmb-form', args: ['jopId' => $jopId],
            label: 'KMB dari ' . $nomor, icon: 'fas fa-truck-ramp-box');
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
        $prWriter = app(PurchaseRequestWriter::class);

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

        // PR yg boleh ditarik = PBUGUDANGSUMBER (cabang yg DIMINTA mengirim/sumber stok)
        // SAMA dgn cabang user login SEKARANG - PERSIS UCABANG yg dipakai KmbForm sbg
        // SUCABANG KMB (single, tidak bisa dipilih), lihat docblock
        // `PurchaseRequestWriter::pullableForKmb()`.
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);

        $pullableJop = $this->showJopPicker
            ? app(KmbWriter::class)->pullableJop($this->jopQ, self::BATAS_JOP + 1)
            : collect();

        return view('livewire.purchase.kmb-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            'pullable'  => $this->showPicker && $ucabang
                ? $prWriter->pullableForKmb($ucabang)->when($this->pickerQ !== '', fn ($c) => $c->filter(
                    fn ($r) => str_contains(strtolower($r->nomor), strtolower($this->pickerQ))
                        || str_contains(strtolower((string) $r->karyawan), strtolower($this->pickerQ))
                ))
                : collect(),
            // Pemilih JOP: dibatasi & disaring di SQL sejak awal (pelajaran pemilih PKB).
            'pullableJop' => $pullableJop->take(self::BATAS_JOP),
            'jopAdaLagi'  => $pullableJop->count() > self::BATAS_JOP,
            'jopBatas'    => self::BATAS_JOP,
        ]);
    }
}
