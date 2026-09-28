<?php

namespace App\Livewire\Inventory;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Kartu Stok - buku besar mutasi stok SATU item di SATU gudang (atau semua gudang) pada
 * rentang tanggal: Saldo Awal, tiap dokumen yg menggerakkan stok, lalu Saldo Akhir.
 * Filter sesuai permintaan user 2026-09-26: Tanggal s/d Tanggal, Cabang, **Nama Item (wajib)**.
 *
 * **TIDAK ADA acuan legacy** - tidak ada form VB6 `bFrm*KartuStok*`, tidak ada implementasi di
 * CI3/CI4, dan tabel `fstoksaldo`/`fstoksaldod` (yg dari namanya jelas dimaksudkan menyimpan
 * saldo awal/akhir per periode) **KOSONG, 0 baris**. Jadi struktur laporan ini dirancang dari
 * data `fstoku`/`fstokd`.
 *
 * READ-ONLY sepenuhnya - tidak ada tulis apa pun ke DB.
 *
 * ## Saldo Awal dihitung dari mutasi, BUKAN dari `bitem.ISTOK{kode}`
 *
 * `Saldo Awal = SUM(SDMASUK - SDKELUAR)` utk semua `fstokd` item+gudang ini dgn
 * `SUTANGGAL < dari`. Alternatifnya (mundur dari stok sekarang: `ISTOK{kode}` - mutasi sejak
 * `dari`) SENGAJA TIDAK dipakai, alasannya diverifikasi langsung ke DB:
 * - **`ISTOK{kode}` dulu bukan stok per gudang.** `F_KOLOMGUDANG()` memetakan **8 gudang** ke
 *   `ISTOKPG` (1 Petogogan, 6 Online, 14 Home Care, 15 Pameran, 39 Gudang Depo Tindakan,
 *   40 Gudang Kubis 1, 42 Depo Research, 49 Busura) dan **2 gudang** ke `ISTOKMP`
 *   (9 Gogobli, 18 Marketplace). Memakainya sbg jangkar akan membuat kartu stok 10 gudang itu
 *   salah diam-diam.
 * - `ISTOKDE` punya **bug double-count** yg sudah didokumentasikan (statemen trigger
 *   `fstokd_add` utk `SDGUDANG=20` muncul 2x).
 *
 * **DUA ALASAN DI ATAS DIPERBAIKI** oleh `database/production/2026-09-28_03_pisah_kolom_stok.sql`
 * (kolom sendiri per gudang + statemen kembar dibuang). **TAPI rumus di kelas ini TETAP dari
 * mutasi, bukan dari `ISTOK{kode}`** - alasan ketiga di bawah (jejak mutasi baru mulai
 * 2026-06-01) tidak ikut hilang, dan itu yang menentukan. Kode di bawah TIDAK perlu diubah
 * setelah file SQL itu dijalankan: kolom dicari lewat `F_KOLOMGUDANG()`, bukan ditulis keras.
 *
 * **KONSEKUENSI YG HARUS DITERIMA** (ditampilkan terang-terangan di layar, bukan disembunyikan):
 * data `fstoku` di DB ini **paling awal 2026-06-01** - bukan sejak awal usaha. Diuji: utk
 * gudang 2 (`ISTOKCP`, pemetaan 1:1) hanya **28 dari 225 item** yg `SUM(masuk-keluar)`-nya sama
 * dgn `ISTOKCP`; sisanya beda karena stok sebelum 2026-06-01 tidak ada jejak mutasinya.
 * Artinya Saldo Awal utk tanggal <= 2026-06-01 selalu 0 dan **saldo akhir kartu ini bisa BEDA
 * dari "Stok Sistem" di master item**. Keduanya ditampilkan berdampingan + selisihnya, supaya
 * user lihat sendiri, bukan ditebak-tebak. Kalau nanti stok awal 2026-06-01 di-import ke
 * `fstoksaldod`, laporan ini otomatis benar tanpa ubah rumus (cukup tambahkan saldo awal itu).
 *
 * ## Catatan teknis
 * - Baris `SDMASUK=0 AND SDKELUAR=0` dibuang: itu sisa dokumen yg DIBATALKAN (pola cancel
 *   semua modul = nolkan qty + `SDCANCEL=1`), jadi tidak perlu jadi baris kosong di kartu.
 * - Saldo berjalan dihitung di PHP mengikuti urutan `SUTANGGAL, SUID, SDURUTAN` (pola sama
 *   `SerialHistori::mutasi()`, yg juga menyalin cara VB6 menghitung saldo di grid).
 * - Label Jenis dari `ajenistransaksistok` (`JTKODE`->`JTNAMA`). **Tabel itu tidak lengkap**:
 *   `AL`, `PL`, `RC` tidak terdaftar padahal ada di data, jadi ditambal di `JENIS_TAMBAHAN`.
 */
#[Layout('layouts.app')]
#[Title('Kartu Stok')]
class KartuStok extends Component
{
    /** Batas baris - pengaman saja. Item tersibuk di DB ini cuma 140 baris (item 379 gudang 20). */
    private const MAKS_BARIS = 2000;

    /** Sumber dokumen yg TIDAK terdaftar di `ajenistransaksistok` (lihat docblock kelas). */
    private const JENIS_TAMBAHAN = [
        'AL' => 'Input Alkes Depo',
        'PL' => 'Pengeluaran Lain',
        'RC' => 'Retur Barang',
    ];

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';

    /** '' = semua gudang (kartu jadi total perusahaan, kolom Gudang ikut ditampilkan). */
    public string $cabang = '';

    public ?int $item = null;
    public ?string $itemLabel = null;

    public function mount(): void
    {
        $this->dari = now()->startOfMonth()->toDateString();
        $this->sampai = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = auth()->user();
        $this->cabang = (int) ($user->UCABANG ?? 0) ? (string) $user->UCABANG : '';
    }

    /**
     * `itemLabel` HARUS diisi ulang tiap item berubah: `<x-search-select>` menyimpan label di
     * state Alpine, tapi tiap render Livewire dibangun ulang dari `:selected-text` - kalau
     * dibiarkan null, kotak pencarian tampak KOSONG padahal item sudah terpilih (GOTCHA yg
     * sudah pernah dilaporkan user 2026-09-25).
     */
    public function updatedItem(): void
    {
        $this->syncItemLabel();
    }

    private function syncItemLabel(): void
    {
        $this->itemLabel = $this->item
            ? (string) DB::table('bitem')->where('IID', $this->item)
                ->selectRaw("CONCAT(IKODE, ' — ', INAMA) as t")->value('t')
            : null;
    }

    /** Gudang yg boleh dilihat user (pola sama PenyesuaianList/PbList). */
    private function allowedBranchIds(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->branchIds();
    }

    private function branchOptions()
    {
        $allowed = $this->allowedBranchIds();

        return $allowed !== []
            ? Branch::active()->whereIn('GID', $allowed)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    /**
     * Gudang yg ikut dihitung. "Semua Gudang" = semua gudang yg BOLEH DILIHAT user, bukan
     * seluruh gudang perusahaan (pola scoping sama semua daftar lain).
     *
     * Pilihan satu gudang pun DIIRISKAN dgn daftar yg boleh dilihat: dropdown memang cuma
     * menawarkan yg boleh, tapi state Livewire datang dari klien - kalau tidak diiriskan,
     * mengirim `cabang` gudang lain akan menembus batasan. Irisan kosong -> `[0]` (GID 0 tidak
     * ada) supaya hasilnya 0 baris; mengembalikan `[]` justru akan MEMBUKA semua gudang.
     */
    private function gudangIds(): array
    {
        $allowed = $this->allowedBranchIds();

        if ($this->cabang === '') {
            return $allowed; // [] = user tanpa batasan -> semua gudang
        }

        $pilih = (int) $this->cabang;

        if ($allowed !== [] && ! in_array($pilih, $allowed, true)) {
            return [0];
        }

        return [$pilih];
    }

    /** Peta SUSUMBER -> nama jenis transaksi (lihat docblock kelas soal tabel yg tidak lengkap). */
    private function jenisMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = DB::table('ajenistransaksistok')->pluck('JTNAMA', 'JTKODE')->all()
                + self::JENIS_TAMBAHAN;
        }

        return $map;
    }

    /** Saldo sebelum tanggal `dari` - lihat docblock kelas soal kenapa dari mutasi. */
    private function saldoAwal(): float
    {
        if (! $this->item) {
            return 0.0;
        }

        $gudang = $this->gudangIds();

        return (float) DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->where('d.SDITEM', $this->item)
            ->when($gudang !== [], fn ($b) => $b->whereIn('d.SDGUDANG', $gudang))
            ->whereDate('u.SUTANGGAL', '<', $this->dari)
            ->sum(DB::raw('d.SDMASUK - d.SDKELUAR'));
    }

    /** Mutasi dalam rentang tanggal + saldo berjalan (dihitung di PHP, lihat docblock). */
    private function mutasi(float $saldoAwal): array
    {
        if (! $this->item) {
            return [];
        }

        $gudang = $this->gudangIds();

        $rows = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'd.SDGUDANG')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDITEM', $this->item)
            ->when($gudang !== [], fn ($b) => $b->whereIn('d.SDGUDANG', $gudang))
            ->whereDate('u.SUTANGGAL', '>=', $this->dari)
            ->whereDate('u.SUTANGGAL', '<=', $this->sampai)
            // Buang sisa dokumen yg dibatalkan (qty sudah dinolkan) - lihat docblock kelas.
            ->where(fn ($b) => $b->where('d.SDMASUK', '>', 0)->orWhere('d.SDKELUAR', '>', 0))
            ->orderBy('u.SUTANGGAL')->orderBy('u.SUID')->orderBy('d.SDURUTAN')
            ->limit(self::MAKS_BARIS)
            ->get([
                'd.SDID as sdid',
                'u.SUTANGGAL as tanggal',
                'u.SUNOTRANSAKSI as nomor',
                'u.SUSUMBER as sumber',
                'u.SUURAIAN as uraian',
                'u.SUSTATUS as status',
                'd.SDMASUK as masuk',
                'd.SDKELUAR as keluar',
                'd.SDCATATAN as catatan',
                'g.GNAMA as gudang',
                'k.KNAMA as kontak',
                's.SKODE as satuan',
            ])->all();

        $jenis = $this->jenisMap();
        $saldo = $saldoAwal;
        foreach ($rows as $r) {
            $saldo += (float) $r->masuk - (float) $r->keluar;
            $r->saldo = $saldo;
            $r->jenisNama = $jenis[$r->sumber] ?? $r->sumber;
        }

        return $rows;
    }

    /**
     * "Stok Sistem" = `bitem.ISTOK{kode}` yg dipelihara trigger - ditampilkan sbg PEMBANDING,
     * bukan sumber angka kartu. Null kalau gudang yg dipilih memakai kolom bersama (kolomnya
     * mencakup gudang lain sehingga tidak bisa dibandingkan jujur) atau saat "Semua Gudang".
     *
     * @return array{nilai:?float,kolom:?string,gabungan:array<int,string>}
     */
    private function stokSistem(): array
    {
        $kosong = ['nilai' => null, 'kolom' => null, 'gabungan' => []];

        if (! $this->item || $this->cabang === '') {
            return $kosong;
        }

        $kol = (string) (DB::selectOne('SELECT F_KOLOMGUDANG(?) AS c', [(int) $this->cabang])->c ?? '');
        if (! preg_match('/^ISTOK[A-Z0-9]+$/', $kol)) {
            return $kosong;
        }

        // Gudang LAIN yg memakai kolom stok yg sama - kalau ada, angka itu bukan milik
        // gudang ini saja (lihat docblock kelas: ISTOKPG dulu dipakai 8 gudang). Dicari
        // lewat F_KOLOMGUDANG(), jadi setelah kolom dipisah hasilnya kosong dgn sendirinya
        // dan peringatan di layar hilang - tanpa ubah kode di sini.
        $gabungan = DB::table('bgudang')
            ->whereRaw('F_KOLOMGUDANG(GID) = ?', [$kol])
            ->where('GID', '!=', (int) $this->cabang)
            ->orderBy('GID')->pluck('GNAMA', 'GID')->all();

        return [
            'nilai'    => (float) DB::table('bitem')->where('IID', $this->item)->value($kol),
            'kolom'    => $kol,
            'gabungan' => $gabungan,
        ];
    }

    public function render()
    {
        abort_unless(can_do('inventory/stock', 'view'), 403);

        if ($this->item && ! $this->itemLabel) {
            $this->syncItemLabel(); // jaring pengaman kalau state dipulihkan tanpa label
        }

        $saldoAwal = $this->saldoAwal();
        $rows = $this->mutasi($saldoAwal);

        $totalMasuk = array_sum(array_map(fn ($r) => (float) $r->masuk, $rows));
        $totalKeluar = array_sum(array_map(fn ($r) => (float) $r->keluar, $rows));

        return view('livewire.inventory.kartu-stok', [
            'branches'    => $this->branchOptions(),
            'rows'        => $rows,
            'saldoAwal'   => $saldoAwal,
            'totalMasuk'  => $totalMasuk,
            'totalKeluar' => $totalKeluar,
            'saldoAkhir'  => $saldoAwal + $totalMasuk - $totalKeluar,
            'semuaGudang' => $this->cabang === '',
            'kepenuhan'   => count($rows) >= self::MAKS_BARIS,
            'maksBaris'   => self::MAKS_BARIS,
            'stokSistem'  => $this->stokSistem(),
            'satuan'      => $rows !== [] ? ($rows[0]->satuan ?? '') : '',
        ]);
    }
}
