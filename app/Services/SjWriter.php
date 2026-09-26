<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Surat Jalan (SJ) - tabel legacy `fstoku`/`fstokd` (`SUSUMBER='SJ'`), BUKAN tabel
 * terpisah (beda dari PR/PKB) - konfirmasi dari `aanomor` NID 718 & VB6 asli
 * `fFrmSuratJalanDepo_CL.frm`. SJ mengonfirmasi barang BENERAN dikirim dari gudang
 * pengirim berdasar PKB yg sudah dibuat. Satu SJ = satu PKB (`SUNOSO`), tapi satu PKB
 * BOLEH dikirim via BEBERAPA SJ scr bertahap selama masih ada sisa qty (PERSIS pola
 * PR→PKB, lihat `PurchaseRequestWriter`/`PkbWriter`).
 *
 * SUSUMBER = 'SJ' (aanomor NID 718). Nomor: {kodecabang}-SJ{yymm}{NNNN} - kodecabang
 * dari cabang PEMBUAT SJ (SAMA gudang yg buat PKB terkait).
 *
 * SUSTATUS: 1 = normal/aktif (BUKAN 0 - kolom ini utk SJ konvensinya TERBALIK dari
 * "0=aktif" yg biasa dipakai sumber fstoku lain, lihat VB6 asli
 * `IIf(chkPending.Value=0,1,0)`; chkPending tersembunyi/tak dipakai form ini shg
 * PRAKTEKNYA selalu 1), 3 = SUDAH DITERIMA cabang tujuan via PBC (dikonfirmasi data
 * NYATA hasil import user 2026-09-18: SJ real cuma pernah berisi 1/3, ditulis
 * `PbcWriter::create()`/`cancel()`, LIHAT kelas itu), 9 = batal (konvensi UNIVERSAL kita
 * sendiri, sama semua sumber fstoku, TIDAK bagian skema legacy di atas).
 *
 * `SDPBDID` (baris fstokd) = `PBDID` PR asal (traceability 3-level PR→PKB→SJ),
 * `SDSODID` = `PKBDID` PKB asal (field KUNCI tracking sisa PKB). DB TRIGGER
 * `fstokd_add`/`_edit`/`_DELL` (SAMA generic trigger yg dipakai POS - dicek `SHOW
 * TRIGGERS`) OTOMATIS meng-update 3 hal tiap kali baris `fstokd` berubah:
 *   1. Stok `bitem` sesuai `SDGUDANG` (mekanisme sama persis POS).
 *   2. `fpermintaanbarangd.PBDQTYTERIMA += (SDMASUK+SDKELUAR)` via `SDPBDID`.
 *   3. `fperintahkirimbarangd.PKBDQTYPAKAI += SDKELUAR` via `SDSODID`.
 * JANGAN tulis manual ke 3 kolom itu - trigger sudah handle (pelajaran yg sama dari
 * kesalahan awal `PkbWriter::create()` yg sempat manual increment `PBDQTYPAKAI`).
 *
 * Efek balik ke PR saat SJ dibuat: `PBUSTATUS` PR → 4 ("Sedang Dikirim", label VB6 asli
 * `fFrmPermintaanbarangData.frm`), ditulis manual di `create()` (TIDAK ada trigger utk
 * ini). Saat SJ dibatalkan, balik ke 3.
 *
 * Pembatalan: TIDAK hard-delete (beda dari VB6 asli yg literally `DELETE FROM
 * fstokd/fstoku`) - ikut pola `PosSaleWriter::replace()` yg SUDAH established di app
 * ini: `fstokd.SDCANCEL=1` (trigger `fstokd_edit` balik stok, bagian stok di-gate
 * `WHERE NEW.SDCANCEL=0`) + `fstoku.SUSTATUS=9`. **Nuance PENTING**: bagian trigger yg
 * urus `PBDQTYTERIMA`/`PKBDQTYPAKAI` TIDAK di-gate `SDCANCEL` - itu murni delta
 * `NEW-OLD` dari `SDMASUK`/`SDKELUAR`, jadi `cancel()` di sini SENGAJA JUGA nge-nolkan
 * `SDKELUAR`/`SDKELUARD` (bukan cuma set `SDCANCEL=1`) spy trigger yg SAMA otomatis
 * membalik `PBDQTYTERIMA`/`PKBDQTYPAKAI` sekaligus, tanpa bookkeeping manual dobel.
 *
 * **BATCH/SERIAL (2026-09-25)**: item `bitem.ISERIAL=1` WAJIB DIPILIH batch-nya sblm kirim -
 * beda dari PB yg MENGETIK batch baru, SJ MEMILIH dari batch yg stoknya masih ada di gudang
 * asal (`SerialBatch::available()`, query asli VB6 `PilihSerial` dari user). Mekanisme
 * simpan (SDSERIAL + `bitemserialhistori` + trigger) ada di `SerialBatch`; di sini arah
 * 'keluar' (isi `ISHKELUAR`). **3 lapis validasi server-side di `create()`**: batch wajib
 * ada, total batch == qty dikirim, DAN tiap batch dicek ulang stoknya di gudang asal SAAT
 * ITU (termasuk akumulasi lintas baris dlm satu SJ) - dokumen keluar tidak boleh menarik
 * batch yg sudah habis diambil dokumen lain. `cancel()` memanggil `SerialBatch::forget()`.
 */
class SjWriter
{
    public const SUMBER = 'SJ';

    public const STATUS_DITERIMA = 3;

    public const STATUS_BATAL = 9;

    public function __construct(private PkbWriter $pkbWriter, private SerialBatch $serial)
    {
    }

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
     * Data siap-kirim dari 1 PKB (dipanggil SjForm::mount(pkbId:) utk SJ baru): header
     * PKB + baris sisa qty (PKBDQTY-PKBDQTYPAKAI) yg masih > 0, plus cabang tujuan (dari
     * PR terkait via PKBUNORS).
     *
     * @return array{header:object,tujuanGudang:?int,noPr:?string,lines:array}|null null
     *         kalau PKB tidak valid/PKBUSTATUS<>0.
     */
    public function fromPkb(int $pkbId, ?int $gudangAsal = null): ?array
    {
        $header = $this->pkbWriter->header($pkbId);
        if (! $header || (int) $header->PKBUSTATUS !== 0) {
            return null;
        }

        $pr = $header->PKBUNORS
            ? DB::table('fpermintaanbarangu')->where('PBUID', $header->PKBUNORS)->first(['PBUGUDANG', 'PBUNOTRANSAKSI'])
            : null;

        $lines = [];
        foreach ($this->pkbWriter->lines($pkbId) as $l) {
            $sisa = (float) $l->PKBDQTY - (float) $l->PKBDQTYPAKAI;
            if ($sisa <= 0) {
                continue;
            }
            $lines[] = [
                'sodid'      => (int) $l->PKBDID,
                'pbdid'      => $l->PKBDRSIDD ? (int) $l->PKBDRSIDD : null,
                'item'       => (int) $l->PKBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PKBDITEM),
                'sisa'       => $sisa,
                'qty'        => $sisa,
                'satuan'     => $l->PKBDSATUAN ? (int) $l->PKBDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
                'serial'     => (int) ($l->ISERIAL ?? 0) === 1 && $this->serial->gudangPakaiSerial($gudangAsal),
                'batches'    => [],
            ];
        }

        return [
            'header'       => $header,
            'tujuanGudang' => $pr->PBUGUDANG ? (int) $pr->PBUGUDANG : null,
            'noPr'         => $pr->PBUNOTRANSAKSI ?? null,
            'lines'        => $lines,
        ];
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS)
     * @param list<array{sodid:int,pbdid:?int,item:int,qty:float,satuan:?int,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string,pkbId:int} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $pkbId = $meta['pkbId'];
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $pkbId) {
                // Re-cek server-side sisa PKB SAAT INI - cegah race/overdraw kalau ada
                // SJ lain menarik PKB yg sama duluan.
                $sisaBySodid = [];
                foreach ($this->pkbWriter->lines($pkbId) as $l) {
                    $sisaBySodid[(int) $l->PKBDID] = (float) $l->PKBDQTY - (float) $l->PKBDQTYPAKAI;
                }

                // Item mana yg WAJIB batch - 2 syarat (gudang asal GPAKAISERIAL=1 + item
                // ISERIAL=1), dibaca ulang dari DB, tidak percaya flag form.
                $gudangAsal = (int) $header['SUCABANG'];
                $serialItems = $this->serial->wajibBatch(array_column($lines, 'item'), $gudangAsal);
                $terpakai = []; // [item][noBatch] => qty, utk cek total lintas baris

                $prId = null;
                foreach ($lines as $l) {
                    $sodid = (int) $l['sodid'];
                    $qty = (float) $l['qty'];
                    $sisa = $sisaBySodid[$sodid] ?? 0;
                    if ($qty <= 0 || $qty > $sisa + 0.0001) {
                        throw new \RuntimeException("Qty dikirim untuk item melebihi sisa yang tersedia (sisa: {$sisa}).");
                    }

                    if (! in_array((int) $l['item'], $serialItems, true)) {
                        continue;
                    }

                    $batches = $this->serial->clean($l['batches'] ?? []);
                    $nama = $l['nama'] ?? ('item #' . $l['item']);
                    if ($batches === []) {
                        throw new \RuntimeException("Batch untuk \"{$nama}\" belum dipilih.");
                    }
                    $totalBatch = array_sum(array_column($batches, 'qty'));
                    if (abs($totalBatch - $qty) > 0.0001) {
                        throw new \RuntimeException("Total batch ({$totalBatch}) tidak sama dengan qty dikirim ({$qty}) pada \"{$nama}\".");
                    }

                    // Re-cek stok TIAP batch di gudang asal SAAT INI - dokumen KELUAR tidak
                    // boleh mengambil batch yg stoknya sudah habis diambil dokumen lain.
                    foreach ($batches as $b) {
                        $pakai = ($terpakai[(int) $l['item']][$b['noBatch']] ?? 0) + (float) $b['qty'];
                        $ada = $this->serial->availableOf((int) $l['item'], $gudangAsal, $b['noBatch']);
                        if ($pakai > $ada + 0.0001) {
                            throw new \RuntimeException("Stok batch {$b['noBatch']} untuk \"{$nama}\" tinggal {$ada} (diminta {$pakai}).");
                        }
                        $terpakai[(int) $l['item']][$b['noBatch']] = $pakai;
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUJENISSURATJALAN'] = 2;
                $header['SUSTATUS'] = 1;
                $header['SUNOSO'] = $pkbId;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                // Insert baris fstokd - DB TRIGGER `fstokd_add` OTOMATIS urus stok +
                // PBDQTYTERIMA (via SDPBDID) + PKBDQTYPAKAI (via SDSODID). JANGAN
                // duplikasi manual di sini (lihat docblock kelas).
                $urut = 1;
                foreach ($lines as $l) {
                    $batches = in_array((int) $l['item'], $serialItems, true)
                        ? $this->serial->clean($l['batches'] ?? [])
                        : [];

                    $sdid = (int) DB::table('fstokd')->insertGetId([
                        'SDIDSU'     => $id,
                        'SDURUTAN'   => $urut++,
                        'SDSUMBER'   => self::SUMBER,
                        'SDITEM'     => $l['item'],
                        'SDKELUAR'   => $l['qty'],
                        'SDKELUARD'  => $l['qty'],
                        'SDSATUAN'   => $l['satuan'] ?: null,
                        'SDSATUAND'  => $l['satuan'] ?: null,
                        'SDGUDANG'   => $header['SUCABANG'],
                        'SDPBDID'    => $l['pbdid'] ?: null,
                        'SDSODID'    => $l['sodid'],
                        'SDCATATAN'  => $l['catatan'] ?: null,
                        'SDSERIAL'   => $batches === [] ? null : $this->serial->pack($batches),
                    ], 'SDID');

                    // Dokumen KELUAR -> isi `ISHKELUAR`. Trigger `bitemserialhistori_add` yg
                    // menurunkan `bitemserial.ISJUMLAH` (jangan update manual).
                    $this->serial->writeHistori($sdid, (int) $l['item'], $batches, 'keluar');

                    if ($l['pbdid']) {
                        $prId ??= (int) DB::table('fpermintaanbarangd')->where('PBDID', $l['pbdid'])->value('PBDIDSU');
                    }
                }

                if ($prId) {
                    DB::table('fpermintaanbarangu')->where('PBUID', $prId)->whereIn('PBUSTATUS', [2, 3])
                        ->update(['PBUSTATUS' => 4]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan SJ - SOFT (`fstokd.SDCANCEL=1` + kosongkan `SDKELUAR`/`SDKELUARD` + SET
     * `fstoku.SUSTATUS=9`, lihat docblock kelas soal alasan `SDKELUAR` ikut dinolkan).
     * PR terkait (via `SDPBDID`) dikembalikan ke status 3 kalau sblmnya 4. Hanya SJ
     * berstatus 1 (belum batal) yg bisa dibatalkan.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('SJ tidak ditemukan atau sudah dibatalkan.');
                }

                $prId = null;
                foreach ($this->lines($id) as $l) {
                    if ($l->SDPBDID) {
                        $prId ??= (int) DB::table('fpermintaanbarangd')->where('PBDID', $l->SDPBDID)->value('PBDIDSU');
                    }
                }

                // Histori batch DIHAPUS (hard delete) spy trigger `bitemserialhistori_del`
                // mengembalikan stok batch - tidak ada trigger di `fstokd` yg melakukannya,
                // jadi kalau dibiarkan, batch yg dikirim tetap tercatat berkurang selamanya.
                $this->serial->forget(DB::table('fstokd')->where('SDIDSU', $id)->pluck('SDID')->all());

                DB::table('fstokd')->where('SDIDSU', $id)->update([
                    'SDCANCEL'  => 1,
                    'SDKELUAR'  => 0,
                    'SDKELUARD' => 0,
                ]);

                DB::table('fstoku')->where('SUID', $id)->update([
                    'SUSTATUS' => self::STATUS_BATAL,
                    'SUMODIFU' => auth()->id(),
                    'SUMODIFD' => now(),
                ]);

                if ($prId) {
                    DB::table('fpermintaanbarangu')->where('PBUID', $prId)->where('PBUSTATUS', 4)
                        ->update(['PBUSTATUS' => 3]);
                }
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
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ISERIAL', 's.SKODE as satuan_kode'])
            ->all();

        foreach ($rows as $r) {
            $r->batches = $this->serial->unpack($r->SDSERIAL ?? null);
        }

        return $rows;
    }

    /**
     * SJ yg boleh "diterima" via PBC baru: belum batal & belum diterima (`SUSTATUS=1`).
     * TIDAK ada filter sisa qty (beda dari `PkbWriter::pullableForSj()`) - PBC v1 =
     * full-receipt sekali jalan, `SDQTPAKAI` (yg SEHARUSNYA lacak sisa per-SJ) terbukti
     * (`SHOW TRIGGERS`+data nyata) tidak pernah ditulis manapun di sistem lama, jadi
     * partial-receiving TIDAK direplikasi. Dipakai picker "Terima dari SJ" di `PbcList`.
     */
    public function pullableForPbc()
    {
        return DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', self::SUMBER)
            ->where('u.SUSTATUS', 1)
            ->orderByDesc('u.SUID')
            ->get(['u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal', 'k.KNAMA as karyawan']);
    }
}
