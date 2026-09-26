<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Input Alkes Depo (AL) - mencatat ALAT KESEHATAN / bahan habis pakai yg dipakai untuk
 * SATU BARIS TINDAKAN di sebuah transaksi POS/IP. VB6 asli: `eFrmPOS_DEPO_2.frm`.
 * Tabel legacy `fstoku`/`fstokd` (`SUSUMBER='AL'`, terdaftar di `aanomor` NKODE='AL',
 * NKETERANGAN='Alkes DEPO'). 7.408 dokumen AL nyata (Juni 2026).
 *
 * **SATU DOKUMEN AL = SATU BARIS TINDAKAN**, bukan satu IP. Dikonfirmasi dari data:
 * `CM-AL26060150` & `CM-AL26060151` dua dokumen terpisah yg `SUIDUALKES`-nya sama (IP
 * 1229724) tapi `SDIDUALKES` baris-barisnya beda. Jadi satu IP dgn 3 tindakan -> 3 AL.
 *
 * **Kolom penghubung** (mudah tertukar krn namanya mirip):
 * - `fstoku.SUIDUALKES` = `fstoku.SUID` dokumen **IP** sumbernya.
 * - `fstokd.SDIDUALKES` = `fstokd.SDID` **baris tindakan** di IP itu.
 * - `fstokd.SDIDALKESNYA` = ditulis BALIK ke baris tindakan IP, berisi `SUID` dokumen AL
 *   (VB6 baris 826). Inilah penanda "tindakan ini sudah diinput alkesnya".
 * - `SUNOREF` = nomor transaksi IP (teks), `SUURAIAN` = "Alkes No IP{no} Tindakan {nama}".
 *
 * **Baris tindakan yg boleh diinput alkes**: `bitem.IJENISITEM IN (1,4,5)` DAN
 * `bitem.IRESEP = 0` (VB6 baris 383).
 *
 * **`bitemalkes` = "resep alkes" per tindakan** (`IPIDB` tindakan -> `IPIDBB` alkes,
 * `IPIDQTY`, `IPIDSATUAN`, urut `IPIDURUTAN`) - 3.155 baris untuk 328 tindakan. Dipakai
 * sbg DAFTAR DEFAULT saat tindakan dipilih; user tinggal centang mana yg benar2 dipakai
 * dan boleh mengubah qty-nya.
 *
 * **`SDKELUAR` vs `SDQTYDASAR`**: `SDQTYDASAR` = qty DEFAULT dari resep (`IPIDQTY`),
 * `SDKELUAR` = qty yg BENAR2 dipakai (hasil edit user). Diverifikasi dari data nyata
 * (`CL-AL26060029`: resep SERUM REJUVE 2 -> dipakai 1, `SDQTYDASAR` tetap 2). Untuk alkes
 * yg ditambah manual di luar resep, `SDQTYDASAR` = 0 (VB6 `SetDataBarang` col 6 = 0).
 *
 * **HANYA baris yg DICENTANG yg disimpan** (VB6: `If IG.TextMatrix(pI,5) = "Y"`) - makanya
 * di data nyata sering cuma 3 dari 13 baris resep yg tersimpan.
 *
 * Stok: baris `fstokd` arah KELUAR di `SDGUDANG` - DB TRIGGER `fstokd_add`/`_edit`/`_DELL`
 * (generic, sama POS/SJ/PBC) yg mengurangi stok. TIDAK ada bookkeeping manual.
 *
 * **`SDGUDANG` & `SUCABANG` = cabang IP-nya**. VB6 sedikit tidak konsisten (header pakai
 * `xCabang` user login, baris pakai `SUCABANG` IP); di sini KEDUANYA dari IP, dan picker IP
 * memang sudah dibatasi ke cabang aktif user - jadi nilainya sama, tanpa celah "input alkes
 * cabang lain".
 *
 * **SUSTATUS**: 7.408/7.408 dokumen AL nyata = 0, dan VB6 tidak pernah mengisinya (ikut
 * default). Kita ikut: 0 saat aktif. **Pembatalan TIDAK ADA di VB6** - di sini ditambahkan
 * (`SUSTATUS=9` + `SDCANCEL=1` + nolkan `SDKELUAR`, pola sama semua modul kita) krn tanpa
 * itu salah input alkes tidak bisa dikoreksi sama sekali & stok telanjur berkurang.
 * Saat dibatalkan, `SDIDALKESNYA` di baris tindakan IP ikut DIKOSONGKAN supaya tindakan itu
 * bisa diinput ulang.
 *
 * **DEFER**: harga/diskon alkes (VB6 py kolomnya di grid tapi TIDAK ikut disimpan ke
 * `fstokd` - `zField` simpan tidak memuat `SDHARGA`/`SDDISKON`), dan batch/serial (form VB6
 * ini tidak menyentuh serial sama sekali).
 */
class AlkesWriter
{
    public const SUMBER = 'AL';

    public const STATUS_AKTIF = 0;

    public const STATUS_BATAL = 9;

    /** Jenis item yg dianggap "tindakan" & boleh diinput alkesnya (VB6 baris 383). */
    public const JENIS_TINDAKAN = [1, 4, 5];

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
     * Transaksi IP (POS) di cabang ini yg PUNYA baris tindakan - utk picker "Cari No IP".
     *
     * @return array<int,object>
     */
    public function pullableIp(int $cabang, string $q = '', ?string $dari = null, ?string $sampai = null): array
    {
        return DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUCABANG', $cabang)
            ->when($dari, fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $dari))
            ->when($sampai, fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $sampai))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")))
            ->whereExists(fn ($sub) => $sub->selectRaw(1)->from('fstokd as d')
                ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
                ->whereColumn('d.SDIDSU', 'u.SUID')
                ->whereIn('i.IJENISITEM', self::JENIS_TINDAKAN)
                ->where('i.IRESEP', 0))
            ->orderByDesc('u.SUID')
            ->limit(50)
            ->get([
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUCABANG as cabang', 'u.SUKONTAK as kontak', 'k.KNAMA as pelanggan',
            ])->all();
    }

    /**
     * Header IP + baris TINDAKAN-nya, lengkap dgn penanda sudah/belum diinput alkes.
     *
     * @return array{header:object,lines:array}|null
     */
    public function fromIp(int $ipId, ?int $cabang = null): ?array
    {
        $header = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUID', $ipId)->where('u.SUSUMBER', 'IP')
            ->when($cabang, fn ($b) => $b->where('u.SUCABANG', $cabang))
            ->first([
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUCABANG', 'u.SUKONTAK',
                'k.KKODE as pelanggan_kode', 'k.KNAMA as pelanggan',
            ]);

        if (! $header) {
            return null;
        }

        $lines = DB::table('fstokd as d')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('fstoku as al', 'al.SUID', '=', 'd.SDIDALKESNYA')
            ->where('d.SDIDSU', $ipId)
            ->whereIn('i.IJENISITEM', self::JENIS_TINDAKAN)
            ->where('i.IRESEP', 0)
            ->orderBy('d.SDURUTAN')
            ->get([
                'd.SDID as sdid', 'd.SDITEM as item', 'd.SDKELUAR as qty', 'd.SDNOREF as noRef',
                'i.IKODE as kode', 'i.INAMA as nama',
                'd.SDIDALKESNYA as alkesSuid',
                'al.SUNOTRANSAKSI as alkesNomor', 'al.SUSTATUS as alkesStatus',
            ])->map(fn ($r) => (object) [
                'sdid'       => (int) $r->sdid,
                'item'       => (int) $r->item,
                'kode'       => $r->kode ?? '',
                'nama'       => $r->nama ?? ('Item #' . $r->item),
                'qty'        => (float) $r->qty,
                'noRef'      => $r->noRef,
                // "sudah ada alkes" HANYA kalau dokumen AL-nya memang ada & belum batal -
                // `SDIDALKESNYA` bisa menunjuk dokumen yg sudah dibatalkan.
                'alkesSuid'  => $r->alkesSuid && $r->alkesNomor !== null
                                && (int) $r->alkesStatus !== self::STATUS_BATAL ? (int) $r->alkesSuid : null,
                'alkesNomor' => $r->alkesNomor !== null && (int) $r->alkesStatus !== self::STATUS_BATAL
                                ? $r->alkesNomor : null,
            ])->all();

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * Daftar alkes DEFAULT untuk satu item tindakan (`bitemalkes`). Qty-nya jadi usulan,
     * bukan keharusan - user mencentang & boleh mengubahnya.
     *
     * @return list<array{item:int,kode:string,nama:string,qty:float,qtyDefault:float,satuan:?int,satuanKode:string,pilih:bool}>
     */
    public function defaultAlkes(int $itemTindakan): array
    {
        return DB::table('bitemalkes as ba')
            ->join('bitem as a', 'a.IID', '=', 'ba.IPIDBB')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'ba.IPIDSATUAN')
            ->where('ba.IPIDB', $itemTindakan)
            ->orderBy('ba.IPIDURUTAN')
            ->get(['a.IID', 'a.IKODE', 'a.INAMA', 'ba.IPIDQTY', 'ba.IPIDSATUAN', 's.SKODE'])
            ->map(fn ($r) => [
                'item'       => (int) $r->IID,
                'kode'       => $r->IKODE ?? '',
                'nama'       => $r->INAMA ?? '',
                'qty'        => (float) $r->IPIDQTY,
                'qtyDefault' => (float) $r->IPIDQTY,
                'satuan'     => $r->IPIDSATUAN ? (int) $r->IPIDSATUAN : null,
                'satuanKode' => $r->SKODE ?? '',
                'pilih'      => true,
            ])->all();
    }

    /** Satu baris alkes yg ditambah MANUAL di luar resep (`SDQTYDASAR` = 0). */
    public function manualAlkes(int $itemId): ?array
    {
        $it = DB::table('bitem as i')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAND')
            ->where('i.IID', $itemId)
            ->first(['i.IID', 'i.IKODE', 'i.INAMA', 'i.ISATUAND', 'i.ISATUAN', 's.SKODE']);

        if (! $it) {
            return null;
        }

        $satuan = $it->ISATUAND ?: $it->ISATUAN;

        return [
            'item'       => (int) $it->IID,
            'kode'       => $it->IKODE ?? '',
            'nama'       => $it->INAMA ?? '',
            'qty'        => 1.0,
            'qtyDefault' => 0.0, // bukan dari resep, lihat docblock kelas
            'satuan'     => $satuan ? (int) $satuan : null,
            'satuanKode' => $it->SKODE ?? (string) DB::table('bsatuan')->where('SID', $satuan)->value('SKODE'),
            'pilih'      => true,
        ];
    }

    /**
     * @param array{ipId:int,tindakanSdid:int,tanggal:string,kodecabang:string} $meta
     * @param list<array{item:int,qty:float,qtyDefault:float,satuan:?int}>      $lines sudah difilter yg dicentang
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $meta, array $lines): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Belum ada alkes yang dipilih.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tanggal']);

        try {
            $id = DB::transaction(function () use ($meta, $lines, $nomor) {
                $ip = DB::table('fstoku')->where('SUID', $meta['ipId'])->where('SUSUMBER', 'IP')
                    ->first(['SUID', 'SUNOTRANSAKSI', 'SUCABANG', 'SUKONTAK']);
                if (! $ip) {
                    throw new \RuntimeException('Transaksi IP tidak ditemukan.');
                }

                $tindakan = DB::table('fstokd as d')
                    ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
                    ->where('d.SDID', $meta['tindakanSdid'])
                    ->where('d.SDIDSU', $meta['ipId'])
                    ->first(['d.SDID', 'd.SDIDALKESNYA', 'i.INAMA']);
                if (! $tindakan) {
                    throw new \RuntimeException('Baris tindakan tidak ada di transaksi IP tersebut.');
                }

                // Cegah dobel-input: tindakan yg sudah py AL aktif tidak boleh diinput lagi.
                if ($tindakan->SDIDALKESNYA) {
                    $lama = DB::table('fstoku')->where('SUID', $tindakan->SDIDALKESNYA)
                        ->first(['SUNOTRANSAKSI', 'SUSTATUS']);
                    if ($lama && (int) $lama->SUSTATUS !== self::STATUS_BATAL) {
                        throw new \RuntimeException('Tindakan ini sudah punya alkes (' . $lama->SUNOTRANSAKSI . ').');
                    }
                }

                $id = (int) DB::table('fstoku')->insertGetId([
                    'SUSUMBER'      => self::SUMBER,
                    'SUNOTRANSAKSI' => $nomor,
                    'SUTANGGAL'     => $meta['tanggal'],
                    'SUKONTAK'      => $ip->SUKONTAK,
                    'SUNOREF'       => $ip->SUNOTRANSAKSI,
                    // VB6 menulis "Alkes No IP" & nomor TANPA spasi ("Alkes No IPPG-IP26090005") -
                    // di sini sengaja DIBERI SPASI (permintaan user 2026-09-26) supaya terbaca.
                    'SUURAIAN'      => 'Alkes No IP ' . $ip->SUNOTRANSAKSI . ' Tindakan ' . ($tindakan->INAMA ?? ''),
                    'SUCABANG'      => $ip->SUCABANG,
                    'SUIDUALKES'    => $ip->SUID,
                    'SUSTATUS'      => self::STATUS_AKTIF,
                    'SUCREATEU'     => auth()->id(),
                ], 'SUID');

                // Baris fstokd arah KELUAR - DB TRIGGER `fstokd_add` yg mengurangi stok.
                $urut = 1;
                foreach ($lines as $l) {
                    $qty = (float) $l['qty'];
                    if ($qty <= 0) {
                        continue;
                    }
                    DB::table('fstokd')->insert([
                        'SDIDSU'     => $id,
                        'SDURUTAN'   => $urut++,
                        'SDSUMBER'   => self::SUMBER,
                        'SDITEM'     => $l['item'],
                        'SDKELUAR'   => $qty,
                        'SDKELUARD'  => $qty,
                        'SDMASUK'    => 0,
                        'SDSATUAN'   => $l['satuan'] ?: null,
                        'SDSATUAND'  => $l['satuan'] ?: null,
                        'SDGUDANG'   => $ip->SUCABANG,
                        'SDIDUALKES' => $meta['tindakanSdid'],
                        'SDQTYDASAR' => (float) ($l['qtyDefault'] ?? 0),
                    ]);
                }

                if ($urut === 1) {
                    throw new \RuntimeException('Semua qty alkes bernilai 0.');
                }

                // Tandai balik baris tindakan IP (VB6 baris 826).
                DB::table('fstokd')->where('SDID', $meta['tindakanSdid'])->update(['SDIDALKESNYA' => $id]);

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Batalkan AL - TIDAK ADA di VB6, ditambahkan di sini (lihat docblock kelas).
     * SOFT: `SDCANCEL=1` + nolkan `SDKELUAR`/`SDKELUARD` (trigger `fstokd_edit` mengembalikan
     * stok) + `SUSTATUS=9`, LALU kosongkan `SDIDALKESNYA` baris tindakan supaya bisa
     * diinput ulang.
     */
    public function cancel(int $id): bool
    {
        try {
            DB::transaction(function () use ($id) {
                $h = $this->header($id);
                if (! $h || (int) $h->SUSTATUS === self::STATUS_BATAL) {
                    throw new \RuntimeException('Alkes tidak ditemukan atau sudah dibatalkan.');
                }

                $tindakanSdid = (int) DB::table('fstokd')->where('SDIDSU', $id)->value('SDIDUALKES');

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

                if ($tindakanSdid) {
                    DB::table('fstokd')->where('SDID', $tindakanSdid)->where('SDIDALKESNYA', $id)
                        ->update(['SDIDALKESNYA' => null]);
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
