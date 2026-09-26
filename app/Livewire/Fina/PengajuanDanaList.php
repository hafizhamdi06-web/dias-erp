<?php

namespace App\Livewire\Fina;

use App\Models\Branch;
use App\Models\User;
use App\Services\PengajuanDanaWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class PengajuanDanaList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fFrom = '';
    public string $fTo = '';
    public string $fCabang = '';

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
        if (! can_do('finance/pengajuan-dana', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'fina.pengajuan-dana-form', args: [],
            label: 'Pengajuan Dana Baru', icon: 'fas fa-hand-holding-dollar');
    }

    public function editTransaksi(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'fina.pengajuan-dana-form', args: ['id' => $id],
            label: 'Pengajuan Dana: ' . $nomor, icon: 'fas fa-hand-holding-dollar');
    }

    #[On('pengajuan-dana-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    public function hapus(int $id, PengajuanDanaWriter $writer): void
    {
        if (! can_do('finance/pengajuan-dana', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h) {
            session()->flash('error', 'Transaksi tidak ditemukan.');

            return;
        }

        if ($writer->delete($id)) {
            activity_log('delete', 'finance/pengajuan-dana', $h->CUNOTRANSAKSI, 'Hapus Pengajuan Dana ' . $h->CUNOTRANSAKSI . ' (baris KK terkait dilepas otomatis)');
            session()->flash('status', 'Pengajuan Dana ' . $h->CUNOTRANSAKSI . ' dihapus, baris Kas Keluar terkait bisa ditarik lagi.');
        }
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('ctransaksipu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.CUKONTAK')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'u.CUREKKAS')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.CUCABANG')
            ->where('u.CUSUMBER', PengajuanDanaWriter::SUMBER)
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

        return view('livewire.fina.pengajuan-dana-list', [
            'rows'     => $rows,
            'branches' => Branch::options(),
        ]);
    }
}
