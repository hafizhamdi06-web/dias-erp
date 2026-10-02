<?php

namespace App\Livewire\Reports;

use App\Models\Branch;
use Livewire\Component;

/**
 * Basis tipis utk tab FILTER laporan: dua tombol yg men-dispatch event peramban
 * (`report-pdf-ready` / `report-excel-download`) ke route BIASA `ReportController` - pola
 * wajib per CLAUDE.md (render PDF besar tidak cocok di siklus hidup Livewire).
 *
 * Dibuat 2026-10-03 saat menambah TIGA laporan sekaligus (Stok Per Hari, Daftar Surat Jalan,
 * Daftar Stok Barang Serial) supaya potongan yg sama tidak disalin tiga kali.
 * `DaftarPenjualanTunai`/`DaftarStokBarang`/`IpTindakanProduk` yg lebih dulu ada SENGAJA belum
 * dipindah ke sini - menyentuhnya di luar cakupan, dan ketiganya sudah teruji apa adanya.
 */
abstract class LaporanFilterBase extends Component
{
    public ?string $tabKey = null;

    /** Nama route PDF-nya; versi Excel diasumsikan `<route>.excel` (konvensi semua laporan). */
    abstract protected function routeName(): string;

    /** @return array<string,mixed> parameter query utk URL laporan */
    abstract protected function urlParams(): array;

    public function tampilkanPdf(): void
    {
        $this->validate();
        $this->dispatch('report-pdf-ready', url: route($this->routeName(), $this->urlParams()));
    }

    public function unduhExcel(): void
    {
        $this->validate();
        $this->dispatch('report-excel-download', url: route($this->routeName() . '.excel', $this->urlParams()));
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    /**
     * Pilihan cabang dibatasi ke cabang yg boleh dilihat user (aturan app 2026-09-28).
     * Ini HANYA kenyamanan UI - penegaknya tetap `ReportController::cabangLaporan()`, karena
     * parameter `cabang` datang lewat URL dan bisa diketik manual.
     */
    protected function branchOptions()
    {
        $boleh = auth()->user()->visibleBranchIds();

        return $boleh !== []
            ? Branch::active()->whereIn('GID', $boleh)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    /**
     * Buang isian kosong TAPI pertahankan ceklis bernilai `0` - `array_filter()` biasa ikut
     * membuang `0`, sehingga ceklis yg DIMATIKAN user kembali menyala di controller
     * (jebakan yg sudah kena di `DaftarStokBarang`).
     *
     * @param  array<string,mixed>  $isian   isian biasa (dibuang kalau null/'')
     * @param  array<string,bool>   $ceklis  ceklis (SELALU dikirim sbg 1/0)
     * @return array<string,mixed>
     */
    protected function rapikan(array $isian, array $ceklis = []): array
    {
        $hasil = array_filter($isian, fn ($v) => $v !== null && $v !== '');

        foreach ($ceklis as $k => $v) {
            $hasil[$k] = $v ? 1 : 0;
        }

        return $hasil;
    }
}
