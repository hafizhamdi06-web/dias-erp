<?php

namespace App\Livewire\Markom;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardMarkom;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Dashboard Penjualan Markom - 1. Penjualan Total: Nilai Penjualan & Jumlah Pasien dari
 * tanggal 1 s/d tanggal 2, dirinci per cabang. Rumusnya di `DashboardMarkom`.
 */
class PenjualanTotal extends Component
{
    use MarkomFilter;

    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';

    public function mount(): void
    {
        $this->dari = now()->startOfMonth()->toDateString();
        $this->sampai = now()->toDateString();
    }

    protected function propSaringan(): array
    {
        return ['dari', 'sampai'];
    }

    public function render()
    {
        abort_unless(can_do('markom/total', 'view'), 403);

        $svc = app(DashboardMarkom::class);
        $branches = $this->branchOptions();
        $hasil = null;
        $galat = null;

        if ($this->tampil) {
            $cabang = $this->cabangDipakai();
            [$hasil, $galat] = $this->tarik('Markom Penjualan Total', fn () => [
                'total' => $svc->total($this->dari, $this->sampai, $cabang),
                'baris' => $svc->perCabang($this->dari, $this->sampai, $cabang),
            ]);
        }

        return view('livewire.markom.penjualan-total', [
            'branches'    => $branches,
            'labelCabang' => $this->labelCabang($branches),
            'total'       => $hasil['total'] ?? null,
            'baris'       => $hasil['baris'] ?? collect(),
            'galat'       => $galat,
            'periode'     => $this->teksPeriode($this->dari, $this->sampai),
        ]);
    }
}
