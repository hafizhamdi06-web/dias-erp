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

    /**
     * Loyalty point: total > 100rb & KTIPE in (12,14,19) -> 1 poin / 10rb.
     */
    public function maybeAddPoints(int $saleId, ?int $kontak, float $total): void
    {
        if (! $kontak || $total <= 100000) {
            return;
        }

        $tipe = (int) DB::table('bkontak')->where('KID', $kontak)->value('KTIPE');
        if (! in_array($tipe, [12, 14, 19], true)) {
            return;
        }

        $point = (int) (($total - fmod($total, 10000)) / 10000);

        DB::table('bpoint')->insert([
            'PIDPASIEN'         => $kontak,
            'PNILAIMASUK'       => $total,
            'PMASUK'            => $point,
            'PIDTRANSAKSIMASUK' => $saleId,
        ]);
        DB::table('fstoku')->where('SUID', $saleId)->update(['SUSTATUSTADA' => 1]);

        $sum = (float) DB::table('bpoint')->where('PIDPASIEN', $kontak)
            ->selectRaw('SUM(PMASUK - PKELUAR) s')->value('s');
        DB::table('bkontak')->where('KID', $kontak)->update(['KPOINT' => $sum]);
    }
}
