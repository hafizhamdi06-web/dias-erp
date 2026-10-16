<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardSurgery;
use Livewire\Component;

/**
 * Dashboard Surgery - pantau transaksi SURGERY yg dibayar cicil (permintaan user
 * 2026-10-16). Angka & cara menyusun rantai di `DashboardSurgery`.
 *
 * Saringan cabang ganda & tombol "Tampilkan" memakai trait `MarkomFilter` - pola yg sama
 * dgn dashboard Markom, termasuk penegakan hak cabang user.
 */
class Surgery extends Component
{
    use MarkomFilter;

    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';
    public string $status = '';

    /** Rantai yg rinciannya dibuka (SUID nota ujung). */
    public ?int $dibuka = null;

    public function mount(): void
    {
        // Default awal tahun: surgery bisa dicicil berbulan-bulan, rentang sebulan akan
        // memotong rantai yg mulai bulan lalu.
        $this->dari = now()->startOfYear()->toDateString();
        $this->sampai = now()->toDateString();
    }

    protected function propSaringan(): array
    {
        return ['dari', 'sampai', 'status'];
    }

    protected function setelahSaringanBerubah(): void
    {
        $this->dibuka = null;
    }

    public function toggle(int $id): void
    {
        $this->dibuka = $this->dibuka === $id ? null : $id;
    }

    public function render()
    {
        abort_unless(can_do('sales/surgery', 'view'), 403);

        $svc = app(DashboardSurgery::class);
        $branches = $this->branchOptions();
        $hasil = null;
        $galat = null;

        if ($this->tampil) {
            $cabang = $this->cabangDipakai();
            [$hasil, $galat] = $this->tarik('Dashboard Surgery', function () use ($svc, $cabang) {
                $rantai = $svc->rantai($cabang, $this->dari, $this->sampai, $this->status);

                return [
                    'rantai' => $rantai,
                    // Ringkasan dari saringan TANPA status - kartu tetap memperlihatkan
                    // komposisi berjalan/selesai/cek walau tabel disaring satu status.
                    'ringkas' => $svc->ringkas($this->status === '' ? $rantai
                        : $svc->rantai($cabang, $this->dari, $this->sampai)),
                    'item'   => $this->dibuka ? $svc->item($this->dibuka) : collect(),
                ];
            });
        }

        return view('livewire.dashboard.surgery', [
            'branches'    => $branches,
            'labelCabang' => $this->labelCabang($branches),
            'rantai'      => $hasil['rantai'] ?? collect(),
            'ringkas'     => $hasil['ringkas'] ?? null,
            'item'        => $hasil['item'] ?? collect(),
            'galat'       => $galat,
            'periode'     => $this->teksPeriode($this->dari, $this->sampai),
        ]);
    }
}
