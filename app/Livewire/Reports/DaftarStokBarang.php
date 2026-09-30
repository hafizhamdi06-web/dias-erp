<?php

namespace App\Livewire\Reports;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab filter laporan Persediaan "Daftar Stok Barang" (judul cetakan: **"Laporan Real Stok
 * Barang"**) - port dari VB6 `DIAS.vbp` **menu 318**.
 *
 * Jejak sumbernya (biar tidak perlu ditelusuri ulang):
 * - `Module\a\Module\aMod_Menu.bas` baris 257: `Case 318, 319, 320, 499, 724, 850` ->
 *   `zfFrmFilterLaporanStok`, dan KHUSUS baris itu `Label7`/`txtTanggal2`/`Cal(1)`
 *   disembunyikan -> **tanggalnya TUNGGAL (cut-off "s/d"), bukan rentang**.
 * - `Module\f\Form\zfFrmFilterLaporanStok.frm` baris 1392 (SQL) & 1243 (filter `pFlt`).
 *
 * ## Filter yg BENAR-BENAR dipakai menu 318 (sisanya di form VB6 diabaikan)
 * Form VB6-nya dipakai bersama belasan menu lain, jadi banyak isian yg TAMPIL tapi tidak
 * pernah dibaca oleh cabang `Case 318`. Yg DIBACA cuma: Tanggal, Cabang, Jenis Item, Item,
 * Jenis Produk, COA 2021, PT, `chkStokSaja`, `chkAktif`, `chkStokNolTidakTampil`.
 * **SENGAJA TIDAK dibuat** krn menu 318 tidak memakainya: Nomor Transaksi, Kontak, Id Item,
 * Jenis Transaksi, Kelompok 2020, Jumlah Data, COA 2026 (hanya menu 850),
 * **"Hitung Saldo Awal"** & **"Posting Saja"** - dua terakhir ini TERCEKLIS di tangkapan
 * layar user, tapi cabang `Case 318` (baris 798-800) tidak pernah membacanya; "Hitung Saldo
 * Awal" cuma dipakai menu 321/322/450/417/448, "Posting Saja" cuma di baris 1191 (menu lain).
 * Membuatnya di sini = memberi user tombol yg tidak berefek.
 *
 * ## BEDA DISENGAJA dari VB6 (2)
 * 1. **COA 2021**: VB6 mengirim `ICOA2021 = ListIndex - 1`, yaitu POSISI BARIS di combo,
 *    bukan `CTTIPEID`. Itu benar HANYA kalau `CTTIPEID` kontigu - dan **tidak**: 26 baris
 *    dgn ID 0..99. Jadi entri terakhir di VB6 mengirim angka yg salah. Di sini dipakai
 *    `CTTIPEID` ASLI sbg value -> identik utk semua entri kecuali yg rusak itu.
 * 2. **Item**: VB6 mencocokkan `UPPER(IKODE) = '<ketikan>'` (sama persis, bukan LIKE).
 *    Di sini pakai `<x-search-select>` -> memfilter `bitem.IID`, lebih presisi & seragam
 *    dgn laporan lain.
 *
 * Cabang default = cabang aktif user (aturan app 2026-09-28, lihat `DaftarPenjualanTunai`).
 * Tiga ceklis default MENYALA, mengikuti tangkapan layar filter yg dikirim user.
 */
class DaftarStokBarang extends Component
{
    public ?string $tabKey = null;

    public string $tanggal = '';
    public ?int $cabang = null;
    public string $jenisItem = '';
    public ?int $item = null;
    public ?string $itemLabel = null;
    public string $jenisProduk = '';
    public string $coa2021 = '';
    public string $pt = '';

    public bool $stokNolSembunyi = true;
    public bool $stokSaja = true;
    public bool $aktifSaja = true;

    /**
     * Daftar `IJENISITEM` - HARDCODE di VB6 (`zfFrmFilterLaporanStok.frm` baris 1090), bukan
     * dari tabel, dan filternya `IJENISITEM = ListIndex - 1`. Jadi kunci array di bawah =
     * nilai kolomnya. Ejaan "Minor Sugery" SENGAJA dipertahankan apa adanya dari VB6 supaya
     * user mengenali pilihan yg sama.
     */
    public const JENIS_ITEM = [
        0 => 'Produk', 1 => 'Tindakan', 2 => 'Ongkos Kirim', 3 => 'Apotek', 4 => 'Royal House',
        5 => 'Paket', 6 => 'Obgyn', 7 => 'Lain-lain', 8 => 'Bahan Baku', 9 => 'Operasional',
        10 => 'Estetika', 11 => 'Paket Estetika', 12 => 'Laser', 13 => 'Minor Sugery', 14 => 'DP',
    ];

    /** `IJENISPRODUK` - hardcode VB6, filternya `= ListIndex` (TANPA -1, beda dari Jenis Item). */
    public const JENIS_PRODUK = [1 => 'Apotik', 2 => 'Depo', 3 => 'Tindakan', 4 => 'Bahan Baku'];

    public function mount(): void
    {
        $this->tanggal = now()->toDateString();
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
    }

    protected function rules(): array
    {
        return ['tanggal' => ['required', 'date']];
    }

    public function tampilkanPdf(): void
    {
        $this->validate();
        $this->dispatch('report-pdf-ready', url: route('reports.daftar-stok-barang', $this->urlParams()));
    }

    public function unduhExcel(): void
    {
        $this->validate();
        $this->dispatch('report-excel-download', url: route('reports.daftar-stok-barang.excel', $this->urlParams()));
    }

    /**
     * Ceklis dikirim sbg '1'/'0' EKSPLISIT, TIDAK boleh lewat `array_filter()` - nilai `0`
     * akan ikut terbuang dan ceklis yg DIMATIKAN user malah kembali menyala di controller.
     */
    private function urlParams(): array
    {
        return array_filter([
            'tanggal'     => $this->tanggal,
            'cabang'      => $this->cabang,
            'jenisItem'   => $this->jenisItem,
            'item'        => $this->item,
            'jenisProduk' => $this->jenisProduk,
            'coa2021'     => $this->coa2021,
            'pt'          => $this->pt,
        ], fn ($v) => $v !== null && $v !== '') + [
            'stok0'     => $this->stokNolSembunyi ? 1 : 0,
            'stokSaja'  => $this->stokSaja ? 1 : 0,
            'aktifSaja' => $this->aktifSaja ? 1 : 0,
        ];
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    /** Idem `DaftarPenjualanTunai` - UI saja, penegaknya `ReportController::cabangLaporan()`. */
    protected function branchOptions()
    {
        $boleh = auth()->user()->visibleBranchIds();

        return $boleh !== []
            ? Branch::active()->whereIn('GID', $boleh)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    public function render()
    {
        return view('livewire.reports.daftar-stok-barang', [
            'branches'  => $this->branchOptions(),
            // CTNAMA bisa NULL (CTTIPEID=0) - disaring supaya tidak jadi pilihan kosong.
            'coaTipes'  => DB::table('bcoatipe_perpt')->whereNotNull('CTNAMA')
                ->where('CTNAMA', '<>', '')->orderBy('CTTIPEID')->get(['CTTIPEID', 'CTNAMA']),
            'ptList'    => DB::table('bnamapt')->orderBy('NPID')->get(['NPID', 'NPKODE', 'NPNAMA']),
        ]);
    }
}
