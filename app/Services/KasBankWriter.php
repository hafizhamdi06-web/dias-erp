<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Kas Masuk/Keluar & Bank Masuk/Keluar - jurnal double-entry SEDERHANA di tabel legacy
 * `ctransaksiu`/`ctransaksid` (BUKAN `fstoku`/`fstokd` - modul PERTAMA sesi ini yg pakai
 * tabel finance, bukan tabel stok). Port dari CI3 (`dias-online-app`,
 * `Fina_{Kas,Bank}_{Masuk,Keluar}.php` + model `M_Fina_*` - 4 controller CI3 HAMPIR
 * IDENTIK strukturnya, jadi di Laravel disatukan jadi 1 writer diparametrisasi `$sumber`
 * (KM/KK/BM/BK) - TIDAK ADA trigger di `ctransaksiu`/`ctransaksid` (`SHOW TRIGGERS`
 * dicek dulu, kosong) jadi aman murni insert manual, tidak ada efek-samping cross-table.
 *
 * **Tidak ada data histori NYATA utk KM/KK/BM/BK di DB ini** (0 baris, beda dari modul2
 * sblmnya yg py data import) - verifikasi HANYA via tinker data sintetis + baca kode CI3,
 * TIDAK bisa cross-check ke data produksi asli spt modul lain. `aanomor`: KM=687, KK=688,
 * BM=689, BK=690, SEMUA `NTABEL='ctransaksiu'`.
 *
 * **Double-entry**: baris rekening (kas/bank yg dipakai, `CUREKKAS`) SELALU baris urutan 1.
 * MASUK: rekening = DEBIT (uang masuk ke kas/bank), baris "biaya" (detail, akun
 * pendapatan/lainnya dari user) = KREDIT. KELUAR: rekening = KREDIT (uang keluar), baris
 * biaya = DEBIT. Total = SUM baris biaya (TIDAK ada input total terpisah, dihitung
 * otomatis - beda dari CI3 yg total dihitung di JS lalu dikirim sbg field terpisah,
 * effect sama, kita hitung server-side langsung dari `$lines`).
 *
 * **COA picker 2 jenis, filter BEDA** (lihat `LookupController::coaRekening()`/
 * `::coaBiaya()`): (1) "rekening" (`CUREKKAS`) - `bcoa.CTIPE=0` (grup KAS) utk form Kas,
 * `CTIPE=1` (grup BANK) utk form Bank - PERBAIKAN dari CI3 yg filter rekening Kas HANYA
 * `ctipe=0` (makanya butuh form Bank terpisah dgn hardcode berbeda per instance, malah
 * form Kas asli py dropdown DISABLED hardcode ke 1 akun - kita bikin dropdown baru yg
 * BENAR memfilter per tipe, TIDAK hardcode). (2) "biaya" (baris detail, `CDNOCOA`) -
 * `bcoa.CKASMASUK=1`/`CKASKELUAR=1` (whitelist SUDAH ADA di data master nyata, dipakai APA
 * ADANYA, SAMA utk versi Kas maupun Bank - CI3 tidak py flag terpisah utk Bank).
 *
 * **Field khusus Bank** (`CUTIPE`=0 Tunai/1 Giro/2 Transfer, `CUBANK`=bank fisik dari
 * `bbank`, `CUNOGIRO`/`CUTGLTEMPO`=no+tgl cek/giro) HANYA dipakai form Bank (Kas TIDAK
 * PERNAH mengisi kolom2 ini) - kondisional di UI: Transfer -> tampilkan `bank`, Giro ->
 * tampilkan `noGiro`+`tglGiro`, Tunai -> tidak ada field tambahan (pola sama CI3
 * `bank-masuk.php`, class `.transfer`/`.giro` toggle via JS, di Livewire kita pakai
 * `x-show`).
 *
 * **Batal = HARD DELETE** (beda dari `fstoku`/`fstokd` yg soft-cancel `SUSTATUS=9`) -
 * pola CI3 `hapusTransaksi()` beneran `DELETE FROM ctransaksiu/ctransaksid`, TIDAK ADA
 * konvensi soft-cancel utk tabel `ctransaksiu` (beda tabel, beda konvensi, JANGAN
 * disamakan dgn aturan `fstoku` di CLAUDE.md - itu KHUSUS `fstoku`/`fstokd`).
 */
class KasBankWriter
{
    public const KAS_MASUK = 'KM';

    public const KAS_KELUAR = 'KK';

    public const BANK_MASUK = 'BM';

    public const BANK_KELUAR = 'BK';

    public const ARAH_MASUK = [self::KAS_MASUK, self::BANK_MASUK];

    public function isMasuk(string $sumber): bool
    {
        return in_array($sumber, self::ARAH_MASUK, true);
    }

    public function nextNumber(string $sumber, string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . $sumber . $yymm;

        $maks = (int) DB::table('ctransaksiu')
            ->where('CUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(CUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array{kontak:int,uraian:?string,rekening:int,tanggal:string,cabang:int,tipeBayar?:int,bank?:?int,noGiro?:?string,tglGiro?:?string} $header
     * @param list<array{coa:int,jumlah:float,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(string $sumber, array $header, array $lines, array $meta): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['jumlah'] > 0));
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail biaya kosong.'];
        }

        $total = array_sum(array_map(fn ($l) => (float) $l['jumlah'], $lines));
        $masuk = $this->isMasuk($sumber);
        $nomor = $this->nextNumber($sumber, $meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($sumber, $header, $lines, $total, $masuk, $nomor) {
                $id = (int) DB::table('ctransaksiu')->insertGetId([
                    'CUSUMBER'       => $sumber,
                    'CUNOTRANSAKSI'  => $nomor,
                    'CUTANGGAL'      => $header['tanggal'],
                    'CUKONTAK'       => $header['kontak'],
                    'CUURAIAN'       => $header['uraian'] ?: null,
                    'CUREKKAS'       => $header['rekening'],
                    'CUTOTALTRANS'   => $total,
                    'CUSTATUS'       => 0,
                    'CUTIPE'         => $header['tipeBayar'] ?? 0,
                    'CUBANK'         => $header['bank'] ?? null,
                    'CUNOGIRO'       => $header['noGiro'] ?? null,
                    'CUTGLTEMPO'     => $header['tglGiro'] ?? null,
                    'CUCABANG'       => $header['cabang'],
                    'CUCREATEU'      => auth()->id(),
                ], 'CUID');

                // Baris 1 = rekening kas/bank. MASUK = debit, KELUAR = kredit.
                // CDCABANG diisi (CI3 asli TIDAK mengisi ini di ctransaksid, cuma cucabang
                // di header - gap CI3, diperbaiki di sini krn PengajuanDanaWriter butuh
                // filter cdcabang saat "tarik data" dari baris KK).
                DB::table('ctransaksid')->insert([
                    'CDIDU'     => $id,
                    'CDURUTAN'  => 1,
                    'CDNOCOA'   => $header['rekening'],
                    'CDDEBIT'   => $masuk ? $total : 0,
                    'CDKREDIT'  => $masuk ? 0 : $total,
                    'CDUANG'    => 1,
                    'CDCATATAN' => $header['uraian'] ?: null,
                    'CDCABANG'  => $header['cabang'],
                ]);

                $urut = 2;
                foreach ($lines as $l) {
                    DB::table('ctransaksid')->insert([
                        'CDIDU'     => $id,
                        'CDURUTAN'  => $urut++,
                        'CDNOCOA'   => $l['coa'],
                        'CDDEBIT'   => $masuk ? 0 : (float) $l['jumlah'],
                        'CDKREDIT'  => $masuk ? (float) $l['jumlah'] : 0,
                        'CDUANG'    => 1,
                        'CDCATATAN' => $l['catatan'] ?: null,
                        'CDCABANG'  => $header['cabang'],
                    ]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /** Hapus transaksi - HARD DELETE (pola CI3 `hapusTransaksi()`, lihat docblock kelas). */
    public function delete(string $sumber, int $id): bool
    {
        $h = $this->header($sumber, $id);
        if (! $h) {
            return false;
        }

        DB::transaction(function () use ($id) {
            DB::table('ctransaksid')->where('CDIDU', $id)->delete();
            DB::table('ctransaksiu')->where('CUID', $id)->delete();
        });

        return true;
    }

    public function header(string $sumber, int $id): ?object
    {
        return DB::table('ctransaksiu')->where('CUID', $id)->where('CUSUMBER', $sumber)->first();
    }

    /** Baris "biaya" saja (urutan > 1) - baris 1 (rekening) diambil terpisah dari header. */
    public function lines(int $id): array
    {
        return DB::table('ctransaksid as d')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->where('d.CDIDU', $id)->where('d.CDURUTAN', '>', 1)
            ->orderBy('d.CDURUTAN')
            ->get(['d.*', 'c.CNOCOA', 'c.CNAMA'])
            ->all();
    }
}
