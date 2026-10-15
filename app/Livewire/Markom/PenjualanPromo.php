<?php

namespace App\Livewire\Markom;

use App\Livewire\Concerns\MarkomFilter;
use App\Services\DashboardMarkom;
use Livewire\Component;

/**
 * Dashboard Penjualan Markom - 3. Penjualan Promo: total per promo, tombol Detail membuka
 * produk/tindakan yg terjual lewat promo itu. Jalur promo & rumus di `DashboardMarkom`.
 *
 * Mode KOMPARASI (permintaan user 2026-10-15): centang "Bandingkan" -> muncul Periode B,
 * tabel promo & rinciannya menampilkan A vs B dgn selisih & persen. Promo yg hanya
 * terpakai di salah satu periode tetap tampil (0 di periode lainnya) - justru promo baru /
 * promo yg berhenti itu yg paling ingin dilihat tim marketing.
 */
class PenjualanPromo extends Component
{
    use MarkomFilter;

    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';

    public bool $bandingkan = false;
    public string $dariB = '';
    public string $sampaiB = '';

    /** `emasterpromou.MPUID` yg rinciannya sedang dibuka. */
    public ?int $promoDibuka = null;

    public function mount(): void
    {
        $this->dari = now()->startOfMonth()->toDateString();
        $this->sampai = now()->toDateString();
        [$this->dariB, $this->sampaiB] = $this->periodeLalu($this->dari, $this->sampai, 'bulan');
    }

    protected function propSaringan(): array
    {
        return ['dari', 'sampai', 'bandingkan', 'dariB', 'sampaiB'];
    }

    protected function setelahSaringanBerubah(): void
    {
        $this->promoDibuka = null;
    }

    /** Tombol "Bulan lalu"/"Tahun lalu" - Periode B = rentang Periode A digeser mundur. */
    public function samakan(string $satuan): void
    {
        [$this->dariB, $this->sampaiB] = $this->periodeLalu($this->dari, $this->sampai, $satuan);
        $this->updated('dariB');
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
            $kolom = ['penjualan', 'pasien', 'qty', 'diskon', 'transaksi'];

            [$hasil, $galat] = $this->tarik('Markom Penjualan Promo', function () use ($svc, $cabang, $kolom) {
                $baris = $svc->perPromo($this->dari, $this->sampai, $cabang);
                $detail = $this->promoDibuka
                    ? $svc->itemPromo($this->promoDibuka, $this->dari, $this->sampai, $cabang)
                    : collect();

                if (! $this->bandingkan) {
                    return ['baris' => $baris, 'detail' => $detail];
                }

                return [
                    'baris'  => $svc->bandingkan($baris, $svc->perPromo($this->dariB, $this->sampaiB, $cabang), $kolom),
                    'detail' => $this->promoDibuka
                        ? $svc->bandingkan($detail,
                            $svc->itemPromo($this->promoDibuka, $this->dariB, $this->sampaiB, $cabang), $kolom)
                        : collect(),
                ];
            });
        }

        return view('livewire.markom.penjualan-promo', [
            'branches'    => $branches,
            'labelCabang' => $this->labelCabang($branches),
            'baris'       => $hasil['baris'] ?? collect(),
            'detail'      => $hasil['detail'] ?? collect(),
            'galat'       => $galat,
            'periode'     => $this->teksPeriode($this->dari, $this->sampai),
            'periodeB'    => $this->teksPeriode($this->dariB, $this->sampaiB),
            // Mode tampilan mengikuti data yg SUDAH ditarik, bukan centangan terbaru -
            // mencentang/melepas "Bandingkan" sudah mengosongkan data lewat `updated()`.
            'mode'        => $this->tampil && $this->bandingkan ? 'banding' : 'tunggal',
        ]);
    }
}
