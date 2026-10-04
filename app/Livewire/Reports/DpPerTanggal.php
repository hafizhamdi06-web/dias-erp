<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"Jumlah DP Pertanggal"** - port VB6 **menu 627**
 * (`amenu.MNAMA` = "Jumlah DP Pertanggal", induk 249; berkas Crystal-nya
 * `zeRptIPSaldoDPPerTanggal.rpt` - jadi maksudnya **SALDO DP per tanggal**).
 *
 * Jejak sumber: `aMod_Menu.bas` baris 219 -> `zeFrmFilterLaporanDaftar`
 * (`Module/d/Form/zdFrmFilterLaporanDaftar2_lama.frm`); query `pSQLString` `Case 627`
 * baris **3274** (satu penugasan saja, sudah diperiksa); filter tanggalnya baris **2758-2761**.
 *
 * **Tanggalnya TUNGGAL & CUT-OFF**: `tanggal_transaksi <= <tgl>` - bukan rentang. Masuk akal,
 * yg dicari posisi saldo DP PADA tanggal itu. Kolomnya `tanggal_transaksi` dari view, BUKAN
 * `SUTANGGAL` (view-nya tidak punya kolom itu) - lihat `ReportController::dataDpPerTanggal()`.
 */
class DpPerTanggal extends LaporanFilterBase
{
    public string $tanggal = '';
    public ?int $cabang = null;

    /** Tampilkan buku besarnya (satu baris per mutasi DP), bukan ringkasan per pasien. */
    public bool $rinci = false;

    /**
     * Sembunyikan pasien bersaldo 0. **Default MATI** - di data ini SELURUH pasien saldonya 0
     * (DP langsung terpakai habis), jadi menyalakannya sebagai bawaan membuat laporan tampak
     * kosong & dikira rusak.
     */
    public bool $sembunyikanNol = false;

    public function mount(): void
    {
        $this->tanggal = now()->toDateString();
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
    }

    protected function rules(): array
    {
        return ['tanggal' => ['required', 'date']];
    }

    protected function routeName(): string
    {
        return 'reports.dp-per-tanggal';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'tanggal' => $this->tanggal,
            'cabang'  => $this->cabang,
        ], [
            'rinci' => $this->rinci,
            'nol0'  => $this->sembunyikanNol,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.dp-per-tanggal', ['branches' => $this->branchOptions()]);
    }
}
