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

    /**
     * `fstokd.SDID` baris yg rincian alkesnya sedang dibuka di tingkat transaksi.
     *
     * SDID, bukan No IP: sejak tingkat terdalam dipecah PER BARIS, satu nota bisa muncul
     * beberapa kali - memakai nomor nota akan membuka rincian di semua baris nota itu
     * sekaligus, dan alkesnya pun milik baris, bukan milik nota.
     */
    public ?int $alkesDibuka = null;

    /**
     * Data baru ditarik SETELAH user menekan "Tampilkan" (permintaan user 2026-10-13).
     *
     * Bukan sekadar selera: sekali tarik menyapu seluruh `fstokd` IP sebulan - Juni 2026
     * saja 15.804 dokumen - jadi menariknya otomatis tiap kali menu dibuka atau saringan
     * disentuh membuat layar terasa menggantung tanpa diminta. Pola yg sama dgn dialog
     * "Cari Transaksi"/"Cari Kontak" yg juga punya tombol Tampilkan.
     */
    public bool $tampil = false;

    /** Saringan diubah setelah data tampil - dipakai utk menandai tombol Tampilkan. */
    public bool $saringanBerubah = false;

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

    public function toggleAlkes(int $sdid): void
    {
        $this->alkesDibuka = $this->alkesDibuka === $sdid ? null : $sdid;
    }

    /** Tombol "Tampilkan" - satu-satunya yg memulai penarikan data. */
    public function tampilkan(): void
    {
        $this->tampil = true;
        $this->saringanBerubah = false;
    }

    /**
     * Ganti saringan = mulai lagi dari tingkat teratas, dan data lama DIKOSONGKAN.
     *
     * Sengaja tidak langsung menarik ulang: angka lama yg masih terpampang padahal
     * saringannya sudah berbeda lebih menyesatkan daripada layar kosong - user bisa
     * salah membaca angka bulan lain sbg bulan yg baru dipilih.
     */
    private function saringanDiubah(): void
    {
        $this->jalur = [];
        $this->alkesDibuka = null;
        $this->saringanBerubah = $this->tampil;
        $this->tampil = false;
    }

    public function updatedPt(): void
    {
        $this->saringanDiubah();
    }

    public function updatedBulan(): void
    {
        $this->saringanDiubah();
    }

    public function geserBulan(int $arah): void
    {
        $this->bulan = Carbon::createFromFormat('Y-m', $this->bulan)->addMonths($arah)->format('Y-m');
        $this->saringanDiubah();
    }

    public function render()
    {
        $svc = app(DashboardPenjualan::class);
        $saring = $this->saring();

        // Selama belum ditekan "Tampilkan", TIDAK ADA kueri berat yg dijalankan sama
        // sekali - bukan sekadar hasilnya disembunyikan.
        $baris = $this->tampil ? $svc->baris($this->tingkat(), $saring) : collect();

        return view('livewire.dashboard.penjualan', [
            'baris'   => $baris,
            // Benar HANYA setelah `baris()` dipanggil - jangan dipindah ke atas.
            'adaSisa' => $this->tampil && $svc->adaSisa(),
            'batas'   => DashboardPenjualan::BATAS_BARIS,
            'total'   => $this->tampil ? $svc->total($saring) : null,
            'ptList'  => $svc->daftarPt(),
            'alkes'   => $this->tampil && $this->alkesDibuka ? $svc->alkesTransaksi($this->alkesDibuka) : collect(),
            'namaBulan' => Carbon::createFromFormat('Y-m', $this->bulan)->locale('id')->isoFormat('MMMM Y'),
            'namaPt'  => $this->pt
                ? (string) DB::table('bnamapt')->where('NPID', $this->pt)->value('NPNAMA')
                : 'Semua PT',
        ]);
    }
}
