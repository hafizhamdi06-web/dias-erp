<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Purchase Order (PO) - Order Pembelian ke SUPPLIER EKSTERNAL. Tabel legacy
 * `esalesorderu` (header) + `esalesorderd` (detail) - tabel SHARED dgn Sales Order
 * (dibedakan via `SOUSUMBER`, TIDAK disentuh modul kita). PO murni dokumen KOMITMEN,
 * TIDAK menyentuh stok sama sekali - stok baru bergerak nanti di modul "Penerimaan
 * Barang" (PB, `fstoku SUSUMBER='PB'`, DEFERRED, belum dibangun). VB6 asli:
 * `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\dFrmOrderPembelian.frm`.
 *
 * SOUSUMBER = 'PO' (aanomor NKODE='PO', SUDAH ADA sblm modul ini - tidak perlu seed
 * baru). Nomor: {kodecabang}-PO{yymm}{NNNN} - kodecabang dari Gudang (SOUCABANG, auto
 * cabang login).
 *
 * SOUSTATUS: **0 = Aktif/Belum Diterima, 2 = Sebagian Diterima, 3 = Selesai Diterima,
 * 9 = Batal (konvensi kita)** - DIKONFIRMASI DATA NYATA hasil import user (235 baris PO
 * asli): SOUSTATUS SELALU 0/2/3, TIDAK PERNAH 1 - dgn form `dFrmOrderPembelian.frm`
 * SENDIRI TIDAK PERNAH menulis kolom ini (`xSimpanData()` tdk py SOUSTATUS di
 * zField/zValue), nilai 0/2/3 datang dari mekanisme LAIN (kemungkinan modul Penerimaan
 * Barang/CI3 paralel yg msh aktif, mirip pola `PUSTATUS` JOP 1/2/3 tp offset ke 0/2/3 di
 * sini). **Awalnya SEMPAT keliru pakai 1=Aktif (dugaan tanpa data), DIPERBAIKI setelah
 * data nyata diimport** - 0 = Aktif (skema TERBUKTI, BUKAN dugaan), 9 = Batal (konvensi
 * kita, TIDAK ada di data nyata - form VB6 asli HARD DELETE saat batal, kita soft-cancel
 * spy ada jejak, pola sama JOP/Produksi/KMB/TMB). PO EDITABLE selama status=0 (dokumen
 * komitmen blm final/blm ada penerimaan barang nyata) - pola sama `PrForm`/`JopForm`,
 * BUKAN read-only-selalu. Baris real py status 2/3 (12 dari 235) otomatis TERKUNCI di UI
 * kita (sudah "diterima" via proses eksternal) - KITA TIDAK PUNYA logic utk MENULIS
 * 2/3 sendiri (modul Penerimaan Barang blm dibangun), cuma MENGHORMATI nilai yg SUDAH
 * ada dari luar.
 *
 * Vendor (`SOUKONTAK`) = `bkontak.KTIPE=6` ("SUPPLIER", dikonfirmasi `bkontaktipe`).
 * Karyawan/"Bag Pembelian" (`SOUKARYAWAN`) = `KTIPE=4` ("KARYAWAN"), dicari manual -
 * BEDA dari pola "Diperintah Oleh" auto-login modul lain, di sini genuinely dicari
 * manual sama spt Vendor/Termin (bukan default dari user login).
 *
 * `SOUATTENTION` - VB6 asli cari dari tabel `kontakperson`, TAPI TABEL ITU TIDAK ADA
 * SAMA SEKALI di DB kita (dicek `information_schema`) - jadi kolom ini TEKS BEBAS
 * (manual ketik), TIDAK ada lookup/search.
 *
 * Kode/Nama override per item (`bitem.ICODING`/`IPONAMA`) - DIKONFIRMASI data nyata
 * hidup (494/1199 dari 5580 item py override) - `lines()` JOIN `bitem` mengembalikan
 * kode/nama ASLI ATAU override, tampilan pakai override kalau ada (fallback ke
 * IKODE/INAMA). `SODITEM` SELALU diresolve dari IID asli (bukan override).
 *
 * DEAD FIELDS (skema ADA tp VB6 asli TIDAK PERNAH menulisnya di `xSimpanData()`,
 * TIDAK direplikasi): `SOUPBID`/`SOUTGL1`/`SOUTGL2`/`SOUDEPARTEMEN` (jalur "Tarik dari
 * Permintaan Barang" - handler `cmdCariNoPermintaan_Click` SELURUHNYA di-comment VB6
 * asli, genuinely dead code), `SOUAPPROVE1`/`SOUAPPROVE2`/`SOUCABANGTUJUAN`/
 * `SOUJENIS`/`SOUSTATUSSJ`/`SOUSTATUSPBDEPO` (kolom milik jalur Sales Order LAIN yg
 * berbagi tabel sama). Grid kolom "Jenis"/COA-tipe jg TERBUKTI display-only (diisi
 * `SetDataBarang` tp tak pernah dibaca balik saat simpan). `SODDIVISI`/`SODGUDANG`
 * (alokasi cabang PER-BARIS) - SKIP v1 (keputusan user - VB6 asli ambigu, combo isi
 * `bgudang` tp resolusi lewat fungsi Divisi, tanpa data nyata utk verifikasi).
 *
 * TIDAK ADA trigger stok apapun disentuh PO - `esalesorderd.SODMASUK`/`SODKELUAR`
 * HANYA disentuh trigger `fstokd_add/_edit/_DELL` via `SDSODID` dari modul Penerimaan
 * Barang (PB) yg BELUM dibangun (dikonfirmasi riset sesi sblmnya).
 */
class PurchaseOrderWriter
{
    public const SUMBER = 'PO';

    public const STATUS_AKTIF = 0;

    public const STATUS_SEBAGIAN_DITERIMA = 2;

    public const STATUS_SELESAI_DITERIMA = 3;

    public const STATUS_BATAL = 9;

    public const KTIPE_SUPPLIER = 6;

    public const KTIPE_KARYAWAN = 4;

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('esalesorderu')
            ->where('SOUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(SOUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Diskon Rp per baris dari harga + diskon bertingkat 3-level, kaskade akumulatif:
     * level 2 & 3 masing2 dihitung dari SISA harga (harga - diskon level sblmnya), BUKAN
     * dari harga penuh berulang. **KOREKSI BUG VB6 asli** - level 3 di source asli py
     * typo (`Harga + DiscRp` alih2 `DiscRpSejauhIni + DiscRpBaru`, beda pola dari level 2
     * tepat di atasnya yg benar) yg kalau direplikasi apa adanya menghasilkan DiscRp
     * LEBIH BESAR dari Harga (absurd) - di sini konsisten akumulatif spt level 2.
     */
    public function hitungDiskonBaris(float $harga, float $disc1, float $disc2, float $disc3): float
    {
        $discRp = $harga * $disc1 / 100;

        if ($disc2 > 0) {
            $sisa = $harga - $discRp;
            $discRp += $sisa * $disc2 / 100;
        }

        if ($disc3 > 0) {
            $sisa = $harga - $discRp;
            $discRp += $sisa * $disc3 / 100;
        }

        return $discRp;
    }

    /**
     * Pajak dari subtotal (SETELAH diskon header) + mode pajak, formula PERSIS
     * `SetPajak()` VB6 asli: 0=Tanpa Pajak, 1=Harga Belum Termasuk Pajak (pajak
     * ditambahkan), 2=Harga Sudah Termasuk Pajak (pajak dipisah dari dalam, total TETAP
     * subtotal - cuma utk pelaporan pajaknya berapa).
     *
     * @return array{pajak:float,total:float}
     */
    public function hitungPajak(float $subtotalSetelahDiskon, int $modePajak, float $nilaiPajakPersen): array
    {
        return match ($modePajak) {
            1 => (function () use ($subtotalSetelahDiskon, $nilaiPajakPersen) {
                $pajak = $subtotalSetelahDiskon * $nilaiPajakPersen / 100;

                return ['pajak' => $pajak, 'total' => $subtotalSetelahDiskon + $pajak];
            })(),
            2 => (function () use ($subtotalSetelahDiskon, $nilaiPajakPersen) {
                $pajak = $subtotalSetelahDiskon * $nilaiPajakPersen / (100 + $nilaiPajakPersen);

                return ['pajak' => $pajak, 'total' => $subtotalSetelahDiskon];
            })(),
            default => ['pajak' => 0.0, 'total' => $subtotalSetelahDiskon],
        };
    }

    /**
     * Cap anggaran PO bulanan dari `aconfig` (`CGROUP='Accounting'`,
     * `CFIELD='AccBugetPO'`, SUDAH ADA barisnya, `CVALUE=0` saat ini = TIDAK ada cap
     * aktif). `$excludeId` dipakai saat EDIT PO yg sudah ada spy transaksi lama tidak
     * dihitung dobel dlm SUM bulan berjalan. Return null kalau OK/cap tidak aktif,
     * string pesan error kalau terlampaui.
     */
    public function cekBudget(float $totalTransaksiIni, string $tglYmd, ?int $excludeId = null): ?string
    {
        $cap = (float) DB::table('aconfig')
            ->where('CGROUP', 'Accounting')->where('CFIELD', 'AccBugetPO')->value('CVALUE');

        if ($cap <= 0) {
            return null;
        }

        $bulan = (int) date('m', strtotime($tglYmd));
        $tahun = (int) date('Y', strtotime($tglYmd));

        $sudahTerpakai = (float) DB::table('esalesorderu')
            ->where('SOUSUMBER', self::SUMBER)
            ->where('SOUSTATUS', '<>', self::STATUS_BATAL)
            ->whereMonth('SOUTANGGAL', $bulan)->whereYear('SOUTANGGAL', $tahun)
            ->when($excludeId, fn ($q) => $q->where('SOUID', '<>', $excludeId))
            ->sum('SOUTOTALTRANSAKSI');

        $totalGabungan = $sudahTerpakai + $totalTransaksiIni;

        if ($totalGabungan > $cap) {
            return 'Total PO bulan ini (' . number_format($totalGabungan, 0, ',', '.') . ') melebihi anggaran bulanan ('
                . number_format($cap, 0, ',', '.') . '). Transaksi tidak bisa dilanjutkan.';
        }

        return null;
    }

    /**
     * @param array $header kolom SOU* (tanpa SOUNOTRANSAKSI/SOUID/SOUSUMBER/SOUSTATUS/
     *              SOUTOTALTRANSAKSI/SOUSUBTOTAL/SOUTOTALPAJAK - dihitung server-side)
     * @param list<array{item:int,qty:float,satuan:?int,kemasan:?string,harga:float,disc1:float,disc2:float,disc3:float,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        return $this->simpan(null, $header, $lines, $meta);
    }

    /**
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function update(int $id, array $header, array $lines, array $meta): array
    {
        $h = $this->header($id);
        if (! $h || (int) $h->SOUSTATUS !== self::STATUS_AKTIF) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'PO tidak ditemukan atau sudah dibatalkan.'];
        }

        return $this->simpan($id, $header, $lines, $meta, $h->SOUNOTRANSAKSI);
    }

    private function simpan(?int $id, array $header, array $lines, array $meta, ?string $nomorExisting = null): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        // Server-side jadi sumber kebenaran akhir (pola sama PrForm) - hitung ULANG
        // diskon per baris + subtotal + pajak, JANGAN percaya angka dari form mentah2.
        $subtotal = 0.0;
        $preparedLines = [];
        foreach ($lines as $l) {
            $qty = (float) $l['qty'];
            $harga = (float) $l['harga'];
            if ($qty <= 0) {
                continue;
            }
            $discRp = $this->hitungDiskonBaris($harga, (float) $l['disc1'], (float) $l['disc2'], (float) $l['disc3']);
            $totalBaris = $qty * ($harga - $discRp);
            $subtotal += $totalBaris;

            $preparedLines[] = $l + ['discRp' => $discRp, 'totalBaris' => $totalBaris];
        }

        if ($preparedLines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $diskonHeader = (float) ($header['SOUDISKON'] ?? 0);
        $subtotalSetelahDiskon = $subtotal - $diskonHeader;
        $pajakInfo = $this->hitungPajak($subtotalSetelahDiskon, (int) ($header['SOUPAJAK'] ?? 0), (float) ($header['SOUNILAIPAJAK'] ?? 0));

        $budgetError = $this->cekBudget($pajakInfo['total'], $meta['tgl'], $id);
        if ($budgetError) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => $budgetError];
        }

        $nomor = $id
            ? ($nomorExisting ?: $this->nextNumber($meta['kodecabang'], $meta['tgl']))
            : $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $newId = DB::transaction(function () use ($id, $header, $preparedLines, $nomor, $subtotal, $subtotalSetelahDiskon, $diskonHeader, $pajakInfo) {
                $header['SOUNOTRANSAKSI'] = $nomor;
                $header['SOUSUMBER'] = self::SUMBER;
                $header['SOUSUBTOTAL'] = $subtotal;
                $header['SOUDISKON'] = $diskonHeader;
                $header['SOUTOTALPAJAK'] = $pajakInfo['pajak'];
                $header['SOUTOTALTRANSAKSI'] = $pajakInfo['total'];

                if ($id) {
                    $header['SOUMODIFU'] = auth()->id();
                    $header['SOUMODIFD'] = now();
                    DB::table('esalesorderu')->where('SOUID', $id)->update($header);
                    DB::table('esalesorderd')->where('SODIDSOU', $id)->delete();
                    $newId = $id;
                } else {
                    $header['SOUSTATUS'] = self::STATUS_AKTIF;
                    $header['SOUCREATEU'] = auth()->id();
                    $newId = (int) DB::table('esalesorderu')->insertGetId($header, 'SOUID');
                }

                $urut = 1;
                foreach ($preparedLines as $l) {
                    DB::table('esalesorderd')->insert([
                        'SODIDSOU'          => $newId,
                        'SODURUTAN'         => $urut++,
                        'SODITEM'           => $l['item'],
                        'SODORDER'          => $l['qty'],
                        'SODORDERD'         => $l['qty'],
                        'SODSATUAN'         => $l['satuan'] ?: null,
                        'SODSATUAND'        => $l['satuan'] ?: null,
                        'SODKEMASAN'        => $l['kemasan'] ?: null,
                        'SODHARGA'          => $l['harga'],
                        'SODDISKON'         => $l['discRp'],
                        'SODDISKONPERSEN'   => $l['disc1'],
                        'SODDISKONPERSEN2'  => $l['disc2'],
                        'SODDISKONPERSEN3'  => $l['disc3'],
                        'SODSUBTOTAL'       => $l['totalBaris'],
                        'SODCATATAN'        => $l['catatan'] ?: null,
                        'SODSUMBER'         => self::SUMBER,
                    ]);
                }

                return $newId;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $newId, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan PO - SOFT (SOUSTATUS=9), TIDAK PERNAH hard-delete (beda dari VB6 asli).
     * Hanya PO berstatus 1 (belum batal) yg bisa dibatalkan.
     */
    public function cancel(int $id): bool
    {
        $h = $this->header($id);
        if (! $h || (int) $h->SOUSTATUS !== self::STATUS_AKTIF) {
            return false;
        }

        DB::table('esalesorderu')->where('SOUID', $id)->update([
            'SOUSTATUS' => self::STATUS_BATAL,
            'SOUMODIFU' => auth()->id(),
            'SOUMODIFD' => now(),
        ]);

        return true;
    }

    public function header(int $id): ?object
    {
        return DB::table('esalesorderu')->where('SOUID', $id)->where('SOUSUMBER', self::SUMBER)->first();
    }

    /**
     * Baris detail + kode/nama ITEM ASLI + override PO (`ICODING`/`IPONAMA`) - tampilan
     * pakai override kalau ada (lihat docblock kelas).
     */
    public function lines(int $id): array
    {
        return DB::table('esalesorderd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SODITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SODSATUAN')
            ->where('d.SODIDSOU', $id)
            ->orderBy('d.SODURUTAN')
            ->get([
                'd.*', 'i.IKODE', 'i.INAMA', 'i.ICODING', 'i.IPONAMA', 's.SKODE as satuan_kode',
            ])
            ->all();
    }
}
