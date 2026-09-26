<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Terima Mutasi Barang (TMB) - tabel legacy `fstoku`/`fstokd` (`SUSUMBER='TMB'`), BUKAN
 * tabel terpisah. Tahap TERAKHIR alur PARALEL PR jenis=0: RS -> KMB -> TMB. Dibuat
 * CABANG PENERIMA (= cabang yg ORIGINALLY raise PR, branch context BERPINDAH dari KMB
 * ke sisi penerima - pola SAMA `PbcWriter`). VB6 asli `fFrmPenerimaanMutasi.frm`
 * (folder LAMA `C:\code\DIAS_MYSQL_2026`).
 *
 * SUSUMBER = 'TMB' (aanomor NKODE='TMB'). Nomor: {kodecabang}-TMB{yymm}{NNNN} -
 * kodecabang dari cabang PENERIMA (user login pembuat TMB).
 *
 * **Satu TMB = SATU KMB, FULL-RECEIPT** (tarik SELURUH `SDKELUAR` KMB, TIDAK ada
 * formula sisa/partial - VB6 asli pull `SDKELUAR` mentah tanpa pengurangan apapun) -
 * pola sama persis `PbcWriter`.
 *
 * **TIDAK mengisi `SUPBUID`/`SDPBDID` sama sekali** (beda dari KMB/SJ/PBC yg semua py
 * referensi baris PR) - dikonfirmasi PERSIS dari daftar field VB6 asli. Traceability
 * ke PR PENUH tetap ada tp cuma TRANSITIF: TMB -> `SUPRUID` -> KMB -> `SUPBUID` -> PR.
 * Konsekuensi: TMB TIDAK mempengaruhi `fpermintaanbarangu.PBUSTATUSKM` sama sekali
 * (kolom itu hanya bereaksi ke `SUPBUID`, lihat docblock `KmbWriter`) - `PBUSTATUSKM`
 * PR TETAP 1 selama-lamanya sampai KMB-nya sendiri dibatalkan, TIDAK PERNAH balik ke 0
 * hanya krn TMB selesai. `fpermintaanbarangu.PBUSTATUS` JUGA TIDAK PERNAH disentuh TMB
 * (nol match di VB6 asli) - SAMA prinsip dgn KMB.
 *
 * Efek simpan: `UPDATE fstoku SET SUSTATUS=3 WHERE SUID=[kmb id]` (tandai KMB
 * "Diterima", `KmbWriter::STATUS_DITERIMA` - konvensi 1/3/9 SAMA PERSIS `SjWriter`).
 * Efek batal: KMB balik `SUSTATUS=1` (aktif, bisa diterima TMB baru lagi).
 *
 * Trigger stok generic `fstokd_add`/`_edit`/`_DELL` (SAMA POS/SJ/PBC) otomatis urus
 * stok arah MASUK (`SDGUDANG`=cabang penerima) - TIDAK perlu kode manual. Krn baris
 * TMB TIDAK py `SDPBDID`, TIDAK memicu taint `PBDQTYTERIMA` sama sekali (beda dari
 * SJ/PBC/KMB).
 *
 * **BATCH/SERIAL (2026-09-25, permintaan user)**: wajib kalau **gudang PENERIMA**
 * (`SUCABANG`, = `SDGUDANG` baris TMB) ber-`GPAKAISERIAL=1` DAN item ber-`ISERIAL=1`.
 * Mekanisme di `SerialBatch`; arah **'masuk'** (`ISHMASUK`) - sama spt VB6
 * `fFrmPenerimaanMutasi.frm` baris 758-786. `cancel()` memanggil `SerialBatch::forget()`.
 *
 * **Batch DIWARISI dari baris KMB** (`fromKmb()` membaca `fstokd.SDSERIAL` dokumen pengirim,
 * persis VB6 baris 539 yg ikut menarik `SDSERIAL`). **TAPI `KmbWriter` kita BELUM menulis
 * `SDSERIAL`**, jadi untuk sekarang warisan itu selalu kosong dan user TMB mengetik no batch
 * manual dari fisik barang. Begitu KMB dikasih input batch, pewarisan ini jalan sendiri
 * tanpa ubah kode. **Konsekuensi yg perlu disadari selama KMB belum punya batch**: stok batch
 * di gudang ASAL tidak berkurang saat barang dikirim, jadi angka batch gudang asal akan
 * menggelembung sampai sisi KMB dilengkapi.
 */
class TmbWriter
{
    public const SUMBER = 'TMB';

    public const STATUS_BATAL = 9;

    public function __construct(private KmbWriter $kmbWriter, private SerialBatch $serial)
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
     * Data siap-terima dari 1 KMB (dipanggil TmbForm::mount(kmbId:) utk TMB baru):
     * header KMB + SEMUA baris (full-receipt, TIDAK difilter sisa - lihat docblock
     * kelas). Re-cek server-side `SUGUDANGTUJUAN` KMB = cabang user login SEKARANG
     * (BUKAN cuma andalkan filter picker `KmbWriter::pullableForTmb()`) - cegah user
     * "mencuri" terima KMB milik cabang lain lewat tab URL langsung.
     *
     * @return array{header:object,lines:array}|null null kalau KMB tidak valid/
     *         SUSTATUS<>1/bukan tujuan cabang user login.
     */
    public function fromKmb(int $kmbId): ?array
    {
        $header = $this->kmbWriter->header($kmbId);
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);
        if (! $header || (int) $header->SUSTATUS !== 1 || (int) $header->SUGUDANGTUJUAN !== $ucabang) {
            return null;
        }

        $kmbLines = $this->kmbWriter->lines($kmbId);
        $wajibBatch = $this->serial->wajibBatch(
            array_map(fn ($l) => (int) $l->SDITEM, $kmbLines),
            $ucabang // gudang PENERIMA - di situlah barangnya masuk
        );

        $lines = [];
        foreach ($kmbLines as $l) {
            $qty = (float) $l->SDKELUAR;
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qtyKirim'   => $qty,
                'qty'        => $qty,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
                'serial'     => in_array((int) $l->SDITEM, $wajibBatch, true),
                // Batch DIWARISI dari baris KMB kalau ada (VB6 `fFrmPenerimaanMutasi` baris
                // 539 ikut menarik `SDSERIAL` dokumen pengirim). KmbWriter kita BELUM
                // menulis `SDSERIAL`, jadi praktisnya masih kosong -> user mengetik manual.
                'batches'    => $this->serial->unpack($l->SDSERIAL ?? null),
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS/SUPRUID)
     * @param list<array{item:int,qty:float,satuan:?int,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string,kmbId:int} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $kmbId = $meta['kmbId'];
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $kmbId) {
                // Re-cek server-side KMB SAAT INI masih aktif DAN memang ditujukan ke
                // cabang yg sedang menyimpan (SUCABANG TMB = SUGUDANGTUJUAN KMB) - cegah
                // race/dobel-terima DAN cegah "mencuri" KMB cabang lain (per instruksi
                // user 2026-09-22, lihat jg `fromKmb()`).
                $kmb = $this->kmbWriter->header($kmbId);
                if (! $kmb || (int) $kmb->SUSTATUS !== 1 || (int) $kmb->SUGUDANGTUJUAN !== (int) $header['SUCABANG']) {
                    throw new \RuntimeException('KMB sudah diterima/dibatalkan, atau bukan tujuan cabang Anda.');
                }

                $qtyKirimByItem = [];
                foreach ($this->kmbWriter->lines($kmbId) as $l) {
                    $qtyKirimByItem[(int) $l->SDITEM] = (float) $l->SDKELUAR;
                }

                // Batch WAJIB kalau gudang PENERIMA ber-GPAKAISERIAL=1 + item ISERIAL=1
                // (dibaca ulang dari DB, tidak percaya flag form).
                $wajibBatch = $this->serial->wajibBatch(
                    array_map(fn ($l) => (int) $l['item'], $lines),
                    (int) $header['SUCABANG']
                );

                foreach ($lines as $l) {
                    $qty = (float) $l['qty'];
                    $qtyKirim = $qtyKirimByItem[(int) $l['item']] ?? 0;
                    if ($qty <= 0 || $qty > $qtyKirim + 0.0001) {
                        throw new \RuntimeException("Qty diterima untuk item melebihi qty dikirim ({$qtyKirim}).");
                    }

                    if (! in_array((int) $l['item'], $wajibBatch, true)) {
                        continue;
                    }
                    $batches = $this->serial->clean($l['batches'] ?? []);
                    $nama = $l['nama'] ?? ('item #' . $l['item']);
                    if ($batches === []) {
                        throw new \RuntimeException("Item \"{$nama}\" wajib diisi No Batch.");
                    }
                    $total = array_sum(array_column($batches, 'qty'));
                    if (abs($total - $qty) > 0.0001) {
                        throw new \RuntimeException("Total qty batch ({$total}) tidak sama dengan qty diterima ({$qty}) pada \"{$nama}\".");
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUPRUID'] = $kmbId;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                // Insert baris fstokd - DB TRIGGER `fstokd_add` OTOMATIS urus stok
                // (arah MASUK). TIDAK ada SDPBDID (lihat docblock kelas).
                $urut = 1;
                foreach ($lines as $l) {
                    $batches = in_array((int) $l['item'], $wajibBatch, true)
                        ? $this->serial->clean($l['batches'] ?? [])
                        : [];

                    $sdid = (int) DB::table('fstokd')->insertGetId([
                        'SDIDSU'     => $id,
                        'SDURUTAN'   => $urut++,
                        'SDSUMBER'   => self::SUMBER,
                        'SDITEM'     => $l['item'],
                        'SDMASUK'    => $l['qty'],
                        'SDMASUKD'   => $l['qty'],
                        'SDSATUAN'   => $l['satuan'] ?: null,
                        'SDSATUAND'  => $l['satuan'] ?: null,
                        'SDGUDANG'   => $header['SUCABANG'],
                        'SDCATATAN'  => $l['catatan'] ?: null,
                        'SDSERIAL'   => $batches === [] ? null : $this->serial->pack($batches),
                    ], 'SDID');

                    // Arah MASUK (`ISHMASUK`) - `bitemserial.ISJUMLAH` diurus trigger.
                    $this->serial->writeHistori($sdid, (int) $l['item'], $batches, 'masuk');
                }

                // KMB sumber -> "Diterima".
                DB::table('fstoku')->where('SUID', $kmbId)->update(['SUSTATUS' => KmbWriter::STATUS_DITERIMA]);

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan TMB - SOFT (`fstokd.SDCANCEL=1`+nolkan `SDMASUK`/`SDMASUKD`+`fstoku.
     * SUSTATUS=9`, pola sama `PbcWriter::cancel()`). KMB sumber dikembalikan ke
     * "Aktif" (1, bisa diterima TMB baru lagi).
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('TMB tidak ditemukan atau sudah dibatalkan.');
                }

                // Histori batch DIHAPUS spy trigger `bitemserialhistori_del` membalik stok
                // batch - tidak ada trigger di `fstokd` yg melakukannya (pola sama PB/SJ/PRO).
                $this->serial->forget(DB::table('fstokd')->where('SDIDSU', $id)->pluck('SDID')->all());

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

                if ($h->SUPRUID) {
                    DB::table('fstoku')->where('SUID', $h->SUPRUID)->where('SUSTATUS', KmbWriter::STATUS_DITERIMA)
                        ->update(['SUSTATUS' => 1]);
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
}
