<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penerimaan Barang Cabang (PBC) - tahap TERAKHIR alur PR/RS → PKB → SJ → PBC. Tabel
 * legacy `fstoku`/`fstokd` (`SUSUMBER='PBC'`), BUKAN tabel terpisah. PERTAMA KALI dlm
 * rantai ini, pembuat transaksi = CABANG PENERIMA (bukan gudang pengirim spt PR/PKB/SJ).
 *
 * SUSUMBER = 'PBC' (aanomor NKODE='PBC', diseed migration terpisah). Nomor:
 * {kodecabang}-PBC{yymm}{NNNN} - kodecabang dari cabang PENERIMA (user login SEKARANG).
 *
 * SUSTATUS: 1 = normal/aktif (konvensi sama SJ - dikonfirmasi data NYATA hasil import,
 * SEMUA baris PBC real cuma berisi 1), 9 = batal (konvensi universal kita).
 *
 * **Satu PBC = SATU SJ, FULL-RECEIPT** (bukan partial) - beda dari PR→PKB & PKB→SJ yg
 * mendukung penuh partial/bertahap. `fstokd.SDQTPAKAI` yg SEHARUSNYA melacak sisa
 * penerimaan per-SJ TERBUKTI (`SHOW TRIGGERS`+`ROUTINES`+data nyata: 5000 baris SJ
 * SEMUA `SDQTPAKAI=0`) TIDAK PERNAH ditulis manapun di sistem lama - fitur setengah
 * jadi, TIDAK direplikasi.
 *
 * **`SDPRDID` SENGAJA TIDAK PERNAH DIISI** - kolom ini di-OVERLOAD oleh DB TRIGGER
 * `fstokd_add`/`_edit`/`_DELL` utk tujuan SAMA SEKALI BEDA & TIDAK TERKAIT:
 * `update official_nmw.ops_invoice_header set ivhStatusDIAS=1 where ivhId=NEW.SDPRDID`
 * (dikonfirmasi `information_schema.TRIGGERS` + data nyata: PBC asli VB6 mengisi
 * `SDPRDID` dgn id baris SJ, TAPI trigger membaca kolom yg SAMA sbg id invoice sistem
 * `official_nmw` - TERPISAH TOTAL dari modul kita). Kalau diisi apa adanya, akan memicu
 * UPDATE tak disengaja ke database lain. Traceability ke SJ cukup via `SUNOSJAPOTIK`
 * (header-level) - granularitas per-baris ke SJ hilang, tp AMAN.
 *
 * Trigger `fstokd_add` (generic, SAMA yg dipakai POS/SJ) OTOMATIS menambah stok `bitem`
 * (arah MASUK krn `SDMASUK` diisi, `SDKELUAR` tidak) + `fpermintaanbarangd.PBDQTYTERIMA`
 * (VIA `SDPBDID` - TERCEMAR, sudah kena tambahan dari SJ jg, lihat
 * `PurchaseRequestWriter::totalDiterima()` utk formula yg BENAR yg dipakai di sini
 * sbg gantinya).
 *
 * Efek balik saat PBC dibuat: SJ sumber → `SUSTATUS=3` ("Diterima", lihat
 * `SjWriter::STATUS_DITERIMA`), PR → status 5/6 via
 * `PurchaseRequestWriter::syncStatusAfterReceive()`.
 */
class PbcWriter
{
    public const SUMBER = 'PBC';

    public const STATUS_BATAL = 9;

    public function __construct(private SjWriter $sjWriter, private PurchaseRequestWriter $prWriter)
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
     * Data siap-terima dari 1 SJ (dipanggil PbcForm::mount(sjId:) utk PBC baru): header
     * SJ + SEMUA baris (full-receipt, TIDAK difilter sisa - lihat docblock kelas).
     *
     * @return array{header:object,lines:array}|null null kalau SJ tidak valid/SUSTATUS<>1.
     */
    public function fromSj(int $sjId): ?array
    {
        $header = $this->sjWriter->header($sjId);
        if (! $header || (int) $header->SUSTATUS !== 1) {
            return null;
        }

        $lines = [];
        foreach ($this->sjWriter->lines($sjId) as $l) {
            $qty = (float) $l->SDKELUAR;
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'pbdid'      => $l->SDPBDID ? (int) $l->SDPBDID : null,
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qtyKirim'   => $qty,
                'qty'        => $qty,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS)
     * @param list<array{pbdid:?int,item:int,qty:float,satuan:?int,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string,sjId:int} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $sjId = $meta['sjId'];
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $sjId) {
                // Re-cek server-side SJ SAAT INI masih aktif (belum diterima/dibatalkan
                // PBC lain) - cegah race/dobel-terima.
                $sj = $this->sjWriter->header($sjId);
                if (! $sj || (int) $sj->SUSTATUS !== 1) {
                    throw new \RuntimeException('SJ sudah diterima atau dibatalkan.');
                }

                $qtyKirimByItem = [];
                foreach ($this->sjWriter->lines($sjId) as $l) {
                    $qtyKirimByItem[(int) $l->SDITEM] = (float) $l->SDKELUAR;
                }

                $prIds = [];
                foreach ($lines as $l) {
                    $qty = (float) $l['qty'];
                    $qtyKirim = $qtyKirimByItem[(int) $l['item']] ?? 0;
                    if ($qty <= 0 || $qty > $qtyKirim + 0.0001) {
                        throw new \RuntimeException("Qty diterima untuk item melebihi qty dikirim ({$qtyKirim}).");
                    }
                    if ($l['pbdid']) {
                        $prId = (int) DB::table('fpermintaanbarangd')->where('PBDID', $l['pbdid'])->value('PBDIDSU');
                        if ($prId) {
                            $prIds[$prId] = true;
                        }
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUNOSJAPOTIK'] = $sjId;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                // Insert baris fstokd - DB TRIGGER `fstokd_add` OTOMATIS urus stok
                // (SDMASUK, arah masuk). `SDPRDID` SENGAJA TIDAK DIISI - lihat docblock
                // kelas soal bahaya trigger `official_nmw.ops_invoice_header`.
                $urut = 1;
                foreach ($lines as $l) {
                    DB::table('fstokd')->insert([
                        'SDIDSU'     => $id,
                        'SDURUTAN'   => $urut++,
                        'SDSUMBER'   => self::SUMBER,
                        'SDITEM'     => $l['item'],
                        'SDMASUK'    => $l['qty'],
                        'SDMASUKD'   => $l['qty'],
                        'SDSATUAN'   => $l['satuan'] ?: null,
                        'SDSATUAND'  => $l['satuan'] ?: null,
                        'SDGUDANG'   => $header['SUCABANG'],
                        'SDPBDID'    => $l['pbdid'] ?: null,
                        'SDCATATAN'  => $l['catatan'] ?: null,
                    ]);
                }

                // SJ sumber -> "Diterima", PR terkait -> status 5/6 (formula sendiri,
                // TIDAK pakai PBDQTYTERIMA yg tercemar - lihat docblock kelas).
                DB::table('fstoku')->where('SUID', $sjId)->update(['SUSTATUS' => SjWriter::STATUS_DITERIMA]);
                foreach (array_keys($prIds) as $prId) {
                    $this->prWriter->syncStatusAfterReceive($prId);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan PBC - SOFT (`fstokd.SDCANCEL=1` + kosongkan `SDMASUK`/`SDMASUKD` + SET
     * `fstoku.SUSTATUS=9`, pola SAMA `SjWriter::cancel()`). SJ sumber dikembalikan ke
     * "Aktif" (1, bisa diterima lagi via PBC baru), PR dihitung ulang statusnya via
     * `PurchaseRequestWriter::releaseOnCancelPbc()`.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('PBC tidak ditemukan atau sudah dibatalkan.');
                }

                $prIds = [];
                foreach ($this->lines($id) as $l) {
                    if ($l->SDPBDID) {
                        $prId = (int) DB::table('fpermintaanbarangd')->where('PBDID', $l->SDPBDID)->value('PBDIDSU');
                        if ($prId) {
                            $prIds[$prId] = true;
                        }
                    }
                }

                DB::table('fstokd')->where('SDIDSU', $id)->update([
                    'SDCANCEL'  => 1,
                    'SDMASUK'   => 0,
                    'SDMASUKD'  => 0,
                ]);

                DB::table('fstoku')->where('SUID', $id)->update([
                    'SUSTATUS' => self::STATUS_BATAL,
                    'SUMODIFU' => auth()->id(),
                    'SUMODIFD' => now(),
                ]);

                if ($h->SUNOSJAPOTIK) {
                    DB::table('fstoku')->where('SUID', $h->SUNOSJAPOTIK)->where('SUSTATUS', SjWriter::STATUS_DITERIMA)
                        ->update(['SUSTATUS' => 1]);
                }

                foreach (array_keys($prIds) as $prId) {
                    $this->prWriter->releaseOnCancelPbc($prId);
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
        return DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode'])
            ->all();
    }
}
