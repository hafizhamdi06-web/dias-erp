<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan Persediaan **"Stok Per Hari"** - port VB6 **menu 417**
 * (`amenu.MNAMA` = "Stok Per Hari", induk 271).
 *
 * Jejak sumber: `aMod_Menu.bas` baris 261 (`Case 417, 769` -> `zfFrmFilterLaporanStok`),
 * query `zfFrmFilterLaporanStok.frm` baris **1388**, filter baris **1193** (`Case 417, 555, 769`).
 *
 * **Tanggalnya RENTANG** (`SUTANGGAL between`), beda dari "Daftar Stok Barang" (menu 318) yg
 * tanggalnya tunggal/cut-off - jangan disamakan walau formnya sama di VB6.
 *
 * **DUA lapis filter**, ini yg paling mudah salah: `pFlt` masuk ke SUBQUERY mutasi
 * (`fstokd`), `pFlt2` masuk ke query LUAR atas `bitem`. Cabang diterjemahkan BERBEDA di
 * keduanya - `SDGUDANG = n` di dalam, tapi `ICABANG LIKE '%|n|%'` di luar (kolom pipa-delimit
 * daftar cabang item). Lihat `ReportController::dataStokPerHari()`.
 */
class StokPerHari extends LaporanFilterBase
{
    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;
    public string $jenisItem = '';
    public ?int $item = null;
    public ?string $itemLabel = null;
    public string $jenisProduk = '';

    public bool $stokSaja = true;
    public bool $aktifSaja = true;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
    }

    protected function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ];
    }

    protected function routeName(): string
    {
        return 'reports.stok-per-hari';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'from'        => $this->from,
            'to'          => $this->to,
            'cabang'      => $this->cabang,
            'jenisItem'   => $this->jenisItem,
            'item'        => $this->item,
            'jenisProduk' => $this->jenisProduk,
        ], [
            'stokSaja'  => $this->stokSaja,
            'aktifSaja' => $this->aktifSaja,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.stok-per-hari', ['branches' => $this->branchOptions()]);
    }
}
