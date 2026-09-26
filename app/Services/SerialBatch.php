<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Batch / No Serial barang (`bitemserial` + `bitemserialhistori`) - dipakai BERSAMA semua
 * modul yg menggerakkan stok (PB masuk, SJ keluar, nanti PBC/KMB/TMB/POS). Dulu logika ini
 * ada di `PbWriter`, dipindah ke sini saat SJ butuh hal yg sama (2026-09-25) - `PbWriter`
 * sekarang mendelegasi ke kelas ini.
 *
 * **WAJIB BATCH = 2 SYARAT BERTINGKAT** (dikonfirmasi user 2026-09-25, sesuai VB6):
 * `bgudang.GPAKAISERIAL = 1` (GUDANG-nya memang memakai batch) **DAN** `bitem.ISERIAL = 1`
 * (ITEM-nya memang berbatch). Persis pola VB6 di 7 form legacy:
 * `If xPakaiSerial = True Then` (setelan gudang) lalu `If iserial = 1 Then` (setelan item).
 * Jadi item ber-ISERIAL=1 TIDAK diminta batch kalau transaksinya di gudang biasa. Pakai
 * `wajibBatch($itemIds, $gudang)`, bukan `serialItems()` saja.
 *
 * **Dua tempat penyimpanan, selalu ditulis berbarengan** (persis VB6):
 * 1. `fstokd.SDSERIAL` - string ringkas `noBatch~dd/mm/yyyy~qty`, antar batch dipisah `|`,
 *    **desimal pakai KOMA** (locale VB6). Diverifikasi dari data nyata:
 *    `062615R~05/06/2029~35,780|062618R~18/06/2029~10,22|042621R~21/04/2029~4` = 50 = SDMASUK.
 * 2. `bitemserialhistori` - SATU baris per batch, `ISHIDFSTOKD`=`fstokd.SDID`,
 *    `ISHURUTAN`=1..n, lalu `ISHMASUK` (dokumen masuk) ATAU `ISHKELUAR` (dokumen keluar).
 *
 * **`ISHMK` BUKAN penanda arah** - default kolomnya 1 dan VB6 form keluar (Surat Jalan)
 * TIDAK pernah mengisinya, jadi 920 baris SJ nyata semuanya `ISHMK=1` tapi `ISHKELUAR>0`.
 * Arah ditentukan MURNI oleh `ISHMASUK` vs `ISHKELUAR`. Kita ikut: selalu tulis 1.
 *
 * **`bitemserial.ISJUMLAH`/`ISAKTIF` TIDAK PERNAH ditulis manual** - trigger
 * `bitemserialhistori_add`/`_update`/`_del` yg mengurus (`ISJUMLAH += ISHMASUK - ISHKELUAR`,
 * `ISAKTIF = ISHMK`; delete membalik; update balik-lama-lalu-terapkan-baru). Tidak ada
 * trigger di `bitemserial` sendiri, dan **tidak ada trigger di `fstokd` yg menyentuh
 * `bitemserial`** - makanya `cancel()` tiap modul WAJIB panggil `forget()` di sini.
 *
 * **`SDPAKAISERIAL` SENGAJA TIDAK diisi** - VB6 pun meng-comment kolom itu dari array
 * field-nya; sumber kebenaran "pakai batch" adalah `bitem.ISERIAL`.
 */
class SerialBatch
{
    /** @var array<int,bool> cache per-request `bgudang.GPAKAISERIAL` */
    private array $gudangCache = [];

    /**
     * Apakah GUDANG ini memakai batch (`bgudang.GPAKAISERIAL=1`)? Ini padanan variabel VB6
     * `xPakaiSerial` yg jadi syarat LUAR di 7 form legacy (`If xPakaiSerial = True Then` ->
     * `If iserial = 1 Then`). Saat ini 5 dari 49 gudang: 10 Bizpark, 34 RII Bahan Baku,
     * 35 RII Produksi, 36 RII Sample Bahan Jadi, 37 RII Sample Bahan Baku.
     *
     * Dikonfirmasi data nyata: **1.151 baris `bitemserialhistori`, 100% milik `fstokd` di
     * gudang ber-`GPAKAISERIAL=1`** - tidak ada satu pun batch di gudang lain.
     */
    public function gudangPakaiSerial(?int $gid): bool
    {
        if (! $gid) {
            return false;
        }

        return $this->gudangCache[$gid] ??= (int) DB::table('bgudang')
            ->where('GID', $gid)->value('GPAKAISERIAL') === 1;
    }

    /**
     * Item mana yg WAJIB batch UNTUK transaksi di gudang ini - gabungan 2 syarat:
     * gudangnya memakai batch DAN `bitem.ISERIAL=1`. Kalau gudangnya tidak memakai batch,
     * hasilnya selalu kosong (item ber-ISERIAL=1 pun tidak diminta batch di sana).
     *
     * @param array<int,int> $itemIds
     *
     * @return list<int>
     */
    public function wajibBatch(array $itemIds, ?int $gudang): array
    {
        return $this->gudangPakaiSerial($gudang) ? $this->serialItems($itemIds) : [];
    }

    /**
     * Item mana dari daftar ini yg ber-`ISERIAL=1` (TANPA melihat gudang - biasanya yg
     * dipakai modul adalah `wajibBatch()`). Selalu dibaca dari DB, jangan percaya flag form.
     *
     * @param array<int,int> $itemIds
     *
     * @return list<int>
     */
    public function serialItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('bitem')->whereIn('IID', $itemIds)->where('ISERIAL', 1)
            ->pluck('IID')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Batch yg MASIH ADA STOKNYA di satu gudang, utk dipilih dokumen KELUAR (SJ dll).
     * Query asli VB6 (`PilihSerial`), diberikan user persis:
     *
     *   select isnoserial,istglexpired,SUM(ISHMASUK-ISHKELUAR) from bitemserialhistori
     *   inner join fstokd on sdid=ISHIDFSTOKD
     *   inner join bitemserial on isid=ishidserial and isitem=sditem
     *   where sditem=? and sdgudang=? group by ISID,... having SUM(ISHMASUK-ISHKELUAR)>0
     *
     * Jadi **stok batch bersifat PER GUDANG** dan dihitung dari histori × `fstokd.SDGUDANG`,
     * BUKAN dari `bitemserial.ISJUMLAH` (yg global lintas gudang). `isitem=sditem` di join
     * adalah pengaman bawaan VB6 thd baris histori yg salah tunjuk item - dipertahankan.
     * TAMBAHAN kita: baris `fstokd` yg sudah dibatalkan diabaikan (saat ini 0 baris spt itu
     * di data nyata, murni pertahanan - modul kita malah menghapus historinya saat batal).
     * Urut kedaluwarsa TERDEKAT dulu (FIFO/FEFO) - relevan utk klinik.
     *
     * @return list<object{isid:int,noBatch:string,expired:?string,tersedia:float}>
     */
    public function available(int $item, int $gudang): array
    {
        return DB::table('bitemserialhistori as h')
            ->join('fstokd as d', 'd.SDID', '=', 'h.ISHIDFSTOKD')
            ->join('bitemserial as s', fn ($j) => $j
                ->on('s.ISID', '=', 'h.ISHIDSERIAL')
                ->on('s.ISITEM', '=', 'd.SDITEM'))
            ->where('d.SDITEM', $item)
            ->where('d.SDGUDANG', $gudang)
            ->where(fn ($q) => $q->whereNull('d.SDCANCEL')->orWhere('d.SDCANCEL', '<>', 1))
            ->groupBy('s.ISID', 's.ISNOSERIAL', 's.ISTGLEXPIRED')
            ->havingRaw('SUM(h.ISHMASUK - h.ISHKELUAR) > 0')
            ->orderByRaw('s.ISTGLEXPIRED IS NULL, s.ISTGLEXPIRED ASC, s.ISNOSERIAL ASC')
            ->get([
                DB::raw('s.ISID as isid'),
                DB::raw('s.ISNOSERIAL as noBatch'),
                DB::raw('s.ISTGLEXPIRED as expired'),
                DB::raw('SUM(h.ISHMASUK - h.ISHKELUAR) as tersedia'),
            ])->map(fn ($r) => (object) [
                'isid'     => (int) $r->isid,
                'noBatch'  => (string) $r->noBatch,
                'expired'  => $r->expired ? substr((string) $r->expired, 0, 10) : null,
                'tersedia' => (float) $r->tersedia,
            ])->all();
    }

    /** Sisa stok SATU batch di satu gudang (dipakai re-cek server-side saat simpan). */
    public function availableOf(int $item, int $gudang, string $noBatch): float
    {
        foreach ($this->available($item, $gudang) as $b) {
            if (strcasecmp($b->noBatch, trim($noBatch)) === 0) {
                return $b->tersedia;
            }
        }

        return 0.0;
    }

    /**
     * Cari-atau-buat baris `bitemserial` utk (item, no batch) - padanan helper VB6
     * `pIDSerial(item, noBatch, tglExpired)` yg dipakai 7 form legacy. `ISJUMLAH` dibiarkan
     * 0 saat create: trigger yg menaikkannya. `ISTGLEXPIRED` hanya ditulis saat baris baru /
     * saat yg lama masih null (jangan timpa data lama - satu no batch = satu expired, di
     * data nyata praktis unik: 1 duplikat dari 2.792 baris).
     */
    public function resolveId(int $item, string $noBatch, ?string $tglExpired): int
    {
        $noBatch = trim($noBatch);

        $existing = DB::table('bitemserial')
            ->where('ISITEM', $item)->where('ISNOSERIAL', $noBatch)
            ->orderBy('ISID')
            ->first(['ISID', 'ISTGLEXPIRED']);

        if ($existing) {
            if ($tglExpired && ! $existing->ISTGLEXPIRED) {
                DB::table('bitemserial')->where('ISID', $existing->ISID)
                    ->update(['ISTGLEXPIRED' => $tglExpired]);
            }

            return (int) $existing->ISID;
        }

        return (int) DB::table('bitemserial')->insertGetId([
            'ISITEM'       => $item,
            'ISNOSERIAL'   => $noBatch,
            'ISTGLEXPIRED' => $tglExpired ?: null,
            'ISAKTIF'      => 1,
            'ISJUMLAH'     => 0,
        ], 'ISID');
    }

    /**
     * Tulis baris histori utk SATU baris `fstokd`. `$arah` = 'masuk' (isi `ISHMASUK`) atau
     * 'keluar' (isi `ISHKELUAR`). Panggil SETELAH baris `fstokd` ter-insert (butuh SDID).
     *
     * @param list<array{noBatch:string,expired:?string,qty:float}> $batches sudah lewat clean()
     */
    public function writeHistori(int $sdid, int $item, array $batches, string $arah): void
    {
        $urut = 1;
        foreach ($batches as $b) {
            $qty = (float) $b['qty'];
            DB::table('bitemserialhistori')->insert([
                'ISHIDSERIAL' => $this->resolveId($item, $b['noBatch'], $b['expired']),
                'ISHMK'       => 1, // BUKAN penanda arah, lihat docblock kelas
                'ISHIDFSTOKD' => $sdid,
                'ISHURUTAN'   => $urut++,
                'ISHMASUK'    => $arah === 'masuk' ? $qty : 0,
                'ISHKELUAR'   => $arah === 'keluar' ? $qty : 0,
            ]);
        }
    }

    /**
     * Hapus histori milik baris2 `fstokd` (dipakai `cancel()` tiap modul). HARD DELETE,
     * supaya trigger `bitemserialhistori_del` membalik `bitemserial.ISJUMLAH` - tidak ada
     * trigger lain yg melakukannya. VB6 TIDAK melakukan ini (bocor, lihat docblock kelas).
     *
     * @param array<int,int> $sdids
     */
    public function forget(array $sdids): void
    {
        if ($sdids === []) {
            return;
        }
        DB::table('bitemserialhistori')->whereIn('ISHIDFSTOKD', $sdids)->delete();
    }

    /**
     * Buang baris batch kosong/qty<=0 + gabung baris dgn no batch SAMA (jumlahkan qty)
     * supaya tidak pernah ada 2 baris histori menunjuk `ISHIDSERIAL` sama utk satu `SDID`.
     *
     * @return list<array{noBatch:string,expired:?string,qty:float}>
     */
    public function clean(array $batches): array
    {
        $byKode = [];
        foreach ($batches as $b) {
            $kode = trim((string) ($b['noBatch'] ?? ''));
            $qty = (float) ($b['qty'] ?? 0);
            if ($kode === '' || $qty <= 0) {
                continue;
            }
            if (isset($byKode[$kode])) {
                $byKode[$kode]['qty'] += $qty;

                continue;
            }
            $byKode[$kode] = [
                'noBatch' => $kode,
                'expired' => ($b['expired'] ?? null) ?: null,
                'qty'     => $qty,
            ];
        }

        return array_values($byKode);
    }

    /**
     * Susun string `fstokd.SDSERIAL` format legacy.
     *
     * @param list<array{noBatch:string,expired:?string,qty:float}> $batches
     */
    public function pack(array $batches): string
    {
        return implode('|', array_map(fn ($b) => trim($b['noBatch'])
            . '~' . ($b['expired'] ? date('d/m/Y', strtotime($b['expired'])) : '')
            . '~' . $this->fmtQty((float) $b['qty']), $batches));
    }

    /**
     * Bongkar string `SDSERIAL` jadi array batch (utk tampil ulang di form / cetakan).
     *
     * @return list<array{noBatch:string,expired:?string,qty:float}>
     */
    public function unpack(?string $packed): array
    {
        if (! $packed || trim($packed) === '') {
            return [];
        }

        $out = [];
        foreach (explode('|', $packed) as $chunk) {
            $p = explode('~', $chunk);
            if (trim($p[0] ?? '') === '') {
                continue;
            }
            $tgl = trim($p[1] ?? '');
            $out[] = [
                'noBatch' => trim($p[0]),
                'expired' => $tgl !== '' && ($t = strtotime(str_replace('/', '-', $tgl))) ? date('Y-m-d', $t) : null,
                'qty'     => (float) str_replace(',', '.', trim($p[2] ?? '0')),
            ];
        }

        return $out;
    }

    /** Angka utk `SDSERIAL` - desimal KOMA, spt data legacy `35,780|10,22|4`. */
    private function fmtQty(float $qty): string
    {
        $s = rtrim(rtrim(number_format($qty, 3, ',', ''), '0'), ',');

        return $s === '' || $s === '-' ? '0' : $s;
    }
}
