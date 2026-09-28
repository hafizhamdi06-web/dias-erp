<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pengeluaran Lain (PL) - pengeluaran stok manual yg BUKAN penjualan/mutasi (pemakaian bahan
 * baku, sample, dipakai produksi, dll). Tabel legacy `fstoku`/`fstokd` dgn `SUSUMBER='PL'`.
 *
 * Kembar `PenyesuaianWriter` (PY) - permintaan user: "inputan sama seperti Penyesuaian Barang,
 * tapi ini hanya keluar saja". Satu-satunya beda substansial: **TIDAK ADA kolom Masuk**,
 * `SDMASUK`/`SDMASUKD` selalu 0 dan baris divalidasi `keluar > 0`.
 *
 * **Bukti struktur dari data nyata** (`RB-PL26060001`, SUID 1221092, satu-satunya PL hasil
 * impor): header identik PY - `SUKONTAK`, `SUCABANG`=34, `SUURAIAN`='Pengeluaran Lain',
 * `SUJENISPENYESUAIAN`=6 (Pemakaian Bahan Baku Cair), `SUSTATUS`=0; 2 baris detail dgn
 * `SDMASUK=0`, `SDKELUAR=3`/`2`, `SDCATATAN`='dipakai produksi'. Jadi PL **ikut memakai
 * `bjenispenyesuaian`** (tidak punya tabel jenis sendiri) - dropdown Jenis sama dgn PY.
 *
 * **CATATAN legacy**: `PL` TIDAK terdaftar di `aanomor` (PY ada, NID 706) maupun di
 * `ajenistransaksistok` - jelas modul yg ditambahkan belakangan tanpa melengkapi tabel
 * referensinya. Tidak mengganggu kita: `nextNumber()` di sini menghitung sendiri dari
 * `SUNOTRANSAKSI` (pola sama semua writer kita), dan format yg dihasilkan sudah dicek sama
 * dgn nomor legacy (`RB-PL26060001` = kodecabang-PL + yymm + urut 4 digit).
 *
 * **Trigger**: sudah diperiksa (`SHOW TRIGGERS`) - `fstoku_add`/`fstokd_add`/`_edit`/`_DELL`
 * TIDAK punya cabang khusus `SUSUMBER='PL'` (beda dgn 'PY' yg menyentuh `fstokopnameu`, dan
 * 'SJ'/'IP' yg punya efek sampingnya sendiri). Stok digerakkan trigger generic `fstokd_add`
 * (`+SDMASUK -SDKELUAR` di `SDGUDANG`) - JANGAN bookkeeping manual.
 *
 * **Pembatalan**: SOFT (`SDCANCEL=1` + nolkan `SDKELUAR` + `SUSTATUS=9`), trigger
 * `fstokd_edit` yg mengembalikan stok dari selisih NEW-OLD. Pola sama PY & modul lain.
 *
 * **DEFER**: `bjenispenyesuaian.JAKUNBIAYA` (akun biaya per jenis, utk posting jurnal) -
 * modul ini spt semua modul lain di app ini TIDAK posting jurnal.
 */
class PengeluaranLainWriter
{
    public const SUMBER = 'PL';

    public const STATUS_AKTIF = 0;

    public const STATUS_BATAL = 9;

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
     * Daftar Jenis utk dropdown - tabel SAMA dgn PY (`bjenispenyesuaian`), lihat docblock kelas.
     *
     * @return array<int,object>
     */
    public function jenisOptions(): array
    {
        return DB::table('bjenispenyesuaian')->orderBy('JNAMA')->get(['JID', 'JKODE', 'JNAMA'])->all();
    }

    /**
     * @param array{tanggal:string,kontak:?int,gudang:int,jenis:?int,uraian:?string,kodecabang:string} $meta
     * @param list<array{item:int,keluar:float,satuan:?int,catatan:?string}>                           $lines
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $meta, array $lines): array
    {
        // Hanya baris yg benar2 mengeluarkan stok yg disimpan.
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['keluar'] > 0));

        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Minimal 1 item dengan Keluar lebih dari 0.'];
        }
        if (! (int) $meta['gudang']) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gudang belum dipilih.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tanggal']);

        try {
            $id = DB::transaction(function () use ($meta, $lines, $nomor) {
                $id = (int) DB::table('fstoku')->insertGetId([
                    'SUSUMBER'           => self::SUMBER,
                    'SUNOTRANSAKSI'      => $nomor,
                    'SUTANGGAL'          => $meta['tanggal'],
                    'SUKONTAK'           => $meta['kontak'] ?: null,
                    'SUCABANG'           => $meta['gudang'],
                    'SUJENISPENYESUAIAN' => $meta['jenis'] ?: null,
                    'SUURAIAN'           => $meta['uraian'] ?: null,
                    'SUSTATUS'           => self::STATUS_AKTIF,
                    'SUCREATEU'          => auth()->id(),
                ], 'SUID');

                // DB TRIGGER `fstokd_add` yg menggerakkan stok - JANGAN bookkeeping manual.
                $urut = 1;
                foreach ($lines as $l) {
                    $keluar = max(0.0, (float) $l['keluar']);

                    DB::table('fstokd')->insert([
                        'SDIDSU'    => $id,
                        'SDURUTAN'  => $urut++,
                        'SDSUMBER'  => self::SUMBER,
                        'SDITEM'    => $l['item'],
                        'SDMASUK'   => 0,
                        'SDMASUKD'  => 0,
                        'SDKELUAR'  => $keluar,
                        'SDKELUARD' => $keluar,
                        'SDSATUAN'  => $l['satuan'] ?: null,
                        'SDSATUAND' => $l['satuan'] ?: null,
                        'SDGUDANG'  => $meta['gudang'],
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

    /** Batalkan PL - SOFT, trigger `fstokd_edit` yg mengembalikan stok (lihat docblock kelas). */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('Pengeluaran tidak ditemukan atau sudah dibatalkan.');
                }

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
