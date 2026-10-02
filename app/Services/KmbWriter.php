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
    /**
     * JOP/JOT yg masih punya sisa bahan utk dikirim, utk pemilih "Tarik dari JOP".
     *
     * Port dari VB6 `bFrmCariTransaksi.frm` baris 944 (`Case "PRO_JOP"`):
     *   `WHERE (PUSUMBER='JOP' or PUSUMBER='JOT') AND PUSTATUS <> 3`
     *
     * **BEDA DISENGAJA dari VB6 (2)**:
     * 1. Ditambah syarat **masih ada sisa** (`PDKELUAR - PDKELUARPAKAI > 0`). VB6 menampilkan
     *    JOP yg sudah habis juga, lalu barisnya kosong & form diam - `cmdCariNoReff_Click`
     *    baris 630 cuma `Exit Sub` tanpa pesan. Lebih baik tidak menawarkan yg mustahil ditarik.
     * 2. **DIBATASI** `$batas` + pencarian di SQL - lihat pelajaran `PkbWriter::pullableForSj()`
     *    (daftar tanpa batas membuat satu respons Livewire membengkak sampai ratusan KB).
     */
    public function pullableJop(?string $cari = null, int $batas = 25)
    {
        $cari = trim((string) $cari);

        return DB::table('fproduksiu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PUCABANG')
            ->whereIn('u.PUSUMBER', ['JOP', 'JOT'])
            ->where('u.PUSTATUS', '<>', 3)
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('fproduksid as d')
                ->whereColumn('d.PDIDSU', 'u.PUID')
                ->where('d.PDKELUAR', '>', 0)
                ->whereRaw('d.PDKELUAR - d.PDKELUARPAKAI > 0'))
            ->when($cari !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.PUNOTRANSAKSI', 'like', "%{$cari}%")
                ->orWhere('k.KNAMA', 'like', "%{$cari}%")))
            ->orderByDesc('u.PUTANGGAL')->orderByDesc('u.PUID')
            ->limit(max(1, $batas))
            ->get(['u.PUID as id', 'u.PUNOTRANSAKSI as nomor', 'u.PUTANGGAL as tanggal',
                'k.KNAMA as karyawan', 'g.GNAMA as cabang']);
    }

    /**
     * Tarik header + baris BAHAN dari JOP/JOT - port `fFrmKirimMutasiBarang.frm`
     * `cmdCariNoReff_Click` (baris 609-651).
     *
     * Barisnya yg **PDKELUAR** (bahan yg harus DIKIRIM ke produksi), bukan `PDMASUK` (hasil
     * produksi) - query VB6 baris 641. Varian `PDMASUK` ada di baris 638 tapi **dikomentari**,
     * jadi jangan tertukar.
     *
     * Sisa = `PDKELUAR - PDKELUARPAKAI`. **`PDKELUARPAKAI` dipelihara TRIGGER, bukan kode**:
     * `fstokd_add` menambah `PDKELUARPAKAI += NEW.SDKELUAR WHERE PDID = NEW.SDIDJOP`,
     * `fstokd_edit` membalik OLD lalu menerapkan NEW, `fstokd_DELL` menguranginya. Jadi KMB
     * cukup mengisi **`fstokd.SDIDJOP = fproduksid.PDID`** dan kuotanya terurus sendiri -
     * **JANGAN pernah meng-UPDATE `PDKELUARPAKAI` manual**, akan terhitung dua kali.
     * Pembatalan KMB pun otomatis mengembalikan kuota, karena `cancel()` menyetel
     * `SDKELUAR = 0` sehingga `fstokd_edit` menguranginya kembali.
     *
     * Gudang tujuan = `PUCABANG` (cabang yg menjalankan produksi), ikut VB6 baris 633.
     */
    public function fromJop(int $jopId): ?array
    {
        $header = DB::table('fproduksiu as u')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PUCABANG')
            ->where('u.PUID', $jopId)
            ->whereIn('u.PUSUMBER', ['JOP', 'JOT'])
            ->where('u.PUSTATUS', '<>', 3)
            ->first(['u.PUID', 'u.PUNOTRANSAKSI', 'u.PUTANGGAL', 'u.PUCABANG', 'u.PUSUMBER', 'g.GNAMA as cabangNama']);

        if (! $header) {
            return null;
        }

        $lines = [];
        foreach ($this->jopLines($jopId) as $l) {
            $sisa = (float) $l->PDKELUAR - (float) $l->PDKELUARPAKAI;
            if ($sisa <= 0) {
                continue;
            }
            $lines[] = [
                // `pbdid` SELALU null di jalur JOP - penghubungnya `pdid` -> `SDIDJOP`.
                'pbdid'      => null,
                'pdid'       => (int) $l->PDID,
                'item'       => (int) $l->PDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PDITEM),
                'qtyMinta'   => $sisa,
                'qty'        => $sisa,
                'satuan'     => $l->PDSATUAN ? (int) $l->PDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /** Baris JOP ber-PDKELUAR, urut `PDURUTAN` (ikut VB6 `ORDER BY pdURUTAN`). */
    private function jopLines(int $jopId)
    {
        return DB::table('fproduksid as d')
            ->join('bitem as i', 'i.IID', '=', 'd.PDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.PDSATUAN')
            ->where('d.PDIDSU', $jopId)
            ->where('d.PDKELUAR', '>', 0)
            ->orderBy('d.PDURUTAN')
            ->get(['d.PDID', 'd.PDITEM', 'd.PDKELUAR', 'd.PDKELUARPAKAI', 'd.PDSATUAN',
                'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode']);
    }

    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        // Jalur JOP ditangani terpisah - sumber, validasi & kolom penghubungnya beda total.
        if (! empty($meta['jopId'])) {
            return $this->createDariJop($header, $lines, $meta);
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
    /**
     * Simpan KMB yg ditarik dari JOP/JOT. Terpisah dari jalur PR karena penghubung & bukunya
     * beda: header `SUIDJOP` (bukan `SUPBUID`), baris `SDIDJOP` (bukan `SDPBDID`).
     *
     * Kuota JOP (`PDKELUARPAKAI`) **TIDAK ditulis di sini** - trigger `fstokd_add` yg
     * mengurusnya lewat `SDIDJOP`; lihat docblock `fromJop()`. Menulisnya manual = dobel.
     *
     * Sisa dibaca ULANG di dalam transaksi (bukan percaya nilai dari layar) - cegah dua user
     * menarik JOP yg sama bersamaan lalu total kirimannya melebihi kebutuhan produksi.
     */
    private function createDariJop(array $header, array $lines, array $meta): array
    {
        $jopId = (int) $meta['jopId'];
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor, $jopId) {
                $jop = DB::table('fproduksiu')->where('PUID', $jopId)
                    ->whereIn('PUSUMBER', ['JOP', 'JOT'])->where('PUSTATUS', '<>', 3)
                    ->lockForUpdate()->first(['PUID', 'PUNOTRANSAKSI']);
                if (! $jop) {
                    throw new \RuntimeException('JOP tidak ditemukan atau sudah ditutup.');
                }

                $sisa = [];
                foreach (DB::table('fproduksid')->where('PDIDSU', $jopId)->lockForUpdate()
                    ->get(['PDID', 'PDKELUAR', 'PDKELUARPAKAI']) as $d) {
                    $sisa[(int) $d->PDID] = (float) $d->PDKELUAR - (float) $d->PDKELUARPAKAI;
                }

                foreach ($lines as $l) {
                    $pdid = (int) ($l['pdid'] ?? 0);
                    $qty = (float) $l['qty'];
                    if ($pdid <= 0 || ! array_key_exists($pdid, $sisa)) {
                        throw new \RuntimeException('Ada baris yang tidak terhubung ke JOP ini.');
                    }
                    if ($qty > $sisa[$pdid] + 0.0001) {
                        throw new \RuntimeException('Qty kirim melebihi sisa kebutuhan JOP (' . $sisa[$pdid] . ').');
                    }
                }

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER'] = self::SUMBER;
                $header['SUSTATUS'] = 1;
                $header['SUIDJOP'] = $jopId;
                $header['SUCREATEU'] = auth()->id();

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                $urut = 1;
                foreach ($lines as $l) {
                    DB::table('fstokd')->insert([
                        'SDIDSU'    => $id,
                        'SDURUTAN'  => $urut++,
                        'SDSUMBER'  => self::SUMBER,
                        'SDITEM'    => $l['item'],
                        'SDKELUAR'  => $l['qty'],
                        'SDKELUARD' => $l['qty'],
                        'SDSATUAN'  => $l['satuan'] ?: null,
                        'SDSATUAND' => $l['satuan'] ?: null,
                        'SDGUDANG'  => $header['SUCABANG'],
                        // Penghubung ke baris JOP - inilah yg dibaca trigger utk memotong
                        // PDKELUARPAKAI. `SDPBDID` SENGAJA dibiarkan null di jalur ini.
                        'SDIDJOP'   => $l['pdid'],
                        'SDCATATAN' => $l['catatan'] ?: null,
                    ]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

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
