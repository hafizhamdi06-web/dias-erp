<?php

namespace App\Livewire\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Tab filter laporan **"Daftar Stok Barang Serial"** - port VB6 **menu 499**
 * (`amenu.MNAMA` = "Daftar Stok Barang Serial", induk 271).
 *
 * **KOREKSI**: user menyebut menu **459**, tapi di tabel `amenu` 459 adalah **"Apotik"**
 * (induk 279). Yang dimaksud 499 - dicek langsung ke `amenu`, bukan ditebak.
 *
 * Jejak sumber: `zfFrmFilterLaporanStok.frm` query baris **1484**, filter baris **1243**
 * (`Case 318, 319, 499, 724` - **filternya SAMA PERSIS dgn "Daftar Stok Barang"/menu 318**,
 * termasuk tanggal TUNGGAL sbg cut-off `SUTANGGAL <=`).
 *
 * Hanya item ber-`ISERIAL=1`, dirinci sampai **nomor serial** (`bitemserial`) lewat riwayatnya
 * (`bitemserialhistori`), jumlahnya `SUM(ISHMASUK - ISHKELUAR)`.
 */
class DaftarStokSerial extends LaporanFilterBase
{
    public string $tanggal = '';
    public ?int $cabang = null;
    public string $jenisItem = '';
    public ?int $item = null;
    public ?string $itemLabel = null;
    public string $jenisProduk = '';
    public string $coa2021 = '';
    public string $pt = '';

    public bool $stokSaja = true;
    public bool $aktifSaja = true;
    /** Sembunyikan serial yg jumlahnya 0 (sudah terpakai habis). */
    public bool $nolSembunyi = true;

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
        return 'reports.daftar-stok-serial';
    }

    protected function urlParams(): array
    {
        return $this->rapikan([
            'tanggal'     => $this->tanggal,
            'cabang'      => $this->cabang,
            'jenisItem'   => $this->jenisItem,
            'item'        => $this->item,
            'jenisProduk' => $this->jenisProduk,
            'coa2021'     => $this->coa2021,
            'pt'          => $this->pt,
        ], [
            'stokSaja'  => $this->stokSaja,
            'aktifSaja' => $this->aktifSaja,
            'nol0'      => $this->nolSembunyi,
        ]);
    }

    public function render()
    {
        return view('livewire.reports.daftar-stok-serial', [
            'branches' => $this->branchOptions(),
            'coaTipes' => DB::table('bcoatipe_perpt')->whereNotNull('CTNAMA')->where('CTNAMA', '<>', '')
                ->orderBy('CTTIPEID')->get(['CTTIPEID', 'CTNAMA']),
            'ptList'   => DB::table('bnamapt')->orderBy('NPID')->get(['NPID', 'NPKODE', 'NPNAMA']),
        ]);
    }
}
