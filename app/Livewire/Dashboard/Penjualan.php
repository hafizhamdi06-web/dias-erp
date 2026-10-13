<?php

namespace App\Livewire\Dashboard;

use App\Services\DashboardPenjualan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Dashboard Penjualan & COGS - lima tingkat rincian sesuai
 * `RANCANGAN DASHBOARD PENJUALAN.xlsx`: PT -> Cabang -> COA -> Item -> Transaksi.
 *
 * Perpindahan tingkat dipegang SATU tumpukan `$jalur`, bukan lima properti terpisah:
 * tingkat yg sedang tampil = kedalaman tumpukan, dan tombol "Kembali" cukup mem-pop-nya.
 * Dgn begitu remah-jejak (breadcrumb), saringan kueri, dan judul selalu sejalan - tidak
 * mungkin tampil "per item" tapi saringan COA-nya tertinggal.
 *
 * Angkanya dihitung `DashboardPenjualan` (satu tempat) - lihat docblock-nya utk rumus,
 * dan kenapa alkes dibagi proporsional.
 */
class Penjualan extends Component
{
    public ?string $tabKey = null;

    /** Saringan: PT (null = semua) & bulan `YYYY-MM`. */
    public ?int $pt = null;
    public string $bulan = '';

    /** @var list<array{tingkat:string,id:int,nama:string}> */
    public array $jalur = [];

    /** No IP yg rincian alkesnya sedang dibuka di tingkat transaksi. */
    public ?string $alkesDibuka = null;

    public function mount(): void
    {
        $this->bulan = now()->format('Y-m');

        // Default PT = PT milik cabang user login (permintaan user), tetap bisa diubah
        // ke "Semua PT".
        $this->pt = app(DashboardPenjualan::class)->ptUser((int) (auth()->user()->UCABANG ?? 0) ?: null);
    }

    /**
     * Tingkat yg sedang tampil.
     *
     * METODE BIASA, bukan properti terhitung (`getTingkatProperty`). Livewire meng-CACHE
     * properti terhitung sepanjang satu request: `detailkan()` membacanya SEBELUM menambah
     * `$jalur`, lalu `render()` di request yg sama memakai nilai basi itu - akibatnya tiap
     * tingkat menampilkan data tingkat DI ATASNYA. Dihitung ulang tiap dipanggil.
     */
    public function tingkat(): string
    {
        return DashboardPenjualan::TINGKAT[count($this->jalur)] ?? 'transaksi';
    }

    /** Saringan utk service - dibangun dari tumpukan, jadi selalu sinkron dgn tampilan. */
    private function saring(): array
    {
        $awal = Carbon::createFromFormat('Y-m', $this->bulan)->startOfMonth();
        $s = ['dari' => $awal->toDateString(), 'sampai' => $awal->copy()->endOfMonth()->toDateString()];

        if ($this->pt) {
            $s['pt'] = $this->pt;
        }
        foreach ($this->jalur as $l) {
            $s[$l['tingkat']] = $l['id'];
        }

        return $s;
    }

    public function detailkan(int $id, string $nama): void
    {
        // Transaksi = tingkat terdalam, tidak ada lagi di bawahnya.
        if ($this->tingkat() === 'transaksi') {
            return;
        }
        $this->jalur[] = ['tingkat' => $this->tingkat(), 'id' => $id, 'nama' => $nama];
        $this->alkesDibuka = null;
    }

    public function kembali(): void
    {
        array_pop($this->jalur);
        $this->alkesDibuka = null;
    }

    /** Lompat langsung lewat remah-jejak. */
    public function keTingkat(int $kedalaman): void
    {
        $this->jalur = array_slice($this->jalur, 0, max(0, $kedalaman));
        $this->alkesDibuka = null;
    }

    public function toggleAlkes(string $noIp): void
    {
        $this->alkesDibuka = $this->alkesDibuka === $noIp ? null : $noIp;
    }

    /** Ganti saringan = mulai lagi dari tingkat teratas. */
    public function updatedPt(): void
    {
        $this->jalur = [];
        $this->alkesDibuka = null;
    }

    public function updatedBulan(): void
    {
        $this->jalur = [];
        $this->alkesDibuka = null;
    }

    public function geserBulan(int $arah): void
    {
        $this->bulan = Carbon::createFromFormat('Y-m', $this->bulan)->addMonths($arah)->format('Y-m');
        $this->updatedBulan();
    }

    public function render()
    {
        $svc = app(DashboardPenjualan::class);
        $saring = $this->saring();

        return view('livewire.dashboard.penjualan', [
            'baris'   => $svc->baris($this->tingkat(), $saring),
            'total'   => $svc->total($saring),
            'ptList'  => $svc->daftarPt(),
            'alkes'   => $this->alkesDibuka ? $svc->alkesTransaksi($this->alkesDibuka) : collect(),
            'namaBulan' => Carbon::createFromFormat('Y-m', $this->bulan)->locale('id')->isoFormat('MMMM Y'),
            'namaPt'  => $this->pt
                ? (string) DB::table('bnamapt')->where('NPID', $this->pt)->value('NPNAMA')
                : 'Semua PT',
        ]);
    }
}
