<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Invoice Penjualan Mutasi (IVM) - tagih cabang PENERIMA (tujuan mutasi) atas barang yg
 * sudah diterima via TMB (Terima Mutasi Barang, `fstoku`/`fstokd` `SUSUMBER='TMB'`), analog
 * `InvoicePenjualanWriter` (IV, sumber SJ) tapi sumbernya TRANSFER ANTAR CABANG, bukan
 * pengiriman ke pelanggan eksternal. VB6 asli: `eFrmInvoicePenjualan.frm` (folder
 * `CODE_VB6`, BERBEDA dari `eFrmInvoicePenjualan_SJ.frm` yg dipakai IV).
 *
 * **GOTCHA riset PENTING**: `eFrmInvoicePenjualan.frm` (dibaca via subagent Explore)
 * SECARA LITERAL query `SUSUMBER='SJ'` di SQL-nya, BUKAN `'TMB'` - kemungkinan besar
 * VB6-nya adalah hasil copy-paste dari form SJ yg lupa diganti sumbernya (variabel msh
 * bernama `IDSJ`/`TampilkanDataSJ`/`cmdNoSJ_Click`). **User EKSPLISIT minta modul ini
 * menarik dari TMB** - instruksi user MENANG atas literal SQL VB6 yg kemungkinan bug
 * copy-paste (ciri KHAS form ini py field header `IPUGUDANG`+`IPUGUDANGTUJUAN`
 * (asal+tujuan) & harga default dari `bitem.ICOGS`, DUA2NYA konsep yg TIDAK RELEVAN utk
 * SJ pelanggan biasa (tidak ada "gudang tujuan" saat jual ke pelanggan luar) - jelas
 * dirancang utk kasus ANTAR CABANG, cuma sumber query-nya kelewat diganti pas develop.
 * Struktur field/numbering/PPN/hard-delete SEMUA tetap dipakai sbg TEMPLATE (akurat),
 * cuma klausa SQL sumber `WHERE SUSUMBER='SJ'` diganti `'TMB'` sesuai instruksi user.
 *
 * **Tabel SAMA PERSIS `IV`**: `einvoicepenjualanu`/`einvoicepenjualand`, dibedakan HANYA
 * lewat `IPUSUMBER='IVM'` (bukan tabel terpisah - VB6 konfirmasi literal `IPUSUMBER=
 * "IVM"` & prefix nomor `"IVM"` sama persis, jadi SATU tabel dipakai BERSAMA IV/IVM,
 * pola sama `ctransaksiu`/`d` dipakai bersama KM/KK/BM/BK dibedakan `CUSUMBER`).
 *
 * **`IPUGUDANG` (asal/pengirim) & `IPUGUDANGTUJUAN` (tujuan/penerima, YG DITAGIH)** -
 * ciri khas form Mutasi, TIDAK ADA di form IV/SJ biasa. Ditelusuri dari TMB yg dipilih:
 * `IPUGUDANGTUJUAN` = `fstoku.SUCABANG` TMB itu sendiri (cabang PEMBUAT TMB = cabang
 * PENERIMA barang, konvensi `TmbWriter`); `IPUGUDANG` (asal) ditelusuri TRANSITIF via
 * `TMB.SUPRUID` -> `KMB.SUID` -> `KMB.SUCABANG` (cabang PENGIRIM, konvensi `KmbWriter`
 * - KMB.SUCABANG = asal, KMB.SUGUDANGTUJUAN = tujuan, SAMA dgn TMB.SUCABANG).
 *
 * **Harga default = `bitem.ICOGS`** (harga pokok/cost), BUKAN `SDHARGA` (SELALU 0 di
 * `fstokd` TMB, dicek eksplisit - sama spt SJ) - transfer antar cabang ditagih di harga
 * modal, EDITABLE (pola sama IV, biar bisa disesuaikan manual).
 *
 * **Efek simpan TAMBAHAN (dari VB6, TIDAK ADA di form IV/SJ biasa)**:
 * `UPDATE bitem SET IHARGADEPO=<harga> WHERE IID=<item>` per baris - menyinkronkan
 * "harga depo" item master ke harga invoice mutasi TERBARU (kolom `bitem.IHARGADEPO`
 * dikonfirmasi ADA di skema). Direplikasi SENGAJA krn spesifik/khas form ini (bukan efek
 * umum semua invoice).
 *
 * **Eligibility "TMB blm ditagih" - VB6 TERNYATA TIDAK PY anti-join server-side sama
 * sekali** (cuma exclude SJ/TMB no yg sudah ada di grid SESI INI, client-side, gap nyata
 * yg memungkinkan 1 TMB ditagih dobel ke invoice berbeda). **Modul ini SENGAJA
 * memperbaiki gap itu** - pakai pola SAMA PERSIS `InvoicePenjualanWriter` (IV): anti-join
 * PER BARIS via `einvoicepenjualand.IPDSJD = fstokd.SDID`, LEBIH BENAR drpd VB6 aslinya.
 * 4 kolom FK (`IPDSUID`/`IPDSJU`/`IPDSJD`/`IPDSDID`) diisi SAMA semua (nama kolom
 * "SJ"-sentris murni historis, fungsinya generik "id transaksi/baris fstoku/fstokd asal",
 * berlaku sama valid utk TMB).
 *
 * **PPN & DEFER**: SAMA PERSIS `InvoicePenjualanWriter` (2 mode PPN, hard delete, DEFER
 * DP/diskon-header/materai/ongkir/faktur-pajak/multi-currency/posting-jurnal) - lihat
 * docblock kelas itu utk detail lengkap, tidak diulang di sini.
 *
 * **Kontak (pelanggan) OPSIONAL** - TIDAK ada konvensi "cabang sbg kontak" di `bkontak`
 * (`bkontaktipe` dicek, tidak ada kategori "Cabang"/internal) - beda dari IV yg kontak
 * WAJIB terisi dari SJ. Di sini identitas "siapa ditagih" cukup lewat `IPUGUDANGTUJUAN`,
 * `IPUKONTAK` boleh dikosongkan atau diisi manual kalau memang ada kontak terkait.
 */
class InvoicePenjualanMutasiWriter
{
    public const SUMBER = 'IVM';

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
     * TMB yg masih py minimal 1 baris blm ditagih, utk picker.
     *
     * @return array<int,object>
     */
    public function pullableTmb(string $q = '', array $cabangScope = []): array
    {
        return DB::table('fstoku as t')
            ->leftJoin('fstoku as k', 'k.SUID', '=', 't.SUPRUID')
            ->leftJoin('bgudang as ga', 'ga.GID', '=', 'k.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 't.SUCABANG')
            ->where('t.SUSUMBER', 'TMB')
            ->where('t.SUSTATUS', '<>', 9)
            ->when($cabangScope !== [], fn ($b) => $b->where(fn ($w) => $w
                ->whereIn('t.SUCABANG', $cabangScope)->orWhereIn('k.SUCABANG', $cabangScope)))
            ->whereExists(fn ($sub) => $sub->selectRaw(1)->from('fstokd as d')
                ->leftJoin('einvoicepenjualand as e', 'e.IPDSJD', '=', 'd.SDID')
                ->whereColumn('d.SDIDSU', 't.SUID')
                ->whereNull('e.IPDID'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('t.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('ga.GNAMA', 'like', "%{$q}%")))
            ->orderByDesc('t.SUID')
            ->limit(30)
            ->get(['t.SUID as id', 't.SUNOTRANSAKSI as nomor', 't.SUTANGGAL as tanggal',
                'ga.GNAMA as asal', 'gt.GNAMA as tujuan'])
            ->all();
    }

    /**
     * Header TMB (+ asal/tujuan gudang) + baris yg masih BISA ditagih.
     * `$excludeSdid` - SDID yg SUDAH ada di grid saat ini (cegah tarik dobel baris yg sama).
     *
     * @return array{header:object,lines:array}|null
     */
    public function fromTmb(int $suId, array $excludeSdid = []): ?array
    {
        $header = DB::table('fstoku as t')
            ->leftJoin('fstoku as k', 'k.SUID', '=', 't.SUPRUID')
            ->where('t.SUID', $suId)->where('t.SUSUMBER', 'TMB')
            ->first(['t.SUID', 't.SUNOTRANSAKSI', 't.SUCABANG as gudangTujuan', 'k.SUCABANG as gudangAsal']);

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
            ->get(['d.*', 'i.IKODE', 'i.INAMA', 'i.ICOGS', 's.SKODE as satuan_kode']);

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'sdid'        => (int) $r->SDID,
                'suid'        => $suId,
                'noTmb'       => $header->SUNOTRANSAKSI,
                'item'        => (int) $r->SDITEM,
                'kode'        => $r->IKODE ?? '',
                'nama'        => $r->INAMA ?? ('Item #' . $r->SDITEM),
                'qty'         => (float) $r->SDMASUK,
                'satuan'      => $r->SDSATUAN ? (int) $r->SDSATUAN : null,
                'satuanKode'  => $r->satuan_kode ?? '',
                'satuanD'     => $r->SDSATUAND ? (int) $r->SDSATUAND : null,
                'qtyD'        => (float) $r->SDMASUKD,
                'harga'       => (float) $r->ICOGS,
                'disc'        => 0.0,
                'discPersen'  => 0.0,
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
                foreach ($lines as $l) {
                    $sudahDitagih = (bool) DB::table('einvoicepenjualand')->where('IPDSJD', $l['sdid'])->exists();
                    if ($sudahDitagih) {
                        throw new \RuntimeException('Salah satu baris TMB sudah ditagih invoice lain, muat ulang data.');
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
                    'IPUKONTAK'         => $header['kontak'] ?: null,
                    'IPUURAIAN'         => $header['uraian'] ?: null,
                    'IPUKARYAWAN'       => $header['karyawan'] ?: null,
                    'IPUCATATAN'        => $header['catatan'] ?: null,
                    'IPUATTENTION'      => $header['attention'] ?: null,
                    'IPUALAMAT'         => $header['alamat'] ?: null,
                    'IPUTERMIN'         => $header['termin'] ?: null,
                    'IPUTGLJATUHTEMPO'  => $header['tglJatuhTempo'] ?: null,
                    'IPUJENISPAJAK'     => $jenisPajak,
                    'IPUNOBKG'          => $header['tmbId'],
                    'IPUGUDANG'         => $header['gudangAsal'],
                    'IPUGUDANGTUJUAN'   => $header['gudangTujuan'],
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
                        'IPDSUID'         => $l['suid'],
                        'IPDSJU'          => $l['suid'],
                        'IPDSJD'          => $l['sdid'],
                        'IPDSDID'         => $l['sdid'],
                    ]);

                    // Efek khas form Mutasi (VB6): sinkronkan harga depo item master.
                    DB::table('bitem')->where('IID', $l['item'])->update(['IHARGADEPO' => $l['harga']]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * Hapus - HARD DELETE (pola sama IV). Trigger `einvoicepenjualand_dell` OTOMATIS
     * balikkan `fstoku.SUTARIKIV=0`. `bitem.IHARGADEPO` TIDAK dikembalikan (efek satu-arah,
     * sama spt VB6 - harga depo tidak "diundo" saat invoice dihapus).
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
