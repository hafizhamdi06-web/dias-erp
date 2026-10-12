<?php

namespace App\Livewire\Concerns;

use App\Services\KmbWriter;
use App\Services\PurchaseRequestWriter;

/**
 * Dialog "Cari Transaksi" - port VB6 `bFrmCariTransaksi`.
 *
 * VB6 memakai **satu** form dialog untuk semua jenis pencarian, dibedakan label
 * tersembunyi `lblSumber`; di sini perannya dipegang `$ctSumber`. Polanya ditiru apa
 * adanya karena memang pas: satu kali hanya satu dialog yg terbuka, jadi satu set
 * keadaan cukup untuk semua sumber.
 *
 * Sumber yg sudah dipakai:
 * - **`RS_KM`** - cari PR untuk ditarik ke KMB
 *   (`fFrmKirimMutasiBarang::cmdCariSJOnline_Click`; nama tombolnya di VB6 menyesatkan,
 *   isinya memang pemilih PR)
 * - **`PRO_JOP`** - cari JOP/JOT untuk ditarik bahannya
 *   (`fFrmKirimMutasiBarang::cmdCariNoReff_Click`)
 * - **`KMB2`** - cari KMB untuk diterima jadi TMB
 *   (`fFrmPenerimaanMutasi::cmdCariNoReff_Click`)
 * - **`PRS`** - cari PR (sudah Disetujui) untuk ditarik ke PKB
 *   (`fFrmPermintaanBarang::cmdCariPermintaan_Click`)
 * - **`RS_SJ`** - cari PKB yang masih bersisa untuk ditarik ke Surat Jalan
 *   (`fFrmSuratJalan::cmdCariSJOnline_Click`; lagi-lagi nama tombolnya menyesatkan)
 *
 * Saringannya SENGAJA tidak `.live`: di VB6 tidak ada yg berubah sampai tombol
 * **Tampilkan** ditekan, dan itu juga menghindari query tiap ketukan tombol.
 */
trait CariTransaksi
{
    public const SUMBER_PR = 'RS_KM';
    public const SUMBER_JOP = 'PRO_JOP';
    public const SUMBER_KMB = 'KMB2';
    public const SUMBER_PR_PKB = 'PRS';
    public const SUMBER_PKB_SJ = 'RS_SJ';

    public bool $ctShow = false;
    public string $ctSumber = '';
    public string $ctCabang = '';
    public string $ctNomor = '';
    public string $ctNama = '';
    public string $ctFrom = '';
    public string $ctTo = '';
    public string $ctKode = '';
    public array $ctHasil = [];
    public ?int $ctPilih = null;
    public int $ctTotalTanpaSaringan = 0;

    /** Batas baris dialog - pelajaran yg sama dgn pemilih PKB (`SjList::BATAS_PKB`). */
    public int $ctBatas = 200;

    /** Apa yg terjadi setelah user menekan "Pilih". */
    abstract protected function terapkanCari(string $sumber, int $id, string $nomor): void;

    /** Hak yg diperiksa sebelum dialog boleh dipakai. */
    abstract protected function hakCari(): bool;

    /**
     * Cabang user login. Untuk `RS_KM` ini BUKAN sekadar nilai awal tapi satu-satunya
     * pilihan - lihat `ctCabangTerkunci()`.
     */
    protected function cabangUser(): int
    {
        return (int) (auth()->user()->UCABANG ?? 0);
    }

    /**
     * Apakah combo Cabang dimatikan?
     *
     * **`RS_KM` & `KMB2`: YA.** Form-nya menetapkan cabang = `UCABANG` user dan tidak
     * menerima pilihan (KMB `SUCABANG` = cabang pengirim, TMB = cabang penerima). Kalau
     * combo-nya bebas, user bisa menarik dokumen milik cabang LAIN lalu dokumen barunya
     * tetap lahir atas nama cabang sendiri - datanya salah diam-diam. Combo-nya tetap
     * ditampilkan (mati) supaya bentuknya sama dgn layar lama.
     *
     * **`PRO_JOP` & `PRS`: TIDAK.** Di jalur JOP cabang yg disaring `PUCABANG` (tempat
     * produksi berjalan), yg memang boleh beda dari cabang pengirim - itu justru tujuan
     * mutasinya. Di jalur PRS cabang yg disaring `PBUGUDANG` (cabang PEMINTA) dan picker-nya
     * memang LINTAS CABANG: PKB dibuat terpusat oleh satu user yg mengurus semua cabang
     * (lihat docblock `PurchaseRequestWriter::pullableForPkb()`; VB6 pun menyetel
     * `cboCabang.ListIndex = 0`). Keduanya default kosong = semua cabang.
     */
    public function ctCabangTerkunci(): bool
    {
        return ! in_array($this->ctSumber,
            [self::SUMBER_JOP, self::SUMBER_PR_PKB, self::SUMBER_PKB_SJ], true);
    }

    /** Judul kolom grid - `xFormatString` VB6 per sumber (kolom ID lebarnya 0, tidak tampil). */
    public function ctKolom(): array
    {
        return match ($this->ctSumber) {
            // "<ID|<No Bukti|<Tanggal|<Nama Kontak"
            self::SUMBER_JOP => ['No Bukti', 'Tanggal', 'Nama Kontak'],
            // "<ID|<No Transaksi|<Tanggal|<Nama Pelanggan"
            self::SUMBER_KMB => ['No Transaksi', 'Tanggal', 'Nama Pelanggan'],
            // "<ID|<No Transaksi|<Tanggal|<No PO" - kolom ke-3 nomor PR, BUKAN nama orang.
            self::SUMBER_PKB_SJ => ['No Transaksi', 'Tanggal', 'No PO'],
            // "<ID|<No Transaksi|<Tanggal|<Nama Karyawan"
            default => ['No Transaksi', 'Tanggal', 'Nama Karyawan'],
        };
    }

    /** Kotak "Kode Produk" hanya ada artinya di jalur PR - VB6 pun tidak memakainya di sumber lain. */
    public function ctPakaiKodeProduk(): bool
    {
        return $this->ctSumber === self::SUMBER_PR;
    }

    /** Sumber yg boleh dibuka komponen ini - dibatasi supaya tidak bisa dipanggil sembarangan. */
    protected function sumberCari(): array
    {
        return [self::SUMBER_PR, self::SUMBER_JOP, self::SUMBER_KMB,
            self::SUMBER_PR_PKB, self::SUMBER_PKB_SJ];
    }

    public function bukaCari(string $sumber): void
    {
        if (! $this->hakCari() || ! in_array($sumber, $this->sumberCari(), true)) {
            return;
        }
        $this->ctSumber = $sumber;
        // Default rentang = BULAN BERJALAN, sama spt dialog VB6 (01..31 bulan ini).
        $this->ctCabang = $this->ctCabangTerkunci() ? (string) ($this->cabangUser() ?: '') : '';
        $this->ctNomor = $this->ctNama = $this->ctKode = '';
        $this->ctFrom = now()->startOfMonth()->toDateString();
        $this->ctTo = now()->endOfMonth()->toDateString();
        $this->ctPilih = null;
        $this->ctShow = true;

        // VB6 memanggil `.IsiData` SEBELUM `.Show`, jadi dialognya sudah terisi saat dibuka.
        $this->tampilkan();
    }

    public function tutupCari(): void
    {
        $this->ctShow = false;
        $this->ctHasil = [];
        $this->ctPilih = null;
    }

    /** Tombol "Tampilkan". */
    public function tampilkan(): void
    {
        if (! $this->hakCari()) {
            return;
        }
        $saring = [
            'nomor' => $this->ctNomor, 'nama' => $this->ctNama,
            'from'  => $this->ctFrom, 'to' => $this->ctTo,
        ];

        $gudang = $this->cabangUser();
        $kmb = app(KmbWriter::class);

        // `$ctTotalTanpaSaringan` dipakai memperingatkan kalau hasilnya kosong gara-gara
        // saringan (paling sering: rentang tanggal default bulan berjalan), bukan karena
        // datanya memang habis.
        [$hasil, $total] = match ($this->ctSumber) {
            self::SUMBER_JOP => [
                $kmb->cariPullableJop($saring + ['cabang' => $this->ctCabang], $this->ctBatas),
                $kmb->jumlahPullableJop(),
            ],
            self::SUMBER_KMB => $gudang
                ? [$kmb->cariPullableForTmb($gudang, $saring, $this->ctBatas), $kmb->jumlahPullableForTmb($gudang)]
                : [collect(), 0],
            // RS_SJ: pemilih PKB utk Surat Jalan. Cabangnya saringan biasa (PBUGUDANGSUMBER),
            // default semua - sama spt perilaku sebelum dialog ini ada.
            self::SUMBER_PKB_SJ => (function () use ($saring) {
                $w = app(\App\Services\PkbWriter::class);

                return [$w->cariPullableForSj($saring + ['cabang' => $this->ctCabang], $this->ctBatas),
                    $w->jumlahPullableForSj()];
            })(),
            // PRS LINTAS CABANG - tidak dibatasi cabang user, cabangnya saringan biasa.
            self::SUMBER_PR_PKB => (function () use ($saring) {
                $w = app(PurchaseRequestWriter::class);

                return [$w->cariPullableForPkb($saring + ['cabang' => $this->ctCabang], $this->ctBatas),
                    $w->jumlahPullableForPkb()];
            })(),
            default => (function () use ($gudang, $saring) {
                $w = app(PurchaseRequestWriter::class);

                return $gudang
                    ? [$w->cariPullableForKmb($gudang, $saring + ['kode' => $this->ctKode], $this->ctBatas),
                        $w->jumlahPullableForKmb($gudang)]
                    : [collect(), 0];
            })(),
        };

        $this->ctHasil = collect($hasil)->map(fn ($r) => (array) $r)->all();
        $this->ctTotalTanpaSaringan = (int) $total;
        $this->ctPilih = null;
    }

    public function sorotBaris(int $id): void
    {
        $this->ctPilih = $id;
    }

    /** Tombol "Pilih" - mengambil baris yg sedang disorot. */
    public function pilihBaris(): void
    {
        if ($this->ctPilih !== null) {
            $this->ambilBaris($this->ctPilih);
        }
    }

    /** Klik-ganda di baris = pilih langsung. */
    public function ambilBaris(int $id): void
    {
        if (! $this->hakCari()) {
            return;
        }
        $baris = collect($this->ctHasil)->firstWhere('id', $id);
        if (! $baris) {
            return;
        }
        $sumber = $this->ctSumber;
        $this->tutupCari();
        $this->terapkanCari($sumber, (int) $baris['id'], (string) $baris['nomor']);
    }
}
