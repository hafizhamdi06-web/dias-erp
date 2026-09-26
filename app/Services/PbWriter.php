<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penerimaan Barang Supplier Di Depo (PB) - terima barang dari SUPPLIER EKSTERNAL
 * berdasarkan Purchase Order (`esalesorderu`/`esalesorderd`, SOUSUMBER='PO'). Tabel
 * legacy `fstoku`/`fstokd` (`SUSUMBER='PB'`), BUKAN tabel terpisah. VB6 asli:
 * `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\fFrmPenerimaanBarangPBDepo.frm`.
 *
 * SUSUMBER='PB' TIDAK ADA di `aanomor` (beda dari PO/PKB/dll) - nomor dihitung sendiri
 * (pola sama semua writer lain), tidak masalah. **162 baris `fstoku` SUSUMBER='PB' SUDAH
 * ADA di data nyata (termasuk s/d Agustus 2026) TAPI 100% tanpa baris `fstokd`** -
 * dikonfirmasi user: sedang proses IMPORT header-dulu, bukan proses lain yg aktif nulis -
 * jadi 'PB' memang milik modul ini, aman dipakai.
 *
 * **Gudang tujuan = CABANG USER LOGIN, tidak bisa dipilih** (permintaan user 2026-09-26,
 * sejalan aturan app "kalau ada pilihan cabang, diisi sesuai cabang user"). SEBELUMNYA
 * berupa dropdown yg dibatasi 3 gudang "Depo" yg secara nyata dipakai PB (GID 20=Depo,
 * 34=RII Bahan Baku, 46=Depo Farmasi - lihat `GUDANG_DEPO`, konstanta DIPERTAHANKAN sbg
 * dokumentasi sejarah walau tidak lagi dipakai memvalidasi). **Pembatasan itu DILEPAS**:
 * kalau tetap dipasang, user yg cabangnya di luar 3 gudang itu (mis. Petogogan) tidak akan
 * pernah bisa menyimpan PB - field-nya terkunci ke cabangnya sendiri. Validasi sekarang
 * cuma "gudang tujuan tidak boleh kosong".
 * **Konsekuensi yg perlu disadari**: PB kini bisa masuk ke gudang mana pun sesuai cabang
 * user, padahal 420 dokumen PB legacy 100% cuma di 3 gudang itu. Kalau ternyata PB memang
 * HARUS depo saja, yg perlu dibatasi adalah SIAPA yg boleh membuka menu PB (lewat hak akses
 * menu per user), BUKAN dropdown gudangnya.
 *
 * **Qty disederhanakan jadi SATU field "Qty Diterima" (satuan DASAR/klinik, `bitem.ISATUAN`)
 * per baris** - VB6 asli punya 2 field terpisah (Qty PO-unit & Qty Depo-unit via
 * `IG.TextMatrix` kolom 18/20/21) yg TIDAK saling link otomatis (col 18 "Qty Depo" mulai
 * dari 0 stlh tarik PO, harus diisi manual, baru col 21 "Qty Klinik" = col18 × `IQTYPERBOX`
 * dihitung otomatis) - membingungkan & berisiko salah hitung stok. Dikonfirmasi ke user,
 * disetujui disederhanakan (2026-09-22). `SDMASUKPB` (qty versi SATUAN PO, dipakai lacak
 * sisa PO) dihitung MUNDUR dari qty dasar: `qtyDasar / IQTYPERBOX` (`IQTYPERBOX` = rasio
 * 1 unit-Depo/PO = N unit-dasar, default 1 kalau null/0 - 452/479 item riwayat PB memang
 * bernilai 1, jadi mayoritas kasus qtyDasar==SDMASUKPB persis, cocok dgn 13 baris `fstokd`
 * PB nyata yg ADA (`SDMASUKPB=SDMASUK=SDMASUKD` selalu sama).
 *
 * **Trigger `fstokd_add`/`_edit`/`_DELL` (SAMA persis dipakai POS/SJ/PBC) OTOMATIS**:
 * (1) update stok `bitem.ISTOK{kode}` sesuai `SDGUDANG` (arah MASUK, `SDKELUAR` harus 0 -
 * diisi eksplisit, BUKAN dibiarkan null, krn kolom `SDSODID` di-OVERLOAD jg dipakai
 * `fperintahkirimbarangd.PKBDQTYPAKAI += SDKELUAR` - aman selama SDKELUAR=0 = no-op);
 * (2) **`esalesorderd.SODMASUK += SDMASUKPB` where `SODID=SDSODID`** - INI mekanisme
 * resmi pelacakan qty PO yg sudah diterima (dikonfirmasi `information_schema.TRIGGERS`),
 * jauh lebih simpel drpd VB6 asli yg re-agregasi `SUM(fstokd.SDMASUKPB)` via JOIN tiap
 * kali tarik PO - kita baca langsung `SODORDER - SODMASUK` sbg sisa.
 *
 * **`SDPBDID`/`SDPRDID`/`SDIDJOP` SENGAJA TIDAK PERNAH DIISI** - kolom di-OVERLOAD trigger
 * utk modul lain sama sekali tidak terkait (PR/`official_nmw` eksternal/Produksi), pola
 * SAMA persis `PbcWriter` (lihat docblock kelas itu utk detail bahaya `SDPRDID`).
 *
 * **`esalesorderu.SOUSTATUS` (0/2/3) TIDAK PERNAH ditulis modul ini** - dikonfirmasi TIDAK
 * ADA trigger yg otomatis mengubahnya dari perubahan `SODMASUK` (`SHOW TRIGGERS` pada
 * `esalesorderd`/`esalesorderu` tidak menyentuh `SOUSTATUS` sama sekali) - mekanisme yg
 * benar2 mengubah 0→2→3 TETAP TIDAK DIKETAHUI (lihat catatan `PurchaseOrderWriter`),
 * modul ini PATUH pola sama: perlakukan sbg READ-ONLY, jangan tebak-tebak nulis sendiri.
 * `esalesorderu.SOUSTATUSPBDEPO` ADA tapi 235/235 baris PO nyata = 0, tidak ada
 * trigger/routine yg menyentuhnya - kolom setengah-jadi, TIDAK direplikasi (pola sama
 * `SDQTPAKAI` di `PbcWriter`).
 *
 * **Harga & diskon dikunci dari PO** (read-only, TIDAK bisa diedit di sini) - beda dari
 * VB6 asli yg sbnrnya izinkan edit diskon (`Columns(8..11).Editable=True`) tapi TIDAK
 * harga (`Columns(5).Editable=False`) - disederhanakan penuh read-only krn dokumen
 * komitmennya (harga+diskon) seharusnya sudah final di PO, bukan saat terima barang.
 *
 * **BUG TRIGGER PRODUKSI (ditemukan 2026-09-22, DIKONFIRMASI ke user, SENGAJA TIDAK
 * DIPERBAIKI)**: `fstokd_add`/`_edit`/`_DELL` py STATEMEN DUPLIKAT utk `ISTOKDE` (GID=20/
 * Depo) - `SHOW CREATE TRIGGER fstokd_add` menunjukkan baris
 * `update bitem set ISTOKDE=ISTOKDE+(...) where NEW.SDGUDANG=20` muncul 2 KALI (beda
 * lokasi dlm body trigger). Akibatnya **SETIAP transaksi apa pun yg insert `SDGUDANG=20`
 * (termasuk PB ini) stok `bitem.ISTOKDE` bertambah 2x lipat dari `SDMASUK` sebenarnya**
 * (diverifikasi eksplisit via tinker: insert SDMASUK=50 -> ISTOKDE +100, bukan +50).
 * GID 34 (RII Bahan Baku)/46 (Depo Farmasi) TIDAK kena (cuma 1 statement masing2).
 * Keputusan user: JANGAN diperbaiki - trigger produksi di luar scope, berisiko ke proses
 * lain yg mungkin sudah "terbiasa" dgn angka ini. Modul kita TIDAK mengkompensasi
 * (insert `SDMASUK` = qty asli apa adanya, sama spt semua writer lain) - efek 2x murni
 * dari trigger, bukan dari kolom yg kita isi.
 *
 * **BATCH/SERIAL (ditambahkan 2026-09-25)**: item dgn `bitem.ISERIAL=1` WAJIB diisi No Batch
 * saat terima - mekanismenya (SDSERIAL + `bitemserialhistori` + trigger) ada di
 * `SerialBatch`, modul ini cuma memanggil. Arah 'masuk' (isi `ISHMASUK`). Total batch per
 * baris HARUS sama dgn qty diterima, divalidasi ulang server-side di `create()` (tidak
 * percaya form). `cancel()` memanggil `SerialBatch::forget()` - WAJIB, krn tidak ada
 * trigger di `fstokd` yg membalik stok batch.
 *
 * **DEFER (pola sama semua modul lain sesi ini)**: HPP/FIFO costing (`xHitungHPP`),
 * kunci periode akuntansi (`CekKunci`), tambah
 * item manual di luar PO (VB6 `IG_CellButtonClick` col 0 bisa cari barang bebas - modul
 * ini SENGAJA cuma dukung jalur "tarik dari PO" sesuai scope diminta user).
 */
class PbWriter
{
    public const SUMBER = 'PB';

    public const STATUS_BATAL = 9;

    /** GID gudang "Depo" yg valid jadi tujuan PB (hardcode dari data nyata, lihat docblock). */
    public const GUDANG_DEPO = [20, 34, 46];

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('fstoku')
            ->where('SUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(SUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * PO yg masih py sisa qty blm diterima (utk modal picker "Cari No PO").
     *
     * @return array<int,object>
     */
    public function pullablePo(string $q = '', ?int $cabangPo = null): array
    {
        return $this->pullablePoQuery($q, $cabangPo)
            ->orderByDesc('u.SOUID')
            ->limit(50)
            ->get([
                'u.SOUID as id', 'u.SOUNOTRANSAKSI as nomor', 'u.SOUTANGGAL as tanggal',
                'u.SOUCABANG as cabangId', 'k.KNAMA as vendor', 'g.GNAMA as cabang',
            ])->all();
    }

    /** Cabang yg PUNYA PO bisa ditarik - utk isi dropdown filter di picker. */
    public function cabangPunyaPo(): array
    {
        return $this->pullablePoQuery()
            ->groupBy('u.SOUCABANG', 'g.GNAMA')
            ->orderBy('g.GNAMA')
            ->get(['u.SOUCABANG as id', 'g.GNAMA as nama', DB::raw('COUNT(*) as jml')])
            ->all();
    }

    private function pullablePoQuery(string $q = '', ?int $cabangPo = null)
    {
        return DB::table('esalesorderu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SOUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SOUCABANG')
            ->where('u.SOUSUMBER', 'PO')
            ->whereIn('u.SOUSTATUS', [0, 2])
            ->whereExists(fn ($sub) => $sub->selectRaw(1)->from('esalesorderd as d')
                ->whereColumn('d.SODIDSOU', 'u.SOUID')
                ->whereColumn('d.SODORDER', '>', 'd.SODMASUK'))
            ->when($cabangPo, fn ($b) => $b->where('u.SOUCABANG', $cabangPo))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SOUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")));
    }

    /**
     * Baris PO yg masih py sisa (`SODORDER > SODMASUK`), siap ditarik ke grid PB.
     * `$excludeSodid` - SODID yg SUDAH ada di grid saat ini (cegah tarik dobel PO yg sama).
     *
     * @return array{header:object,lines:array}|null
     */
    public function fromPo(int $poId, array $excludeSodid = [], ?int $gudang = null): ?array
    {
        $header = DB::table('esalesorderu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SOUKONTAK')
            ->where('u.SOUID', $poId)->where('u.SOUSUMBER', 'PO')
            ->first(['u.SOUID', 'u.SOUNOTRANSAKSI', 'u.SOUKONTAK', 'k.KNAMA as vendor']);

        if (! $header || ! in_array((int) DB::table('esalesorderu')->where('SOUID', $poId)->value('SOUSTATUS'), [0, 2], true)) {
            return null;
        }

        $rows = DB::table('esalesorderd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SODITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SODSATUAN')
            ->where('d.SODIDSOU', $poId)
            ->whereColumn('d.SODORDER', '>', 'd.SODMASUK')
            ->when($excludeSodid !== [], fn ($b) => $b->whereNotIn('d.SODID', $excludeSodid))
            ->orderBy('d.SODURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ISATUAN as baseSatuan', 'i.IQTYPERBOX', 'i.ISERIAL', 's.SKODE as satuan_kode']);

        $lines = [];
        foreach ($rows as $r) {
            $sisaPoUnit = (float) $r->SODORDER - (float) $r->SODMASUK;
            if ($sisaPoUnit <= 0) {
                continue;
            }
            $rasio = (float) $r->IQTYPERBOX > 0 ? (float) $r->IQTYPERBOX : 1.0;
            $baseUnitKode = DB::table('bsatuan')->where('SID', $r->baseSatuan)->value('SKODE');

            $lines[] = [
                'sodid'        => (int) $r->SODID,
                'noPo'         => $header->SOUNOTRANSAKSI,
                'item'         => (int) $r->SODITEM,
                'kode'         => $r->IKODE ?? '',
                'nama'         => $r->INAMA ?? ('Item #' . $r->SODITEM),
                'sisaPoUnit'   => $sisaPoUnit,
                'satuanPoKode' => $r->satuan_kode ?? '',
                'satuanPo'     => $r->SODSATUAN ? (int) $r->SODSATUAN : null,
                'rasio'        => $rasio,
                'satuanDasar'  => $r->baseSatuan ? (int) $r->baseSatuan : null,
                'satuanDasarKode' => $baseUnitKode ?? '',
                'qty'          => round($sisaPoUnit * $rasio, 4),
                'harga'        => (float) $r->SODHARGA,
                'disc1'        => (float) $r->SODDISKON,
                'disc2'        => (float) $r->SODDISKONPERSEN,
                'disc3'        => (float) $r->SODDISKONPERSEN2,
                'disc4'        => (float) $r->SODDISKONPERSEN3,
                'catatan'      => null,
                'serial'       => (int) $r->ISERIAL === 1 && $this->serial->gudangPakaiSerial($gudang),
                'batches'      => [],
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    public function __construct(private SerialBatch $serial)
    {
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS)
     * @param list<array{sodid:int,item:int,qty:float,rasio:float,satuanPo:?int,satuanDasar:?int,harga:float,disc1:float,disc2:float,disc3:float,disc4:float,catatan:?string,batches?:list<array{noBatch:string,expired:?string,qty:float}>}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }
        if (! (int) ($header['SUCABANG'] ?? 0)) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gudang tujuan kosong.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor) {
                // Item mana yg WAJIB batch - 2 syarat (gudang GPAKAISERIAL=1 + item ISERIAL=1),
                // dibaca ulang dari DB, tidak percaya flag dari form.
                $serialItems = $this->serial->wajibBatch(array_column($lines, 'item'), (int) $header['SUCABANG']);

                // Re-cek server-side tiap baris masih py sisa PO cukup - cegah race/dobel-terima.
                foreach ($lines as $l) {
                    $sisa = (float) DB::table('esalesorderd')->where('SODID', $l['sodid'])
                        ->selectRaw('SODORDER - SODMASUK as sisa')->value('sisa');
                    $rasio = (float) $l['rasio'] > 0 ? (float) $l['rasio'] : 1.0;
                    $qtyPoUnit = (float) $l['qty'] / $rasio;
                    if ($qtyPoUnit > $sisa + 0.0001) {
                        throw new \RuntimeException("Qty diterima melebihi sisa PO (sisa {$sisa}).");
                    }

                    $batches = $this->serial->clean($l['batches'] ?? []);
                    if (in_array((int) $l['item'], $serialItems, true)) {
                        if ($batches === []) {
                            throw new \RuntimeException('Item "' . ($l['nama'] ?? $l['item']) . '" wajib diisi No Batch.');
                        }
                        $totalBatch = array_sum(array_column($batches, 'qty'));
                        if (abs($totalBatch - (float) $l['qty']) > 0.0001) {
                            throw new \RuntimeException('Total qty batch (' . $totalBatch . ') tidak sama dengan qty diterima ('
                                . (float) $l['qty'] . ') pada item "' . ($l['nama'] ?? $l['item']) . '".');
                        }
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                $urut = 1;
                foreach ($lines as $l) {
                    $rasio = (float) $l['rasio'] > 0 ? (float) $l['rasio'] : 1.0;
                    $batches = in_array((int) $l['item'], $serialItems, true)
                        ? $this->serial->clean($l['batches'] ?? [])
                        : [];

                    $sdid = (int) DB::table('fstokd')->insertGetId([
                        'SDIDSU'            => $id,
                        'SDURUTAN'          => $urut++,
                        'SDSUMBER'          => self::SUMBER,
                        'SDITEM'            => $l['item'],
                        'SDMASUK'           => $l['qty'],
                        'SDMASUKD'          => $l['qty'],
                        'SDKELUAR'          => 0,
                        'SDSATUAN'          => $l['satuanDasar'] ?: null,
                        'SDSATUAND'         => $l['satuanDasar'] ?: null,
                        'SDMASUKPB'         => round((float) $l['qty'] / $rasio, 4),
                        'SDSATUANPB'        => $l['satuanPo'] ?: null,
                        'SDGUDANG'          => $header['SUCABANG'],
                        'SDSODID'           => $l['sodid'],
                        'SDHARGA'           => $l['harga'],
                        'SDDISKON'          => $l['disc1'],
                        'SDDISKONPERSEN'    => $l['disc2'],
                        'SDDISKONPERSEN2'   => $l['disc3'],
                        'SDDISKONPERSEN3'   => $l['disc4'],
                        'SDCATATAN'         => $l['catatan'] ?: null,
                        'SDSERIAL'          => $batches === [] ? null : $this->serial->pack($batches),
                    ], 'SDID');

                    // Satu baris `bitemserialhistori` per batch. `bitemserial.ISJUMLAH`/`ISAKTIF`
                    // DIURUS TRIGGER `bitemserialhistori_add` - jangan pernah di-update manual.
                    $this->serial->writeHistori($sdid, (int) $l['item'], $batches, 'masuk');
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan PB - SOFT (`fstokd.SDCANCEL=1` + kosongkan `SDMASUK`/`SDMASUKD`/`SDMASUKPB`
     * + `fstoku.SUSTATUS=9`, pola sama `PbcWriter::cancel()`). Trigger `fstokd_edit`
     * OTOMATIS balikkan stok DAN `esalesorderd.SODMASUK` (baca old vs new = 0).
     *
     * **Baris `bitemserialhistori` DIHAPUS (hard delete), bukan dikosongkan** - TIDAK ADA
     * trigger di `fstokd` yg menyentuh `bitemserial`, jadi kalau baris histori dibiarkan,
     * `bitemserial.ISJUMLAH` tetap menghitung batch yg stoknya sudah dibalik = stok batch
     * jadi hantu. Trigger `bitemserialhistori_del` (BEFORE DELETE) yg membalik `ISJUMLAH`.
     * Ini SENGAJA LEBIH BAIK dari VB6 asli: `fFrmPenerimaanBarang` hanya
     * `delete from fstokd where SDIDSU=...` tanpa menghapus histori, jadi `ISJUMLAH`
     * legacy bocor setiap kali PB berbatch dihapus (dicatat, tidak diperbaiki mundur).
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('PB tidak ditemukan atau sudah dibatalkan.');
                }

                $this->serial->forget(DB::table('fstokd')->where('SDIDSU', $id)->pluck('SDID')->all());

                DB::table('fstokd')->where('SDIDSU', $id)->update([
                    'SDCANCEL'  => 1,
                    'SDMASUK'   => 0,
                    'SDMASUKD'  => 0,
                    'SDMASUKPB' => 0,
                ]);

                DB::table('fstoku')->where('SUID', $id)->update([
                    'SUSTATUS' => self::STATUS_BATAL,
                    'SUMODIFU' => auth()->id(),
                    'SUMODIFD' => now(),
                ]);
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function header(int $id): ?object
    {
        return DB::table('fstoku')->where('SUID', $id)->where('SUSUMBER', self::SUMBER)->first();
    }

    public function lines(int $id): array
    {
        $rows = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->leftJoin('esalesorderd as sod', 'sod.SODID', '=', 'd.SDSODID')
            ->leftJoin('esalesorderu as sou', 'sou.SOUID', '=', 'sod.SODIDSOU')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ISERIAL', 's.SKODE as satuan_kode', 'sou.SOUNOTRANSAKSI as no_po'])
            ->all();

        foreach ($rows as $r) {
            $r->batches = $this->serial->unpack($r->SDSERIAL ?? null);
        }

        return $rows;
    }
}
