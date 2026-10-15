<?php

namespace App\Livewire\Concerns;

use App\Models\Branch;

/**
 * Saringan bersama tiga layar Dashboard Penjualan Markom:
 *  - CABANG GANDA (`$cabangPilih`, kosong = semua cabang yg boleh dilihat);
 *  - tombol "Tampilkan" - pola yg sama dgn Dashboard Penjualan: data baru ditarik setelah
 *    tombol ditekan, dan begitu saringan diubah angka lama DIKOSONGKAN supaya tidak salah
 *    dibaca sbg angka saringan baru (lihat `Dashboard\Penjualan::saringanDiubah()`).
 *
 * Pemakai wajib mendefinisikan `propSaringan()` - nama properti tanggal miliknya.
 */
trait MarkomFilter
{
    /** @var list<string> GID cabang yg dicentang. `[]` = semua cabang yg boleh dilihat. */
    public array $cabangPilih = [];

    public bool $tampil = false;
    public bool $saringanBerubah = false;

    /** @return list<string> */
    abstract protected function propSaringan(): array;

    /** Dipanggil sesudah saringan berubah - komponen boleh menimpa utk menutup rinciannya. */
    protected function setelahSaringanBerubah(): void
    {
    }

    public function updated(string $name): void
    {
        if ($name === 'cabangPilih' || str_starts_with($name, 'cabangPilih.')
            || in_array($name, $this->propSaringan(), true)) {
            $this->saringanBerubah = $this->tampil;
            $this->tampil = false;
            $this->setelahSaringanBerubah();
        }
    }

    public function tampilkan(): void
    {
        $this->tampil = true;
        $this->saringanBerubah = false;
    }

    public function pilihSemuaCabang(): void
    {
        $this->cabangPilih = [];
        $this->updated('cabangPilih');
    }

    /**
     * Cabang yg boleh dilihat user - pola sama `LaporanFilterBase::branchOptions()`.
     * Super user `visibleBranchIds() = []` -> semua cabang aktif.
     */
    protected function branchOptions()
    {
        $boleh = auth()->user()->visibleBranchIds();

        return $boleh !== []
            ? Branch::active()->whereIn('GID', $boleh)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    /**
     * Cabang yg BENAR-BENAR dipakai kueri. Pilihan dicek ulang terhadap hak akses -
     * `cabangPilih` bisa dikirim manual lewat request Livewire, tidak harus dari kotak
     * centang yg tampil. Hasil `[]` = tanpa batas (hanya mungkin utk super user).
     *
     * @return list<int>
     */
    protected function cabangDipakai(): array
    {
        $boleh = auth()->user()->visibleBranchIds();
        $pilih = array_values(array_unique(array_filter(array_map('intval', $this->cabangPilih))));

        if ($boleh === []) {
            return $pilih;
        }
        $pilih = array_values(array_intersect($pilih, $boleh));

        return $pilih !== [] ? $pilih : $boleh;
    }

    /**
     * Jalankan penarikan data dgn PENAHAN GALAT - alasan sama dgn `Dashboard\Penjualan::
     * render()`: galat yg lolos membuat request Livewire gagal dan di layar terlihat sbg
     * bilah kemajuan yg berjalan terus. Galat dicatat ke log lalu dikembalikan sbg pesan.
     *
     * @return array{0:mixed,1:?string} [hasil, pesan galat]
     */
    protected function tarik(string $layar, callable $fn): array
    {
        try {
            return [$fn(), null];
        } catch (\Throwable $e) {
            report($e);
            \Illuminate\Support\Facades\Log::error("{$layar} gagal", [
                'cabang' => $this->cabangPilih,
                'galat'  => $e->getMessage(),
                'di'     => $e->getFile() . ':' . $e->getLine(),
            ]);

            return [null, $e->getMessage()];
        }
    }

    /** Teks ringkas pilihan cabang utk tombol & sub judul. */
    protected function labelCabang($branches): string
    {
        $pilih = array_map('intval', $this->cabangPilih);
        if ($pilih === []) {
            return 'Semua cabang';
        }
        $nama = $branches->whereIn('GID', $pilih)->pluck('GNAMA');

        return $nama->count() <= 2 ? $nama->implode(', ') : $nama->count() . ' cabang';
    }
}
