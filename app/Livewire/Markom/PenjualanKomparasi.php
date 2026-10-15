<?php

namespace App\Livewire\Markom;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardMarkom;
use Illuminate\Support\Carbon;
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
        $this->samakanBulanLalu();
    }

    protected function propSaringan(): array
    {
        return ['dariA', 'sampaiA', 'dariB', 'sampaiB'];
    }

    /** Tombol "Bulan lalu" - Periode B = rentang Periode A digeser mundur satu bulan. */
    public function samakanBulanLalu(): void
    {
        $this->dariB = Carbon::parse($this->dariA)->subMonthNoOverflow()->toDateString();
        $this->sampaiB = Carbon::parse($this->sampaiA)->subMonthNoOverflow()->toDateString();
        $this->updated('dariB');
    }

    /** Tombol "Tahun lalu" - Periode B = rentang Periode A di tahun sebelumnya. */
    public function samakanTahunLalu(): void
    {
        $this->dariB = Carbon::parse($this->dariA)->subYearNoOverflow()->toDateString();
        $this->sampaiB = Carbon::parse($this->sampaiA)->subYearNoOverflow()->toDateString();
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
            [$hasil, $galat] = $this->tarik('Markom Komparasi', function () use ($svc, $cabang) {
                $a = $svc->perCabang($this->dariA, $this->sampaiA, $cabang);
                $b = $svc->perCabang($this->dariB, $this->sampaiB, $cabang);

                // Gabungkan kedua periode per cabang - cabang yg hanya ada di salah satu
                // periode tetap tampil dgn 0 di periode lainnya.
                $baris = $a->keys()->merge($b->keys())->unique()->map(fn ($id) => (object) [
                    'id'    => $id,
                    'nama'  => $a[$id]->nama ?? $b[$id]->nama ?? null,
                    'jualA' => $a[$id]->penjualan ?? 0.0,
                    'jualB' => $b[$id]->penjualan ?? 0.0,
                    'pasA'  => $a[$id]->pasien ?? 0,
                    'pasB'  => $b[$id]->pasien ?? 0,
                ])->sortByDesc('jualA')->values();

                return [
                    'baris'  => $baris,
                    'totalA' => $svc->total($this->dariA, $this->sampaiA, $cabang),
                    'totalB' => $svc->total($this->dariB, $this->sampaiB, $cabang),
                ];
            });
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

    private function teksPeriode(string $dari, string $sampai): string
    {
        $f = fn ($t) => $t ? Carbon::parse($t)->locale('id')->isoFormat('D MMM Y') : '—';

        return $f($dari) . ' s/d ' . $f($sampai);
    }
}
