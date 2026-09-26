<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Produksi (PRO) - EKSEKUSI NYATA hasil manufaktur: tabel legacy `fstoku`/`fstokd` (SAMA
 * infra SJ/PBC/KMB/TMB), `SUSUMBER='PRO'`. Konsumsi bahan baku (KELUAR, Gudang Produksi)
 * + produk jadi MASUK (LANGSUNG ke Gudang Jadi - Keputusan user 2026-09-23, BUKAN pola
 * VB6 asli "masuk-lalu-keluar netral di Gudang Produksi" krn form penerimaan di Gudang
 * Jadi TIDAK ada/TIDAK diminta). VB6 asli:
 * `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\fFrmProduksi.frm`.
 *
 * SUSUMBER = 'PRO' (aanomor NKODE='PRO'). Nomor: {kodecabang}-PRO{yymm}{NNNN} -
 * kodecabang dari Gudang Produksi (cabang pembuat/login, SAMA yg dipakai JOP terkait).
 *
 * SUSTATUS: 1 = Aktif, 9 = Batal (konvensi universal kita).
 *
 * `SUIDJOP` (header, OPSIONAL) = referensi ke 1 Job Order (`JopWriter`) - satu Produksi
 * BOLEH menarik BEBERAPA baris sisa dari SATU JOP yg sama, ATAU dibuat BEBAS (freeform,
 * `SUIDJOP=null`) tanpa referensi apapun. `SDIDJOP` (per baris `fstokd` produk jadi,
 * OPSIONAL per baris) = `PDID` baris JOP spesifik yg ditarik - DB TRIGGER
 * `fstokd_add/_edit/_DELL` (GENERIC, SAMA dipakai POS/SJ/PBC/KMB/TMB) OTOMATIS maintain
 * `fproduksid.PDMASUKPAKAI`/`PDKELUARPAKAI` via kolom ini (TERBUKTI HIDUP, dicek
 * `information_schema.TRIGGERS` - lihat docblock `JopWriter`). JANGAN pernah tulis
 * manual ke 2 kolom itu.
 *
 * Trigger stok generic jg urus stok `bitem` per `SDGUDANG`: baris bahan baku KELUAR di
 * Gudang Produksi (`SUCABANG`), baris produk jadi MASUK LANGSUNG di Gudang Jadi
 * (`SUGUDANGTUJUAN`), baris sample (opsional) MASUK di Gudang Sample (`SUGUDANGASAL`).
 *
 * Pembatalan: SOFT (`fstokd.SDCANCEL=1`+nolkan SEMUA `SDMASUK`/`SDMASUKD`/`SDKELUAR`/
 * `SDKELUARD` baris terkait+`fstoku.SUSTATUS=9`, pola SAMA SjWriter/PbcWriter/KmbWriter/
 * TmbWriter). **Nuance PENTING (SAMA pola SJ)**: bagian trigger yg urus
 * `PDMASUKPAKAI`/`PDKELUARPAKAI` TIDAK di-gate `SDCANCEL` - murni delta `NEW-OLD` dari
 * `SDMASUK`/`SDKELUAR`, jadi nolkan qty (bukan cuma set `SDCANCEL=1`) OTOMATIS membalik
 * `PDMASUKPAKAI`/`PDKELUARPAKAI` sekaligus tanpa bookkeeping manual dobel - method
 * `cancel()` di sini HANYA perlu panggil `JopWriter::releaseOnCancelPro()` SETELAHNYA
 * utk re-hitung status JOP dari kolom yg SUDAH ter-update trigger.
 *
 * **BATCH/SERIAL - HANYA BARIS PRODUK JADI** (2026-09-25, permintaan user). Mekanismenya di
 * `SerialBatch`; wajib kalau **Gudang Jadi** (`SUGUDANGTUJUAN`, = `SDGUDANG` baris itu)
 * ber-`GPAKAISERIAL=1` DAN item ber-`ISERIAL=1`. Produk jadi = stok BARU, jadi user MENGETIK
 * no batch (pola PB), bukan memilih batch bersisa (pola SJ). Arah 'masuk' (`ISHMASUK`).
 *
 * **Baris BAHAN BAKU sengaja TIDAK berbatch** - VB6 pun tidak (2 blok `If xPakaiSerial` di
 * `fFrmProduksi.frm` baris 1064 & 1108 KEDUANYA di bagian produk jadi, tidak ada di loop
 * komposisi). **Konsekuensi yg perlu disadari**: kalau bahan baku ber-ISERIAL=1 dikeluarkan
 * dari Gudang Produksi yg ber-GPAKAISERIAL=1, stok batch bahan baku itu TIDAK ikut berkurang
 * (stok `bitem` tetap benar krn diurus trigger `fstokd`). Ini warisan VB6, dibiarkan sampai
 * user memutuskan sebaliknya.
 *
 * **BEDA dari VB6 (lanjutan Keputusan user 2026-09-23)**: VB6 menulis DUA baris `fstokd` per
 * produk jadi dgn string batch yg SAMA - masuk ke Gudang Produksi lalu langsung keluar lagi
 * ("akan ditarik oleh gudang jadi"), sehingga efek netto ke `bitemserial.ISJUMLAH` = NOL.
 * Modul kita cuma punya SATU baris (masuk LANGSUNG ke Gudang Jadi), jadi batch-nya benar2
 * bertambah di Gudang Jadi - justru lebih tepat: stok batch mengikuti stok fisik.
 *
 * DEFER v1: HPP/FIFO costing, kunci periode akuntansi - infrastruktur belum ada di manapun
 * di sistem kita (alasan sama modul2 sblmnya).
 */
class ProduksiWriter
{
    public const SUMBER = 'PRO';

    public const STATUS_BATAL = 9;

    public function __construct(private JopWriter $jopWriter, private SerialBatch $serial)
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
     * Data siap-produksi dari 1 JOP (dipanggil ProduksiForm::mount(jopId:) utk Produksi
     * baru): header JOP + baris yg MASIH ADA SISA (`PDMASUK-PDMASUKPAKAI>0`), komposisi
     * per baris DI-SKALA ULANG proporsional ke SISA (BUKAN copy mentah spt VB6 asli yg
     * TIDAK rescale saat partial-pull - koreksi wajar krn `bitembahanbaku.IPIDQTY` scr
     * desain adalah rasio PER-UNIT, jadi cukup dikali qty sisa yg BENERAN mau diproduksi).
     *
     * @return array{header:object,lines:array}|null null kalau JOP tidak valid/PUSTATUS
     *         bukan 1/2.
     */
    public function fromJop(int $jopId, ?int $gudangJadi = null): ?array
    {
        $header = $this->jopWriter->header($jopId);
        if (! $header || ! in_array((int) $header->PUSTATUS, [JopWriter::STATUS_AKTIF, JopWriter::STATUS_SEBAGIAN], true)) {
            return null;
        }

        $semuaItem = array_map(fn ($l) => (int) $l['item'], $this->jopWriter->linesWithKomposisi($jopId));
        $wajibBatch = $this->serial->wajibBatch($semuaItem, $gudangJadi);

        $lines = [];
        foreach ($this->jopWriter->linesWithKomposisi($jopId) as $l) {
            $sisa = $l['qty'] - $l['qtyPakai'];
            if ($sisa <= 0) {
                continue;
            }
            $rasio = $l['qty'] > 0 ? $sisa / $l['qty'] : 0;

            $komposisi = [];
            foreach ($l['komposisi'] as $k) {
                $komposisi[] = [
                    'item'       => $k['item'],
                    'kode'       => $k['kode'],
                    'nama'       => $k['nama'],
                    'qtyDefault' => $l['qty'] > 0 ? (float) $k['qty'] / $l['qty'] : 0,
                    'qty'        => (float) $k['qty'] * $rasio,
                    'satuan'     => $k['satuan'],
                    'satuanKode' => $k['satuanKode'],
                    'catatan'    => $k['catatan'],
                ];
            }

            $lines[] = [
                'pdid'       => $l['pdid'],
                'item'       => $l['item'],
                'kode'       => $l['kode'],
                'nama'       => $l['nama'],
                'qtySisa'    => $sisa,
                'qty'        => $sisa,
                'satuan'     => $l['satuan'],
                'satuanKode' => $l['satuanKode'],
                'catatan'    => null,
                'komposisi'  => $komposisi,
                'serial'     => in_array((int) $l['item'], $wajibBatch, true),
                'batches'    => [],
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS)
     * @param list<array{pdid:?int,item:int,qty:float,satuan:?int,catatan:?string,komposisi:list<array{item:int,qty:float,satuan:?int,catatan:?string}>}> $lines
     * @param array{kodecabang:string,tgl:string,jopId:?int} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail produk jadi kosong.'];
        }

        $jopId = $meta['jopId'] ?? null;
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $jopId) {
                // Re-cek server-side sisa JOP SAAT INI (kalau ditarik dari JOP) - cegah
                // race/overdraw kalau ada Produksi lain menarik JOP yg sama duluan.
                if ($jopId) {
                    $sisaByPdid = [];
                    foreach ($this->jopWriter->linesWithKomposisi($jopId) as $l) {
                        $sisaByPdid[$l['pdid']] = $l['qty'] - $l['qtyPakai'];
                    }
                    foreach ($lines as $l) {
                        if (! $l['pdid']) {
                            continue;
                        }
                        $sisa = $sisaByPdid[$l['pdid']] ?? 0;
                        if ((float) $l['qty'] > $sisa + 0.0001) {
                            throw new \RuntimeException("Qty produksi melebihi sisa Job Order yang tersedia (sisa: {$sisa}).");
                        }
                    }
                }

                // Batch WAJIB kalau Gudang Jadi berbatch + item ber-ISERIAL=1 (dibaca ulang
                // dari DB, tidak percaya flag form). HANYA baris produk jadi.
                $wajibBatch = $this->serial->wajibBatch(
                    array_map(fn ($l) => (int) $l['item'], $lines),
                    (int) ($header['SUGUDANGTUJUAN'] ?? 0)
                );

                foreach ($lines as $l) {
                    if ((float) $l['qty'] <= 0 || ! in_array((int) $l['item'], $wajibBatch, true)) {
                        continue;
                    }
                    $batches = $this->serial->clean($l['batches'] ?? []);
                    $nama = $l['nama'] ?? ('item #' . $l['item']);
                    if ($batches === []) {
                        throw new \RuntimeException("Produk jadi \"{$nama}\" wajib diisi No Batch.");
                    }
                    $total = array_sum(array_column($batches, 'qty'));
                    if (abs($total - (float) $l['qty']) > 0.0001) {
                        throw new \RuntimeException("Total qty batch ({$total}) tidak sama dengan qty diproduksi (" . (float) $l['qty'] . ") pada \"{$nama}\".");
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUIDJOP'] = $jopId ?: null;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                $urut = 1;
                foreach ($lines as $l) {
                    $qty = (float) $l['qty'];
                    if ($qty <= 0) {
                        continue;
                    }

                    // Urutan baris PRODUK JADI (dialokasikan lebih dulu, walau baris
                    // fisiknya diinsert BELAKANGAN) - dipakai SDURUTANKEPALA baris bahan
                    // baku di bawah, persis pola JopWriter::writeLines()/VB6 asli
                    // (xBrU = xBr, dicatat SEBELUM loop komposisi jalan).
                    $urutanProdukJadi = $urut + count(array_filter($l['komposisi'], fn ($k) => (float) $k['qty'] > 0));

                    // Bahan baku KELUAR - Gudang Produksi (SUCABANG).
                    foreach ($l['komposisi'] as $k) {
                        $kq = (float) $k['qty'];
                        if ($kq <= 0) {
                            continue;
                        }
                        DB::table('fstokd')->insert([
                            'SDIDSU'         => $id,
                            'SDURUTAN'       => $urut++,
                            'SDSUMBER'       => self::SUMBER,
                            'SDITEM'         => $k['item'],
                            'SDKELUAR'       => $kq,
                            'SDKELUARD'      => $kq,
                            'SDSATUAN'       => $k['satuan'] ?: null,
                            'SDSATUAND'      => $k['satuan'] ?: null,
                            'SDCATATAN'      => $k['catatan'] ?: null,
                            'SDGUDANG'       => $header['SUCABANG'],
                            'SDURUTANKEPALA' => $urutanProdukJadi,
                        ]);
                    }

                    // Produk jadi MASUK - LANGSUNG Gudang Jadi (Keputusan #3). DB
                    // TRIGGER `fstokd_add` OTOMATIS urus stok + PDMASUKPAKAI via
                    // SDIDJOP (lihat docblock kelas). JANGAN duplikasi manual.
                    $batches = in_array((int) $l['item'], $wajibBatch, true)
                        ? $this->serial->clean($l['batches'] ?? [])
                        : [];

                    $sdid = (int) DB::table('fstokd')->insertGetId([
                        'SDIDSU'    => $id,
                        'SDURUTAN'  => $urut++,
                        'SDSUMBER'  => self::SUMBER,
                        'SDITEM'    => $l['item'],
                        'SDMASUK'   => $qty,
                        'SDMASUKD'  => $qty,
                        'SDSATUAN'  => $l['satuan'] ?: null,
                        'SDSATUAND' => $l['satuan'] ?: null,
                        'SDCATATAN' => $l['catatan'] ?: null,
                        'SDGUDANG'  => $header['SUGUDANGTUJUAN'],
                        'SDIDJOP'   => $l['pdid'] ?: null,
                        'SDSERIAL'  => $batches === [] ? null : $this->serial->pack($batches),
                    ], 'SDID');

                    // Produk jadi = stok BARU -> arah 'masuk'. `bitemserial.ISJUMLAH` diurus
                    // trigger `bitemserialhistori_add`, jangan update manual.
                    $this->serial->writeHistori($sdid, (int) $l['item'], $batches, 'masuk');
                }

                if ($jopId) {
                    $this->jopWriter->syncStatusAfterPull($jopId);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan Produksi - SOFT (`fstokd.SDCANCEL=1`+nolkan SEMUA qty baris+`fstoku.
     * SUSTATUS=9`, pola sama SJ/PBC/KMB/TMB). Hanya Produksi berstatus 1 (belum batal)
     * yg bisa dibatalkan.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('Produksi tidak ditemukan atau sudah dibatalkan.');
                }

                // Histori batch DIHAPUS spy trigger `bitemserialhistori_del` mengembalikan
                // stok batch - tidak ada trigger di `fstokd` yg melakukannya (pola sama
                // PbWriter/SjWriter).
                $this->serial->forget(DB::table('fstokd')->where('SDIDSU', $id)->pluck('SDID')->all());

                DB::table('fstokd')->where('SDIDSU', $id)->update([
                    'SDCANCEL'  => 1,
                    'SDMASUK'   => 0,
                    'SDMASUKD'  => 0,
                    'SDKELUAR'  => 0,
                    'SDKELUARD' => 0,
                ]);

                DB::table('fstoku')->where('SUID', $id)->update([
                    'SUSTATUS' => self::STATUS_BATAL,
                    'SUMODIFU' => auth()->id(),
                    'SUMODIFD' => now(),
                ]);

                if ($h->SUIDJOP) {
                    $this->jopWriter->releaseOnCancelPro((int) $h->SUIDJOP);
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

    /**
     * Baris produk jadi + nested komposisi bahan baku, dikelompokkan via
     * `SDURUTANKEPALA` (pola sama `JopWriter::linesWithKomposisi()`).
     */
    public function linesWithKomposisi(int $id): array
    {
        $rows = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ISERIAL', 's.SKODE as satuan_kode']);

        $produkJadi = [];
        foreach ($rows as $r) {
            if ((float) $r->SDMASUK > 0) {
                $produkJadi[(int) $r->SDURUTAN] = [
                    'sdid'       => (int) $r->SDID,
                    'item'       => (int) $r->SDITEM,
                    'kode'       => $r->IKODE ?? '',
                    'nama'       => $r->INAMA ?? ('Item #' . $r->SDITEM),
                    'qty'        => (float) $r->SDMASUK,
                    'satuan'     => $r->SDSATUAN ? (int) $r->SDSATUAN : null,
                    'satuanKode' => $r->satuan_kode ?? '',
                    'catatan'    => $r->SDCATATAN,
                    'gudang'     => (int) $r->SDGUDANG,
                    'idJop'      => $r->SDIDJOP ? (int) $r->SDIDJOP : null,
                    'serial'     => (int) ($r->ISERIAL ?? 0) === 1,
                    'batches'    => $this->serial->unpack($r->SDSERIAL ?? null),
                    'komposisi'  => [],
                ];
            }
        }

        foreach ($rows as $r) {
            if ((float) $r->SDKELUAR > 0 && $r->SDURUTANKEPALA && isset($produkJadi[(int) $r->SDURUTANKEPALA])) {
                $produkJadi[(int) $r->SDURUTANKEPALA]['komposisi'][] = [
                    'item'       => (int) $r->SDITEM,
                    'kode'       => $r->IKODE ?? '',
                    'nama'       => $r->INAMA ?? ('Item #' . $r->SDITEM),
                    'qty'        => (float) $r->SDKELUAR,
                    'satuan'     => $r->SDSATUAN ? (int) $r->SDSATUAN : null,
                    'satuanKode' => $r->satuan_kode ?? '',
                    'catatan'    => $r->SDCATATAN,
                ];
            }
        }

        return array_values($produkJadi);
    }
}
