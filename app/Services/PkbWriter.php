<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Perintah Kirim Barang (PKB) - tabel legacy fperintahkirimbarangu (header) +
 * fperintahkirimbarangd (detail). Berfungsi sbg PO: otorisasi gudang pengirim utk
 * menyiapkan/mengirim barang yg diminta lewat PR (Permintaan Barang) yg SUDAH
 * diverifikasi (PBUSTATUS=2). Satu PKB = satu PR (PKBUNORS wajib, lihat
 * PurchaseRequestWriter::pullableForPkb()/applyPull()/revertPull() - PR yg sama BOLEH
 * ditarik lebih dari satu PKB scr bertahap selama masih ada sisa qty, pola asli
 * VB6 `fFrmPerintahkirimBarang.frm`).
 *
 * PKBUSUMBER = 'PKB' (aanomor NKODE='PKB', diseed migration terpisah). Nomor:
 * {kodecabang}-PKB{yymm}{NNNN} - kodecabang dari cabang PEMBUAT PKB (gudang
 * pengirim/pusat, BUKAN cabang PR peminta).
 *
 * PKBUSTATUS: 0 = baru, 9 = batal (konvensi sama fstoku/PR - "samakan dengan POS,
 * tidak ada hapus, adanya rubah status").
 *
 * PKBUGUDANGSUMBER/PKBUTIPEPERMINTAAN di-copy read-only dari PR sumber saat dibuat
 * (deviasi kecil dari VB6 asli yg tidak pernah menulis 2 kolom ini - murni kenyamanan
 * tampilan list/filter, PKBUNORS tetap satu2nya sumber kebenaran).
 */
class PkbWriter
{
    public const SUMBER = 'PKB';

    public const STATUS_BATAL = 9;

    public function __construct(private PurchaseRequestWriter $prWriter)
    {
    }

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('fperintahkirimbarangu')
            ->where('PKBUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(PKBUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Data siap-pull dari 1 PR (dipanggil PkbForm::mount(prId:) utk PKB baru): header
     * PR + baris sisa qty (PBDQTY - PBDQTYPAKAI) yg masih > 0, plus saldo 3bln/1bln.
     *
     * @return array{header:object,lines:array}|null null kalau PR tidak valid/PBUSTATUS<>2.
     */
    public function fromPr(int $prId): ?array
    {
        $header = $this->prWriter->header($prId);
        if (! $header || (int) $header->PBUSTATUS !== 2) {
            return null;
        }

        $lines = [];
        foreach ($this->prWriter->lines($prId) as $l) {
            $sisa = (float) $l->PBDQTY - (float) $l->PBDQTYPAKAI;
            if ($sisa <= 0) {
                continue;
            }
            $lines[] = [
                'rsidd'      => (int) $l->PBDID,
                'item'       => (int) $l->PBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PBDITEM),
                'sisa'       => $sisa,
                'qty'        => $sisa,
                'satuan'     => $l->PBDSATUAN ? (int) $l->PBDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => null,
                'saldo3'     => (float) (DB::selectOne('SELECT F_SALDO3BULANLALU(?, ?) AS v', [$l->PBDITEM, $header->PBUGUDANG])->v ?? 0),
                'saldo1'     => (float) (DB::selectOne('SELECT F_SALDO1LALU(?, ?) AS v', [$l->PBDITEM, $header->PBUGUDANG])->v ?? 0),
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom PKBU* (tanpa PKBUNOTRANSAKSI/PKBUID/PKBUSUMBER/PKBUSTATUS)
     * @param list<array{rsidd:int,item:int,qty:float,satuan:?int,catatan:?string}> $lines
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
                // Re-cek server-side sisa PR SAAT INI (bukan cuma nilai yg dibawa form) -
                // mencegah race/overdraw kalau ada PKB lain menarik PR yg sama duluan.
                $sisaByLine = [];
                foreach ($this->prWriter->lines($prId) as $l) {
                    $sisaByLine[(int) $l->PBDID] = (float) $l->PBDQTY - (float) $l->PBDQTYPAKAI;
                }

                foreach ($lines as $l) {
                    $rsidd = (int) $l['rsidd'];
                    $qty = (float) $l['qty'];
                    $sisa = $sisaByLine[$rsidd] ?? 0;
                    if ($qty <= 0 || $qty > $sisa + 0.0001) {
                        throw new \RuntimeException("Qty dikirim untuk item melebihi sisa yang tersedia (sisa: {$sisa}).");
                    }
                }

                $header['PKBUNOTRANSAKSI'] = $nomor;
                $header['PKBUSUMBER'] = self::SUMBER;
                $header['PKBUSTATUS'] = 0;
                $header['PKBUCREATEU'] = auth()->id();
                $header['PKBUNORS'] = $prId;

                $id = (int) DB::table('fperintahkirimbarangu')->insertGetId($header, 'PKBUID');

                // Insert baris PKBD - DB TRIGGER `fperintahkirimbarangd_add` OTOMATIS
                // meng-increment `fpermintaanbarangd.PBDQTYPAKAI` per baris begitu insert
                // ini terjadi (lihat docblock PurchaseRequestWriter::syncStatusAfterPull()) -
                // JANGAN duplikasi increment scr manual di sini.
                $urut = 1;
                foreach ($lines as $l) {
                    DB::table('fperintahkirimbarangd')->insert([
                        'PKBDIDSU'     => $id,
                        'PKBDURUTAN'   => $urut++,
                        'PKBDSUMBER'   => self::SUMBER,
                        'PKBDITEM'     => $l['item'],
                        'PKBDQTY'      => $l['qty'],
                        'PKBDQTYD'     => $l['qty'],
                        'PKBDSATUAN'   => $l['satuan'] ?: null,
                        'PKBDSATUAND'  => $l['satuan'] ?: null,
                        'PKBDCATATAN'  => $l['catatan'] ?: null,
                        'PKBDRSIDD'    => $l['rsidd'],
                        'PKBDRSIDU'    => $prId,
                        'PKBD3BULAN'   => $l['saldo3'] ?? 0,
                        'PKBD1BULAN'   => $l['saldo1'] ?? 0,
                    ]);
                }

                $this->prWriter->syncStatusAfterPull($prId);

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan PKB - SOFT status (PKBUSTATUS=STATUS_BATAL), TIDAK hard-delete baris
     * `fperintahkirimbarangd` (krn TIDAK dihapus, trigger DELETE tidak ikut terpicu utk
     * membalik `PBDQTYPAKAI` di PR - makanya `releaseOnCancel()` melakukannya scr manual,
     * lihat docblock method itu). Hanya PKB berstatus 0 (baru) yg bisa dibatalkan.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->PKBUSTATUS !== 0) {
                    throw new \RuntimeException('PKB tidak ditemukan atau tidak bisa dibatalkan.');
                }

                $qtyByLineId = [];
                foreach ($this->lines($id) as $l) {
                    $qtyByLineId[(int) $l->PKBDRSIDD] = (float) $l->PKBDQTY;
                }

                DB::table('fperintahkirimbarangu')->where('PKBUID', $id)->update([
                    'PKBUSTATUS' => self::STATUS_BATAL,
                    'PKBUMODIFU' => auth()->id(),
                    'PKBUMODIFD' => now(),
                ]);

                $this->prWriter->releaseOnCancel((int) $h->PKBUNORS, $qtyByLineId);
            });

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function header(int $id): ?object
    {
        return DB::table('fperintahkirimbarangu')->where('PKBUID', $id)->first();
    }

    public function lines(int $id): array
    {
        return DB::table('fperintahkirimbarangd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.PKBDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.PKBDSATUAN')
            ->where('d.PKBDIDSU', $id)
            ->orderBy('d.PKBDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ISERIAL', 'i.IQTYPERBOX', 's.SKODE as satuan_kode'])
            ->all();
    }

    /**
     * PKB yg boleh ditarik ke SJ baru: belum batal (`PKBUSTATUS=0`) & masih ada sisa qty
     * yg belum dikirim (`PKBDQTY-PKBDQTYPAKAI>0` di min. 1 baris) - PERSIS pola
     * `PurchaseRequestWriter::pullableForPkb()`. Dipakai picker "Tarik dari PKB" di
     * `SjList` (lihat `App\Services\SjWriter`).
     */
    /**
     * PKB yg masih punya sisa qty, utk pemilih "SJ Baru".
     *
     * **WAJIB DIBATASI.** Sampai 2026-10-03 method ini mengembalikan SEMUA baris dan
     * `SjList` menyaringnya di PHP - di data nyata itu **342 PKB**, dan modal pemilihnya
     * merender semuanya sekaligus: satu respons Livewire jadi **196 KB** (vs 3,9 KB saat
     * pemilih tertutup). Di jaringan klinik respons sebesar itu rapuh - user melaporkan
     * `ERR_QUIC_PROTOCOL_ERROR`/"Failed to fetch" persis saat membuka & menarik PKB. Jumlahnya
     * cuma akan bertambah seiring waktu, jadi ini bukan masalah sesaat.
     *
     * Penyaringan DIDORONG KE SQL (dulu `->filter()` atas koleksi penuh) supaya 342 baris tidak
     * ditarik hanya untuk dibuang. `LIKE '%..%'` di sini AMAN walau menyentuh `bkontak`: tabel
     * penggeraknya `fperintahkirimbarangu` (sudah disaring `PKBUSUMBER`+`PKBUSTATUS`), jadi
     * yang dicocokkan hanya baris hasil join - bukan pemindaian 321rb kontak. Aturan
     * "jangan leading-wildcard di bkontak" tetap berlaku untuk pencarian yg DIGERAKKAN bkontak.
     *
     * Pemanggil mengambil `$batas + 1` baris agar bisa tahu "masih ada lagi" tanpa query COUNT
     * terpisah.
     */
    public function pullableForSj(?string $cari = null, int $batas = 25)
    {
        $cari = trim((string) $cari);

        return DB::table('fperintahkirimbarangu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PKBUKONTAK')
            ->where('u.PKBUSUMBER', self::SUMBER)
            ->where('u.PKBUSTATUS', 0)
            ->whereRaw('(select coalesce(sum(d.PKBDQTY - d.PKBDQTYPAKAI), 0) from fperintahkirimbarangd d where d.PKBDIDSU = u.PKBUID) > 0')
            ->when($cari !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.PKBUNOTRANSAKSI', 'like', "%{$cari}%")
                ->orWhere('k.KNAMA', 'like', "%{$cari}%")))
            ->orderByDesc('u.PKBUID')
            ->limit(max(1, $batas))
            ->get(['u.PKBUID as id', 'u.PKBUNOTRANSAKSI as nomor', 'u.PKBUTANGGAL as tanggal', 'k.KNAMA as karyawan']);
    }
}
