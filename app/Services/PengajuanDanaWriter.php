<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pengajuan Dana (PDN) - konsolidasi baris "biaya" (detail) dari transaksi Kas Keluar (KK,
 * `ctransaksid`/`ctransaksiu`) yg BELUM PERNAH ditarik ke pengajuan manapun, jadi SATU
 * dokumen pengajuan dana/reimbursement. VB6 asli:
 * `C:\hafiz\PROMPT DIAS ERP LARAVEL\CODE_VB6\cFrmPengajuanDana.frm`. TIDAK ADA di CI3
 * (dicek, tidak ada controller/model terkait) - murni dari VB6 + skema DB.
 *
 * **Tabel TERPISAH dari `ctransaksiu`/`ctransaksid`**: `ctransaksipu`/`ctransaksipd`
 * ("Pu"/"Pd" = versi "Pengajuan" dari jurnal, struktur kolom SAMA PERSIS CU/CD tapi
 * baris/dokumennya independen) - Pengajuan Dana BUKAN entry baru di buku besar, cuma
 * DOKUMEN PENGAJUAN yg mereferensikan baris KK yg sudah ada.
 *
 * **Mekanisme "belum pernah ditarik" via `ctransaksid.CDDIBUATPENGAJUAN`, DIJAGA OTOMATIS
 * OLEH TRIGGER** (`SHOW TRIGGERS` dicek dulu, WAJIB per CLAUDE.md) - `ctransaksipd_add`
 * (AFTER INSERT): `UPDATE ctransaksid SET CDDIBUATPENGAJUAN=1 WHERE cdid=NEW.CDBKKID`.
 * `ctransaksipd_dell` (BEFORE DELETE): kebalikannya, set balik ke 0. **Artinya**: kita
 * CUKUP insert `ctransaksipd` dgn `CDBKKID` = id baris `ctransaksid` sumber - trigger yg
 * urus flag-nya, TIDAK PERLU UPDATE manual. Konsekuensi bagus: hapus Pengajuan Dana
 * OTOMATIS "mengembalikan" baris KK-nya supaya bisa ditarik lagi di pengajuan berikutnya
 * (VB6 `xHapusData()` SEPINTAS terlihat tidak menangani ini, TERNYATA trigger yg urus).
 *
 * **BUG CI3 ditemukan & DIPERBAIKI**: `ctransaksid.CDCABANG` TIDAK PERNAH diisi oleh CI3
 * `M_Fina_Kas_Keluar` (dicek eksplisit, cuma `cucabang` di header) - padahal filter
 * "tarik data" form ini WAJIB `cdcabang = <cabang user>`. Diperbaiki di `KasBankWriter`
 * kita sendiri (SEKARANG mengisi `CDCABANG` tiap baris) SEBELUM modul ini dibangun, supaya
 * data KK yg dibuat via Laravel bisa benar2 ditarik ke sini.
 *
 * **Filter tarik data (`pullableKk()`)**: `ctransaksid.cusumber='KK'` (via join
 * `ctransaksiu`) AND `curekkas = <rekening dipilih>` (HARUS SAMA dgn rekening Pengajuan
 * Dana - user pilih rekening dulu, baru bisa tarik) AND `cdcabang = <cabang user>` AND
 * `CDDIBUATPENGAJUAN=0` AND `cddebit>0` (baris biaya KK, BUKAN baris rekening KK sendiri
 * yg justru KREDIT).
 *
 * **UX disederhanakan** (pola sama `PbForm`/`PoForm` sesi2 sblmnya): VB6 asli tarik SEMUA
 * baris eligible ke grid dgn CHECKBOX per-baris (default TIDAK tercentang, user pilih
 * manual) - user MINTA "menarik semua data yg belum ditarik", jadi kita tarik SEMUA
 * otomatis TERMASUK (bukan checkbox kosong), user tinggal HAPUS baris yg tidak diinginkan
 * (tombol remove, bukan checkbox toggle) - lebih sesuai permintaan & konsisten pola modul
 * lain.
 *
 * **Double-entry provisional**: baris 1 `ctransaksipd` = rekening (`CDNOCOA`=rekening,
 * `CDKREDIT`=total) - SAMA konsep KK (rekening di-kredit). Baris 2+ = tiap baris KK yg
 * ditarik (`CDNOCOA`=akun biaya asal, `CDDEBIT`=jumlah, **`CDBKKID`=id baris `ctransaksid`
 * sumber** - kunci relasi & pemicu trigger).
 *
 * **Kontak**: kategori "KEUANGAN" (`bkontaktipe.KTID=16`, 113 baris data nyata - isinya
 * rekening bank/finance counterparty) - lihat `LookupController::kontakKeuangan()`.
 * **Rekening**: SEMUA akun `bcoa` leaf (`CGD='D'`), TIDAK dibatasi CTIPE Kas/Bank spt
 * modul KM/KK/BM/BK (`LookupController::coaRekening()` tanpa `tipe`).
 *
 * **DEFER**: multi-currency (kurs selalu 1, uang selalu Rp - field VB6 `txtKurs`/
 * `txtMataUang` py `Enabled=0` alias disabled di form aslinya jg), divisi/proyek per baris
 * (`CDDVISI`/`CDEVENT` - KOSONG krn `KasBankWriter` kita SENDIRI tidak pernah mengisi
 * kolom itu di baris KK sumber, jadi tidak ada apa2 utk di-copy), status "pending"
 * (`CUSTATUS` - checkbox `chkPending` VB6 `Enabled=0 Visible=0` py `Value=1` PERMANEN,
 * artinya CUSTATUS SELALU 0 di praktiknya - kita fix ke 0 juga, bukan setengah-fitur).
 * **Hapus = HARD DELETE** (pola sama `ctransaksiu`/`ctransaksid`, BUKAN soft-cancel).
 */
class PengajuanDanaWriter
{
    public const SUMBER = 'PDN';

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-' . self::SUMBER . $yymm;

        $maks = (int) DB::table('ctransaksipu')
            ->where('CUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(CUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Baris KK (`ctransaksid`) yg BELUM ditarik, utk rekening+cabang tertentu.
     *
     * @return list<array{cdid:int,coa:int,coaLabel:string,jumlah:float,catatan:?string,noKk:string}>
     */
    public function pullableKk(int $rekening, int $cabang): array
    {
        $rows = DB::table('ctransaksid as d')
            ->join('ctransaksiu as u', 'u.CUID', '=', 'd.CDIDU')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->where('u.CUSUMBER', 'KK')
            ->where('u.CUREKKAS', $rekening)
            ->where('d.CDCABANG', $cabang)
            ->where('d.CDDIBUATPENGAJUAN', 0)
            ->where('d.CDDEBIT', '>', 0)
            ->orderBy('u.CUTANGGAL')
            ->orderBy('d.CDURUTAN')
            ->get(['d.CDID', 'd.CDNOCOA', 'c.CNOCOA', 'c.CNAMA', 'd.CDDEBIT', 'd.CDCATATAN', 'u.CUNOTRANSAKSI']);

        return $rows->map(fn ($r) => [
            'cdid'     => (int) $r->CDID,
            'coa'      => (int) $r->CDNOCOA,
            'coaLabel' => trim(($r->CNOCOA ?? '') . ' — ' . ($r->CNAMA ?? '')),
            'jumlah'   => (float) $r->CDDEBIT,
            'catatan'  => $r->CDCATATAN,
            'noKk'     => $r->CUNOTRANSAKSI,
        ])->all();
    }

    /**
     * @param array{kontak:int,uraian:?string,rekening:int,tanggal:string,cabang:int} $header
     * @param list<array{cdid:int,coa:int,jumlah:float,catatan:?string}> $lines
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function create(array $header, array $lines, array $meta): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['jumlah'] > 0));
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Detail biaya kosong.'];
        }

        $total = array_sum(array_map(fn ($l) => (float) $l['jumlah'], $lines));
        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $total, $nomor) {
                // Re-cek server-side tiap baris MASIH blm ditarik - cegah race/tarik dobel
                // (mis. 2 user buka Pengajuan Dana rekening sama bersamaan).
                foreach ($lines as $l) {
                    $sudahDitarik = (bool) DB::table('ctransaksid')->where('CDID', $l['cdid'])
                        ->where('CDDIBUATPENGAJUAN', 1)->exists();
                    if ($sudahDitarik) {
                        throw new \RuntimeException('Salah satu baris KK sudah ditarik pengajuan lain, muat ulang data.');
                    }
                }

                $id = (int) DB::table('ctransaksipu')->insertGetId([
                    'CUSUMBER'      => self::SUMBER,
                    'CUNOTRANSAKSI' => $nomor,
                    'CUTANGGAL'     => $header['tanggal'],
                    'CUKONTAK'      => $header['kontak'],
                    'CUURAIAN'      => $header['uraian'] ?: null,
                    'CUREKKAS'      => $header['rekening'],
                    'CUTOTALTRANS'  => $total,
                    'CUSTATUS'      => 0,
                    'CUTIPE'        => 0,
                    'CUCABANG'      => $header['cabang'],
                    'CUCREATEU'     => auth()->id(),
                ], 'CUID');

                // Baris 1 = rekening (kredit total) - TIDAK py CDBKKID (bukan tarikan dari KK).
                DB::table('ctransaksipd')->insert([
                    'CDIDU'     => $id,
                    'CDURUTAN'  => 1,
                    'CDNOCOA'   => $header['rekening'],
                    'CDKREDIT'  => $total,
                    'CDUANG'    => 1,
                    'CDCATATAN' => $header['uraian'] ?: null,
                    'CDCABANG'  => $header['cabang'],
                ]);

                // Baris 2+ = tarikan dari KK - CDBKKID memicu trigger ctransaksipd_add
                // (set ctransaksid.CDDIBUATPENGAJUAN=1 utk baris sumbernya).
                $urut = 2;
                foreach ($lines as $l) {
                    DB::table('ctransaksipd')->insert([
                        'CDIDU'     => $id,
                        'CDURUTAN'  => $urut++,
                        'CDNOCOA'   => $l['coa'],
                        'CDDEBIT'   => (float) $l['jumlah'],
                        'CDUANG'    => 1,
                        'CDCATATAN' => $l['catatan'] ?: null,
                        'CDBKKID'   => $l['cdid'],
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

    /**
     * Hapus - HARD DELETE (pola sama `ctransaksiu`/`ctransaksid`). Hapus baris `ctransaksipd`
     * SATU-PER-SATU (trigger `ctransaksipd_dell` per-row, BUKAN bulk - Eloquent `delete()`
     * pada query builder tetap jalankan 1 statement DELETE tapi trigger MySQL tetap fire
     * per baris yg kena, jadi aman) OTOMATIS balikkan `CDDIBUATPENGAJUAN=0` baris KK sumber.
     */
    public function delete(int $id): bool
    {
        $h = $this->header($id);
        if (! $h) {
            return false;
        }

        DB::transaction(function () use ($id) {
            DB::table('ctransaksipd')->where('CDIDU', $id)->delete();
            DB::table('ctransaksipu')->where('CUID', $id)->delete();
        });

        return true;
    }

    public function header(int $id): ?object
    {
        return DB::table('ctransaksipu')->where('CUID', $id)->where('CUSUMBER', self::SUMBER)->first();
    }

    /** Baris tarikan saja (urutan > 1, exclude baris rekening) + traceability ke KK asal. */
    public function lines(int $id): array
    {
        return DB::table('ctransaksipd as d')
            ->leftJoin('bcoa as c', 'c.CID', '=', 'd.CDNOCOA')
            ->leftJoin('ctransaksid as kk', 'kk.CDID', '=', 'd.CDBKKID')
            ->leftJoin('ctransaksiu as kku', 'kku.CUID', '=', 'kk.CDIDU')
            ->where('d.CDIDU', $id)->where('d.CDURUTAN', '>', 1)
            ->orderBy('d.CDURUTAN')
            ->get(['d.*', 'c.CNOCOA', 'c.CNAMA', 'kku.CUNOTRANSAKSI as no_kk'])
            ->all();
    }
}
