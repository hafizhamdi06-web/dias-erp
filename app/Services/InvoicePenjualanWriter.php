<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Invoice Penjualan (IV) - tagih pelanggan berdasarkan baris Surat Jalan (SJ,
 * `fstoku`/`fstokd` `SUSUMBER='SJ'`) yg SUDAH dikirim tapi BELUM ditagih. VB6 asli:
 * `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\eFrmInvoicePenjualan_SJ.frm`. CI3:
 * `PJ_Faktur_Penjualan.php`/`M_PJ_Faktur_Penjualan.php` (dicek via subagent, LALU
 * dikonfirmasi ULANG langsung ke DB - lihat catatan trigger di bawah, beberapa klaim
 * CI3 TERNYATA tidak akurat/basi).
 *
 * **Tabel TERPISAH** (BUKAN `fstoku`/`fstokd` lagi spt SJ-nya) - `einvoicepenjualanu`
 * (`IPU*`)/`einvoicepenjualand` (`IPD*`), analog `ctransaksipu`/`pd` punya PengajuanDana:
 * dokumen BARU yg mereferensikan baris SJ yg sudah ada, bukan entry stok baru (invoice
 * TIDAK memindah stok lagi - itu sudah selesai di level SJ).
 *
 * **`einvoicepenjualanu` di data produksi saat ini KOSONG (0 baris)** - fitur ini blm
 * pernah dipakai lewat CI3/VB6 di DB ini, jadi TIDAK ADA data lama utk dikompatibelkan;
 * modul ini murni fitur baru dari sisi data (walau strukturnya legacy).
 *
 * **Mekanisme "SJ mana yg masih bisa ditagih" - DIKONFIRMASI LANGSUNG via SHOW CREATE
 * TRIGGER (VB6 & CI3 punya klaim BERBEDA & keduanya TERNYATA tidak match trigger asli)**:
 * - VB6: anti-join per (SJ header, ITEM) via `einvoicepenjualand` lama, TANPA qty parsial.
 * - CI3 (`M_PJ_Faktur_Penjualan::getdatapengiriman()`): eligibility `sdkeluar-sdfaktur>0` -
 *   **kolom `fstokd.sdfaktur` TERNYATA TIDAK ADA di skema** (`SHOW COLUMNS` dicek eksplisit)
 *   - klaim ini keliru/basi, mungkin dari fungsi lain yg salah teratribusi.
 * - **Yang BENAR2 ada & jalan otomatis**: trigger `einvoicepenjualand_ADD`/`_dell`/`_update`
 *   (AFTER INSERT/DELETE/UPDATE on `einvoicepenjualand`) `UPDATE fstoku SET SUTARIKIV=1/0
 *   WHERE suid = NEW/OLD.ipdsuid` - flag HEADER-LEVEL (`fstoku.SUTARIKIV`), bukan per-baris.
 *   Kolom `fstokd.SDDITARIKIV` (per-baris) JUGA ADA di skema TAPI TIDAK disentuh trigger
 *   manapun DAN TIDAK direferensikan di kode CI3 sama sekali (dicek grep) - kolom legacy
 *   yatim, TIDAK dipakai modul ini (jangan tertipu namanya kedengaran relevan).
 *
 * **Keputusan eligibility v1 (LEBIH KETAT & benar drpd trigger header-level yg kasar)**:
 * anti-join PER BARIS SJ (bukan per header, bukan per item) via `einvoicepenjualand.IPDSJD
 * = fstokd.SDID` - begitu SATU baris SJ sudah masuk baris invoice manapun, baris itu saja
 * yg hilang dari daftar tarik (baris SJ lain di header yg sama TETAP bisa ditagih terpisah).
 * Ini SENGAJA tidak mengandalkan `fstoku.SUTARIKIV` sbg filter (kasar, all-or-nothing per SJ)
 * - flag itu TETAP kepakai otomatis (trigger DB, gratis, tidak perlu kode manual), sekadar
 * indikator "SJ ini pernah disentuh minimal 1 invoice", TIDAK dipakai memutuskan eligibility.
 *
 * **4 kolom FK SJ tersedia di `einvoicepenjualand`**: `IPDSUID` (dipakai VB6 & trigger DB -
 * WAJIB diisi = `fstoku.SUID` SJ header, kalau tidak trigger `SUTARIKIV` tidak jalan),
 * `IPDSJU` (duplikat SUID, dipakai CI3), `IPDSJD`/`IPDSDID` (duplikat SJ line id =
 * `fstokd.SDID`, dipakai CI3 & anti-join eligibility kita). Diisi KONSISTEN semua 4
 * (bukan cross-module overloading spt `SDPRDID` dll - ini murni redundansi historis DI
 * SATU tabel yg sama, aman diisi semua sekaligus).
 *
 * **PPN disederhanakan jadi 2 mode** (VB6 py 3: none/exclusive-11%/inclusive-10% via rumus
 * `10/111` yg kemungkinan basi drpd tarif PPN saat ini) - v1 cuma dukung `IPUJENISPAJAK`:
 * 0=Tanpa Pajak, 1=PPN 11% (exclusive, ditambahkan di atas subtotal). Mode "inclusive"
 * VB6 DIHILANGKAN (membingungkan & tarifnya diragukan akurat) - kalau nanti perlu, minta
 * konfirmasi rate PPN yg benar dulu.
 *
 * **DEFER (pola sama modul2 lain sesi ini, tidak diminta scope-nya)**: DP/uang muka
 * (`einvoicepenjualandp`/`ddp` - tabel/alur terpisah, blm ada modul DP Laravel sama sekali),
 * diskon+ongkir+materai header (`IPUDISKON*`/`IPUBIAYAONGKIR`/`IPUBIAYAMATERAI`), faktur
 * pajak formal (`IPUNOFAKTURPAJAK`/`IPUTGLPAJAK`/`IPUBUKTIPPN` dst - workflow terpisah),
 * posting jurnal/COA otomatis (`IPUCOAPAJAK`/`IPUCOAPIUTANG` - infrastruktur jurnal umum
 * blm ada di Laravel app ini sama sekali, modul lain spt PB/PR/SJ/KMB/TMB/JOP jg tidak
 * posting jurnal), multi-currency (`IPUVALAS`/`IPUKURS` - selalu 1/Rp, pola sama PDN).
 *
 * **Hapus = HARD DELETE** (dikonfirmasi VB6 `xHapusData` & CI3 `hapusTransaksi()` DUA2NYA
 * hard delete, tidak ada status-void di modul ini) - trigger `einvoicepenjualand_dell`
 * OTOMATIS balikkan `fstoku.SUTARIKIV=0`. **Keterbatasan trigger yg TIDAK kita perbaiki**
 * (di luar scope, trigger produksi): kalau 1 SJ header py baris yg ditagih via >1 invoice
 * berbeda, hapus SATU invoice akan reset `SUTARIKIV=0` walau invoice lain msh mereferensikan
 * SJ itu - flag itu memang cuma indikator kasar, TIDAK dipakai eligibility (lihat di atas),
 * jadi tidak mempengaruhi kebenaran fungsional modul ini.
 */
class InvoicePenjualanWriter
{
    public const SUMBER = 'IV';

    public const PAJAK_NONE = 0;

    public const PAJAK_PPN11 = 1;

    public const TARIF_PPN = 0.11;

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('einvoicepenjualanu')
            ->where('IPUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(IPUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * SJ (`fstoku` SUSUMBER='SJ') yg masih py minimal 1 baris blm ditagih, utk picker.
     *
     * @return array<int,object>
     */
    public function pullableSj(string $q = '', array $cabangScope = []): array
    {
        return DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', 'SJ')
            ->where('u.SUSTATUS', '<>', 9)
            ->when($cabangScope !== [], fn ($b) => $b->whereIn('u.SUCABANG', $cabangScope))
            ->whereExists(fn ($sub) => $sub->selectRaw(1)->from('fstokd as d')
                ->leftJoin('einvoicepenjualand as e', 'e.IPDSJD', '=', 'd.SDID')
                ->whereColumn('d.SDIDSU', 'u.SUID')
                ->whereNull('e.IPDID'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")))
            ->orderByDesc('u.SUID')
            ->limit(30)
            ->get(['u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal', 'k.KNAMA as kontak'])
            ->all();
    }

    /**
     * Header SJ + baris yg masih BISA ditagih (anti-join per baris, lihat docblock kelas).
     * `$excludeSdid` - SDID yg SUDAH ada di grid saat ini (cegah tarik dobel baris yg sama).
     *
     * @return array{header:object,lines:array}|null
     */
    public function fromSj(int $suId, array $excludeSdid = []): ?array
    {
        $header = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUID', $suId)->where('u.SUSUMBER', 'SJ')
            ->first(['u.SUID', 'u.SUNOTRANSAKSI', 'u.SUKONTAK', 'u.SUCABANG', 'k.KNAMA as kontak', 'k.K1ALAMAT as alamat']);

        if (! $header || (int) DB::table('fstoku')->where('SUID', $suId)->value('SUSTATUS') === 9) {
            return null;
        }

        $rows = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.SDSATUAN')
            ->leftJoin('einvoicepenjualand as e', 'e.IPDSJD', '=', 'd.SDID')
            ->where('d.SDIDSU', $suId)
            ->whereNull('e.IPDID')
            ->when($excludeSdid !== [], fn ($b) => $b->whereNotIn('d.SDID', $excludeSdid))
            ->orderBy('d.SDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode']);

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'sdid'        => (int) $r->SDID,
                'suid'        => $suId,
                'noSj'        => $header->SUNOTRANSAKSI,
                'item'        => (int) $r->SDITEM,
                'kode'        => $r->IKODE ?? '',
                'nama'        => $r->INAMA ?? ('Item #' . $r->SDITEM),
                'qty'         => (float) $r->SDKELUAR,
                'satuan'      => $r->SDSATUAN ? (int) $r->SDSATUAN : null,
                'satuanKode'  => $r->satuan_kode ?? '',
                'satuanD'     => $r->SDSATUAND ? (int) $r->SDSATUAND : null,
                'qtyD'        => (float) $r->SDKELUARD,
                'harga'       => (float) $r->SDHARGA,
                'disc'        => (float) $r->SDDISKON,
                'discPersen'  => (float) $r->SDDISKONPERSEN,
                'catatan'     => null,
            ];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * @param array $header kolom IPU* (tanpa IPUNOTRANSAKSI/IPUID/IPUSUMBER/IPUSTATUS)
     *                      + kunci tambahan 'jenisPajak' (int, lihat PAJAK_*)
     * @param list<array{sdid:int,suid:int,item:int,qty:float,satuan:?int,satuanD:?int,qtyD:float,harga:float,disc:float,discPersen:float,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail item kosong.'];
        }

        $jenisPajak = (int) ($header['jenisPajak'] ?? self::PAJAK_NONE);
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $jenisPajak, $nomor) {
                // Re-cek server-side tiap baris SJ msh blm ditagih - cegah race/tarik dobel.
                foreach ($lines as $l) {
                    $sudahDitagih = (bool) DB::table('einvoicepenjualand')->where('IPDSJD', $l['sdid'])->exists();
                    if ($sudahDitagih) {
                        throw new \RuntimeException('Salah satu baris SJ sudah ditagih invoice lain, muat ulang data.');
                    }
                }

                $subtotal = 0.0;
                foreach ($lines as $l) {
                    $subtotal += ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty'];
                }
                $pajak = $jenisPajak === self::PAJAK_PPN11 ? round($subtotal * self::TARIF_PPN, 2) : 0.0;
                $total = $subtotal + $pajak;

                $id = (int) DB::table('einvoicepenjualanu')->insertGetId([
                    'IPUSUMBER'         => self::SUMBER,
                    'IPUNOTRANSAKSI'    => $nomor,
                    'IPUTANGGAL'        => $header['tanggal'],
                    'IPUKONTAK'         => $header['kontak'],
                    'IPUURAIAN'         => $header['uraian'] ?: null,
                    'IPUKARYAWAN'       => $header['karyawan'] ?: null,
                    'IPUCATATAN'        => $header['catatan'] ?: null,
                    'IPUATTENTION'      => $header['attention'] ?: null,
                    'IPUALAMAT'         => $header['alamat'] ?: null,
                    'IPUTERMIN'         => $header['termin'] ?: null,
                    'IPUTGLJATUHTEMPO'  => $header['tglJatuhTempo'] ?: null,
                    'IPUJENISPAJAK'     => $jenisPajak,
                    'IPUNOBKG'          => $header['sjId'],
                    'IPUGUDANG'         => $header['cabang'],
                    'IPUSUBTOTAL'       => $subtotal,
                    'IPUTOTALPAJAK'     => $pajak,
                    'IPUTOTALTRANSAKSI' => $total,
                    'IPUSTATUS'         => 1,
                    'IPUCREATEU'        => auth()->id(),
                ], 'IPUID');

                $urut = 1;
                foreach ($lines as $l) {
                    $lineSubtotal = ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty'];
                    DB::table('einvoicepenjualand')->insert([
                        'IPDSUMBER'       => self::SUMBER,
                        'IPDIDIPU'        => $id,
                        'IPDURUTAN'       => $urut++,
                        'IPDITEM'         => $l['item'],
                        'IPDKELUAR'       => $l['qty'],
                        'IPDKELUARD'      => $l['qtyD'],
                        'IPDSATUAN'       => $l['satuan'] ?: null,
                        'IPDSATUAND'      => $l['satuanD'] ?: null,
                        'IPDHARGA'        => $l['harga'],
                        'IPDDISKON'       => $l['disc'],
                        'IPDDISKONPERSEN' => $l['discPersen'],
                        'IPDSUBTOTAL'     => $lineSubtotal,
                        'IPDTOTAL'        => $lineSubtotal,
                        'IPDCATATAN'      => $l['catatan'] ?: null,
                        // 4 kolom FK SJ - lihat docblock kelas, IPDSUID WAJIB diisi utk trigger.
                        'IPDSUID'         => $l['suid'],
                        'IPDSJU'          => $l['suid'],
                        'IPDSJD'          => $l['sdid'],
                        'IPDSDID'         => $l['sdid'],
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
     * Hapus - HARD DELETE (lihat docblock kelas). Trigger `einvoicepenjualand_dell`
     * OTOMATIS balikkan `fstoku.SUTARIKIV=0` (indikator kasar, bukan penentu eligibility).
     */
    public function delete(int $id): bool
    {
        $h = $this->header($id);
        if (! $h) {
            return false;
        }

        DB::transaction(function () use ($id) {
            DB::table('einvoicepenjualand')->where('IPDIDIPU', $id)->delete();
            DB::table('einvoicepenjualanu')->where('IPUID', $id)->delete();
        });

        return true;
    }

    public function header(int $id): ?object
    {
        return DB::table('einvoicepenjualanu')->where('IPUID', $id)->where('IPUSUMBER', self::SUMBER)->first();
    }

    public function lines(int $id): array
    {
        return DB::table('einvoicepenjualand as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.IPDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'd.IPDSATUAN')
            ->where('d.IPDIDIPU', $id)
            ->orderBy('d.IPDURUTAN')
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 's.SKODE as satuan_kode'])
            ->all();
    }
}
