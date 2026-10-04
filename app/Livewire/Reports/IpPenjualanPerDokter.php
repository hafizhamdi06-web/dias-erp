<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IP Penjualan Per Dokter"** - port VB6 **menu 583**
 * (`amenu.MNAMA` = "IP Penjualan Per Dokter *****", induk 249 "Report").
 *
 * Jejak sumber: `aMod_Menu.bas` baris 197 -> form `zeFrmFilterLaporanDaftar`, berkasnya
 * **`Module/d/Form/zdFrmFilterLaporanDaftar2_lama.frm`** (sama spt menu 531 - lihat catatan
 * di `IpKedatanganPasien` soal empat berkas bernama mirip). Query `pSQLString` `Case 583`
 * baris **3215**. `areport` ARID 345 `ARSQL`-nya KOSONG & `ARPROCEDURE=0`.
 *
 * Beda dari menu 531: di sini `pSQL` **hanya ditugaskan SEKALI** (enam varian lain di baris
 * 3203-3210 semuanya DIKOMENTARI) - sudah diperiksa, tidak ada jebakan "timpa berkali-kali".
 *
 * Query VB6-nya tingkat BARIS tanpa `GROUP BY` - Crystal (`zeRptIPPenjualanPerDokter.rpt`) yg
 * mengelompokkan per dokter. Di sini pengelompokannya dilakukan di SQL; lihat
 * `ReportController::dataIpPenjualanPerDokter()`.
 */
class IpPenjualanPerDokter extends LaporanFilterBase
{
    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;

    /** Pecah tiap dokter per Kelompok 2020 (`IK2KODE`), bukan satu baris per dokter. */
    public bool $rinciKelompok = false;

    public bool $tindakanSaja = false;
    public bool $tanpaNh = false;

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
        return 'reports.ip-penjualan-per-dokter';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'from'   => $this->from,
            'to'     => $this->to,
            'cabang' => $this->cabang,
        ], [
            'rinciKelompok' => $this->rinciKelompok,
            'tindakanSaja'  => $this->tindakanSaja,
            'tanpaNh'       => $this->tanpaNh,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.ip-penjualan-per-dokter', ['branches' => $this->branchOptions()]);
    }
}
