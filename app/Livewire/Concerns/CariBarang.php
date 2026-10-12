<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Dialog "Cari Barang" - port VB6 `bFrmCariItem`.
 *
 * Caption "Cari Barang" disetel saat berjalan oleh `bMod_Cari.bas::CariBarang()`
 * (`.Caption = xUmum.Nz(JudulForm, "Cari Barang")`) - itu sebabnya tidak ada `.frm`
 * yg caption-nya "Cari Barang"; di berkasnya tertulis "Cari Produk / Tindakan".
 * Di form Permintaan Barang dialog ini dibuka dari tombol di sel Kode grid
 * (`fFrmPermintaanBarang2::IG_CellButtonClick` Case 0 -> `CariBarang`).
 *
 * Menyaring **langsung saat diketik** - VB6 `txtData_Change()` memanggil `IsiData`,
 * tidak ada tombol "Tampilkan" di dialog ini (beda dgn `CariTransaksi`).
 */
trait CariBarang
{
    public bool $cbShow = false;
    public string $cbData = '';
    public array $cbHasil = [];
    public ?int $cbPilih = null;
    public int $cbTotal = 0;
    /** Berapa item ditambahkan sejak dialog dibuka - lihat alasannya di `ambilBarang()`. */
    public int $cbDitambah = 0;

    /**
     * Batas baris yg dikirim ke layar - **20**, sama dgn `bFrmCariItem::IsiData`
     * (`... order by IKODE limit 20`), permintaan user 2026-10-10.
     *
     * Daftar penuh memang tidak pernah dikirim sekaligus: di cabang 1 saja ada 1.696 item
     * yg lolos saringan. `$cbTotal` tetap menghitung SELURUH yg cocok, jadi dialognya bisa
     * memberi tahu "ditampilkan 20 dari sekian" - tanpa itu user mengira barangnya tidak ada
     * padahal cuma terpotong batas.
     */
    public int $cbBatas = 20;

    /** Hak yg diperiksa sebelum dialog boleh dipakai. */
    abstract protected function hakCariBarang(): bool;

    /** Cabang yg stoknya ditampilkan di kolom "Stok". */
    abstract protected function gudangCariBarang(): ?int;

    /** Apa yg terjadi setelah user menekan "Pilih". */
    abstract protected function terapkanBarang(int $itemId): void;

    /**
     * Kolom TAMBAHAN yg ikut dicocokkan selain `IKODE`/`INAMA`.
     *
     * Dipakai form PO: di sana item juga dicari lewat kode/nama SUPPLIER
     * (`ICODING`/`IPONAMA`) - 1.102 dari 2.764 item aktif mengisinya. Nama kolom di sini
     * disisipkan ke SQL, jadi isinya harus KONSTANTA di kode, tidak pernah dari layar.
     */
    protected function kolomCariTambahan(): array
    {
        return [];
    }

    /**
     * Apakah daftar dibatasi item yg boleh dipakai cabang ini (`ICABANG`).
     *
     * Default TRUE, mengikuti `bFrmCariItem::IsiData`. Form PO mematikannya - lihat
     * alasannya di `PoForm::saringCabangCariBarang()`.
     */
    protected function saringCabangCariBarang(): bool
    {
        return true;
    }

    /**
     * Nama kolom stok cabang di `bitem` (`ISTOKxx`), dari fungsi legacy `F_KOLOMGUDANG`.
     *
     * Hasilnya DIPAKAI LANGSUNG di SQL, jadi WAJIB lewat whitelist - pola sama dgn
     * `PrForm::stokColumn()`/`PkbForm::stokColumn()`.
     */
    protected function kolomStokGudang(?int $gid): string
    {
        if (! $gid) {
            return 'ISTOKPG';
        }
        $col = (string) (DB::selectOne('SELECT F_KOLOMGUDANG(?) AS c', [$gid])->c ?? '');

        return preg_match('/^ISTOK[A-Z0-9]+$/', $col) ? $col : 'ISTOKPG';
    }

    public function bukaCariBarang(): void
    {
        if (! $this->hakCariBarang()) {
            return;
        }
        $this->cbData = '';
        $this->cbPilih = null;
        $this->cbDitambah = 0;
        $this->cbShow = true;

        // VB6 memanggil `.IsiData` SEBELUM `.Show` - dialognya sudah terisi saat dibuka.
        $this->cariBarang();
    }

    public function tutupCariBarang(): void
    {
        $this->cbShow = false;
        $this->cbHasil = [];
        $this->cbPilih = null;
    }

    /** Hook Livewire utk `$cbData` - menyaring langsung saat diketik, spt `txtData_Change`. */
    public function updatedCbData(): void
    {
        $this->cariBarang();
    }

    public function cariBarang(): void
    {
        if (! $this->hakCariBarang()) {
            $this->cbHasil = [];
            $this->cbTotal = 0;

            return;
        }

        $gid = $this->gudangCariBarang();
        $kol = $this->kolomStokGudang($gid);
        $q = trim($this->cbData);

        // Tiga saringan, semuanya ikut `bFrmCariItem::IsiData`:
        //   WHERE ISTATUS=0 AND ICABANG like '%|<cabang>|%' AND <masa berlaku>
        //
        // 1. ISTATUS = 0 -> item aktif.
        // 2. Masa berlaku (ITANGGAL1/ITANGGAL2). Saat ini tidak menyaring satu baris pun
        //    (0 dari 2.764 item aktif), tapi tetap dipasang supaya item kedaluwarsa hilang
        //    sendiri, bukan muncul lalu ditolak saat disimpan.
        // 3. `ICABANG` - item yg boleh dipakai cabang ini. **Memangkas banyak**: di cabang 1,
        //    2.764 -> 1.696 item (1.068 tersaring).
        //
        // ICABANG WAJIB pakai REGEXP, BUKAN `LIKE '%|gid|%'` spt VB6: ~1.638 baris (29%
        // katalog) datanya rusak - kata literal "ICABANG" + CRLF nyasar menggantikan pipe
        // pembatas pertama - sehingga GID di posisi pertama salah ditolak. Kebetulan
        // SEMUA baris rusak itu sekarang tidak aktif (terukur: 0 yg lolos ISTATUS=0), jadi
        // hari ini LIKE & REGEXP memberi hasil sama - tapi begitu satu saja diaktifkan lagi,
        // LIKE langsung salah. Lihat `PosTerminal::applyIcabangFilter()`.
        $dasar = fn () => DB::table('bitem')
            ->where('ISTATUS', 0)
            ->whereRaw('COALESCE(ITANGGAL1, CURDATE()) <= CURDATE()')
            ->whereRaw('COALESCE(ITANGGAL2, CURDATE()) >= CURDATE()')
            ->when($gid && $this->saringCabangCariBarang(),
                fn ($b) => $b->whereRaw('ICABANG REGEXP ?', ['[|\r\n]' . (int) $gid . '\|']))
            ->when($q !== '', fn ($b) => $b->where(function ($w) use ($q) {
                $w->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%");
                // Konstanta dari kode, bukan dari layar - lihat `kolomCariTambahan()`.
                foreach ($this->kolomCariTambahan() as $kol) {
                    $w->orWhere($kol, 'like', "%{$q}%");
                }
            }));

        $this->cbTotal = (int) $dasar()->count();
        // `$kol` sudah lewat whitelist di `kolomStokGudang()`, aman disisipkan.
        $this->cbHasil = $dasar()
            ->orderBy('IKODE')
            ->limit($this->cbBatas)
            ->get(['IID as id', 'IKODE as kode', 'INAMA as nama', DB::raw("IFNULL(`{$kol}`, 0) as stok")])
            ->map(fn ($r) => (array) $r)->all();

        $this->cbPilih = null;
    }

    public function sorotBarang(int $id): void
    {
        $this->cbPilih = $id;
    }

    /** Tombol "Pilih". */
    public function pilihBarang(): void
    {
        if ($this->cbPilih !== null) {
            $this->ambilBarang($this->cbPilih);
        }
    }

    /**
     * Klik-ganda di baris = pilih langsung.
     *
     * Dialog SENGAJA TETAP TERBUKA setelah menambah - BEDA dari VB6, yg menutupnya tiap
     * kali karena di sana dialog dipanggil per-sel grid. Satu Permintaan Barang biasa
     * berisi belasan item (contoh nyata: BL-RS26080014 punya 47 baris); menutup dialog
     * tiap kali berarti membukanya 47 kali. Supaya penambahannya tetap terlihat - grid
     * di belakang tertutup modal - jumlahnya ditampilkan lewat `$cbDitambah`.
     */
    public function ambilBarang(int $id): void
    {
        if (! $this->hakCariBarang()) {
            return;
        }
        if (! collect($this->cbHasil)->firstWhere('id', $id)) {
            return;
        }
        $this->terapkanBarang($id);
        $this->cbDitambah++;
        $this->cbPilih = null;
    }
}
