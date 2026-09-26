<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Models\User;
use App\Services\InvoicePenjualanMutasiWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Invoice Penjualan Mutasi (IVM)" - daftar `einvoicepenjualanu` `IPUSUMBER='IVM'`.
 * Sama pola `InvoiceList` (IV): "Tambah" langsung buka `InvoiceMutasiForm` kosong,
 * tarik-dari-TMB dilakukan DI DALAM form. Lihat docblock `InvoicePenjualanMutasiWriter`.
 */
class InvoiceMutasiList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
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

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }

    public function newInvoice(): void
    {
        if (! can_do('sales/invoice-mutasi', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'sales.invoice-mutasi-form', args: [],
            label: 'Invoice Mutasi Baru', icon: 'fas fa-truck-arrow-right');
    }

    public function editInvoice(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'sales.invoice-mutasi-form', args: ['invoiceId' => $id],
            label: 'IVM: ' . $nomor, icon: 'fas fa-truck-arrow-right');
    }

    #[On('invoice-mutasi-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    public function delete(int $id, InvoicePenjualanMutasiWriter $writer): void
    {
        if (! can_do('sales/invoice-mutasi', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h) {
            session()->flash('error', 'Invoice tidak ditemukan.');

            return;
        }

        if ($writer->delete($id)) {
            activity_log('delete', 'sales/invoice-mutasi', $h->IPUNOTRANSAKSI, 'Hapus Invoice Mutasi ' . $h->IPUNOTRANSAKSI);
            session()->flash('status', 'Invoice Mutasi dihapus.');
        }
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('einvoicepenjualanu as u')
            ->leftJoin('bgudang as ga', 'ga.GID', '=', 'u.IPUGUDANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.IPUGUDANGTUJUAN')
            ->where('u.IPUSUMBER', InvoicePenjualanMutasiWriter::SUMBER)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.IPUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('ga.GNAMA', 'like', "%{$q}%")
                ->orWhere('gt.GNAMA', 'like', "%{$q}%")))
            ->when($this->fCabang !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.IPUGUDANG', $this->fCabang)->orWhere('u.IPUGUDANGTUJUAN', $this->fCabang)))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.IPUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.IPUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.IPUID')
            ->paginate(20, [
                'u.IPUID as id', 'u.IPUNOTRANSAKSI as nomor', 'u.IPUTANGGAL as tanggal',
                'ga.GNAMA as asal', 'gt.GNAMA as tujuan', 'u.IPUTOTALTRANSAKSI as total',
            ]);

        return view('livewire.sales.invoice-mutasi-list', [
            'rows'     => $rows,
            'branches' => Branch::options(),
        ]);
    }
}
