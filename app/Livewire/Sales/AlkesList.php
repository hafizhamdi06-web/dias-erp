<?php

namespace App\Livewire\Sales;

use App\Services\AlkesWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar dokumen Input Alkes Depo (`fstoku` SUSUMBER='AL') + picker transaksi IP untuk
 * membuat yg baru. Lihat docblock `AlkesWriter` untuk seluruh aturan modul ini.
 */
#[Layout('layouts.app')]
#[Title('Input Alkes Depo')]
class AlkesList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $dari = '';
    public string $sampai = '';
    public string $fStatus = '';

    // modal picker "Cari No IP"
    public bool $showPicker = false;
    public string $pickerQ = '';

    public function mount(): void
    {
        $this->dari = now()->toDateString();
        $this->sampai = now()->toDateString();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingDari(): void
    {
        $this->resetPage();
    }

    public function updatingSampai(): void
    {
        $this->resetPage();
    }

    public function updatingFStatus(): void
    {
        $this->resetPage();
    }

    public function openPicker(): void
    {
        abort_unless(can_do('sales/alkes', 'add'), 403);
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    /** Buka tab form untuk 1 transaksi IP. */
    public function pilihIp(int $ipId): void
    {
        abort_unless(can_do('sales/alkes', 'add'), 403);
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'sales.alkes-form', args: ['ipId' => $ipId],
            label: 'Input Alkes', icon: 'fas fa-syringe');
    }

    public function lihat(int $id): void
    {
        $this->dispatch('open-tab', cmp: 'sales.alkes-form', args: ['alkesId' => $id],
            label: 'Alkes', icon: 'fas fa-syringe');
    }

    public function batalkan(int $id, AlkesWriter $writer): void
    {
        abort_unless(can_do('sales/alkes', 'delete'), 403);

        if (! $writer->cancel($id)) {
            // `toast` = event global, ditangani `dias-helpers.js` (lihat diasToast).
            $this->dispatch('toast', message: 'Gagal membatalkan - mungkin sudah dibatalkan.', type: 'error');

            return;
        }

        $nomor = (string) DB::table('fstoku')->where('SUID', $id)->value('SUNOTRANSAKSI');
        activity_log('delete', 'sales/alkes', $nomor, 'Batalkan Alkes ' . $nomor);
        $this->dispatch('toast', message: 'Alkes ' . $nomor . ' dibatalkan, stok dikembalikan.', type: 'success');
    }

    #[On('alkes-saved')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(can_do('sales/alkes', 'view'), 403);

        $q = trim($this->search);
        $cabang = (int) (auth()->user()->UCABANG ?? 0);

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', AlkesWriter::SUMBER)
            ->where('u.SUCABANG', $cabang)
            ->when($this->dari !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->dari))
            ->when($this->sampai !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->sampai))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('u.SUNOREF', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUNOREF', 'u.SUURAIAN',
                'u.SUSTATUS', 'k.KNAMA as pelanggan', 'g.GNAMA as cabang',
                DB::raw('(SELECT COUNT(*) FROM fstokd d WHERE d.SDIDSU = u.SUID) as n_item'),
            ]);

        return view('livewire.sales.alkes-list', [
            'rows'     => $rows,
            'pullable' => $this->showPicker
                ? app(AlkesWriter::class)->pullableIp($cabang, $this->pickerQ, $this->dari, $this->sampai)
                : [],
        ]);
    }
}
