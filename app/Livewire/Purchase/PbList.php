<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Penerimaan Barang (PB)" - daftar fstoku (SUSUMBER='PB'). Beda dari PBC/list lain:
 * TIDAK ada picker di sini - "Tambah" langsung buka `PbForm` kosong, tarik-dari-PO
 * dilakukan DI DALAM form (bisa multi-PO, lihat docblock `PbForm`).
 */
class PbList extends Component
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
        // Default filter Gudang = cabang AKTIF user (permintaan user 2026-09-26; dulu cuma
        // terisi kalau cabangnya kebetulan salah satu dari 3 gudang Depo hardcode).
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->fCabang = $ucabang ? (string) $ucabang : '';
    }

    /**
     * Cabang yg boleh dilihat user ini (`auser.UCABANGPILIH` via `User::branchIds()`) -
     * KOSONG (NULL di DB) berarti TIDAK dibatasi, boleh semua cabang aktif. Pola PERSIS
     * `PrList::allowedBranchIds()`/`PosDataList`.
     */
    private function allowedBranchIds(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return $user->branchIds();
    }

    /**
     * Isi dropdown filter Gudang = cabang milik user (permintaan user 2026-09-26).
     * DULU: 3 gudang Depo hardcode (`PbWriter::GUDANG_DEPO`) - dilepas bersamaan dgn
     * perubahan "Gudang Tujuan = cabang user login" di `PbForm`.
     */
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

    public function newPb(): void
    {
        if (! can_do('purchase/receipt', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'purchase.pb-form', args: [],
            label: 'PB Baru', icon: 'fas fa-dolly');
    }

    public function editPb(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.pb-form', args: ['pbId' => $id],
            label: 'PB: ' . $nomor, icon: 'fas fa-dolly');
    }

    #[On('pb-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /** Batalkan PB - SOFT, lihat PbWriter::cancel() utk detail pembalikan stok/sisa PO. */
    public function cancel(int $id, PbWriter $writer): void
    {
        if (! can_do('purchase/receipt', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS === PbWriter::STATUS_BATAL) {
            session()->flash('error', 'PB ini sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'purchase/receipt', $h->SUNOTRANSAKSI, 'Batalkan PB ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'PB dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', PbWriter::SUMBER)
            // Dibatasi ke CABANG MILIK USER (defense-in-depth: filter dropdown boleh
            // dimanipulasi dari sisi klien, batas ini yg menjaga). Kalau `UCABANGPILIH`
            // kosong = user tidak dibatasi, boleh lihat semua cabang. DULU: dibatasi ke 3
            // gudang Depo hardcode.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('u.SUNOREF', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'g.GNAMA as gudang', 'u.SUNOREF as noReff',
            ]);

        return view('livewire.purchase.pb-list', [
            'rows'     => $rows,
            'branches' => $this->branchOptions(),
        ]);
    }
}
