<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Perubahan Harga (PH) - menaikkan/menurunkan `bitem.IHARGAJUAL1` lewat DOKUMEN, dengan
 * harga lama tersimpan sehingga bisa dikembalikan.
 *
 * ## Asal: VB6 `fFrmPerubahanHarga` (amenu MID 517) - tapi VB6 TIDAK PERNAH menerapkan harga
 * Form VB6 hanya menyimpan dokumen (`gperubahanhargau` / `gperubahanhargad`: harga lama,
 * harga baru, harga setelah pembulatan, tanggal berlaku, persen) - **tidak ada satu pun
 * `UPDATE bitem` di form itu**, dan kedua tabelnya 0 baris di DB ini (fitur belum pernah
 * dipakai). TERAPKAN & KEMBALIKAN di bawah adalah TAMBAHAN (permintaan user 2026-10-17).
 * Tabel & kolomnya dipakai apa adanya supaya layar/cetakan VB6 (MID 518 & 519) tetap bisa
 * membaca dokumen yg dibuat di sini.
 *
 * Kolom detail:
 *   `SDHARGALAMA`  = harga jual saat ini (disegarkan ULANG saat diterapkan - itu yg
 *                    dipulihkan "Kembalikan")
 *   `SDHARGABARU`  = harga lama + persen, sebelum pembulatan
 *   `SDHARGAAKHIR` = harga yg BENAR2 dipasang ke `IHARGAJUAL1` (setelah pembulatan / edit)
 *
 * ## Status (`SUSTATUS`)
 *   0 Draft - boleh diubah, dihapus, diterapkan
 *   1 Diterapkan - terkunci; hanya bisa "Kembalikan"
 *   2 Dikembalikan - harga lama sudah dipulihkan; boleh diubah & diterapkan lagi
 * Riwayat tiap aksi ada di `lv_perubahan_harga_log` (lihat migrasinya).
 *
 * ## Dua pengaman yg TIDAK boleh dilonggarkan
 * 1. **Terapkan DITOLAK kalau harga sekarang ≠ `SDHARGALAMA`.** Artinya harga item itu sudah
 *    berubah sejak dokumen disusun, dan persen/pembulatannya dihitung dari angka basi. User
 *    harus "Segarkan Harga Lama" lalu memeriksa ulang - bukan diterapkan diam-diam.
 * 2. **Kembalikan HANYA item yg harganya masih = `SDHARGAAKHIR`.** Kalau sesudah diterapkan
 *    ada yg mengubah harga item itu lagi (Data Item POS / dokumen PH lain), mengembalikannya
 *    akan menimpa perubahan orang lain. Item itu DILEWATI & dilaporkan.
 *
 * Juga: `bitem.ITANGGALRUBAHHARGA` (kolom legacy "tanggal rubah harga", terakhir terisi 2015)
 * diisi tanggal berlaku saat diterapkan, dan dipulihkan dari log saat dikembalikan.
 * `bitem` tidak punya trigger (dicek), jadi UPDATE-nya tidak menjalar ke mana pun.
 */
class PerubahanHargaWriter
{
    public const SUMBER = 'PH';

    public const STATUS_DRAFT = 0;

    public const STATUS_DITERAPKAN = 1;

    public const STATUS_DIKEMBALIKAN = 2;

    public const STATUS_LABEL = [
        self::STATUS_DRAFT        => 'Draft',
        self::STATUS_DITERAPKAN   => 'Diterapkan',
        self::STATUS_DIKEMBALIKAN => 'Dikembalikan',
    ];

    /** Selisih harga yg dianggap "sama" (double). */
    private const TOLERANSI = 0.005;

    /**
     * Pilihan pembulatan: kelipatan terdekat. 5.000 = aturan VB6 `cmdProses_Click` (sisa bagi
     * 10.000 dibandingkan 2.500/5.000/7.500 - sama dgn pembulatan ke 5.000 terdekat).
     */
    public const PEMBULATAN = [0 => 'Tanpa pembulatan', 100 => '100', 500 => '500',
        1000 => '1.000', 5000 => '5.000 (aturan VB6)'];

    public function nextNumber(string $kodeCabang, string $tglYmd): string
    {
        $base = $kodeCabang . '-' . self::SUMBER . date('ym', strtotime($tglYmd));

        $maks = (int) DB::table('gperubahanhargau')
            ->where('SUNOTRANSAKSI', 'like', $base . '%')
            ->selectRaw('MAX(CAST(RIGHT(SUNOTRANSAKSI, 4) AS UNSIGNED)) AS maks')
            ->value('maks');

        return $base . str_pad((string) ($maks + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Harga lama + persen, lalu dibulatkan ke kelipatan `$kelipatan` terdekat. */
    public static function hitung(float $lama, float $persen, int $kelipatan): array
    {
        $baru = round($lama * (1 + $persen / 100), 2);
        $akhir = $kelipatan > 0 ? round($baru / $kelipatan) * $kelipatan : $baru;

        // Harga kecil bisa jatuh ke 0 (mis. 1.050 dgn kelipatan 5.000) - harga jual 0 jelas
        // bukan maksudnya, jadi pakai harga sebelum pembulatan.
        if ($akhir <= 0) {
            $akhir = $baru;
        }

        return [$baru, (float) $akhir];
    }

    public function header(int $id): ?object
    {
        return DB::table('gperubahanhargau')->where('SUID', $id)->where('SUSUMBER', self::SUMBER)->first();
    }

    /** Baris dokumen + harga jual SEKARANG (`hargaSekarang`) utk dibandingkan. */
    public function lines(int $id): array
    {
        return DB::table('gperubahanhargad as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get(['d.SDITEM', 'd.SDHARGALAMA', 'd.SDHARGABARU', 'd.SDHARGAAKHIR',
                'i.IKODE', 'i.INAMA', 'i.IHARGAJUAL1 as hargaSekarang', 's.SKODE as satuan'])
            ->all();
    }

    public function riwayat(int $id): array
    {
        return DB::table('lv_perubahan_harga_log as l')
            ->leftJoin('auser as u', 'u.UID', '=', 'l.user_id')
            ->where('l.suid', $id)
            ->orderByDesc('l.id')
            ->get(['l.id', 'l.aksi', 'l.jumlah_item', 'l.jumlah_dilewati', 'l.rincian', 'l.created_at', 'u.UNAMA as user'])
            ->all();
    }

    private static function sama(float $a, float $b): bool
    {
        return abs($a - $b) < self::TOLERANSI;
    }

    /**
     * Simpan dokumen baru (`$id` null) atau ubah Draft/Dikembalikan.
     *
     * @param array{tanggal:string,berlaku:string,kontak:?int,uraian:?string,persen:float,cabang:int,kodecabang:string} $meta
     * @param list<array{item:int,lama:float,baru:float,akhir:float}> $lines
     *
     * @return array{ok:bool,id:?int,nomor:?string,error:?string}
     */
    public function save(?int $id, array $meta, array $lines): array
    {
        $gagal = fn (string $e) => ['ok' => false, 'id' => $id, 'nomor' => null, 'error' => $e];

        if ($lines === []) {
            return $gagal('Belum ada item.');
        }
        $item = array_map(fn ($l) => (int) $l['item'], $lines);
        if (count($item) !== count(array_unique($item))) {
            return $gagal('Ada item yang tercantum lebih dari sekali.');
        }
        foreach ($lines as $l) {
            if ((float) $l['akhir'] <= 0) {
                return $gagal('Harga akhir tidak boleh 0 atau minus.');
            }
        }

        return DB::transaction(function () use ($id, $meta, $lines, $gagal) {
            $data = [
                'SUSUMBER'         => self::SUMBER,
                'SUTANGGAL'        => $meta['tanggal'],
                'SUTANGGALBERLAKU' => $meta['berlaku'],
                'SUKONTAK'         => $meta['kontak'] ?: null,
                'SUURAIAN'         => $meta['uraian'] ?: null,
                'SUNILAI'          => $meta['persen'],
                'SUTOTALTRANSAKSI' => array_sum(array_map(fn ($l) => (float) $l['akhir'], $lines)),
                'SUMODIFU'         => auth()->id(),
                'SUMODIFD'         => now(),
            ];

            if ($id) {
                $h = DB::table('gperubahanhargau')->where('SUID', $id)->lockForUpdate()->first();
                if (! $h || (int) $h->SUSTATUS === self::STATUS_DITERAPKAN) {
                    return $gagal('Dokumen yang sudah diterapkan tidak bisa diubah - kembalikan dulu.');
                }
                DB::table('gperubahanhargau')->where('SUID', $id)->update($data);
                DB::table('gperubahanhargad')->where('SDIDSU', $id)->delete();
                $nomor = $h->SUNOTRANSAKSI;
            } else {
                $nomor = $this->nextNumber($meta['kodecabang'], $meta['tanggal']);
                $id = (int) DB::table('gperubahanhargau')->insertGetId($data + [
                    'SUNOTRANSAKSI' => $nomor,
                    'SUCABANG'      => $meta['cabang'],
                    'SUSTATUS'      => self::STATUS_DRAFT,
                    'SUCREATEU'     => auth()->id(),
                ], 'SUID');
            }

            $urut = 1;
            foreach ($lines as $l) {
                DB::table('gperubahanhargad')->insert([
                    'SDIDSU'       => $id,
                    'SDURUTAN'     => $urut++,
                    'SDSUMBER'     => self::SUMBER,
                    'SDITEM'       => (int) $l['item'],
                    'SDHARGALAMA'  => (float) $l['lama'],
                    'SDHARGABARU'  => (float) $l['baru'],
                    'SDHARGAAKHIR' => (float) $l['akhir'],
                ]);
            }

            return ['ok' => true, 'id' => $id, 'nomor' => $nomor, 'error' => null];
        });
    }

    /** Hapus PERMANEN - hanya Draft yg belum pernah diterapkan (pola VB6 `xHapusData`). */
    public function delete(int $id): array
    {
        $h = $this->header($id);
        if (! $h || (int) $h->SUSTATUS !== self::STATUS_DRAFT
            || DB::table('lv_perubahan_harga_log')->where('suid', $id)->exists()) {
            return ['ok' => false, 'error' => 'Hanya draft yang belum pernah diterapkan yang bisa dihapus.'];
        }

        $paths = DB::table('lv_perubahan_harga_lampiran')->where('suid', $id)->pluck('path')->all();
        DB::transaction(function () use ($id) {
            DB::table('lv_perubahan_harga_lampiran')->where('suid', $id)->delete();
            DB::table('gperubahanhargau')->where('SUID', $id)->delete(); // detail ikut (FK CASCADE)
        });
        // File dihapus SETELAH commit - kalau DB gagal, file tidak ikut hilang.
        Storage::disk(self::DISK)->delete($paths);

        return ['ok' => true, 'error' => null];
    }

    // =========================== Lampiran (audit) ===========================
    //
    // Bukti surat/IOM kenaikan harga (gambar / PDF). Aturan - JANGAN dilonggarkan:
    //  1. Terapkan WAJIB punya minimal 1 lampiran (dijaga di `terapkan()`, bukan cuma tombol).
    //  2. Lampiran TIDAK BISA dihapus begitu dokumen PERNAH diterapkan (ada log) - bukti audit
    //     tidak boleh hilang walau dokumennya sudah dikembalikan. Menambah tetap boleh.
    //  3. Tipe dibaca dari ISI file (finfo), bukan dari nama/ekstensi kiriman user.

    public const DISK = 'local';

    public const LAMPIRAN_MIME = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    ];

    /** Batas ukuran lampiran (KB) yg kita izinkan - batas server PHP bisa lebih kecil, lihat `batasUnggahKb()`. */
    public const LAMPIRAN_MAKS_KB = 10240;

    /** Batas EFEKTIF: yg terkecil dari batas kita & `upload_max_filesize`/`post_max_size` server. */
    public static function batasUnggahKb(): int
    {
        $kb = function (string $v): int {
            $v = trim($v);
            $n = (float) $v;
            $satuan = strtolower(substr($v, -1));
            $hasil = match ($satuan) {
                'g' => $n * 1048576, 'm' => $n * 1024, 'k' => $n, default => $n / 1024,
            };

            return (int) $hasil;
        };
        $batas = array_filter([self::LAMPIRAN_MAKS_KB, $kb((string) ini_get('upload_max_filesize')), $kb((string) ini_get('post_max_size'))]);

        return (int) min($batas);
    }

    public function lampiran(int $id): array
    {
        return DB::table('lv_perubahan_harga_lampiran as l')
            ->leftJoin('auser as u', 'u.UID', '=', 'l.user_id')
            ->where('l.suid', $id)->orderBy('l.id')
            ->get(['l.id', 'l.nama_asli', 'l.mime', 'l.ukuran', 'l.created_at', 'u.UNAMA as user'])->all();
    }

    public function bolehHapusLampiran(int $id): bool
    {
        $h = $this->header($id);

        return $h && (int) $h->SUSTATUS === self::STATUS_DRAFT
            && ! DB::table('lv_perubahan_harga_log')->where('suid', $id)->exists();
    }

    /**
     * @param string $sumber jalur file sementara (hasil unggahan)
     * @return array{ok:bool,error:?string,id:?int}
     */
    public function simpanLampiran(int $id, string $sumber, string $namaAsli): array
    {
        $gagal = fn (string $e) => ['ok' => false, 'error' => $e, 'id' => null];
        if (! $this->header($id)) {
            return $gagal('Dokumen tidak ditemukan.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sumber);
        if (! isset(self::LAMPIRAN_MIME[$mime])) {
            return $gagal('Lampiran harus PDF atau gambar (JPG/PNG/WEBP). File ini terbaca sebagai "' . $mime . '".');
        }
        $ukuran = (int) filesize($sumber);
        if ($ukuran <= 0 || $ukuran > self::LAMPIRAN_MAKS_KB * 1024) {
            return $gagal('Ukuran lampiran maksimal ' . (self::LAMPIRAN_MAKS_KB / 1024) . ' MB.');
        }
        $sha = hash_file('sha256', $sumber);
        if (DB::table('lv_perubahan_harga_lampiran')->where('suid', $id)->where('sha256', $sha)->exists()) {
            return $gagal('File yang sama sudah dilampirkan di dokumen ini.');
        }

        $nama = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $namaAsli)) ?: 'lampiran', 0, 200);
        $path = 'lampiran/perubahan-harga/' . $id . '/' . \Illuminate\Support\Str::ulid() . '.' . self::LAMPIRAN_MIME[$mime];
        $isi = fopen($sumber, 'rb');
        $tersimpan = Storage::disk(self::DISK)->put($path, $isi);
        if (is_resource($isi)) {
            fclose($isi);
        }
        if (! $tersimpan) {
            return $gagal('Lampiran gagal disimpan ke server.');
        }

        try {
            $lid = (int) DB::table('lv_perubahan_harga_lampiran')->insertGetId([
                'suid' => $id, 'nama_asli' => $nama, 'path' => $path, 'mime' => $mime,
                'ukuran' => $ukuran, 'sha256' => $sha, 'user_id' => auth()->id(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);   // jangan tinggalkan file yatim
            throw $e;
        }

        return ['ok' => true, 'error' => null, 'id' => $lid];
    }

    /** @return array{ok:bool,error:?string,nama:?string} */
    public function hapusLampiran(int $lampiranId): array
    {
        $l = DB::table('lv_perubahan_harga_lampiran')->where('id', $lampiranId)->first();
        if (! $l) {
            return ['ok' => false, 'error' => 'Lampiran tidak ditemukan.', 'nama' => null];
        }
        if (! $this->bolehHapusLampiran((int) $l->suid)) {
            return ['ok' => false, 'nama' => $l->nama_asli,
                'error' => 'Lampiran dokumen yang sudah pernah diterapkan tidak bisa dihapus (bukti audit).'];
        }
        DB::table('lv_perubahan_harga_lampiran')->where('id', $lampiranId)->delete();
        Storage::disk(self::DISK)->delete($l->path);

        return ['ok' => true, 'error' => null, 'nama' => $l->nama_asli];
    }

    /**
     * Pasang `SDHARGAAKHIR` ke `bitem.IHARGAJUAL1`. Semua-atau-tidak-sama-sekali: kalau ada
     * satu item yg harganya sudah berubah sejak dokumen disusun, TIDAK ADA yg diterapkan.
     *
     * @return array{ok:bool,error:?string,berubah:list<string>,jumlah:int}
     */
    public function terapkan(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $h = DB::table('gperubahanhargau')->where('SUID', $id)->lockForUpdate()->first();
            if (! $h || (int) $h->SUSTATUS === self::STATUS_DITERAPKAN) {
                return ['ok' => false, 'error' => 'Dokumen sudah diterapkan.', 'berubah' => [], 'jumlah' => 0];
            }

            $lines = DB::table('gperubahanhargad')->where('SDIDSU', $id)->orderBy('SDURUTAN')->get();
            if ($lines->isEmpty()) {
                return ['ok' => false, 'error' => 'Dokumen tidak punya item.', 'berubah' => [], 'jumlah' => 0];
            }
            // Audit: harga jual tidak boleh berubah tanpa bukti surat kenaikan terlampir.
            if (! DB::table('lv_perubahan_harga_lampiran')->where('suid', $id)->exists()) {
                return ['ok' => false, 'berubah' => [], 'jumlah' => 0,
                    'error' => 'Lampirkan dulu surat/IOM kenaikan harga (gambar atau PDF) - wajib untuk audit sebelum harga diterapkan.'];
            }

            $items = DB::table('bitem')->whereIn('IID', $lines->pluck('SDITEM'))->lockForUpdate()
                ->get(['IID', 'IKODE', 'IHARGAJUAL1', 'ITANGGALRUBAHHARGA'])->keyBy('IID');

            $berubah = [];
            foreach ($lines as $l) {
                $it = $items->get($l->SDITEM);
                if (! $it) {
                    $berubah[] = 'Item #' . $l->SDITEM . ' tidak ditemukan';
                } elseif (! self::sama((float) $it->IHARGAJUAL1, (float) $l->SDHARGALAMA)) {
                    $berubah[] = $it->IKODE . ' (dokumen ' . self::rp($l->SDHARGALAMA) . ', sekarang ' . self::rp($it->IHARGAJUAL1) . ')';
                }
            }
            if ($berubah !== []) {
                return ['ok' => false, 'berubah' => $berubah, 'jumlah' => 0,
                    'error' => 'Harga ' . count($berubah) . ' item sudah berubah sejak dokumen disusun. '
                        . 'Klik "Segarkan Harga Lama", periksa lagi harganya, simpan, lalu terapkan.'];
            }

            $rincian = [];
            foreach ($lines as $l) {
                $it = $items->get($l->SDITEM);
                DB::table('bitem')->where('IID', $l->SDITEM)->update([
                    'IHARGAJUAL1'        => (float) $l->SDHARGAAKHIR,
                    'ITANGGALRUBAHHARGA' => $h->SUTANGGALBERLAKU,
                ]);
                $rincian[] = ['item' => (int) $l->SDITEM, 'kode' => $it->IKODE,
                    'sebelum' => (float) $it->IHARGAJUAL1, 'sesudah' => (float) $l->SDHARGAAKHIR,
                    'tglSebelum' => $it->ITANGGALRUBAHHARGA];
            }

            $this->catat($id, 'terapkan', $rincian, 0);
            DB::table('gperubahanhargau')->where('SUID', $id)->update([
                'SUSTATUS' => self::STATUS_DITERAPKAN, 'SUMODIFU' => auth()->id(), 'SUMODIFD' => now(),
            ]);

            return ['ok' => true, 'error' => null, 'berubah' => [], 'jumlah' => count($rincian)];
        });
    }

    /**
     * Pulihkan `SDHARGALAMA` - HANYA utk item yg harganya masih = `SDHARGAAKHIR`. Sisanya
     * dilewati & dikembalikan di `dilewati`.
     *
     * @return array{ok:bool,error:?string,jumlah:int,dilewati:list<string>}
     */
    public function kembalikan(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $h = DB::table('gperubahanhargau')->where('SUID', $id)->lockForUpdate()->first();
            if (! $h || (int) $h->SUSTATUS !== self::STATUS_DITERAPKAN) {
                return ['ok' => false, 'error' => 'Hanya dokumen berstatus Diterapkan yang bisa dikembalikan.', 'jumlah' => 0, 'dilewati' => []];
            }

            // Tanggal rubah harga SEBELUM diterapkan - dari log terapkan terakhir.
            $log = DB::table('lv_perubahan_harga_log')->where('suid', $id)->where('aksi', 'terapkan')
                ->orderByDesc('id')->value('rincian');
            $tglSebelum = collect(json_decode((string) $log, true) ?: [])->pluck('tglSebelum', 'item');

            $lines = DB::table('gperubahanhargad')->where('SDIDSU', $id)->orderBy('SDURUTAN')->get();
            $items = DB::table('bitem')->whereIn('IID', $lines->pluck('SDITEM'))->lockForUpdate()
                ->get(['IID', 'IKODE', 'IHARGAJUAL1'])->keyBy('IID');

            $rincian = [];
            $dilewati = [];
            foreach ($lines as $l) {
                $it = $items->get($l->SDITEM);
                if (! $it) {
                    continue;
                }
                if (! self::sama((float) $it->IHARGAJUAL1, (float) $l->SDHARGAAKHIR)) {
                    $dilewati[] = $it->IKODE . ' (sekarang ' . self::rp($it->IHARGAJUAL1) . ', bukan ' . self::rp($l->SDHARGAAKHIR) . ')';
                    $rincian[] = ['item' => (int) $l->SDITEM, 'kode' => $it->IKODE, 'dilewati' => true,
                        'sekarang' => (float) $it->IHARGAJUAL1];

                    continue;
                }
                DB::table('bitem')->where('IID', $l->SDITEM)->update([
                    'IHARGAJUAL1'        => (float) $l->SDHARGALAMA,
                    'ITANGGALRUBAHHARGA' => $tglSebelum->get((int) $l->SDITEM),
                ]);
                $rincian[] = ['item' => (int) $l->SDITEM, 'kode' => $it->IKODE,
                    'sebelum' => (float) $it->IHARGAJUAL1, 'sesudah' => (float) $l->SDHARGALAMA];
            }

            $this->catat($id, 'kembalikan', $rincian, count($dilewati));
            DB::table('gperubahanhargau')->where('SUID', $id)->update([
                'SUSTATUS' => self::STATUS_DIKEMBALIKAN, 'SUMODIFU' => auth()->id(), 'SUMODIFD' => now(),
            ]);

            return ['ok' => true, 'error' => null, 'jumlah' => count($rincian) - count($dilewati), 'dilewati' => $dilewati];
        });
    }

    private function catat(int $id, string $aksi, array $rincian, int $dilewati): void
    {
        DB::table('lv_perubahan_harga_log')->insert([
            'suid'            => $id,
            'aksi'            => $aksi,
            'user_id'         => auth()->id(),
            'jumlah_item'     => count($rincian),
            'jumlah_dilewati' => $dilewati,
            'rincian'         => json_encode($rincian),
            'created_at'      => now(),
        ]);
    }

    private static function rp($n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
}
