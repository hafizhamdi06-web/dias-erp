<?php

namespace App\Livewire\Markom;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardMarkom;
use Livewire\Component;

/**
 * Dashboard Penjualan Markom - 2. Penjualan Komparasi: Nilai Penjualan & Pasien PERIODE A
 * dibandingkan PERIODE B, untuk satu atau beberapa cabang sekaligus.
 *
 * Default: A = awal bulan ini s/d hari ini, B = rentang tanggal yg SAMA di bulan lalu -
 * perbandingan bulan-ke-bulan yg adil (jumlah hari sama).
 */
class PenjualanKomparasi extends Component
{
    use MarkomFilter;

    public ?string $tabKey = null;

    public string $dariA = '';
    public string $sampaiA = '';
    public string $dariB = '';
    public string $sampaiB = '';

    public function mount(): void
    {
        $this->dariA = now()->startOfMonth()->toDateString();
        $this->sampaiA = now()->toDateString();
        [$this->dariB, $this->sampaiB] = $this->periodeLalu($this->dariA, $this->sampaiA, 'bulan');
    }

    protected function propSaringan(): array
    {
        return ['dariA', 'sampaiA', 'dariB', 'sampaiB'];
    }

    /** Tombol "Bulan lalu"/"Tahun lalu" - Periode B = rentang Periode A digeser mundur. */
    public function samakan(string $satuan): void
    {
        [$this->dariB, $this->sampaiB] = $this->periodeLalu($this->dariA, $this->sampaiA, $satuan);
        $this->updated('dariB');
    }

    public function render()
    {
        abort_unless(can_do('markom/komparasi', 'view'), 403);

        $svc = app(DashboardMarkom::class);
        $branches = $this->branchOptions();
        $hasil = null;
        $galat = null;

        if ($this->tampil) {
            $cabang = $this->cabangDipakai();
            [$hasil, $galat] = $this->tarik('Markom Komparasi', fn () => [
                'baris'  => $svc->bandingkan(
                    $svc->perCabang($this->dariA, $this->sampaiA, $cabang),
                    $svc->perCabang($this->dariB, $this->sampaiB, $cabang),
                    ['penjualan', 'pasien']
                ),
                'totalA' => $svc->total($this->dariA, $this->sampaiA, $cabang),
                'totalB' => $svc->total($this->dariB, $this->sampaiB, $cabang),
            ]);
        }

        return view('livewire.markom.penjualan-komparasi', [
            'branches'    => $branches,
            'labelCabang' => $this->labelCabang($branches),
            'baris'       => $hasil['baris'] ?? collect(),
            'totalA'      => $hasil['totalA'] ?? null,
            'totalB'      => $hasil['totalB'] ?? null,
            'galat'       => $galat,
            'periodeA'    => $this->teksPeriode($this->dariA, $this->sampaiA),
            'periodeB'    => $this->teksPeriode($this->dariB, $this->sampaiB),
        ]);
    }
}
