<?php

namespace App\Livewire\Markom;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardMarkom;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Dashboard Penjualan Markom - 3. Penjualan Promo: total per promo, tombol Detail membuka
 * produk/tindakan yg terjual lewat promo itu. Jalur promo & rumus di `DashboardMarkom`.
 */
class PenjualanPromo extends Component
{
    use MarkomFilter;

    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';

    /** `emasterpromou.MPUID` yg rinciannya sedang dibuka. */
    public ?int $promoDibuka = null;

    public function mount(): void
    {
        $this->dari = now()->startOfMonth()->toDateString();
        $this->sampai = now()->toDateString();
    }

    protected function propSaringan(): array
    {
        return ['dari', 'sampai'];
    }

    protected function setelahSaringanBerubah(): void
    {
        $this->promoDibuka = null;
    }

    public function toggleDetail(int $promo): void
    {
        $this->promoDibuka = $this->promoDibuka === $promo ? null : $promo;
    }

    public function render()
    {
        abort_unless(can_do('markom/promo', 'view'), 403);

        $svc = app(DashboardMarkom::class);
        $branches = $this->branchOptions();
        $hasil = null;
        $galat = null;

        if ($this->tampil) {
            $cabang = $this->cabangDipakai();
            [$hasil, $galat] = $this->tarik('Markom Penjualan Promo', fn () => [
                'baris'  => $svc->perPromo($this->dari, $this->sampai, $cabang),
                'detail' => $this->promoDibuka
                    ? $svc->itemPromo($this->promoDibuka, $this->dari, $this->sampai, $cabang)
                    : collect(),
            ]);
        }

        $f = fn ($t) => $t ? Carbon::parse($t)->locale('id')->isoFormat('D MMM Y') : '—';

        return view('livewire.markom.penjualan-promo', [
            'branches'    => $branches,
            'labelCabang' => $this->labelCabang($branches),
            'baris'       => $hasil['baris'] ?? collect(),
            'detail'      => $hasil['detail'] ?? collect(),
            'galat'       => $galat,
            'periode'     => $f($this->dari) . ' s/d ' . $f($this->sampai),
        ]);
    }
}
