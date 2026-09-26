<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Job Order Produksi (JOP) - tabel legacy `fproduksiu` (header) + `fproduksid` (detail),
 * BUKAN `fstoku`/`fstokd` (BEDA dari SJ/PBC/KMB/TMB) - dokumen RENCANA produksi (target
 * produk jadi + resep bahan baku), TIDAK ADA trigger stok sama sekali di tabel ini. VB6
 * asli: `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\fFrmProduksi_JobOrder.frm`.
 *
 * PUSUMBER = 'JOP' (aanomor NKODE='JOP'). Nomor: {kodecabang}-JOP{yymm}{NNNN} -
 * kodecabang dari Gudang Produksi (cabang pembuat/login).
 *
 * PUSTATUS: 1 = Aktif/Belum Ditarik (default saat dibuat - GANTI dari VB6 asli yg
 * selalu simpan 1 tanpa makna lebih lanjut), 2 = Sebagian Ditarik, 3 = Selesai Ditarik
 * (2/3 dihitung dari `SUM(PDMASUK-PDMASUKPAKAI)` per header - PERSIS formula
 * `SetStatusOrder`/`KembalikanStatusOrder` VB6 asli di `fFrmProduksi.frm`), 9 = Batal
 * (konvensi universal kita - user KONFIRMASI 2026-09-23 JOP TIDAK PERNAH di-hard-delete,
 * BEDA dari VB6 asli yg literally `DELETE FROM fproduksid/fproduksiu` & BEDA dari data
 * nyata yg ternyata SEMUA histori JOP-nya sudah terhapus permanen - kita SENGAJA TIDAK
 * ikuti itu).
 *
 * PDMASUKPAKAI (kolom `fproduksid`) = running-total qty yg SUDAH ditarik ke Produksi
 * manapun via `SDIDJOP` - DI-MAINTAIN OTOMATIS oleh DB TRIGGER `fstokd_add/_edit/_DELL`
 * (trigger GENERIC yg SAMA dipakai POS/SJ/PBC/KMB/TMB, dicek `information_schema.
 * TRIGGERS` - TERBUKTI HIDUP & benar, BEDA dari `PBDQTYPAKAI`-utk-KMB yg mati). JANGAN
 * pernah tulis manual ke kolom ini - lihat `syncStatusAfterPull()`/`releaseOnCancelPro()`
 * di bawah, method itu HANYA baca ulang (kolom sudah ter-update trigger) & set status.
 *
 * PDURUTANKEPALA (kolom `fproduksid`) = kunci pengelompokan: baris bahan baku (PDKELUAR)
 * menunjuk ke `PDURUTAN` baris produk jadi (PDMASUK) induknya dlm 1 `fproduksiu` yg sama
 * - dipakai `linesWithKomposisi()` utk menyusun nested array per baris produk jadi.
 */
class JopWriter
{
    public const SUMBER = 'JOP';

    public const STATUS_AKTIF = 1;

    public const STATUS_SEBAGIAN = 2;

    public const STATUS_SELESAI = 3;

    public const STATUS_BATAL = 9;

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('fproduksiu')
            ->where('PUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(PUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array $header kolom PU* (tanpa PUNOTRANSAKSI/PUID/PUSUMBER/PUSTATUS)
     * @param list<array{item:int,qty:float,satuan:?int,catatan:?string,komposisi:list<array{item:int,qty:float,satuan:?int,catatan:?string}>}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail produk jadi kosong.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor) {
                $header['PUNOTRANSAKSI'] = $nomor;
                $header['PUSUMBER'] = self::SUMBER;
                $header['PUSTATUS'] = self::STATUS_AKTIF;
                $header['PUCREATEU'] = auth()->id();

                $id = (int) DB::table('fproduksiu')->insertGetId($header, 'PUID');
                $this->writeLines($id, $lines, (int) $header['PUCABANG']);

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
            return ['ok' => false, 'error' => 'Detail produk jadi kosong.'];
        }

        $h = $this->header($id);
        if (! $h || (int) $h->PUSTATUS !== self::STATUS_AKTIF) {
            return ['ok' => false, 'error' => 'JOP tidak ditemukan atau sudah pernah ditarik/dibatalkan.'];
        }

        unset($header['PUNOTRANSAKSI'], $header['PUSUMBER'], $header['PUSTATUS'], $header['PUCREATEU']);
        $header['PUMODIFU'] = auth()->id();
        $header['PUMODIFD'] = now();

        try {
            DB::transaction(function () use ($id, $header, $lines) {
                DB::table('fproduksiu')->where('PUID', $id)->update($header);
                DB::table('fproduksid')->where('PDIDSU', $id)->delete();
                $this->writeLines($id, $lines, (int) $header['PUCABANG']);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Tulis baris produk jadi + komposisi bahan bakunya. Urutan generic dari 1 utk semua
     * baris dlm 1 header (bahan baku baris ke-N + produk jadi baris ke-N SAMA urutan
     * numbering-nya spt legacy) - `PDURUTANKEPALA` (SEMUA baris, termasuk produk jadi
     * sendiri) menunjuk urutan baris PRODUK JADI induknya, dipakai `linesWithKomposisi()`
     * utk regroup.
     */
    private function writeLines(int $id, array $lines, int $gudang): void
    {
        $urut = 1;
        foreach ($lines as $l) {
            $urutanProdukJadi = $urut;

            DB::table('fproduksid')->insert([
                'PDIDSU'         => $id,
                'PDURUTAN'       => $urut++,
                'PDSUMBER'       => self::SUMBER,
                'PDITEM'         => $l['item'],
                'PDMASUK'        => $l['qty'],
                'PDMASUKD'       => $l['qty'],
                'PDKELUAR'       => 0,
                'PDKELUARD'      => 0,
                'PDSATUAN'       => $l['satuan'] ?: null,
                'PDSATUAND'      => $l['satuan'] ?: null,
                'PDCATATAN'      => $l['catatan'] ?: null,
                'PDGUDANG'       => $gudang,
                'PDURUTANKEPALA' => $urutanProdukJadi,
            ]);

            foreach ($l['komposisi'] as $k) {
                $qty = (float) $k['qty'];
                if ($qty <= 0) {
                    continue;
                }
                DB::table('fproduksid')->insert([
                    'PDIDSU'         => $id,
                    'PDURUTAN'       => $urut++,
                    'PDSUMBER'       => self::SUMBER,
                    'PDITEM'         => $k['item'],
                    'PDMASUK'        => 0,
                    'PDMASUKD'       => 0,
                    'PDKELUAR'       => $qty,
                    'PDKELUARD'      => $qty,
                    'PDSATUAN'       => $k['satuan'] ?: null,
                    'PDSATUAND'      => $k['satuan'] ?: null,
                    'PDCATATAN'      => $k['catatan'] ?: null,
                    'PDGUDANG'       => $gudang,
                    'PDURUTANKEPALA' => $urutanProdukJadi,
                ]);
            }
        }
    }

    /**
     * Batalkan JOP - SOFT status (PUSTATUS=STATUS_BATAL), TIDAK PERNAH hard-delete
     * (Keputusan user 2026-09-23). Hanya JOP yg BELUM PERNAH ditarik (status 1) boleh
     * dibatalkan langsung - kalau sudah sebagian/selesai ditarik (2/3), batalkan lewat
     * pembatalan Produksi terkait dulu.
     */
    public function cancel(int $id): bool
    {
        $h = $this->header($id);
        if (! $h || (int) $h->PUSTATUS !== self::STATUS_AKTIF) {
            return false;
        }

        DB::table('fproduksiu')->where('PUID', $id)->update([
            'PUSTATUS' => self::STATUS_BATAL,
            'PUMODIFU' => auth()->id(),
            'PUMODIFD' => now(),
        ]);

        return true;
    }

    public function header(int $id): ?object
    {
        return DB::table('fproduksiu')->where('PUID', $id)->where('PUSUMBER', self::SUMBER)->first();
    }

    /**
     * Baris produk jadi + nested komposisi bahan baku, dikelompokkan via
     * `PDURUTANKEPALA` (lihat docblock kelas).
     *
     * @return list<array{pdid:int,item:int,kode:string,nama:string,qty:float,satuan:?int,satuanKode:string,catatan:?string,qtyPakai:float,komposisi:list<object>}>
     */
    public function linesWithKomposisi(int $id): array
    {
        $rows = DB::table('fproduksid as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.PDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.PDSATUAN')
            ->where('d.PDIDSU', $id)
            ->orderBy('d.PDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode']);

        $produkJadi = [];
        foreach ($rows as $r) {
            if ((float) $r->PDMASUK > 0) {
                $produkJadi[(int) $r->PDURUTAN] = [
                    'pdid'       => (int) $r->PDID,
                    'item'       => (int) $r->PDITEM,
                    'kode'       => $r->IKODE ?? '',
                    'nama'       => $r->INAMA ?? ('Item #' . $r->PDITEM),
                    'qty'        => (float) $r->PDMASUK,
                    'qtyPakai'   => (float) $r->PDMASUKPAKAI,
                    'satuan'     => $r->PDSATUAN ? (int) $r->PDSATUAN : null,
                    'satuanKode' => $r->satuan_kode ?? '',
                    'catatan'    => $r->PDCATATAN,
                    'komposisi'  => [],
                ];
            }
        }

        foreach ($rows as $r) {
            if ((float) $r->PDKELUAR > 0 && isset($produkJadi[(int) $r->PDURUTANKEPALA])) {
                $produkJadi[(int) $r->PDURUTANKEPALA]['komposisi'][] = [
                    'item'       => (int) $r->PDITEM,
                    'kode'       => $r->IKODE ?? '',
                    'nama'       => $r->INAMA ?? ('Item #' . $r->PDITEM),
                    'qty'        => (float) $r->PDKELUAR,
                    'satuan'     => $r->PDSATUAN ? (int) $r->PDSATUAN : null,
                    'satuanKode' => $r->satuan_kode ?? '',
                    'catatan'    => $r->PDCATATAN,
                ];
            }
        }

        return array_values($produkJadi);
    }

    /**
     * Total sisa qty (SEMUA baris produk jadi) yg BELUM ditarik ke Produksi manapun.
     * Formula PERSIS `SetStatusOrder`/`KembalikanStatusOrder` VB6 asli, kolom
     * `PDMASUKPAKAI` TERBUKTI hidup (trigger `fstokd_add/_edit/_DELL` via SDIDJOP).
     */
    public function sisaQty(int $jopId): float
    {
        return (float) DB::table('fproduksid')
            ->where('PDIDSU', $jopId)
            ->where('PDMASUK', '>', 0)
            ->selectRaw('COALESCE(SUM(PDMASUK - PDMASUKPAKAI), 0) AS sisa')
            ->value('sisa');
    }

    /**
     * Dipanggil `ProduksiWriter::create()` SETELAH baris `fstokd` (dgn SDIDJOP terisi)
     * di-insert - TIDAK increment `PDMASUKPAKAI` scr manual (trigger SUDAH melakukannya
     * otomatis, lihat docblock kelas). HANYA baca ulang sisa & set status 2/3. TIDAK
     * menyentuh JOP berstatus 9 (batal).
     */
    public function syncStatusAfterPull(int $jopId): void
    {
        $h = $this->header($jopId);
        if (! $h || (int) $h->PUSTATUS === self::STATUS_BATAL) {
            return;
        }

        $status = $this->sisaQty($jopId) <= 0 ? self::STATUS_SELESAI : self::STATUS_SEBAGIAN;
        DB::table('fproduksiu')->where('PUID', $jopId)->update(['PUSTATUS' => $status]);
    }

    /**
     * Dipanggil `ProduksiWriter::cancel()` stlh baris `fstokd` terkait dinolkan
     * (`SDMASUK`/`SDKELUAR`=0) - trigger `fstokd_edit` OTOMATIS membalik `PDMASUKPAKAI`
     * (net-delta NEW-OLD, TIDAK digate SDCANCEL, lihat docblock kelas). Method ini HANYA
     * baca ulang sisa & set status 1 (kalau tidak ada SAMA SEKALI yg pernah ditarik) atau
     * 2 (masih ada baris LAIN yg pernah/sedang ditarik). TIDAK menyentuh JOP berstatus 9.
     */
    public function releaseOnCancelPro(int $jopId): void
    {
        $h = $this->header($jopId);
        if (! $h || (int) $h->PUSTATUS === self::STATUS_BATAL) {
            return;
        }

        $sisa = $this->sisaQty($jopId);
        $totalQty = (float) DB::table('fproduksid')->where('PDIDSU', $jopId)->where('PDMASUK', '>', 0)->sum('PDMASUK');
        $sudahDitarik = $totalQty - $sisa;

        $status = $sudahDitarik > 0 ? self::STATUS_SEBAGIAN : self::STATUS_AKTIF;
        DB::table('fproduksiu')->where('PUID', $jopId)->update(['PUSTATUS' => $status]);
    }

    /**
     * JOP yg boleh ditarik ke Produksi baru: `PUCABANG` (Gudang Produksi) = cabang user
     * login (KEDUA sisi JOP & Produksi SAMA2 dibuat cabang yg sama - beda dari KMB/TMB yg
     * lintas-cabang, TIDAK perlu klarifikasi tambahan), `PUSTATUS` 1 (belum ditarik) atau
     * 2 (sebagian ditarik, masih ada sisa). Dipakai picker "Tarik dari Job Order" di
     * `ProduksiList`.
     */
    public function pullableForPro(int $gudangProduksi)
    {
        return DB::table('fproduksiu as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.PUKONTAK')
            ->where('u.PUSUMBER', self::SUMBER)
            ->where('u.PUCABANG', $gudangProduksi)
            ->whereIn('u.PUSTATUS', [self::STATUS_AKTIF, self::STATUS_SEBAGIAN])
            ->orderByDesc('u.PUID')
            ->get(['u.PUID as id', 'u.PUNOTRANSAKSI as nomor', 'u.PUTANGGAL as tanggal', 'k.KNAMA as karyawan']);
    }
}
