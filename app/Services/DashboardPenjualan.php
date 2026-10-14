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
     * Batas baris yg dikirim ke layar per tingkat.
     *
     * BUKAN kosmetik: seluruh tabel ikut di payload Livewire tiap kali tombol ditekan.
     * Di proyek ini hal itu sudah pernah menjatuhkan layar - 342 baris pemilih PKB
     * menghasilkan respons 196 KB dan laporan `ERR_QUIC_PROTOCOL_ERROR` yg nyata.
     * Tingkat Item & Transaksi di cabang ramai bisa jauh melebihi itu.
     *
     * Baris diurutkan dari penjualan TERBESAR, jadi yg terpotong selalu ekor yg paling
     * kecil. Angka TOTAL di kartu atas tetap dihitung dari SELURUH data (lihat `total()`),
     * bukan dari baris yg tampil - jadi batas ini tidak pernah memalsukan ringkasan.
     */
    public const BATAS_BARIS = 200;

    /**
     * Tingkat TRANSAKSI pakai HALAMAN, bukan dipotong di 200 (permintaan user 2026-10-15).
     *
     * Bedanya mendasar: tingkat lain meringkas, jadi memotong ekor yg kecil tidak membuat
     * data hilang dari pandangan - sedangkan tingkat transaksi adalah DAFTAR, dan baris ke
     * 201 dst memang perlu bisa dilihat. Lagipula dgn `OFFSET/LIMIT` server hanya menyusun
     * 100 baris per permintaan, bukan menyapu lalu membuang - itu yg membuatnya terasa
     * lebih cepat.
     */
    public const PER_HALAMAN = 100;

    /** Ukuran halaman yg BERLAKU - `config/dashboard.php` kalau diisi. */
    public static function perHalaman(): int
    {
        return max(1, (int) config('dashboard.per_halaman', self::PER_HALAMAN));
    }

    /** Diisi `baris()`; dibaca `adaSisa()`. TIDAK dipakai di tingkat transaksi (berhalaman). */
    private bool $adaSisa = false;

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
    /**
     * **COA yg nilainya DITARIK** - CI3 `if ($row->CTTIDAKDITARIK==0) { ... }`.
     *
     * `bcoatipe_pendapatan.CTTIDAKDITARIK = 1` berarti COA itu bukan penjualan barang/jasa
     * klinik, jadi COGS Produk, COGS Alkes, Pendapatan Produk, Pendapatan Alkes dan PPN-nya
     * TIDAK diambil - hanya Penjualan & Pendapatan Klinik yg tetap jalan. Di DB ini ada 6:
     * Biaya Layanan/Diskon, DP, Lab Revenue, Membership, Service Fee Revenue, Shipping
     * Revenue.
     *
     * `COALESCE(...,0)` penting: `ct` di-LEFT JOIN, jadi baris tanpa COA bernilai NULL dan
     * harus dihitung spt 0 (ditarik) - sama spt perbandingan longgar `== 0` di CI3.
     */
    private function ditarik(): string
    {
        return 'COALESCE(ct.CTTIDAKDITARIK, 0) = 0';
    }

    private function ekspresiCogsProduk(): string
    {
        // TAMBAHAN di luar CI3 (permintaan user 2026-10-14): baris ber-QTY 0 juga bernilai
        // 0. Contohnya `PG-IP26091494` - qty 0 krn barisnya datang dari PAKET, tapi COGS
        // Produk-nya masih terhitung (295.313) padahal biaya itu sudah dihitung di baris
        // paket induknya. Tidak ada barang keluar = tidak ada harga pokok PENJUALAN.
        // COGS ALKES sengaja TIDAK ikut dinolkan - alkesnya memang benar-benar terpakai.
        return 'IF((COALESCE(d.SDDARIPAKET,0) <> 0 AND COALESCE(d.SDKEDATANGAN,0) > 0)
                   OR COALESCE(d.SDKELUAR,0) = 0
                   OR NOT (' . $this->ditarik() . '), 0, COALESCE(d.SDBELI,0))';
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
        return '(CASE WHEN ' . $this->ditarik() . '
                      THEN (SELECT SUM(COALESCE(aa.SDBELI,0)) FROM fstokd aa
                             WHERE aa.SDIDUALKES = d.SDID)
                 END)';
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
            //
            // GERBANG `penjualan <> 0` adalah TAMBAHAN di luar CI3 (permintaan user
            // 2026-10-14): "penjualan produk, penjualan alkes, ppn dan pendapatan klinik
            // dihitung jika ada nilai Penjualannya saja". Tanpa ini, baris tanpa penjualan
            // tetap memunculkan angka produk/alkes/PPN, dan Pendapatan Klinik jadi MINUS
            // (contoh `PG-IP26091494`: 0 - 310.078 - 1.208 - 34.241 = -345.527).
            //
            // Digerbang PER BARIS, bukan per grup: kalau per grup, jumlah tingkat anak
            // tidak akan sama lagi dgn angka induknya.
            // Cabang `ICOA2021 = 1` (jual / 1,11) perlu gerbang `ditarik()` TERSENDIRI -
            // cabang satunya sudah ikut ternol lewat `$cogs`, yg ini tidak.
            DB::raw('SUM(CASE WHEN (' . $jual . ') <> 0 AND ' . $this->ditarik() . ' THEN
                        CASE WHEN i.ICOA2021 = 1 THEN (' . $jual . ') / ' . (1 + self::PPN)
                    . ' ELSE (' . $cogs . ') * ' . self::MARKUP . ' END
                      ELSE 0 END) as penjProdukSql'),
            // COGS Alkes di bawah TIDAK digerbang - alkesnya memang terpakai; yg digerbang
            // hanya nilai JUALnya.
            DB::raw('SUM(CASE WHEN (' . $jual . ') <> 0 THEN COALESCE(' . $alkes . ', 0) ELSE 0 END) * '
                    . self::MARKUP . ' as penjAlkesSql'),
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
    public function baris(string $tingkat, array $saring, int $halaman = 1, ?int $perHalaman = null): Collection
    {
        $q = $this->dasar($saring);

        [$kunci, $nama, $urut] = match ($tingkat) {
            'pt'     => ['g.GPT', 'pt.NPNAMA', 'pt.NPNAMA'],
            'cabang' => ['u.SUCABANG', 'g.GNAMA', 'g.GNAMA'],
            // COA kosong digabung jadi satu grup "(Tanpa COA)" - kalau dibuang, jumlah
            // rinciannya tidak akan sama dgn angka cabang di atasnya.
            'coa'    => [DB::raw('COALESCE(ct.CTID, 0)'), DB::raw("COALESCE(ct.CTNAMA, '(Tanpa COA)')"), 'ct.CTNAMA'],
            'item'   => ['d.SDITEM', 'i.INAMA', 'i.INAMA'],
            // Tingkat terdalam = PER BARIS `fstokd.SDID`, BUKAN per nota (permintaan user
            // 2026-10-14). Satu No IP dgn 3 baris tindakan muncul 3 kali. Ini juga yg
            // dilakukan laporan CI3 acuan - `GROUP BY ... , rowsdid` (`a.sdid`).
            default  => ['d.SDID', 'u.SUNOTRANSAKSI', 'u.SUNOTRANSAKSI'],
        };

        $pilih = array_merge([
            DB::raw(($kunci instanceof \Illuminate\Database\Query\Expression ? $kunci->getValue(DB::connection()->getQueryGrammar()) : $kunci) . ' as id'),
            DB::raw(($nama instanceof \Illuminate\Database\Query\Expression ? $nama->getValue(DB::connection()->getQueryGrammar()) : $nama) . ' as nama'),
        ], $this->ukuran());

        if ($tingkat === 'transaksi') {
            $q->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK');
            $pilih[] = DB::raw('MIN(u.SUTANGGAL) as tanggal');
            $pilih[] = DB::raw('MIN(k.KNAMA) as pasien');
            // Nama ITEM ikut ditampilkan: begitu satu nota bisa muncul beberapa kali,
            // tanpa ini barisnya kembar di layar & user tidak tahu bedanya apa.
            $pilih[] = DB::raw('MIN(i.INAMA) as item');
            $q->groupBy('d.SDID', 'u.SUNOTRANSAKSI');
        } else {
            $q->groupBy($kunci, $nama);
        }

        if ($tingkat === 'transaksi') {
            /*
             * Urut NO TRANSAKSI saja (permintaan user 2026-10-15), BUKAN penjualan
             * terbesar. Dua alasannya:
             *   - ini daftar, dan orang mencari nota tertentu - urutan nomor yg dicari.
             *   - mengurutkan `SUM(...)` memaksa server menghitung seluruh grup dulu baru
             *     menyusunnya; mengurutkan kolom yg memang sudah jadi kunci grup jauh
             *     lebih murah.
             *
             * `d.SDID` jadi pengurut kedua supaya baris-baris DALAM satu nota tidak
             * bertukar tempat antar halaman - tanpa itu, urutan baris ber-nota sama tidak
             * dijamin dan satu baris bisa muncul dua kali di dua halaman berbeda.
             */
            $rows = $q->orderBy('u.SUNOTRANSAKSI')
                ->orderBy('d.SDID')
                ->forPage($halaman, $perHalaman ?: self::perHalaman())
                ->get($pilih);

            $this->adaSisa = false;   // tingkat ini berhalaman, tidak ada yg "terpotong"

            return $rows->map(fn ($r) => $this->lengkapi($r));
        }

        // Tingkat ringkasan: diurutkan dari penjualan TERBESAR, lalu dipotong
        // `BATAS_BARIS`. Diambil satu LEBIH dari batas supaya pemanggil bisa tahu "masih
        // ada sisanya" tanpa kueri COUNT terpisah di atas kueri yg sudah ber-GROUP BY.
        $rows = $q->orderByDesc(DB::raw('SUM(' . $this->ekspresiPenjualan() . ')'))
            ->limit(self::BATAS_BARIS + 1)
            ->get($pilih);

        $this->adaSisa = $rows->count() > self::BATAS_BARIS;

        return $rows->take(self::BATAS_BARIS)->map(fn ($r) => $this->lengkapi($r));
    }

    /**
     * SELURUH baris transaksi sesuai saringan - utk ekspor Excel, bukan utk layar.
     *
     * Ada batas keras `$batas`: ekspor sebulan penuh satu PT bisa puluhan ribu baris dan
     * semuanya ditahan di memori sekaligus. Dipotong diam-diam akan berbahaya (user mengira
     * berkasnya lengkap), jadi pemanggil WAJIB memberi tahu kalau kena batas - lihat
     * `ReportController`.
     */
    public function semuaBarisTransaksi(array $saring, int $batas = 20000): Collection
    {
        return $this->baris('transaksi', $saring, 1, $batas);
    }

    /**
     * Banyaknya BARIS di tingkat transaksi - utk penomoran halaman.
     *
     * `COUNT(DISTINCT d.SDID)`, bukan `COUNT(*)`: `bitem2` ternyata punya 4 item ber-baris
     * GANDA (terukur: 5.598 baris utk 5.594 item - semuanya baris yatim yg itemnya sudah
     * tidak ada di `bitem`, dan tak satu pun terpakai di transaksi IP). Hari ini `COUNT(*)`
     * memberi angka yg sama, tapi kalau suatu saat item ganda benar-benar dipakai, jumlah
     * halamannya akan melar sendiri tanpa ada yg sadar.
     */
    public function jumlahBaris(array $saring): int
    {
        return (int) $this->dasar($saring)->distinct()->count('d.SDID');
    }

    /** True kalau panggilan `baris()` terakhir masih menyisakan baris di luar batas. */
    public function adaSisa(): bool
    {
        return $this->adaSisa;
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
     * Rincian alkes satu BARIS IP - tingkat paling dalam ("Tampilkan Detail alkes").
     *
     * TIDAK digerbang `ditarik()`, persis spt `queryAlkes` CI3 yg juga tanpa saringan
     * `CTTIDAKDITARIK`: yg ditampilkan di sini alkes yg memang FISIK terpakai. Akibatnya,
     * utk COA yg tidak ditarik, rincian bisa berisi sementara kolom COGS Alkes-nya 0 - itu
     * sengaja, bukan selisih.
     */
    public function alkesTransaksi(int $sdid): Collection
    {
        return DB::table('fstokd as ad')
            ->join('bitem as ai', 'ai.IID', '=', 'ad.SDITEM')
            // Lewat `SDIDUALKES` - alkes milik BARIS itu, bukan seluruh nota. Jalur yg
            // sama dgn `queryAlkes` CI3 (`aa.SDIDUALKES = a2.sdid`) dan dgn
            // `ekspresiCogsAlkes()`, jadi rincian ini pasti menjumlah ke kolom COGS
            // Alkes di barisnya.
            ->where('ad.SDIDUALKES', $sdid)
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

    /**
     * **Paket / Promo / Dokter satu baris IP** - isi tombol "Detail Paket" di tingkat
     * transaksi (permintaan user 2026-10-14: ketiganya jadi TOMBOL, bukan tiga kolom -
     * tabelnya sudah 14 kolom).
     *
     * Jalurnya disalin dari laporan acuan CI3
     * (`laporan-ip-per-coa-2026-detail-v-agt-no-ip`):
     *   paket  -> `epaketu.PUID = fstokd.SDIDPOTONGSTOK`
     *   promo  -> `emasterpromod.MPDID = fstokd.SDIDPROMO`, lalu naik ke
     *             `emasterpromou.MPUID = MPDIDU` (nama promo ada di INDUKnya)
     *   dokter -> `bkontak.KID = fstokd.SDDOKTER`
     *
     * Semua LEFT JOIN: satu baris bisa punya dokter tanpa paket, paket tanpa promo, dst.
     * Nomor koli & kedatangan ikut diambil - di daftar POS keduanya ditampilkan bersama
     * paket, dan tanpa itu baris paket yg berulang terlihat kembar.
     */
    public function infoTransaksi(int $sdid): ?object
    {
        return DB::table('fstokd as d')
            ->leftJoin('epaketu as pkt', 'pkt.PUID', '=', 'd.SDIDPOTONGSTOK')
            ->leftJoin('emasterpromod as pmd', 'pmd.MPDID', '=', 'd.SDIDPROMO')
            ->leftJoin('emasterpromou as pmu', 'pmu.MPUID', '=', 'pmd.MPDIDU')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'd.SDDOKTER')
            ->where('d.SDID', $sdid)
            ->first([
                DB::raw('pkt.PUKODE as paketKode'),
                DB::raw('pkt.PUNAMA as paket'),
                DB::raw('pmu.MPUNAMA as promo'),
                DB::raw('dr.KNAMA as dokter'),
                DB::raw('d.SDCATATANKOLI as koli'),
                DB::raw('d.SDKEDATANGAN as kedatangan'),
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
