<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penyesuaian Barang (PY) - koreksi stok manual (hasil repack, racikan, stok opname,
 * penurunan barang, dll). Tabel legacy `fstoku`/`fstokd` (`SUSUMBER='PY'`, terdaftar di
 * `aanomor` NKODE='PY' = "Penyesuaian Barang"). 600 dokumen PY nyata.
 *
 * **TIDAK ADA file VB6 acuan** di `CODE_VB6` untuk form ini - struktur direkonstruksi dari
 * DATA NYATA + spesifikasi field dari user (2026-09-26).
 *
 * **Satu baris boleh punya MASUK dan KELUAR SEKALIGUS** - bukan salah satu saja. Terbukti
 * di data: `KM-PY26060001` baris "SARUNG TANGAN S" `SDMASUK=200` DAN `SDKELUAR=100`. Trigger
 * generic `fstokd_add`/`_edit`/`_DELL` menghitung stok `bitem` = `+SDMASUK - SDKELUAR`, jadi
 * kombinasi itu sah & dampaknya netto. Baris tanpa masuk MAUPUN keluar dibuang (tidak ada
 * gunanya).
 *
 * **Kolom header**:
 * - `SUKONTAK` = Kontak, `SUCABANG` = Gudang (juga dipakai `SDGUDANG` tiap baris),
 *   `SUJENISPENYESUAIAN` = FK ke `bjenispenyesuaian.JID` (Jenis Penyesuaian:
 *   Racikan 361x, Penurunan Barang 194x, Pembelian Langsung 25x, dst), `SUURAIAN` = Uraian.
 * - **`SUSTATUS` = 0 saat aktif** - 600/600 dokumen nyata bernilai 0 (pola SAMA modul AL,
 *   BEDA dari SJ/PBC/KMB yg pakai 1). Batal = 9 (konvensi kita; di data legacy tidak ada).
 *
 * **Pembatalan**: SOFT (`SDCANCEL=1` + nolkan `SDMASUK`/`SDKELUAR` + `SUSTATUS=9`) - trigger
 * `fstokd_edit` yg mengembalikan stok dari selisih NEW-OLD. Pola sama semua modul kita.
 *
 * **DEFER**: `bjenispenyesuaian.JAKUNBIAYA` (akun biaya per jenis) - dipakai posting jurnal,
 * dan modul ini (spt semua modul lain di app ini) TIDAK posting jurnal.
 */
class PenyesuaianWriter
{
    public const SUMBER = 'PY';

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

    /** @return array<int,object> daftar Jenis Penyesuaian utk dropdown */
    public function jenisOptions(): array
    {
        return DB::table('bjenispenyesuaian')->orderBy('JNAMA')->get(['JID', 'JKODE', 'JNAMA'])->all();
    }

    /**
     * @param array{tanggal:string,kontak:?int,gudang:int,jenis:?int,uraian:?string,kodecabang:string} $meta
     * @param list<array{item:int,masuk:float,keluar:float,satuan:?int,catatan:?string}>               $lines
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $meta, array $lines): array
    {
        // Baris tanpa masuk & tanpa keluar tidak ada gunanya - buang di sini supaya
        // validasi "minimal 1 baris" menilai yg BENAR2 berdampak ke stok.
        $lines = array_values(array_filter(
            $lines,
            fn ($l) => (float) $l['masuk'] > 0 || (float) $l['keluar'] > 0
        ));

        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Minimal 1 item dengan Masuk atau Keluar lebih dari 0.'];
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

                // Baris fstokd - DB TRIGGER `fstokd_add` yg menggerakkan stok
                // (+SDMASUK -SDKELUAR di SDGUDANG). JANGAN bookkeeping manual.
                $urut = 1;
                foreach ($lines as $l) {
                    $masuk = max(0.0, (float) $l['masuk']);
                    $keluar = max(0.0, (float) $l['keluar']);

                    DB::table('fstokd')->insert([
                        'SDIDSU'    => $id,
                        'SDURUTAN'  => $urut++,
                        'SDSUMBER'  => self::SUMBER,
                        'SDITEM'    => $l['item'],
                        'SDMASUK'   => $masuk,
                        'SDMASUKD'  => $masuk,
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

    /** Batalkan PY - SOFT, trigger `fstokd_edit` yg mengembalikan stok (lihat docblock kelas). */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('Penyesuaian tidak ditemukan atau sudah dibatalkan.');
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
