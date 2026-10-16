<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penjualan POS - menulis ke tabel transaksi legacy fstoku (header) & fstokd (detail).
 * Stok & jurnal ditangani TRIGGER DB (fstoku_add / fstokd_add / fstokd_edit) - kita cukup
 * insert/update baris yang benar.
 *
 * SUSUMBER = 'IP' (Invoice Penjualan / Penjualan Tunai).
 * Nomor: {KODECABANG}-IP{YYMM}{NNNN}, unik global (U_fstoku_sunotransaksi),
 * urut diambil dari prefix nomor (bukan dari SUCABANG).
 *
 * fstoku.SUSTATUS = 9 artinya "Cancel" (semua transaksi aktif difilter `WHERE sustatus<>9`,
 * konvensi asli CI3). Baris CANCEL tidak pernah dihapus - lihat `replace()`.
 *
 * PENTING: class ini TIDAK PERNAH menghapus baris `fstoku`/`fstokd` yang sudah tersimpan -
 * pembatalan/edit selalu lewat UPDATE status (`replace()`), tidak ada method hard-delete di
 * sini. Kalau nanti benar-benar butuh fitur hapus transaksi sungguhan (mis. admin membersihkan
 * entri salah input), buat method/fitur baru terpisah dgn otorisasi jelas (spt CI3
 * `hapusTransaksi()` yg digerbang password supervisor) - jangan diam-diam ditambahkan ke sini.
 */
class PosSaleWriter
{
    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $yymm = date('ym', strtotime($tglYmd));
        $base = $kodeCabang . '-IP' . $yymm;

        $maks = (int) DB::table('fstoku')
            ->where('SUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(SUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array        $header kolom fstoku (tanpa SUNOTRANSAKSI/SUID)
     * @param list<array>   $lines  kolom fstokd (tanpa SDIDSU/SDURUTAN)
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function save(array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Keranjang kosong.'];
        }

        $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

        try {
            $id = DB::transaction(function () use ($header, $lines, $nomor) {
                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER']      = 'IP';
                $header['SUURAIAN']      = 'Penjualan - POS';

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                $urut = 1;
                foreach ($lines as $line) {
                    $line['SDIDSU']   = $id;
                    $line['SDURUTAN'] = $urut++;
                    $line['SDSUMBER'] = 'IP';
                    DB::table('fstokd')->insert($line);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan transaksi: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
    }

    /**
     * "Edit" transaksi: simpan transaksi baru + batalkan `$oldId` dalam SATU transaksi DB
     * atomik - kalau simpan yg baru gagal, batal-nya ikut roll back (transaksi lama TETAP AKTIF
     * apa adanya). Dipakai supaya transaksi lama baru benar-benar dibatalkan SETELAH transaksi
     * pengganti selesai tersimpan, bukan langsung saat tombol "Edit" diklik.
     *
     * **Pola pembatalan SAMA PERSIS dgn CI3 legacy (`M_PJ_POS_HP::tambahTransaksi()`,
     * konfirmasi dari kode asli)**: baris lama TIDAK DIHAPUS, cuma:
     *   - `fstokd.SDCANCEL = 1` (bukan delete baris) - trigger `fstokd_edit` (AFTER UPDATE)
     *     yg membalik stok: baris lama dikurangi dari perhitungan lama, TIDAK ditambahkan lagi
     *     krn digate `WHERE NEW.SDCANCEL=0` - net effect = stok kembali persis seperti delete,
     *     tapi baris & riwayatnya tetap ada utk audit.
     *   - `fstoku.SUSTATUS = 9` (bukan delete header), `SUDP1=0`, `SUTOTALDP=0`,
     *     `SUIPBARU=<id transaksi baru>` (link lama->baru, kolom asli legacy).
     *   - `bpoint` (poin loyalti) tetap DIHAPUS spt legacy (poin transaksi batal tidak berlaku).
     *   - Transaksi baru diberi `SUNOIPLAMA = <no. transaksi lama>` (link baru->lama).
     * Konvensi tampilan: transaksi dgn SUSTATUS=9 = "Cancel", selain itu = "Aktif" (dipakai di
     * semua query "transaksi aktif" legacy via `WHERE sustatus<>9`).
     *
     * @param array        $header kolom fstoku (tanpa SUNOTRANSAKSI/SUID)
     * @param list<array>   $lines  kolom fstokd (tanpa SDIDSU/SDURUTAN)
     * @param array{kodecabang:string,tgl:string} $meta
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function replace(int $oldId, array $header, array $lines, array $meta): array
    {
        if ($lines === []) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Keranjang kosong.'];
        }

        $oldNomor = DB::table('fstoku')->where('SUID', $oldId)->value('SUNOTRANSAKSI');

        try {
            $result = DB::transaction(function () use ($oldId, $oldNomor, $header, $lines, $meta) {
                $nomor = $this->nextNumber($meta['kodecabang'], $meta['tgl']);

                $header['SUNOTRANSAKSI'] = $nomor;
                $header['SUSUMBER']      = 'IP';
                $header['SUURAIAN']      = 'Penjualan - POS';
                $header['SUNOIPLAMA']    = $oldNomor;

                $id = (int) DB::table('fstoku')->insertGetId($header, 'SUID');

                $urut = 1;
                foreach ($lines as $line) {
                    $line['SDIDSU']   = $id;
                    $line['SDURUTAN'] = $urut++;
                    $line['SDSUMBER'] = 'IP';
                    DB::table('fstokd')->insert($line);
                }

                // Batalkan transaksi lama TANPA menghapus baris - pola CI3: SDCANCEL=1 + SUSTATUS=9.
                DB::table('bpoint')->where('PIDTRANSAKSIMASUK', $oldId)->delete();
                DB::table('fstokd')->where('SDIDSU', $oldId)->update(['SDCANCEL' => 1]);
                DB::table('fstoku')->where('SUID', $oldId)->update([
                    'SUSTATUS'  => 9,
                    'SUDP1'     => 0,
                    'SUTOTALDP' => 0,
                    'SUIPBARU'  => $id,
                ]);

                return ['id' => $id, 'nomor' => $nomor];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'nomor' => null, 'error' => 'Gagal menyimpan transaksi pengganti: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $result['id'], 'nomor' => $result['nomor'], 'error' => null];
    }

    /**
     * Batalkan transaksi POS TANPA pengganti - dipakai aksi "Batalkan" di Data Transaksi POS
     * (permintaan user 2026-10-13).
     *
     * **Memakai aturan pembatalan yg SAMA dgn `replace()`** (pola CI3
     * `M_PJ_POS_HP::tambahTransaksi()`), bukan sekadar menyetel status:
     *   - `bpoint` dihapus - poin loyalti transaksi batal tidak berlaku;
     *   - `fstokd.SDCANCEL = 1` (baris TIDAK dihapus) -> trigger `fstokd_edit` memulihkan
     *     stok krn bagian penambahannya digate `WHERE NEW.SDCANCEL=0`. Barisnya tetap ada
     *     utk audit;
     *   - `fstoku`: `SUSTATUS = 9`, `SUDP1 = 0`, `SUTOTALDP = 0`.
     *
     * BEDA dari `replace()`: `SUIPBARU` TIDAK diisi - kolom itu menunjuk transaksi
     * PENGGANTI, dan di sini memang tidak ada penggantinya.
     *
     * Semuanya dalam satu transaksi DB: stok & status harus berubah bersama, kalau
     * sebagian saja yg jadi, stok dan dokumen akan bertentangan.
     *
     * @return array{ok:bool,nomor:?string,error:?string}
     */
    public function cancel(int $id): array
    {
        $h = DB::table('fstoku')->where('SUID', $id)->where('SUSUMBER', 'IP')
            ->first(['SUID', 'SUNOTRANSAKSI', 'SUSTATUS']);

        if (! $h) {
            return ['ok' => false, 'nomor' => null, 'error' => 'Transaksi tidak ditemukan.'];
        }
        if ((int) $h->SUSTATUS === 9) {
            return ['ok' => false, 'nomor' => $h->SUNOTRANSAKSI,
                'error' => 'Transaksi ' . $h->SUNOTRANSAKSI . ' sudah dibatalkan.'];
        }

        try {
            DB::transaction(function () use ($id) {
                DB::table('bpoint')->where('PIDTRANSAKSIMASUK', $id)->delete();
                DB::table('fstokd')->where('SDIDSU', $id)->update(['SDCANCEL' => 1]);
                DB::table('fstoku')->where('SUID', $id)->update([
                    'SUSTATUS'  => 9,
                    'SUDP1'     => 0,
                    'SUTOTALDP' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'nomor' => $h->SUNOTRANSAKSI,
                'error' => 'Gagal membatalkan: ' . $e->getMessage()];
        }

        return ['ok' => true, 'nomor' => $h->SUNOTRANSAKSI, 'error' => null];
    }

    public function header(int $id): ?object
    {
        return DB::table('fstoku')->where('SUID', $id)->first();
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

    /** Cabang yg TIDAK memberi poin - VB6 eFrmPOS2: Online, Gogobli, Bizpark, Marketplace. */
    public const CABANG_TANPA_POIN = [6, 9, 10, 18];

    /** Batas & bonus - disalin dari VB6 (eFrmPOS2 simpan + eMod_Point.PointMasuk). */
    public const POIN_MIN_TOTAL = 100000;

    public const POIN_PER = 10000;

    public const TEMAN_MIN_TOTAL = 500000;

    /** Bonus teman: VB6 memanggil PointMasuk(teman, 10.000.000) = 1.000 poin. */
    public const TEMAN_NILAI = 10000000;

    /**
     * Poin loyalti saat transaksi disimpan - aturan VB6 `eFrmPOS2` (permintaan user
     * 2026-10-16; versi sebelumnya memberi poin ke tipe 12/14/19 di semua cabang, total
     * > 100rb termasuk DP):
     *   - cabang Online/Gogobli/Bizpark/Marketplace tidak memberi poin sama sekali
     *   - MEMBER (KTIPE 12) & total >= 100.000 -> 1 poin / 10.000
     *   - `$totalTanpaDp` = total TANPA item DP & DP Konsul/Denda Member/Surcharge/Cash Back
     *     (VB6 `xHitungTotalTanpaDP`) - dihitung pemanggil
     *   - BONUS TEMAN: pasien BARU (KCREATED = tanggal transaksi, aturan VB6) belanja
     *     >= 500.000 & membawa teman (SUTEMAN) -> teman dapat 1.000 poin. Dicatat sbg baris
     *     `bpoint` SENDIRI - di VB6 keduanya memakai kunci PIDTRANSAKSIMASUK yg sama
     *     sehingga poin teman MENIMPA poin pasien.
     *
     * KPOINT HANYA diubah trigger `bpoint_add` (menambah poin baris baru) - TIDAK dihitung
     * ulang dari SUM bpoint spt VB6 `UpdatePoint`/versi lama di sini: terukur 2026-10-16,
     * 2.927 pasien punya KPOINT lebih besar dari SUM bpoint-nya (total +- 3,9 jt poin,
     * mis. poin migrasi) - menghitung ulang akan MENGHAPUS saldo itu.
     */
    public function maybeAddPoints(int $saleId, ?int $kontak, float $totalTanpaDp, int $cabang, ?int $teman = null): void
    {
        if (! $kontak || in_array($cabang, self::CABANG_TANPA_POIN, true)) {
            return;
        }

        $pasien = DB::table('bkontak')->where('KID', $kontak)->first(['KTIPE', 'KCREATED']);
        $diberi = [];

        if ((int) ($pasien->KTIPE ?? 0) === 12 && $totalTanpaDp >= self::POIN_MIN_TOTAL) {
            $this->tambahPoin($kontak, $totalTanpaDp, $saleId, null);
            $diberi[] = $kontak;
        }

        $tglTransaksi = (string) DB::table('fstoku')->where('SUID', $saleId)->value('SUTANGGAL');
        $pasienBaru = $pasien && $pasien->KCREATED
            && substr((string) $pasien->KCREATED, 0, 10) === substr($tglTransaksi, 0, 10);
        if ($teman && $teman !== $kontak && $pasienBaru && $totalTanpaDp >= self::TEMAN_MIN_TOTAL) {
            $this->tambahPoin($teman, self::TEMAN_NILAI, $saleId, 'Bonus bawa teman - pasien baru KID ' . $kontak);
            $diberi[] = $teman;
        }

        if ($diberi !== []) {
            DB::table('fstoku')->where('SUID', $saleId)->update(['SUSTATUSTADA' => 1]);
        }
    }

    /** 1 poin / 10.000 dari `$nilai` (VB6 PointMasuk: nilai - (nilai mod 10.000)) / 10.000. */
    private function tambahPoin(int $kid, float $nilai, int $saleId, ?string $pesan): void
    {
        DB::table('bpoint')->insert([
            'PIDPASIEN'         => $kid,
            'PNILAIMASUK'       => $nilai,
            'PMASUK'            => (int) floor($nilai / self::POIN_PER),
            'PIDTRANSAKSIMASUK' => $saleId,
            'PPESAN'            => $pesan,
        ]);
    }
}
