<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Permintaan Barang - tabel legacy fpermintaanbarangu (header) + fpermintaanbarangd (detail).
 * TIDAK ada trigger stok (dokumen permintaan saja, belum ada mutasi barang).
 *
 * PBUSUMBER = 'RS' (aanomor NID 728). Nomor: {kodecabang}-RS{yymm}{NNNN},
 * urut dari MAX(RIGHT(PBUNOTRANSAKSI,4)) atas prefix (PBUNOTRANSAKSI unik global).
 *
 * PBUSTATUS - v1 kita AKTIF pakai 0/1/2/3/9, TAPI kolom ini di DB tetap py RENTANG PENUH
 * 8-tahap legacy (dikonfirmasi dari data hasil import user 1 Agt-skrg, isinya benar2
 * pakai semua nilai ini): 0 = belum verifikasi ("Belum Proses" di legacy), 1 = pending
 * (butuh catatan), 2 = disetujui ("Verifikasi Fina/acc"), 3 = sudah ditarik penuh ke PKB
 * ("Perintah Kirim" - lihat PkbWriter, status ini ditambahkan 2026-09-18 mengikuti VB6
 * asli `fFrmPerintahkirimBarang.frm::SetStatusOrder`, murni informasional/otomatis, TIDAK
 * bisa dipilih user lewat verify() di atas), 4 = Sedang Dikirim, 5 = Progress Diterima
 * Cabang, 6 = Selesai Diterima Cabang, 7 = Konfirmasi Bag Pembelian (4-7 BELUM py alur
 * apapun di v1 - murni label tampilan `PrList`/`PrForm` biar histori import kebaca benar,
 * blm ada modul yg MENULIS ke situ, nanti nyambung modul SJ/Terima Barang), 9 = batal
 * (SAMA konvensi fstoku.SUSTATUS=9 POS, konvensi KITA sendiri, bukan bagian skema
 * legacy 0-7 - sengaja lompat jauh spy tidak tertukar kalau legacy nambah tahap lagi).
 * PBUJENIS : 0 = Permintaan Barang, 1 = Permintaan Pembelian (DIKOREKSI 2026-09-18 dari
 * 1/2 - salah tebak sblmnya, legacy `cboJenisPermintaan.ListIndex` VB6 asli 0-based &
 * ditulis mentah2 ke kolom ini, dikonfirmasi jg dari data import: PBUJENIS cuma pernah
 * 0/1, TIDAK PERNAH 2. Nol PR nyata sempat tersimpan pakai skema lama sblm fix ini).
 *
 * PBDQTYPAKAI (kolom `fpermintaanbarangd`) = running-total qty yg SUDAH ditarik ke PKB
 * manapun (bisa BERTAHAP, satu PR boleh ditarik lebih dari satu PKB selama masih ada
 * sisa) - di-maintain OTOMATIS oleh DB TRIGGER `fperintahkirimbarangd_add/_UPDATE/_dell`
 * (lihat docblock `syncStatusAfterPull()`/`releaseOnCancel()` di bawah utk detail kapan
 * kita ikut campur manual vs biarkan trigger).
 */
class PurchaseRequestWriter
{
    public const SUMBER = 'RS';

    public const STATUS_BATAL = 9;

    public const STATUS_DITARIK_SELESAI = 3;

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('fpermintaanbarangu')
            ->where('PBUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(PBUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array         $header kolom PBU* (tanpa PBUNOTRANSAKSI/PBUID/PBUSUMBER)
     * @param list<array>    $lines  kolom PBD* (tanpa PBDIDSU/PBDURUTAN)
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor) {
                $header['PBUNOTRANSAKSI'] = $nomor;
                $header['PBUSUMBER']      = self::SUMBER;
                $header['PBUSTATUS']      = 0;
                $header['PBUCREATEU']     = auth()->id();

                $id = (int) DB::table('fpermintaanbarangu')->insertGetId($header, 'PBUID');
                $this->writeLines($id, $lines);

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * @return array{ok:bool,error:?string}
     */
    public function update(int $id, array $header, array $lines): array
    {
        if ($lines === []) {
            return ['ok' => false, 'error' => 'Detail item kosong.'];
        }

        unset($header['PBUNOTRANSAKSI'], $header['PBUSUMBER'], $header['PBUSTATUS'], $header['PBUCREATEU']);
        $header['PBUMODIFU'] = auth()->id();
        $header['PBUMODIFD'] = now();

        try {
            DB::transaction(function () use ($id, $header, $lines) {
                DB::table('fpermintaanbarangu')->where('PBUID', $id)->update($header);
                DB::table('fpermintaanbarangd')->where('PBDIDSU', $id)->delete();
                $this->writeLines($id, $lines);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    private function writeLines(int $id, array $lines): void
    {
        $urut = 1;
        foreach ($lines as $l) {
            $l['PBDIDSU']   = $id;
            $l['PBDURUTAN'] = $urut++;
            $l['PBDSUMBER'] = self::SUMBER;
            DB::table('fpermintaanbarangd')->insert($l);
        }
    }

    /**
     * Batalkan permintaan - SOFT status (PBUSTATUS=STATUS_BATAL), TIDAK PERNAH hard-delete
     * baris `fpermintaanbarangu`/`fpermintaanbarangd` - persis pola `fstoku.SUSTATUS=9` di
     * POS, per permintaan user 2026-09-17 ("samakan dengan POS, tidak ada hapus, adanya
     * rubah status"). Baris detail dibiarkan utuh apa adanya (tidak ada trigger stok yg
     * perlu dibalik, tidak ada kolom cancel per-baris spt fstokd.SDCANCEL - cukup header).
     */
    public function cancel(int $id): bool
    {
        try {
            DB::table('fpermintaanbarangu')->where('PBUID', $id)->update([
                'PBUSTATUS' => self::STATUS_BATAL,
                'PBUMODIFU' => auth()->id(),
                'PBUMODIFD' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Verifikasi: $status 1 (pending, butuh catatan) atau 2 (disetujui).
     */
    public function verify(int $id, int $status, ?string $catatan): array
    {
        if (! in_array($status, [1, 2], true)) {
            return ['ok' => false, 'error' => 'Status verifikasi tidak valid.'];
        }
        if ($status === 1 && trim((string) $catatan) === '') {
            return ['ok' => false, 'error' => 'Catatan wajib diisi untuk status Pending.'];
        }

        DB::table('fpermintaanbarangu')->where('PBUID', $id)->update([
            'PBUSTATUS'            => $status,
            'PBUKONFIRMASICATATAN' => $catatan ?: null,
            'PBUAPPROVEU'          => auth()->id(),
            'PBUKONFIRMASIU'       => auth()->id(),
            'PBUKONFIRMASITANGGAL' => now()->toDateString(),
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Batalkan verifikasi - balikkan PR "Disetujui" (2) ke "Belum Verifikasi" (0), utk
     * kasus verifikator salah klik/mau tinjau ulang (permintaan user 2026-09-24). HANYA
     * diizinkan kalau (1) status SAAT INI persis 2 (bukan 1/Pending - itu tinggal
     * diverifikasi ulang biasa; BUKAN status 3+ juga - itu tandanya PKB SUDAH ditarik,
     * lihat poin 2), DAN (2) `PBDQTYPAKAI` SEMUA baris masih 0 (BELUM ADA PKB yg menarik
     * qty dari PR ini) - kalau sudah ada PKB, PR ini "batal diverifikasi" akan bikin PKB
     * nyantol ke PR yg tidak disetujui, data jadi tidak konsisten. User HARUS batalkan
     * PKB-nya dulu (`PkbWriter::cancel()`, otomatis `releaseOnCancel()` PBDQTYPAKAI balik
     * 0) baru bisa batalkan verifikasi PR ini.
     *
     * Reset field konfirmasi ke NULL (bukan cuma ganti status) - PR ini secara efektif
     * "belum pernah diverifikasi" lagi, riwayat keputusan lama TIDAK relevan dipertahankan.
     *
     * **GOTCHA KRITIS ditemukan 2026-09-24 (saat bangun fitur "Histori")**: cek "sudah
     * ditarik PKB?" TERNYATA TIDAK BOLEH pakai `SUM(PBDQTYPAKAI)` - kolom itu TERBUKTI
     * TIDAK RELIABEL scr luas (**4.439 dari 5.002 baris PR aktif, 89%!, NILAINYA TIDAK
     * COCOK** dgn `SUM(PKBDQTY)` aktual dari `fperintahkirimbarangd` - dicek query
     * langsung). Pola gandanya BUKAN "trigger tidak jalan" doang - trigger `AFTER INSERT`
     * `fperintahkirimbarangd_add` DIKONFIRMASI benar (1 statement, tidak duplikat spt bug
     * `ISTOKDE` sblmnya) & trigger `AFTER UPDATE`-nya jg benar (pola delta OLD-lalu-NEW
     * yg aman) - TAPI kasus nyata ditemukan PKB dgn TEPAT SATU baris detail (`PKBDQTY`=50)
     * yg `PBDQTYPAKAI` PR sumbernya PERSIS 2x lipat (100) - root cause PERSIS belum
     * ketemu (bukan duplicate-trigger, bukan duplicate-row, bukan tulis manual dari CI3
     * ataupun `PkbWriter` kita sendiri - keduanya dicek, tidak ada yg sentuh kolom ini).
     * **Keputusan (KONSISTEN pola sesi ini utk bug trigger produksi spt `ISTOKDE`)**:
     * TIDAK diperbaiki di sini (trigger produksi, berisiko), TAPI kode BARU (method ini)
     * SENGAJA TIDAK PERCAYA kolom itu - cek `EXISTS` langsung ke `fperintahkirimbarangd`
     * (keberadaan baris = ground truth, TIDAK bisa salah spt angka qty yg diakumulasi).
     * **DAMPAK LEBIH LUAS blm ditindaklanjuti** (di luar scope perbaikan ini, WAJIB
     * diberitahu user): `PkbWriter::fromPr()`/`PurchaseRequestWriter::pullableForPkb()`
     * MASIH pakai formula `PBDQTY - PBDQTYPAKAI` (dari SEBELUM temuan ini) utk hitung
     * "sisa yg boleh ditarik" - kalau `PBDQTYPAKAI` under-count (spt kasus DE-PKB26090008
     * di atas, tersimpan 0 padahal beneran sudah ada tarikan), "sisa" bisa OVER-REPORT,
     * BERISIKO PKB BARU MENARIK QTY YG SEBENARNYA SUDAH DIALOKASIKAN (over-allocation).
     * BELUM diperbaiki krn di luar scope permintaan ini - perlu keputusan user terpisah.
     */
    public function unverify(int $id): array
    {
        $h = $this->header($id);
        if (! $h || (int) $h->PBUSTATUS !== 2) {
            return ['ok' => false, 'error' => 'Hanya PR berstatus Disetujui yang bisa dibatalkan verifikasinya.'];
        }

        $sudahDitarik = DB::table('fperintahkirimbarangd as pkd')
            ->whereIn('pkd.PKBDRSIDD', DB::table('fpermintaanbarangd')->where('PBDIDSU', $id)->select('PBDID'))
            ->exists();
        if ($sudahDitarik) {
            return ['ok' => false, 'error' => 'PR ini sudah ditarik ke PKB - batalkan PKB terkait dulu sebelum membatalkan verifikasi.'];
        }

        DB::table('fpermintaanbarangu')->where('PBUID', $id)->update([
            'PBUSTATUS'            => 0,
            'PBUKONFIRMASICATATAN' => null,
            'PBUAPPROVEU'          => null,
            'PBUKONFIRMASIU'       => null,
            'PBUKONFIRMASITANGGAL' => null,
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function header(int $id): ?object
    {
        return DB::table('fpermintaanbarangu')->where('PBUID', $id)->first();
    }

    public function lines(int $id): array
    {
        return DB::table('fpermintaanbarangd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.PBDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.PBDSATUAN')
            ->where('d.PBDIDSU', $id)
            ->orderBy('d.PBDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode'])
            ->all();
    }

    /**
     * Histori penarikan PER BARIS PR, 4 LEVEL: PR -> PKB -> SJ -> PBC - permintaan user
     * 2026-09-24 ("kalau sudah ditarik PKB, nomor berapa & qty berapa; kalau PKB-nya
     * sudah dibuat SJ, tampilkan detail SJ; lanjut lagi kalau SJ-nya sudah ditarik jadi
     * PBC, tampilkan data PBC-nya"). HANYA relevan jenis=1 (Permintaan Pembelian, alur
     * PR->PKB->SJ->PBC) - jenis=0 (RS->KMB->TMB) pakai chain BEDA sama sekali, TIDAK
     * dicakup method ini (sudah kebaca lewat badge KM/TMB terpisah di list).
     *
     * Traceability PR->PKB->SJ (3 level pertama) SUDAH ada langsung di skema via FK
     * eksplisit (dikonfirmasi via docblock `SjWriter`): `fperintahkirimbarangd.PKBDRSIDD`
     * = FK ke `PBDID` (baris PR), `fstokd.SDSODID` = FK ke `PKBDID` (baris PKB) utk baris
     * SJ (`SDSUMBER='SJ'`).
     * **Level ke-4 (SJ->PBC) BEDA POLA** - `PbcWriter` TIDAK py FK per-baris ke SJ sumber
     * (dikonfirmasi docblock kelas itu: `SDPRDID` di-OVERLOAD trigger lain, TIDAK BISA
     * dipakai) - traceability PBC->SJ cuma HEADER-level via `fstoku.SUNOSJAPOTIK` = SJ.SUID.
     * Baris PBC yg cocok utk 1 item dicari via `SDITEM` DALAM PBC yg `SUNOSJAPOTIK`-nya SJ
     * itu - AMAN krn PBC "satu PBC = satu SJ, FULL-RECEIPT" (PBC SELALU bawa semua item
     * SJ-nya, tidak pernah partial), jadi pencarian by-item dalam scope 1 PBC tidak ambigu.
     *
     * @return list<array{item:int,kode:string,nama:string,qtyDiminta:float,qtyDitarik:float,satuan:string,pkb:list<array>}>
     */
    public function history(int $prId): array
    {
        $result = [];

        foreach ($this->lines($prId) as $l) {
            // GOTCHA DATA ditemukan 2026-09-24 (jarang, dicek TIDAK sistemik spt bug
            // PBDQTYPAKAI - cuma 4 dari 4.804 baris, ~0.08%): `PKBDRSIDD` (FK dari PKB ke
            // baris PR) & `PKBDITEM` (item AKTUAL baris PKB itu) KADANG tidak sinkron -
            // PKB yg "mengaku" menarik dari baris PR item X ternyata isinya item Y. Filter
            // `pkd.PKBDITEM = $l->PBDITEM` DIREKATKAN sbg penjaga - baris PKB yg datanya
            // inkonsisten spt ini TIDAK ditampilkan di bawah item yg SALAH (lebih aman
            // sembunyikan drpd salah asosiasi), BUKAN usaha "perbaiki" data-nya sendiri.
            $pkbRows = DB::table('fperintahkirimbarangd as pkd')
                ->join('fperintahkirimbarangu as pku', 'pku.PKBUID', '=', 'pkd.PKBDIDSU')
                ->where('pkd.PKBDRSIDD', $l->PBDID)
                ->where('pkd.PKBDITEM', $l->PBDITEM)
                ->orderBy('pku.PKBUID')
                ->get(['pkd.PKBDID', 'pkd.PKBDQTY', 'pku.PKBUID', 'pku.PKBUNOTRANSAKSI', 'pku.PKBUTANGGAL', 'pku.PKBUSTATUS']);

            $pkbList = [];
            foreach ($pkbRows as $pkb) {
                $sjRows = DB::table('fstokd as d')
                    ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
                    ->where('d.SDSODID', $pkb->PKBDID)
                    ->where('d.SDITEM', $l->PBDITEM) // penjaga sama - lihat gotcha di atas
                    ->where('u.SUSUMBER', 'SJ')
                    ->orderBy('u.SUID')
                    ->get(['u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUSTATUS', 'd.SDKELUAR']);

                $pkbList[] = [
                    'nomor'    => $pkb->PKBUNOTRANSAKSI,
                    'tanggal'  => $pkb->PKBUTANGGAL,
                    'batal'    => (int) $pkb->PKBUSTATUS === self::STATUS_BATAL,
                    'qty'      => (float) $pkb->PKBDQTY,
                    'sj'       => $sjRows->map(function ($sj) use ($l) {
                        // PBC (Penerimaan Barang Cabang) - traceability HEADER-LEVEL saja
                        // (`fstoku.SUNOSJAPOTIK` = SJ.SUID, BUKAN FK per-baris - PBC MEMANG
                        // "satu PBC = satu SJ, FULL-RECEIPT", lihat docblock `PbcWriter`),
                        // jadi baris PBC yg cocok utk item PR ini dicari via SDITEM DALAM PBC
                        // yg SUNOSJAPOTIK-nya SJ ini (aman krn full-receipt = PBC selalu bawa
                        // SEMUA item SJ-nya).
                        // LEFT JOIN ke detail (bukan INNER) - banyak dokumen di DB ini
                        // header-saja tanpa baris detail (hasil import), jangan sampai PBC
                        // yg SUNGGUHAN ADA malah terlihat "belum diterima".
                        $pbcRows = DB::table('fstoku as pu')
                            ->leftJoin('fstokd as pd', function ($j) use ($l) {
                                $j->on('pd.SDIDSU', '=', 'pu.SUID')->where('pd.SDITEM', '=', $l->PBDITEM);
                            })
                            ->where('pu.SUSUMBER', 'PBC')
                            ->where('pu.SUNOSJAPOTIK', $sj->SUID)
                            ->orderBy('pu.SUID')
                            ->get(['pu.SUNOTRANSAKSI', 'pu.SUTANGGAL', 'pu.SUSTATUS', 'pd.SDMASUK']);

                        return [
                            'nomor'   => $sj->SUNOTRANSAKSI,
                            'tanggal' => $sj->SUTANGGAL,
                            'batal'   => (int) $sj->SUSTATUS === 9,
                            'qty'     => (float) $sj->SDKELUAR,
                            'pbc'     => $pbcRows->map(fn ($pbc) => [
                                'nomor'   => $pbc->SUNOTRANSAKSI,
                                'tanggal' => $pbc->SUTANGGAL,
                                'batal'   => (int) $pbc->SUSTATUS === 9,
                                'qty'     => $pbc->SDMASUK !== null ? (float) $pbc->SDMASUK : null,
                            ])->all(),
                        ];
                    })->all(),
                ];
            }

            $result[] = [
                'item'       => (int) $l->PBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PBDITEM),
                'qtyDiminta' => (float) $l->PBDQTY,
                // Dihitung dari `pkbList` yg BARU DITELUSURI di atas (ground truth baris
                // `fperintahkirimbarangd` sungguhan), BUKAN kolom `PBDQTYPAKAI` - kolom itu
                // TERBUKTI TIDAK RELIABEL (lihat gotcha panjang di docblock `unverify()`).
                'qtyDitarik' => array_sum(array_column($pkbList, 'qty')),
                'satuan'     => $l->satuan_kode ?? '',
                'pkb'        => $pkbList,
            ];
        }

        return $result;
    }

    /**
     * Histori PER BARIS PR utk jenis=0 (Permintaan Barang / MUTASI antar cabang):
     * PR -> KMB (Kirim Mutasi Barang) -> TMB (Terima Mutasi Barang) - permintaan user
     * 2026-09-24, PASANGAN dari `history()` yg utk jenis=1 (PR->PKB->SJ->PBC). Dua alur
     * ini TERPISAH TOTAL (beda tabel perantara, beda kolom FK, beda konvensi status) -
     * makanya method-nya jg sengaja dipisah, BUKAN dipaksa jadi satu yg penuh `if`.
     *
     * Rantai FK (dikonfirmasi dari `KmbWriter`/`TmbWriter` yg dibangun sesi2 sblmnya):
     * - **PR -> KMB PER BARIS**: `fstokd.SDPBDID` = `PBDID` (KMB MENGISI kolom ini, beda
     *   dari TMB yg TIDAK pernah mengisi) utk baris `SDSUMBER='KMB'`. Qty di `SDKELUAR`.
     * - **KMB -> TMB HEADER-level saja**: `fstoku.SUPRUID` = KMB.SUID (TMB TIDAK py FK
     *   per-baris ke KMB - lihat docblock `TmbWriter`), baris TMB utk 1 item dicari via
     *   `SDITEM` DALAM TMB itu - AMAN krn konvensi "satu TMB = SATU KMB, FULL-RECEIPT"
     *   (persis pola PBC di alur jenis=1). Qty di `SDMASUK`.
     *
     * Penjaga `SDITEM = PBDITEM` dipasang di level KMB (pola sama `history()` - lihat
     * gotcha data `PKBDRSIDD`/`PKBDITEM` di sana).
     *
     * @return list<array{item:int,kode:string,nama:string,qtyDiminta:float,qtyDitarik:float,satuan:string,kmb:list<array>}>
     */
    public function historyMutasi(int $prId): array
    {
        $result = [];

        // SEMUA KMB milik PR ini lewat relasi HEADER (`fstoku.SUPBUID` = PBUID) - BUKAN
        // cuma lewat baris detail - lihat catatan "dokumen header-saja" di docblock.
        $kmbHeaders = DB::table('fstoku')
            ->where('SUSUMBER', 'KMB')
            ->where('SUPBUID', $prId)
            ->orderBy('SUID')
            ->get(['SUID', 'SUNOTRANSAKSI', 'SUTANGGAL', 'SUSTATUS']);

        foreach ($this->lines($prId) as $l) {
            $kmbList = [];
            foreach ($kmbHeaders as $kmb) {
                $kmbLine = DB::table('fstokd')
                    ->where('SDIDSU', $kmb->SUID)
                    ->where('SDPBDID', $l->PBDID)
                    ->where('SDITEM', $l->PBDITEM)
                    ->first(['SDKELUAR']);

                if (! $kmbLine) {
                    // Baris utk item ini tidak ada. Kalau KMB-nya PUNYA baris lain, berarti
                    // KMB ini memang tidak memuat item ini -> LEWATI. Kalau KMB sama sekali
                    // TIDAK punya baris detail (dokumen header-saja), tetap TAMPILKAN dgn
                    // qty tidak diketahui - jangan sampai terlihat "belum ada KMB".
                    if (DB::table('fstokd')->where('SDIDSU', $kmb->SUID)->exists()) {
                        continue;
                    }
                }

                $tmbRows = DB::table('fstoku as tu')
                    ->leftJoin('fstokd as td', function ($j) use ($l) {
                        $j->on('td.SDIDSU', '=', 'tu.SUID')->where('td.SDITEM', '=', $l->PBDITEM);
                    })
                    ->where('tu.SUSUMBER', 'TMB')
                    ->where('tu.SUPRUID', $kmb->SUID)
                    ->orderBy('tu.SUID')
                    ->get(['tu.SUNOTRANSAKSI', 'tu.SUTANGGAL', 'tu.SUSTATUS', 'td.SDMASUK']);

                $kmbList[] = [
                    'nomor'   => $kmb->SUNOTRANSAKSI,
                    'tanggal' => $kmb->SUTANGGAL,
                    'batal'   => (int) $kmb->SUSTATUS === 9,
                    // KMB SUSTATUS=3 artinya "sudah diterima TMB" (KmbWriter::STATUS_DITERIMA)
                    'diterima' => (int) $kmb->SUSTATUS === 3,
                    'qty'     => $kmbLine ? (float) $kmbLine->SDKELUAR : null,
                    'tmb'     => $tmbRows->map(fn ($tmb) => [
                        'nomor'   => $tmb->SUNOTRANSAKSI,
                        'tanggal' => $tmb->SUTANGGAL,
                        'batal'   => (int) $tmb->SUSTATUS === 9,
                        'qty'     => $tmb->SDMASUK !== null ? (float) $tmb->SDMASUK : null,
                    ])->all(),
                ];
            }

            $result[] = [
                'item'       => (int) $l->PBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PBDITEM),
                'qtyDiminta' => (float) $l->PBDQTY,
                // Ground truth dari baris KMB yg BARU ditelusuri (BUKAN kolom bookkeeping
                // manapun - alasan sama spt di `history()`).
                'qtyDitarik' => array_sum(array_column($kmbList, 'qty')),
                'satuan'     => $l->satuan_kode ?? '',
                'kmb'        => $kmbList,
            ];
        }

        return $result;
    }

    /**
     * Total sisa qty yg BELUM ditarik ke PKB manapun (`SUM(PBDQTY - PBDQTYPAKAI)`).
     * Formula ini SENGAJA dipakai KONSISTEN di sini & di applyPull()/revertPull() di
     * bawah (bukan `PBDQTYKONFIRMASI` spt VB6 asli - kolom itu TIDAK PERNAH diisi oleh
     * verify() kita krn approval v1 cuma header-level, bukan per-baris, jadi kalau
     * dipakai APA ADANYA rumus VB6 akan salah selalu-habis sejak tarikan pertama).
     */
    public function sisaQty(int $prId): float
    {
        return (float) DB::table('fpermintaanbarangd')
            ->where('PBDIDSU', $prId)
            ->selectRaw('COALESCE(SUM(PBDQTY - PBDQTYPAKAI), 0) AS sisa')
            ->value('sisa');
    }

    /**
     * PR yg boleh ditarik ke PKB baru: sudah Disetujui (status 2) & masih ada sisa qty
     * (belum full ditarik). Dipakai picker "Tarik dari PR" di PkbList.
     */
    /**
     * SENGAJA TIDAK dibatasi cabang (beda dari picker "tarik" modul lain) - PKB dibuat
     * TERPUSAT oleh SATU user yg urus SEMUA cabang (dikonfirmasi user 2026-09-24), jadi
     * picker ini MEMANG harus nampilin PR Disetujui dari SELURUH cabang sekaligus. Kolom
     * `cabang` (nama cabang PEMINTA, `PBUGUDANG`) disertakan/ditampilkan biar user pusat
     * bisa BEDAKAN/CARI per cabang di antara banyak PR campuran, bukan utk membatasi.
     */
    public function pullableForPkb()
    {
        return DB::table('fpermintaanbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PBUKONTAK')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.PBUGUDANG')
            ->where('u.PBUSUMBER', self::SUMBER)
            ->where('u.PBUSTATUS', 2)
            ->whereRaw('(select coalesce(sum(d.PBDQTY - d.PBDQTYPAKAI), 0) from fpermintaanbarangd d where d.PBDIDSU = u.PBUID) > 0')
            ->orderByDesc('u.PBUID')
            ->get(['u.PBUID as id', 'u.PBUNOTRANSAKSI as nomor', 'u.PBUTANGGAL as tanggal', 'k.KNAMA as karyawan', 'g.GNAMA as cabang']);
    }

    /**
     * Dipanggil PkbWriter::create() SETELAH baris `fperintahkirimbarangd` di-insert -
     * TIDAK increment `PBDQTYPAKAI` scr manual (DB TRIGGER `fperintahkirimbarangd_add`
     * SUDAH otomatis melakukan `PBDQTYPAKAI += PKBDQTY` per baris begitu insert terjadi,
     * dikonfirmasi via `SHOW TRIGGERS` - pola sama persis trigger stok POS `fstoku_add`/
     * `fstokd_add`). Method ini HANYA meng-cek ULANG sisa (yg kolomnya sudah ter-update
     * trigger) & menaikkan PR ke STATUS_DITARIK_SELESAI (3) kalau sisa = 0. TIDAK
     * menyentuh PR yg statusnya bukan 2 (mis. sudah dibatalkan/9).
     */
    public function syncStatusAfterPull(int $prId): void
    {
        if ($this->sisaQty($prId) <= 0) {
            DB::table('fpermintaanbarangu')->where('PBUID', $prId)->where('PBUSTATUS', 2)
                ->update(['PBUSTATUS' => self::STATUS_DITARIK_SELESAI]);
        }
    }

    /**
     * Dipanggil PkbWriter::cancel() saat PKB dibatalkan. BEDA dari syncStatusAfterPull()
     * di atas - di sini `PBDQTYPAKAI` PERLU di-decrement SCR MANUAL, krn pembatalan PKB
     * di app ini SOFT (`PKBUSTATUS=9`, baris `fperintahkirimbarangd` TIDAK ikut dihapus,
     * per konvensi "tidak ada hapus" yg sudah berlaku di PR/POS) - trigger DELETE
     * (`fperintahkirimbarangd_dell`) yg SEHARUSNYA membalik `PBDQTYPAKAI` otomatis TIDAK
     * PERNAH terpicu krn barisnya memang tidak dihapus. $qtyByLineId = [PBDID => qty yg
     * sempat ditarik PKB ini]. Setelah dikembalikan, PR yg tadinya "Sudah Ditarik" (3)
     * balik ke "Disetujui" (2) kalau sisa jadi > 0 lagi.
     */
    public function releaseOnCancel(int $prId, array $qtyByLineId): void
    {
        foreach ($qtyByLineId as $lineId => $qty) {
            if ((float) $qty <= 0) {
                continue;
            }
            DB::table('fpermintaanbarangd')->where('PBDID', $lineId)->decrement('PBDQTYPAKAI', (float) $qty);
        }

        if ($this->sisaQty($prId) > 0) {
            DB::table('fpermintaanbarangu')->where('PBUID', $prId)->where('PBUSTATUS', self::STATUS_DITARIK_SELESAI)
                ->update(['PBUSTATUS' => 2]);
        }
    }

    /**
     * Total qty SUDAH DITERIMA cabang tujuan (via PBC) utk PR ini. **SENGAJA TIDAK pakai
     * `PBDQTYTERIMA`** - kolom itu TERBUKTI (data NYATA hasil import user 2026-09-18)
     * dobel/tripel-hitung krn DB TRIGGER `fstokd_add` menambah `PBDQTYTERIMA` utk BARIS
     * APAPUN yg py `SDPBDID` terisi, TERMASUK baris SJ (qty di `SDKELUAR`) - jadi kolom
     * itu SUDAH tercemar sejak tahap SJ, SEBELUM barang beneran diterima cabang. Method
     * ini hitung ULANG scr akurat langsung dari `SUM(fstokd.SDMASUK) WHERE SDSUMBER=
     * 'PBC'` (SATU-SATUNYA sumber "beneran diterima" yg valid).
     */
    public function totalDiterima(int $prId): float
    {
        return (float) DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('fpermintaanbarangd as pbd', 'pbd.PBDID', '=', 'd.SDPBDID')
            ->where('pbd.PBDIDSU', $prId)
            ->where('u.SUSUMBER', 'PBC')
            ->sum('d.SDMASUK');
    }

    /**
     * Sisa qty PR yg BELUM diterima cabang tujuan (`SUM(PBDQTY) - totalDiterima()`).
     */
    public function sisaDiterima(int $prId): float
    {
        $totalQty = (float) DB::table('fpermintaanbarangd')->where('PBDIDSU', $prId)->sum('PBDQTY');

        return $totalQty - $this->totalDiterima($prId);
    }

    /**
     * Dipanggil `PbcWriter::create()` setelah baris `fstokd` (SUSUMBER='PBC') di-insert.
     * PR → status 6 ("Selesai Diterima Cabang") kalau sisa sudah habis, atau 5
     * ("Progress Diterima Cabang") kalau masih ada sisa tp SUDAH ada yg diterima (label
     * status 5/6 dari VB6 asli `fFrmPermintaanbarangData.frm`, titik penulisannya persis
     * di PBC ini - dikonfirmasi jg dari `fFrmPenerimaanBarangDariSJ.frm::SetStatusOrder`).
     * TIDAK menyentuh PR berstatus 9 (batal).
     */
    public function syncStatusAfterReceive(int $prId): void
    {
        $h = $this->header($prId);
        if (! $h || (int) $h->PBUSTATUS === self::STATUS_BATAL) {
            return;
        }

        $sisa = $this->sisaDiterima($prId);
        $status = $sisa <= 0 ? 6 : 5;

        DB::table('fpermintaanbarangu')->where('PBUID', $prId)->update(['PBUSTATUS' => $status]);
    }

    /**
     * Dipanggil `PbcWriter::cancel()` stlh PBC dibatalkan (baris `fstokd` di-nolkan
     * `SDMASUK`, lihat `SjWriter::cancel()` utk pola yg sama). Hitung ULANG status PR
     * scr akurat dari kondisi SAAT INI: sisa diterima = 0 → TETAP 6 (jarang, PBC lain
     * mungkin masih menutupi), > 0 tp ada yg pernah diterima → 5, kalau BELUM PERNAH ada
     * penerimaan sama sekali (`totalDiterima()=0`) → balik ke 4 ("Sedang Dikirim").
     * TIDAK menyentuh PR berstatus 9 (batal).
     */
    public function releaseOnCancelPbc(int $prId): void
    {
        $h = $this->header($prId);
        if (! $h || (int) $h->PBUSTATUS === self::STATUS_BATAL) {
            return;
        }

        $diterima = $this->totalDiterima($prId);
        $sisa = $this->sisaDiterima($prId);

        $status = match (true) {
            $sisa <= 0    => 6,
            $diterima > 0 => 5,
            default       => 4,
        };

        DB::table('fpermintaanbarangu')->where('PBUID', $prId)->update(['PBUSTATUS' => $status]);
    }

    /**
     * PR jenis=0 (Permintaan Barang - alur PARALEL "tanpa verifikasi", RS -> KMB -> TMB,
     * BEDA TOTAL dari jenis=1 yg pakai PR->PKB->SJ->PBC di atas) yg boleh ditarik jadi
     * KMB baru: `PBUSTATUS<>9` SAJA (SENGAJA TANPA syarat verifikasi apapun, sesuai
     * instruksi user 2026-09-19 & dikonfirmasi VB6 asli `fFrmKirimMutasiBarang.frm` yg
     * baca `fpermintaanbarangu` tanpa filter status), DAN `PBUSTATUSKM=0` (belum ada KMB
     * hidup - lihat docblock kelas `KmbWriter` soal kolom ini, DB TRIGGER `fstoku_add`
     * yg maintain-nya otomatis begitu KMB dibuat/dibatalkan). TIDAK ada formula
     * sisa/qty spt `pullableForPkb()` krn `PBDQTYPAKAI` TERBUKTI tidak dipakai jalur
     * ini (satu2nya gate valid adalah boolean `PBUSTATUSKM`, bukan qty) - artinya
     * "satu PR jenis=0 = satu KMB, full qty", TIDAK bertahap.
     *
     * DAN `PBUGUDANGSUMBER` ("Gudang Tujuan/PO Ke" di form PR - utk kategori "Cabang"
     * ini hasil CARI MANUAL user pembuat PR, lihat docblock `PrForm::$gudangSumber`)
     * = `$gudangPengirim` (WAJIB diisi pemanggil) - per instruksi user 2026-09-22: "data
     * PR yang tampil adalah yang PBUGUDANGSUMBER = gudang user login". `PBUGUDANGSUMBER`
     * di sini = cabang yg DIMINTA MENGIRIM (sumber stok), jadi picker KMB HARUS
     * dibatasi ke PR yg memang menyebut cabang pembuat KMB sbg sumbernya - TANPA ini,
     * cabang MANAPUN bisa mengirim (KMB) memenuhi PR org lain yg sebenarnya ditujukan
     * ke cabang tertentu.
     */
    public function pullableForKmb(int $gudangPengirim)
    {
        return DB::table('fpermintaanbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PBUKONTAK')
            ->where('u.PBUSUMBER', self::SUMBER)
            ->where('u.PBUJENIS', 0)
            ->where('u.PBUSTATUS', '<>', self::STATUS_BATAL)
            ->where('u.PBUSTATUSKM', 0)
            ->where('u.PBUGUDANGSUMBER', $gudangPengirim)
            ->orderByDesc('u.PBUID')
            ->get(['u.PBUID as id', 'u.PBUNOTRANSAKSI as nomor', 'u.PBUTANGGAL as tanggal', 'k.KNAMA as karyawan']);
    }
}
