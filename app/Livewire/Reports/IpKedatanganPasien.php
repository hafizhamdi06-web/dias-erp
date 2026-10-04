<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IP Kedatangan Pasien"** - port VB6 **menu 531**
 * (`amenu.MNAMA` = "IP Kedatangan Pasien", induk 249 "Report").
 *
 * Jejak sumber: `aMod_Menu.bas` baris 191 mengarahkan 531 ke form `zeFrmFilterLaporanDaftar`,
 * yang berkasnya **`Module/d/Form/zdFrmFilterLaporanDaftar2_lama.frm`** (satu-satunya yg
 * mendeklarasikan nama form itu persis - tiga berkas lain mirip namanya & semuanya ikut
 * dikompilasi di `DIAS.vbp`, jadi jangan asal pilih). Query di fungsi `pSQLString`,
 * `Case 531` baris **3142**; filter di blok bersama baris 2505/2571-2618.
 *
 * `areport` ARID 315 (`zeRptIPPerPelangganKedatangan.rpt`) **ARSQL-nya KOSONG** dan
 * `ARPROCEDURE=0` - jadi query memang dibangun di form, bukan di DB.
 *
 * ## JEBAKAN: `pSQL` ditimpa EMPAT KALI
 * Di `Case 531` variabel `pSQL` diisi di baris 3144, 3149, 3154, lalu **3166**. Di VB6 yg
 * berlaku **yang TERAKHIR** - tiga yg pertama kode mati. Yang dipakai = versi 3166: agregat
 * **per pasien** dari subquery per-baris, bukan daftar per-transaksi seperti tiga versi awal.
 */
class IpKedatanganPasien extends LaporanFilterBase
{
    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;

    /** `SUDKKWALKIN`: '' semua, 0 Hanya Beli, 1 DKK, 2 Walk In. */
    public string $jenisKunjungan = '';

    public bool $tindakanSaja = false;
    public bool $adaDokterSaja = false;
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
        return 'reports.ip-kedatangan-pasien';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'from'           => $this->from,
            'to'             => $this->to,
            'cabang'         => $this->cabang,
            'jenisKunjungan' => $this->jenisKunjungan,
        ], [
            'tindakanSaja'  => $this->tindakanSaja,
            'adaDokterSaja' => $this->adaDokterSaja,
            'tanpaNh'       => $this->tanpaNh,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.ip-kedatangan-pasien', ['branches' => $this->branchOptions()]);
    }
}
