<?php

namespace App\Livewire\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Tab filter laporan **"Daftar Surat Jalan Barang"** - port VB6 **menu 451**
 * (`amenu.MNAMA` = "Daftar Surat Jalan Barang", induk 271).
 *
 * Jejak sumber: `aMod_Menu.bas` baris 323 (`Case 451, 742, 839` -> `zfFrmFilterLaporanDaftar`,
 * berkasnya `Module/f/Form/zdFrmFilterLaporanDaftar2.frm`), query baris **802**, filter **746**.
 *
 * **AWAS**: `zfFrmFilterLaporanStok.frm` JUGA punya `Case 451` (baris 1385) dengan query yg
 * LEBIH SEDIKIT kolomnya - itu BUKAN yg dipakai, karena `aMod_Menu` mengarahkan menu 451 ke
 * form `...LaporanDaftar`. Yg dipakai versi baris 802 (ada gudang asal/tujuan, PT, COGS).
 *
 * Satu baris per BARIS ITEM Surat Jalan (`fstokd` + `fstoku` `SUSUMBER='SJ'`).
 */
class DaftarSuratJalan extends LaporanFilterBase
{
    public string $from = '';
    public string $to = '';
    public string $noDari = '';
    public string $noSampai = '';
    public ?int $cabang = null;
    public string $gudangTujuan = '';
    public string $coa2021 = '';
    public string $pt = '';

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
        return 'reports.daftar-surat-jalan';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'from'         => $this->from,
            'to'           => $this->to,
            'noDari'       => trim($this->noDari),
            'noSampai'     => trim($this->noSampai),
            'cabang'       => $this->cabang,
            'gudangTujuan' => $this->gudangTujuan,
            'coa2021'      => $this->coa2021,
            'pt'           => $this->pt,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.daftar-surat-jalan', [
            'branches' => $this->branchOptions(),
            // Gudang TUJUAN tidak dibatasi hak akses cabang user - tujuan bisa cabang mana pun
            // (pola sama `PrList::$fGudangTujuan`).
            'semuaCabang' => \App\Models\Branch::options(),
            'coaTipes' => DB::table('bcoatipe_perpt')->whereNotNull('CTNAMA')->where('CTNAMA', '<>', '')
                ->orderBy('CTTIPEID')->get(['CTTIPEID', 'CTNAMA']),
            'ptList'   => DB::table('bnamapt')->orderBy('NPID')->get(['NPID', 'NPKODE', 'NPNAMA']),
        ]);
    }
}
