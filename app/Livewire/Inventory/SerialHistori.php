<?php

namespace App\Livewire\Inventory;

use App\Models\Branch;
use App\Services\SerialBatch;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Data Histori Serial - penelusuran batch/no serial per item per gudang. VB6 asli:
 * `bFrmItemData_Serial.frm` ("Data Serial Item"), 2 grid bertingkat:
 * 1. `IsiData(Item)` baris 414 - daftar BATCH milik item di gudang aktif + sisa stoknya.
 * 2. `IsiDataHistori(IDSerial, Item)` baris 432 - MUTASI batch terpilih (dokumen yg
 *    memasukkan/mengeluarkan), plus kolom **Saldo berjalan** yg di VB6 dihitung di grid
 *    (baris 440-447), bukan di SQL - kita hitung di PHP, sama persis.
 *
 * **Stok batch PER GUDANG** (pola sama `SerialBatch::available()`): dihitung dari
 * `bitemserialhistori` x `fstokd.SDGUDANG`, BUKAN dari `bitemserial.ISJUMLAH` yg global
 * lintas gudang. Join `bitemserial` pakai `isid=ishidserial AND isitem=sditem` - pengaman
 * bawaan VB6 thd baris histori yg salah tunjuk item.
 *
 * **BEDA SENGAJA dari VB6 (3, semua perbaikan/penyesuaian aturan app ini)**:
 * 1. VB6 query grid batch pakai **INNER JOIN bkontak** (`kid=sukontak`) - artinya batch dari
 *    dokumen TANPA kontak HILANG dari daftar, padahal kontak tidak ada hubungannya dgn stok
 *    batch. Ironisnya query grid mutasi (baris 432) di VB6 sendiri sudah pakai LEFT JOIN.
 *    Di sini KEDUANYA LEFT JOIN. (Saat ini 0 dari 1.151 baris nyata terdampak - jadi ini
 *    murni pertahanan, bukan perubahan angka.)
 * 2. VB6 memakai `xCabang` (gudang aktif global) tanpa bisa dipilih. Di sini ADA pilihan
 *    Gudang, default cabang aktif user - mengikuti aturan app ini ("jika ada pilihan cabang,
 *    diisi sesuai cabang user").
 * 3. Pilih item pakai search-select `lookup.item-serial` (HANYA item ber-`ISERIAL=1`),
 *    bukan 5 kotak filter (Kode/Nama/Kelompok/Kategori/Sub Kategori) spt VB6 - item yg tidak
 *    ber-serial mustahil punya batch, jadi tidak ada gunanya bisa dipilih.
 *
 * **DEFER**: tombol "Generate Barcode" di VB6 - fitur cetak barcode, tidak terkait
 * penelusuran batch.
 *
 * READ-ONLY sepenuhnya - tidak ada tulis apa pun ke DB.
 */
#[Layout('layouts.app')]
#[Title('Data Histori Serial')]
class SerialHistori extends Component
{
    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public ?int $cabang = null;
    public ?int $item = null;
    public ?string $itemLabel = null;

    /** `bitemserial.ISID` batch yg sedang dilihat mutasinya. */
    public ?int $serialId = null;
    public ?string $serialLabel = null;

    /** Sembunyikan batch yg sisanya 0 (VB6 menampilkan semua). */
    public bool $hanyaAdaStok = false;

    public function mount(): void
    {
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
    }

    public function updatedCabang(): void
    {
        $this->serialId = null;
        $this->serialLabel = null;
    }

    /**
     * `itemLabel` HARUS diisi ulang tiap item berubah: `<x-search-select>` menyimpan label
     * di state Alpine, tapi tiap render Livewire komponen itu dibangun ulang dari
     * `:selected-text` - kalau dibiarkan null, kotak pencarian tampak KOSONG padahal item
     * sudah terpilih (dilaporkan user 2026-09-25).
     */
    public function updatedItem(): void
    {
        $this->serialId = null;
        $this->serialLabel = null;
        $this->syncItemLabel();
    }

    private function syncItemLabel(): void
    {
        $this->itemLabel = $this->item
            ? (string) DB::table('bitem')->where('IID', $this->item)
                ->selectRaw("CONCAT(IKODE, ' — ', INAMA) as t")->value('t')
            : null;
    }

    public function lihatMutasi(int $isid, string $noBatch): void
    {
        $this->serialId = $isid;
        $this->serialLabel = $noBatch;
    }

    public function tutupMutasi(): void
    {
        $this->serialId = null;
        $this->serialLabel = null;
    }

    /** Daftar batch item ini di gudang terpilih + sisa stoknya (VB6 `IsiData`). */
    private function batches(): array
    {
        if (! $this->item || ! $this->cabang) {
            return [];
        }

        $rows = DB::table('bitemserialhistori as h')
            ->join('fstokd as d', 'd.SDID', '=', 'h.ISHIDFSTOKD')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitemserial as s', fn ($j) => $j
                ->on('s.ISID', '=', 'h.ISHIDSERIAL')
                ->on('s.ISITEM', '=', 'd.SDITEM'))
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK') // VB6: INNER, lihat docblock
            ->where('d.SDITEM', $this->item)
            ->where('d.SDGUDANG', $this->cabang)
            ->groupBy('s.ISID', 's.ISNOSERIAL', 's.ISTGLEXPIRED', 's.ISAKTIF')
            ->orderByRaw('s.ISTGLEXPIRED IS NULL, s.ISTGLEXPIRED ASC, s.ISNOSERIAL ASC')
            ->get([
                DB::raw('s.ISID as isid'),
                DB::raw('s.ISNOSERIAL as noBatch'),
                DB::raw('s.ISTGLEXPIRED as expired'),
                DB::raw('s.ISAKTIF as aktif'),
                DB::raw('SUM(h.ISHMASUK - h.ISHKELUAR) as tersedia'),
                DB::raw('SUM(h.ISHMASUK) as totalMasuk'),
                DB::raw('SUM(h.ISHKELUAR) as totalKeluar'),
            ])->all();

        if ($this->hanyaAdaStok) {
            $rows = array_values(array_filter($rows, fn ($r) => (float) $r->tersedia > 0));
        }

        return $rows;
    }

    /**
     * Mutasi satu batch di gudang terpilih + SALDO BERJALAN (VB6 menghitungnya di grid,
     * bukan SQL - kita samakan: akumulasi masuk-keluar mengikuti urutan `SDID`).
     */
    private function mutasi(): array
    {
        if (! $this->serialId || ! $this->item || ! $this->cabang) {
            return [];
        }

        $rows = DB::table('bitemserialhistori as h')
            ->join('fstokd as d', 'd.SDID', '=', 'h.ISHIDFSTOKD')
            ->join('bitemserial as s', fn ($j) => $j
                ->on('s.ISID', '=', 'h.ISHIDSERIAL')
                ->on('s.ISITEM', '=', 'd.SDITEM'))
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('s.ISITEM', $this->item)
            ->where('h.ISHIDSERIAL', $this->serialId)
            ->where('d.SDGUDANG', $this->cabang)
            ->orderBy('d.SDID')
            ->get([
                'h.ISHID as ishid',
                's.ISNOSERIAL as noBatch',
                'u.SUNOTRANSAKSI as nomor',
                'u.SUTANGGAL as tanggal',
                'u.SUSUMBER as sumber',
                'u.SUID as suid',
                'u.SUSTATUS as status',
                'k.KNAMA as kontak',
                'h.ISHMASUK as masuk',
                'h.ISHKELUAR as keluar',
            ])->all();

        $saldo = 0.0;
        foreach ($rows as $r) {
            $saldo += (float) $r->masuk - (float) $r->keluar;
            $r->saldo = $saldo;
        }

        return $rows;
    }

    public function render()
    {
        abort_unless(can_do('inventory/serial', 'view'), 403);

        if ($this->item && ! $this->itemLabel) {
            $this->syncItemLabel(); // jaring pengaman kalau state dipulihkan tanpa label
        }

        $batches = $this->batches();

        return view('livewire.inventory.serial-histori', [
            'branches'   => Branch::options(),
            'gudangLabel' => $this->cabang
                ? (string) DB::table('bgudang')->where('GID', $this->cabang)->value('GNAMA') : null,
            'batches'    => $batches,
            'mutasi'     => $this->mutasi(),
            'totalSisa'  => array_sum(array_map(fn ($r) => (float) $r->tersedia, $batches)),
            'pakaiBatch' => app(SerialBatch::class)->gudangPakaiSerial($this->cabang),
        ]);
    }
}
