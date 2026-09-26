<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Kirim Mutasi Barang (KMB) - tabel legacy `fstoku`/`fstokd` (`SUSUMBER='KMB'`), BUKAN
 * tabel terpisah (pola sama SJ/PBC). Tahap PERTAMA alur PARALEL BARU khusus PR jenis=0
 * ("Permintaan Barang" - tujuan CABANG): RS -> KMB -> TMB, SAMA SEKALI TERPISAH dari
 * alur PR->PKB->SJ->PBC (jenis=1 "Permintaan Pembelian"). Dibuat CABANG PENGIRIM (yg
 * py stok, BUKAN cabang yg minta) - VB6 asli `fFrmKirimMutasiBarang.frm` (folder LAMA
 * `C:\code\DIAS_MYSQL_2026`).
 *
 * SUSUMBER = 'KMB' (aanomor NKODE='KMB'). Nomor: {kodecabang}-KMB{yymm}{NNNN} -
 * kodecabang dari cabang PENGIRIM (user login pembuat KMB).
 *
 * **TANPA VERIFIKASI** - ditarik dari PR jenis=0 TANPA syarat PBUSTATUS apapun (beda
 * dari PKB yg wajib PR sudah Disetujui/status 2) - dikonfirmasi instruksi user
 * 2026-09-19 & VB6 asli yg baca PR tanpa filter status sama sekali.
 *
 * **`SUPBUID` DIISI = PBUID PR asal** (beda dari SJ/PBC yg TIDAK PERNAH mengisi kolom
 * ini) - krn DB TRIGGER `fstoku_add`/`_edit`/`_del` (level HEADER `fstoku`, GENERIC utk
 * SEMUA SUSUMBER, dicek `information_schema.TRIGGERS`) OTOMATIS maintain
 * `fpermintaanbarangu.PBUSTATUSKM` via kolom ini: `_add` set 1, `_del` set 0, `_edit`
 * set OLD punya->0 lalu NEW punya->1 (NET NO-OP kalau SUPBUID tidak berubah antar
 * update). `PBUSTATUSKM` adalah SATU-SATUNYA sinyal legacy utk "PR ini sedang ada KMB
 * hidup" - TMB TIDAK PERNAH mengisi `SUPBUID` sama sekali jadi TIDAK mempengaruhi
 * kolom ini (dikonfirmasi VB6 `fFrmPenerimaanMutasi.frm`). `fpermintaanbarangu.
 * PBUSTATUS` sendiri TIDAK PERNAH disentuh KMB maupun TMB (nol match di kedua file
 * VB6) - progres PR jenis=0 dilacak MURNI via `PBUSTATUSKM` + status KMB/TMB sendiri.
 *
 * `PBDQTY-PBDQTYPAKAI` (formula "sisa" yg dipakai form KMB asli) TERBUKTI MATI utk
 * jalur ini - `PBDQTYPAKAI` HANYA di-maintain trigger rantai PKB (`fperintahkirim
 * barangd_add/_UPDATE/_dell`), KMB tidak py hubungan ke situ. Krn `PBUSTATUSKM` cuma
 * BOOLEAN (bukan qty), legacy scr implisit HANYA mendukung SATU KMB hidup per PR pada
 * satu waktu -> **v1: satu PR jenis=0 = SATU KMB, FULL qty semua baris PR** (TIDAK
 * bertahap, pola sama "satu PKB=satu PR"/"satu PBC=satu SJ").
 *
 * Trigger stok generic `fstokd_add`/`_edit`/`_DELL` (SAMA dipakai POS/SJ/PBC) otomatis
 * urus stok arah KELUAR (`SDGUDANG`=cabang pengirim) - TIDAK perlu kode manual. Baris
 * KMB (py `SDPBDID`) JUGA memicu `PBDQTYTERIMA` tercemar (bug sama yg sudah didokumen-
 * tasikan di `SjWriter`) - diabaikan, TIDAK dipakai jalur ini.
 *
 * Pembatalan: SOFT (`fstokd.SDCANCEL=1`+nolkan `SDKELUAR`/`SDKELUARD`+`fstoku.
 * SUSTATUS=9`, pola sama `SjWriter::cancel()`), DITAMBAH manual `UPDATE
 * fpermintaanbarangu SET PBUSTATUSKM=0` - trigger `fstoku_edit` TIDAK membantu di sini
 * krn UPDATE status kita TIDAK mengubah `SUPBUID` (OLD=NEW -> net no-op).
 */
class KmbWriter
{
    public const SUMBER = 'KMB';

    public const STATUS_DITERIMA = 3;

    public const STATUS_BATAL = 9;

    public function __construct(private PurchaseRequestWriter $prWriter)
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
     * Data siap-kirim dari 1 PR jenis=0 (dipanggil KmbForm::mount(prId:) utk KMB baru):
     * header PR + SEMUA baris FULL qty (TIDAK ada formula sisa, lihat docblock kelas).
     * Re-cek server-side `PBUGUDANGSUMBER` PR = cabang user login SEKARANG (BUKAN cuma
     * andalkan filter picker `PurchaseRequestWriter::pullableForKmb()`) - cegah user
     * "mencuri" kirim PR yg sumbernya diminta dari cabang lain lewat tab URL langsung.
     *
     * @return array{header:object,lines:array}|null null kalau PR tidak valid/sudah
     *         batal/sudah py KMB hidup/bukan sumber cabang user login (re-cek
     *         server-side, bukan cuma andalkan picker).
     */
    public function fromPr(int $prId): ?array
    {
        $header = $this->prWriter->header($prId);
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);
        if (! $header || (int) $header->PBUJENIS !== 0
            || (int) $header->PBUSTATUS === PurchaseRequestWriter::STATUS_BATAL
            || (int) $header->PBUSTATUSKM !== 0
            || (int) $header->PBUGUDANGSUMBER !== $ucabang) {
            return null;
        }

        $lines = [];
        foreach ($this->prWriter->lines($prId) as $l) {
            $qty = (float) $l->PBDQTY;
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'pbdid'      => (int) $l->PBDID,
                'item'       => (int) $l->PBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PBDITEM),
                'qtyMinta'   => $qty,
                'qty'        => $qty,
                'satuan'     => $l->PBDSATUAN ? (int) $l->PBDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom SU* (tanpa SUNOTRANSAKSI/SUID/SUSUMBER/SUSTATUS/SUPBUID)
     * @param list<array{pbdid:int,item:int,qty:float,satuan:?int,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string,prId:int} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $prId = $meta['prId'];
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $prId) {
                // Re-cek server-side PR SAAT INI masih boleh ditarik (belum batal, belum
                // py KMB hidup lain) DAN memang menyebut cabang yg sedang menyimpan sbg
                // sumbernya (SUCABANG KMB = PBUGUDANGSUMBER PR) - cegah race/dobel-tarik
                // DAN cegah "mencuri" PR cabang lain (per instruksi user 2026-09-22, lihat
                // jg `fromPr()`).
                $pr = $this->prWriter->header($prId);
                if (! $pr || (int) $pr->PBUJENIS !== 0
                    || (int) $pr->PBUSTATUS === PurchaseRequestWriter::STATUS_BATAL
                    || (int) $pr->PBUSTATUSKM !== 0
                    || (int) $pr->PBUGUDANGSUMBER !== (int) $header['SUCABANG']) {
                    throw new \RuntimeException('PR sudah dibatalkan, sudah ada KMB yang berjalan, atau bukan sumber cabang Anda.');
                }

                $qtyByLine = [];
                foreach ($this->prWriter->lines($prId) as $l) {
                    $qtyByLine[(int) $l->PBDID] = (float) $l->PBDQTY;
                }

                foreach ($lines as $l) {
                    $pbdid = (int) $l['pbdid'];
                    $qty = (float) $l['qty'];
                    $qtyMinta = $qtyByLine[$pbdid] ?? 0;
                    if ($qty <= 0 || $qty > $qtyMinta + 0.0001) {
                        throw new \RuntimeException("Qty kirim untuk item melebihi qty diminta ({$qtyMinta}).");
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUPBUID'] = $prId;
                $header['SUCREATEU'] = auth()->id();

                // Insert header - DB TRIGGER `fstoku_add` OTOMATIS set
                // `fpermintaanbarangu.PBUSTATUSKM=1` via SUPBUID (lihat docblock kelas).
                // JANGAN duplikasi manual di sini.
                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                // Insert baris fstokd - DB TRIGGER `fstokd_add` OTOMATIS urus stok
                // (arah KELUAR). TIDAK ada bookkeeping PBDQTYPAKAI manual (kolom itu
                // mati utk jalur ini, lihat docblock kelas).
                $urut = 1;
                foreach ($lines as $l) {
                    DB::table('fstokd')->insert([
                        'SDIDSU'     => $id,
                        'SDURUTAN'   => $urut++,
                        'SDSUMBER'   => self::SUMBER,
                        'SDITEM'     => $l['item'],
                        'SDKELUAR'   => $l['qty'],
                        'SDKELUARD'  => $l['qty'],
                        'SDSATUAN'   => $l['satuan'] ?: null,
                        'SDSATUAND'  => $l['satuan'] ?: null,
                        'SDGUDANG'   => $header['SUCABANG'],
                        'SDPBDID'    => $l['pbdid'],
                        'SDCATATAN'  => $l['catatan'] ?: null,
                    ]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan KMB - SOFT (`fstokd.SDCANCEL=1`+nolkan `SDKELUAR`/`SDKELUARD`+`fstoku.
     * SUSTATUS=9`, pola sama `SjWriter::cancel()`), DITAMBAH manual reset
     * `fpermintaanbarangu.PBUSTATUSKM=0` (lihat docblock kelas soal kenapa trigger
     * TIDAK membantu di sini). Hanya KMB berstatus 1 (belum diterima TMB, belum batal)
     * yg boleh dibatalkan langsung - kalau sudah 3 (diterima), batalkan lewat
     * pembatalan TMB-nya dulu.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS !== 1) {
                    throw new \RuntimeException('KMB tidak ditemukan atau tidak bisa dibatalkan langsung.');
                }

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

                if ($h->SUPBUID) {
                    DB::table('fpermintaanbarangu')->where('PBUID', $h->SUPBUID)->update(['PBUSTATUSKM' => 0]);
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

    /**
     * KMB yg boleh "diterima" via TMB baru: belum batal & belum diterima (`SUSTATUS=1`),
     * DAN `SUGUDANGTUJUAN` (cabang PEMINTA/tujuan mutasi) = cabang user login SEKARANG
     * (`$gudangTujuan`, WAJIB diisi pemanggil - per instruksi user 2026-09-22: "KMB yang
     * bisa ditarik adalah SUGUDANGTUJUAN = cabang user login"). TANPA filter ini, cabang
     * MANAPUN bisa "mencuri" terima KMB yg sebenarnya dituju cabang lain - konsisten dgn
     * `TmbForm::mount()` yg SELALU pakai `UCABANG` user login apa adanya sbg `SUCABANG`
     * TMB (single, tidak bisa dipilih), jadi picker HARUS dibatasi ke KMB yg memang
     * ditujukan ke cabang yg sama. Dipakai picker "Terima dari KMB" di `TmbList`.
     */
    public function pullableForTmb(int $gudangTujuan)
    {
        return DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', self::SUMBER)
            ->where('u.SUSTATUS', 1)
            ->where('u.SUGUDANGTUJUAN', $gudangTujuan)
            ->orderByDesc('u.SUID')
            ->get(['u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal', 'k.KNAMA as karyawan']);
    }
}
