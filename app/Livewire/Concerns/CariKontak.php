<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Dialog "Cari Kontak" - port VB6 `bFrmCariKontak`.
 *
 * SATU dialog melayani BEBERAPA kotak isian, persis VB6 - yg membedakan cuma kategori yg
 * dipasang pemanggil sebelum `.Show 1`:
 *   `dFrmOrderPembelian::cmdCariVendor_Click` -> `cboKategori = "SUPPLIER"` (+ cabang Semua)
 *   `dFrmOrderPembelian::cmdCariSales_Click`  -> `cboKategori = "Karyawan"`
 * Di sini pembedanya `$ckTujuan`, pola sama `lblSumber` di `CariTransaksi`.
 *
 * Saringan mengikuti `bFrmCariKontak::IsiData`:
 *   KTIPE = <kategori>  (atau `KKODE <> ''` kalau <Semua>)
 *   AND KAKTIF <> 0     (hanya saat "Aktif Saja" dicentang; VB6 mencentangnya di Form_Load)
 *   AND KCABANG = <n>   (atau `KCABANG <> 99` kalau Semua)
 *   AND upper(<field>) like '%<data>%'
 * `order by KID DESC limit 20`.
 *
 * **Menyaring lewat tombol "Tampilkan", BUKAN saat diketik** - sama spt VB6 (ada
 * `cmdTampilkan` + label "Ketik Di Data, Setelah itu Tekan Enter/Klik Tampilkan"), beda
 * dari `CariBarang` yg menyaring langsung. Di sini itu bukan sekadar meniru: `bkontak`
 * berisi **321.768 baris**, jadi menyaring tiap ketikan berarti memindai tabel itu
 * berulang kali.
 */
trait CariKontak
{
    public bool $ckShow = false;
    /** Kotak isian mana yg sedang dicarikan - lihat docblock trait. */
    public string $ckTujuan = '';
    /**
     * KTID di `bkontaktipe`; '' = <Semua>.
     *
     * Sengaja `string`, bukan `?int`: opsi <Semua> mengirim string kosong dari layar, dan
     * properti ber-tipe `?int` akan menolaknya (TypeError), bukan mengartikannya null.
     * Pola sama `$ckCabang`.
     */
    public string $ckKategori = '';
    public bool $ckAktifSaja = true;
    /** Kunci di `ckFieldOpsi()`. VB6 `cboField.ListIndex = 1` = "Nama Kontak". */
    public string $ckField = 'nama';
    public string $ckData = '';
    /** GID gudang; '' = Semua. */
    public string $ckCabang = '';
    public array $ckHasil = [];
    public ?int $ckPilih = null;
    public int $ckTotal = 0;

    /** Sama dgn `limit 20` di `bFrmCariKontak::IsiData`. */
    public int $ckBatas = 20;

    /** Hak yg diperiksa sebelum dialog boleh dipakai. */
    abstract protected function hakCariKontak(): bool;

    /** Apa yg terjadi setelah user menekan "Pilih" - pemanggil melihat `$ckTujuan`. */
    abstract protected function terapkanKontak(int $kontakId): void;

    /**
     * Kategori yg dipasang saat dialog dibuka, per tujuan. Dikembalikan null = <Semua>.
     * Pemanggil menimpanya kalau punya lebih dari satu kotak isian.
     */
    protected function kategoriAwalCariKontak(string $tujuan): ?string
    {
        return null;
    }

    /**
     * Pilihan "Field" -> kolom `bkontak`. Isi & urutannya sama dgn
     * `xItCombo.IsiData cboField, "Kode Kontak~Nama Kontak~No KTP~ID Pasien~No Member~No Kartu~No HP"`.
     *
     * Kuncinya memang bukan nama kolom: nama kolom TIDAK BOLEH datang dari sisi klien,
     * jadi nilai dari layar cuma dipakai sbg kunci array ini.
     */
    public function ckFieldOpsi(): array
    {
        return [
            'kode'     => ['Kode Kontak', 'KKODE'],
            'nama'     => ['Nama Kontak', 'KNAMA'],
            'ktp'      => ['No KTP',      'KNOKTP'],
            'pasien'   => ['ID Pasien',   'KIDPASIEN'],
            'member'   => ['No Member',   'KNOMEMBER'],
            'kartu'    => ['No Kartu',    'KCARD'],
            'hp'       => ['No HP',       'K1TELP1'],
        ];
    }

    public function bukaCariKontak(string $tujuan = ''): void
    {
        if (! $this->hakCariKontak()) {
            return;
        }
        $this->ckTujuan = $tujuan;
        $this->ckData = '';
        $this->ckPilih = null;
        $this->ckField = 'nama';
        $this->ckAktifSaja = true;
        $this->ckCabang = '';

        // Dicocokkan TANPA peduli huruf besar/kecil: VB6 menulis "SUPPLIER" di satu tempat
        // dan "Karyawan" di tempat lain, sedangkan `bkontaktipe.KTNAMA` isinya huruf besar.
        $awal = $this->kategoriAwalCariKontak($tujuan);
        $ktid = $awal
            ? DB::table('bkontaktipe')->whereRaw('UPPER(KTNAMA) = ?', [strtoupper($awal)])->value('KTID')
            : null;
        $this->ckKategori = $ktid ? (string) $ktid : '';

        $this->ckShow = true;

        // VB6 mengisi grid sebelum `.Show` - dialognya sudah berisi saat dibuka.
        $this->cariKontak();
    }

    public function tutupCariKontak(): void
    {
        $this->ckShow = false;
        $this->ckHasil = [];
        $this->ckPilih = null;
        $this->ckTujuan = '';
    }

    public function cariKontak(): void
    {
        if (! $this->hakCariKontak()) {
            $this->ckHasil = [];
            $this->ckTotal = 0;

            return;
        }

        $q = trim($this->ckData);
        [, $kolom] = $this->ckFieldOpsi()[$this->ckField] ?? $this->ckFieldOpsi()['nama'];

        $dasar = fn () => DB::table('bkontak as k')
            ->leftJoin('bkontaktipe as t', 't.KTID', '=', 'k.KTIPE')
            ->when($this->ckKategori !== '', fn ($b) => $b->where('k.KTIPE', (int) $this->ckKategori))
            // VB6: kalau kategori <Semua>, saringannya cuma `KKODE <> ''`.
            ->when($this->ckKategori === '', fn ($b) => $b->where('k.KKODE', '<>', ''))
            ->when($this->ckAktifSaja, fn ($b) => $b->where('k.KAKTIF', '<>', 0))
            ->when($this->ckCabang !== '', fn ($b) => $b->where('k.KCABANG', (int) $this->ckCabang))
            ->when($this->ckCabang === '', fn ($b) => $b->where('k.KCABANG', '<>', 99))
            // `$kolom` dari whitelist `ckFieldOpsi()`, tidak pernah dari layar.
            ->when($q !== '', fn ($b) => $b->where("k.{$kolom}", 'like', "%{$q}%"));

        $this->ckTotal = (int) $dasar()->count();
        $this->ckHasil = $dasar()
            ->orderByDesc('k.KID')
            ->limit($this->ckBatas)
            ->get(['k.KID as id', 'k.KKODE as kode', 'k.KNAMA as nama', 't.KTNAMA as jenis'])
            ->map(fn ($r) => (array) $r)->all();

        $this->ckPilih = null;
    }

    public function sorotKontak(int $id): void
    {
        $this->ckPilih = $id;
    }

    /** Tombol "Pilih". */
    public function pilihKontak(): void
    {
        if ($this->ckPilih !== null) {
            $this->ambilKontak($this->ckPilih);
        }
    }

    /**
     * Klik-ganda di baris = pilih langsung.
     *
     * Dialog DITUTUP setelah memilih - beda dari `CariBarang` yg sengaja dibiarkan terbuka.
     * Di sana user menambah belasan item berturut-turut; di sini kontaknya cuma SATU per
     * dokumen, jadi membiarkannya terbuka malah menghalangi.
     */
    public function ambilKontak(int $id): void
    {
        if (! $this->hakCariKontak()) {
            return;
        }
        if (! collect($this->ckHasil)->firstWhere('id', $id)) {
            return;
        }
        $this->terapkanKontak($id);
        $this->tutupCariKontak();
    }
}
