<?php

namespace App\Livewire\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Basis SATU keluarga laporan Invoice Penjualan (IV / IVM) - port VB6 form
 * **`zeFrmFilterLaporanDaftar_2022`** (`Module/d/Form/zdFrmFilterLaporanDaftar_2022.frm`),
 * menu **780, 788, 796, 819, 821, 822** (grup menu baru "IV Penjualan", permintaan user
 * 2026-10-04).
 *
 * Keenam menu memakai **blok filter yang SAMA** (`Case 760, 780, 788, 819, 796, 821, 822`
 * baris **2157-2168**): rentang `IPUTANGGAL`, `IPUGUDANG`, dan `ikelompok2020`. Karena itu
 * filternya ditaruh di basis ini, bukan disalin enam kali - subclass-nya cuma menyebut
 * route & judulnya.
 *
 * Tiap `Case` di `pSQLString` punya **tepat SATU** penugasan `pSQL` - sudah diperiksa satu per
 * satu, jadi jebakan "timpa berkali-kali" (lihat menu 531) tidak berlaku di keluarga ini.
 */
abstract class IvLaporanBase extends LaporanFilterBase
{
    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;
    public string $kelompok = '';

    /** Judul yang tampil di kepala tab filter. */
    abstract public function judul(): string;

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

    protected function urlParams(): array
    {
        return $this->rapikan([
            'from'     => $this->from,
            'to'       => $this->to,
            'cabang'   => $this->cabang,
            'kelompok' => $this->kelompok,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.iv-laporan', [
            'judul'    => $this->judul(),
            'branches' => $this->branchOptions(),
            // `ikelompok2020` -> `bitemkelompok2020.IK2ID` (VB6 memakai ListIndex-1 di combo
            // yang diisi dari tabel itu; di sini dipakai ID aslinya - lihat catatan COA 2021
            // di `DaftarStokBarang`, masalahnya sama).
            'kelompokList' => DB::table('bitemkelompok2020')->orderBy('IK2ID')->get(['IK2ID', 'IK2KODE']),
        ]);
    }
}
