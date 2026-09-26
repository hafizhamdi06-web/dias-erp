<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Models\User;
use App\Services\InvoicePenjualanWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Invoice Penjualan (IV)" - daftar `einvoicepenjualanu`. Sama pola `PbList`: TIDAK
 * ada picker di sini - "Tambah" langsung buka `InvoiceForm` kosong, tarik-dari-SJ
 * dilakukan DI DALAM form. Lihat docblock `InvoicePenjualanWriter` utk detail riset.
 */
class InvoiceList extends Component
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
        if (! can_do('sales/invoice', 'add')) {
            return;
        }
        $this->dispatch('open-tab', cmp: 'sales.invoice-form', args: [],
            label: 'Invoice Baru', icon: 'fas fa-file-invoice-dollar');
    }

    public function editInvoice(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'sales.invoice-form', args: ['invoiceId' => $id],
            label: 'IV: ' . $nomor, icon: 'fas fa-file-invoice-dollar');
    }

    #[On('invoice-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    public function delete(int $id, InvoicePenjualanWriter $writer): void
    {
        if (! can_do('sales/invoice', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h) {
            session()->flash('error', 'Invoice tidak ditemukan.');

            return;
        }

        if ($writer->delete($id)) {
            activity_log('delete', 'sales/invoice', $h->IPUNOTRANSAKSI, 'Hapus Invoice ' . $h->IPUNOTRANSAKSI);
            session()->flash('status', 'Invoice dihapus.');
        }
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('einvoicepenjualanu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.IPUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.IPUGUDANG')
            ->where('u.IPUSUMBER', InvoicePenjualanWriter::SUMBER)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.IPUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.IPUGUDANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.IPUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.IPUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.IPUID')
            ->paginate(20, [
                'u.IPUID as id', 'u.IPUNOTRANSAKSI as nomor', 'u.IPUTANGGAL as tanggal',
                'k.KNAMA as kontak', 'g.GNAMA as gudang', 'u.IPUTOTALTRANSAKSI as total',
            ]);

        return view('livewire.sales.invoice-list', [
            'rows'     => $rows,
            'branches' => Branch::options(),
        ]);
    }
}
