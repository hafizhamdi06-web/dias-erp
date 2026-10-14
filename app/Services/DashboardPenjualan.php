<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka untuk Dashboard Penjualan - lima tingkat rincian: PT -> Cabang -> COA -> Item ->
 * Transaksi. SATU kelas supaya rumusnya cuma ada di satu tempat; kalau tiap tingkat
 * menghitung sendiri, angka induk tidak akan sama dgn jumlah anaknya.
 *
 * ## SUMBER KEBENARAN RUMUS: laporan CI3, bukan turunan sendiri
 * Rumusnya DISALIN dari laporan CI3 yg sudah dipakai & dinyatakan benar oleh user
 * (2026-10-14):
 *   `dias-online-app/application/views/modul/laporan/
 *    laporan-ip-per-coa-2026-detail-v-agt-no-ip.php`
 * Kalau nanti laporan itu berubah, UBAH DI SINI JUGA - jangan "merapikan" rumus di bawah
 * tanpa mencocokkan ulang ke sana.
 *
 * Basis: `fstokd` + `fstoku` `SUSUMBER='IP'`, `SUSTATUS<>9`,
 * `COALESCE(SDKEPALAALKES,0)=0` (baris kepala alkes dibuang - alkesnya dihitung lewat
 * `SDIDUALKES`, kalau ikut terhitung nilainya dobel).
 *
 *   Penjualan      -> `ekspresiPenjualan()`   (porsi baris x UANG YG DITERIMA, bukan
 *                                              qty x harga)
 *   COGS Produk    -> `ekspresiCogsProduk()`  (`SDBELI`, 0 utk baris paket+kedatangan)
 *   COGS Alkes     -> `ekspresiCogsAlkes()`   (`SUM(SDBELI)` alkes lewat `SDIDUALKES`)
 *   Penj. Produk   -> dua cabang `ICOA2021` (lihat `ukuran()`)
 *   Penj. Alkes    = COGS Alkes x MARKUP (1,05)
 *
 * Dua ukuran berikut TIDAK ada di laporan CI3 - dipakai krn diminta rancangan dashboard,
 * dan dipilih supaya empat bagiannya tetap menjumlah ke Penjualan:
 *   PPN               = (Penj. Produk + Penj. Alkes) x 11%
 *   Pendapatan Klinik = Penjualan - Penj.Produk - Penj.Alkes - PPN
 *   Laba Kotor        = Penjualan - (COGS Produk + COGS Alkes)
 *
 * ## Riwayat yg jangan diulang
 * Versi pertama dashboard ini menghitung Penjualan sbg `qty x (harga - diskon)`, COGS dari
 * `bitem.ICOGS`, dan membagi alkes PROPORSIONAL per dokumen lewat `SUNOREF` - ketiganya
 * SALAH. Pembagian proporsional itu lahir dari kesimpulan keliru bahwa kaitan alkes per
 * baris tidak andal; yg saya periksa waktu itu `SDIDALKESNYA` di sisi baris IP, padahal
 * kaitan yg benar `SDIDUALKES` ada di sisi baris ALKES - dan itu terisi 1.196 dari 1.196,
 * 100% nyambung.
 */
class DashboardPenjualan
{
    /** Penjualan Produk/Alkes = COGS x ini. */
    public const MARKUP = 1.05;

    /** PPN atas (Penj. Produk + Penj. Alkes). */
    public const PPN = 0.11;

    public const TINGKAT = ['pt', 'cabang', 'coa', 'item', 'transaksi'];

    /**
     * Potongan SQL per baris IP, lengkap dgn alkes yg sudah dibagi.
     *
     * @param array{dari:string,sampai:string,pt?:?int,cabang?:?int,coa?:?int,item?:?int} $saring
     */
    private function dasar(array $saring)
    {
        $q = DB::table('fstokd as d')
            ->leftJoin('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bnamapt as pt', 'pt.NPID', '=', 'g.GPT')
            // COA pendapatan: lewat `bitem2`, BUKAN kolom `ICOA*` di bitem.
            ->leftJoin('bitem2 as i2', 'i2.I2IDITEM', '=', 'i.IID')
            ->leftJoin('bcoatipe_pendapatan as ct', 'ct.CTID', '=', 'i2.I2COAPENDAPATAN')
            // Biaya merchant (EDC) - dipotong dari uang yg benar-benar diterima.
            ->leftJoin('bmerchant as mc', 'mc.MCKODE', '=', 'u.SUMERCHANTJENIS')
            // Baris KEPALA alkes dikeluarkan - alkes dihitung lewat `SDIDUALKES`, kalau
            // ikut terhitung di sini nilainya dobel.
            ->whereRaw('COALESCE(d.SDKEPALAALKES, 0) = 0')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->whereBetween('u.SUTANGGAL', [$saring['dari'], $saring['sampai']]);

        return $q
            ->when(! empty($saring['pt']), fn ($b) => $b->where('g.GPT', (int) $saring['pt']))
            ->when(! empty($saring['cabang']), fn ($b) => $b->where('u.SUCABANG', (int) $saring['cabang']))
            ->when(isset($saring['coa']) && $saring['coa'] !== null,
                fn ($b) => (int) $saring['coa'] === 0
                    ? $b->whereNull('ct.CTID')
                    : $b->where('ct.CTID', (int) $saring['coa']))
            ->when(! empty($saring['item']), fn ($b) => $b->where('d.SDITEM', (int) $saring['item']));
    }

    /**
     * **Penjualan satu baris** - DISALIN dari CI3 `laporan-ip-per-coa-2026-detail-v-agt-no-ip`.
     *
     * Ini BUKAN `qty x (harga - diskon)`. Yg dipakai = porsi baris ini terhadap nilai nota,
     * dikalikan UANG YG BENAR-BENAR DITERIMA: kartu debit + kredit + transfer + (kas - sisa)
     * + DP + merchant setelah dipotong biaya EDC (`bmerchant.MCBIAYA` persen). Jadi diskon
     * nota, kembalian, dan potongan merchant semuanya sudah tercermin.
     *
     * `ICOA2021 = 12` punya pembilang berbeda: nilai barisnya dikurangi `SDBAYARDP` lebih
     * dulu. Dibiarkan apa adanya spt CI3.
     *
     * `NULLIF(...,0)` di pembagi: nota bernilai 0 akan jadi NULL (diabaikan `SUM`), sama
     * hasilnya dgn CI3 tapi tanpa galat bagi-nol.
     */
    private function ekspresiPenjualan(): string
    {
        $uangDiterima = '(u.SUTOTALKARTUDEBIT + u.SUTOTALKARTUKREDIT + u.SUTOTALTRANSFER
                          + (u.SUTOTALKAS - u.SUTOTALSISA) + u.SUTOTALDP
                          + (u.SUMERCHANTJUMLAH - (u.SUMERCHANTJUMLAH * COALESCE(mc.MCBIAYA,0) / 100)))';

        return "CASE WHEN i.ICOA2021 = 12
                     THEN (d.SDKELUAR * (d.SDHARGA - d.SDDISKON) - d.SDBAYARDP)
                          / NULLIF(u.SUTOTALTRANSAKSI, 0) * {$uangDiterima}
                     ELSE d.SDKELUAR * (d.SDHARGA - d.SDDISKON)
                          / NULLIF(u.SUTOTALTRANSAKSI, 0) * {$uangDiterima}
                END";
    }

    /**
     * **COGS produk satu baris** - CI3: `IF(SDDARIPAKET<>0 AND SDKEDATANGAN>0, 0, sdbeli)`.
     *
     * Jadi sumbernya `SDBELI` (COGS yg dibekukan saat transaksi), dan baris yg berasal dari
     * PAKET sekaligus baris KEDATANGAN di-nol-kan - COGS-nya sudah dihitung di tempat lain,
     * kalau ikut dihitung di sini jadi dobel.
     */
    private function ekspresiCogsProduk(): string
    {
        return 'IF(COALESCE(d.SDDARIPAKET,0) <> 0 AND COALESCE(d.SDKEDATANGAN,0) > 0, 0, COALESCE(d.SDBELI,0))';
    }

    /**
     * **COGS alkes satu baris** - CI3 memakai subkueri berkorelasi
     * `SUM(sdbeli) FROM fstokd aa WHERE aa.SDIDUALKES = a.sdid`.
     *
     * Alkes terhubung ke baris IP-nya LANGSUNG lewat `SDIDUALKES` (ada di baris alkes,
     * menunjuk `fstokd.SDID` baris IP) - terukur 1.196 dari 1.196 baris alkes nyambung,
     * 100%. (Versi dashboard sebelumnya membagi alkes proporsional per dokumen krn saya
     * salah memeriksa `SDIDALKESNYA` di sisi baris IP; itu keliru dan sudah dibuang.)
     */
    private function ekspresiCogsAlkes(): string
    {
        return '(SELECT SUM(COALESCE(aa.SDBELI,0)) FROM fstokd aa WHERE aa.SDIDUALKES = d.SDID)';
    }

    /** Kolom ukuran yg sama di SEMUA tingkat - inilah yg menjamin angkanya konsisten. */
    private function ukuran(): array
    {
        $jual = $this->ekspresiPenjualan();
        $cogs = $this->ekspresiCogsProduk();
        $alkes = $this->ekspresiCogsAlkes();

        return [
            DB::raw("SUM({$jual}) as penjualan"),
            DB::raw("SUM({$cogs}) as cogsProduk"),
            DB::raw("SUM(COALESCE({$alkes}, 0)) as cogsAlkes"),
            // **Penjualan Produk** - CI3 `nilaiproduk`:
            //   ICOA2021 = 1  -> nilai jual dikeluarkan PPN-nya (`subtotal / 1,11`)
            //   selain itu    -> COGS x markup
            // Dua cabang ini TIDAK boleh disederhanakan jadi satu rumus.
            DB::raw('SUM(CASE WHEN i.ICOA2021 = 1 THEN (' . $jual . ') / ' . (1 + self::PPN)
                    . ' ELSE (' . $cogs . ') * ' . self::MARKUP . ' END) as penjProdukSql'),
            DB::raw('SUM(COALESCE(' . $alkes . ', 0)) * ' . self::MARKUP . ' as penjAlkesSql'),
            DB::raw('SUM(d.SDKELUAR) as qty'),
            DB::raw('COUNT(DISTINCT u.SUID) as jmlTransaksi'),
        ];
    }

    /** Lengkapi baris hasil dgn turunannya (markup/PPN/laba). */
    private function lengkapi(object $r): object
    {
        $r->penjualan = (float) $r->penjualan;
        $r->cogsProduk = (float) $r->cogsProduk;
        $r->cogsAlkes = (float) $r->cogsAlkes;

        // Penjualan Produk & Alkes dihitung DI SQL (lihat `ukuran()`) - Produk punya dua
        // cabang per `ICOA2021` yg tidak bisa diturunkan dari angka yg sudah dijumlah.
        $r->penjProduk = (float) $r->penjProdukSql;
        $r->penjAlkes = (float) $r->penjAlkesSql;
        $r->ppn = ($r->penjProduk + $r->penjAlkes) * self::PPN;
        $r->pendapatanKlinik = $r->penjualan - $r->penjProduk - $r->penjAlkes - $r->ppn;

        $r->cogs = $r->cogsProduk + $r->cogsAlkes;
        $r->labaKotor = $r->penjualan - $r->cogs;
        $r->marginPersen = $r->penjualan != 0.0 ? $r->labaKotor / $r->penjualan * 100 : 0.0;

        return $r;
    }

    /**
     * Baris untuk satu tingkat.
     *
     * @param array{dari:string,sampai:string,pt?:?int,cabang?:?int,coa?:?int,item?:?int} $saring
     */
    public function baris(string $tingkat, array $saring): Collection
    {
        $q = $this->dasar($saring);

        [$kunci, $nama, $urut] = match ($tingkat) {
            'pt'     => ['g.GPT', 'pt.NPNAMA', 'pt.NPNAMA'],
            'cabang' => ['u.SUCABANG', 'g.GNAMA', 'g.GNAMA'],
            // COA kosong digabung jadi satu grup "(Tanpa COA)" - kalau dibuang, jumlah
            // rinciannya tidak akan sama dgn angka cabang di atasnya.
            'coa'    => [DB::raw('COALESCE(ct.CTID, 0)'), DB::raw("COALESCE(ct.CTNAMA, '(Tanpa COA)')"), 'ct.CTNAMA'],
            'item'   => ['d.SDITEM', 'i.INAMA', 'i.INAMA'],
            default  => ['u.SUID', 'u.SUNOTRANSAKSI', 'u.SUNOTRANSAKSI'],
        };

        $pilih = array_merge([
            DB::raw(($kunci instanceof \Illuminate\Database\Query\Expression ? $kunci->getValue(DB::connection()->getQueryGrammar()) : $kunci) . ' as id'),
            DB::raw(($nama instanceof \Illuminate\Database\Query\Expression ? $nama->getValue(DB::connection()->getQueryGrammar()) : $nama) . ' as nama'),
        ], $this->ukuran());

        if ($tingkat === 'transaksi') {
            $q->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK');
            $pilih[] = DB::raw('MIN(u.SUTANGGAL) as tanggal');
            $pilih[] = DB::raw('MIN(k.KNAMA) as pasien');
            $q->groupBy('u.SUID', 'u.SUNOTRANSAKSI');
        } else {
            $q->groupBy($kunci, $nama);
        }

        return $q->orderByDesc(DB::raw('SUM(d.SDKELUAR * (d.SDHARGA - d.SDDISKON))'))
            ->get($pilih)
            ->map(fn ($r) => $this->lengkapi($r));
    }

    /** Satu baris ringkasan seluruh saringan - dipakai utk kartu total di kepala layar. */
    public function total(array $saring): object
    {
        $r = $this->dasar($saring)->get($this->ukuran())->first();
        $r->nama = 'TOTAL';
        $r->id = 0;

        return $this->lengkapi($r);
    }

    /**
     * Rincian alkes satu transaksi IP - tingkat paling dalam ("Tampilkan Detail alkes").
     * Lewat `SUNOREF`, jalur yg 100% nyambung.
     */
    public function alkesTransaksi(string $noIp): Collection
    {
        return DB::table('fstoku as al')
            ->join('fstokd as ad', 'ad.SDIDSU', '=', 'al.SUID')
            ->join('bitem as ai', 'ai.IID', '=', 'ad.SDITEM')
            ->where('al.SUSUMBER', 'AL')->where('al.SUSTATUS', '<>', 9)
            ->where('al.SUNOREF', $noIp)
            ->groupBy('ai.IID', 'ai.IKODE', 'ai.INAMA')
            ->orderBy('ai.INAMA')
            ->get([
                DB::raw('ai.IKODE as kode'),
                DB::raw('ai.INAMA as nama'),
                DB::raw('SUM(ad.SDKELUAR) as qty'),
                // `SDBELI` - sumber COGS alkes yg sama dgn CI3.
                DB::raw('SUM(COALESCE(ad.SDBELI,0)) as cogs'),
            ]);
    }

    /** PT yg boleh dipilih di saringan. */
    public function daftarPt(): Collection
    {
        return DB::table('bnamapt')->whereNotNull('NPNAMA')->where('NPNAMA', '<>', '-')
            ->orderBy('NPNAMA')->get(['NPID as id', 'NPNAMA as nama']);
    }

    /** PT milik user login - dari cabangnya. */
    public function ptUser(?int $gid): ?int
    {
        if (! $gid) {
            return null;
        }

        return (int) DB::table('bgudang')->where('GID', $gid)->value('GPT') ?: null;
    }
}
