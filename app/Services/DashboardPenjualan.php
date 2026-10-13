<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka untuk Dashboard Penjualan - lima tingkat rincian: PT -> Cabang -> COA -> Item ->
 * Transaksi. SATU kelas supaya rumusnya cuma ada di satu tempat; kalau tiap tingkat
 * menghitung sendiri, angka induk tidak akan sama dgn jumlah anaknya.
 *
 * ## Sumber data
 * Penjualan dari `fstoku`/`fstokd` **`SUSUMBER='IP'`** (invoice pasien), `SUSTATUS <> 9`.
 * Alkes yg terpakai ada di DOKUMEN TERPISAH `SUSUMBER='AL'`, terhubung ke IP lewat
 * **`SUNOREF` = nomor IP**.
 *
 * ## Rumus (ditetapkan user 2026-10-13, diturunkan dari RANCANGAN DASHBOARD PENJUALAN.xlsx)
 *   COGS Produk     = SUM(qty x `bitem.ICOGS`) baris IP
 *   COGS Alkes      = SUM(qty x `bitem.ICOGS`) baris dokumen AL terkait
 *   Penjualan Produk = COGS Produk x MARKUP      (1,05)
 *   Penjualan Alkes  = COGS Alkes  x MARKUP
 *   PPN              = (Penj. Produk + Penj. Alkes) x PPN   (11%)
 *   Pendapatan Klinik = Penjualan - Penj.Produk - Penj.Alkes - PPN
 *   Laba Kotor       = Penjualan - (COGS Produk + COGS Alkes)
 * Dicocokkan ke dua baris contoh di rancangan dan ketiganya pas sampai 2 desimal -
 * lihat `tests`/catatan CLAUDE.md.
 *
 * Angka "NILAI COGS (50% of Sales)" di sheet PER PT rancangan itu CUMA CONTOH; COGS tiap
 * tingkat dijumlah dari transaksi, supaya rincian selalu menjumlah ke induknya.
 *
 * ## Kenapa alkes dibagi PROPORSIONAL
 * Kaitan alkes **per baris** (`fstokd.SDIDALKESNYA`) TIDAK BISA DIPAKAI: cuma 396 dari 769
 * baris yg menunjuk dokumen AL yg benar-benar ada (49% yatim). Yg 100% andal adalah
 * `SUNOREF` - 7.409 dari 7.409 dokumen AL ketemu IP-nya. Jadi alkes diambil per DOKUMEN,
 * lalu dibagi ke baris-barisnya SEBANDING NILAI PENJUALAN. Pembagian itu dipilih bukan
 * karena paling tepat per item, tapi karena menjamin **jumlah rincian = angka induk** di
 * setiap tingkat - sifat yg wajib ada di layar yg bisa di-"detailkan".
 * Dokumen ber-nilai 0 (tindakan gratis) dibagi RATA per baris, kalau tidak alkes-nya hilang.
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
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->leftJoin('bnamapt as pt', 'pt.NPID', '=', 'g.GPT')
            // COA pendapatan: lewat `bitem2`, BUKAN kolom `ICOA*` di bitem - sudah diuji
            // dan 0 dari 22 baris menunjuk akun yg benar (lihat CLAUDE.md).
            ->leftJoin('bitem2 as i2', 'i2.I2IDITEM', '=', 'i.IID')
            ->leftJoin('bcoatipe_pendapatan as ct', 'ct.CTID', '=', 'i2.I2COAPENDAPATAN')
            // alkes per DOKUMEN IP (lihat docblock kelas)
            ->leftJoin(DB::raw('(' . $this->sqlAlkesPerNota() . ') as alk'), 'alk.nota', '=', 'u.SUNOTRANSAKSI')
            // total & jumlah baris per dokumen - pembagi alokasi alkes
            ->leftJoin(DB::raw('(' . $this->sqlTotalPerDokumen() . ') as tot'), 'tot.suid', '=', 'u.SUID')
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

    private function sqlAlkesPerNota(): string
    {
        return 'SELECT al.SUNOREF AS nota, SUM(ad.SDKELUAR * COALESCE(ai.ICOGS, 0)) AS cogsAlkes
                FROM fstoku al
                JOIN fstokd ad ON ad.SDIDSU = al.SUID
                JOIN bitem ai ON ai.IID = ad.SDITEM
                WHERE al.SUSUMBER = \'AL\' AND al.SUSTATUS <> 9
                GROUP BY al.SUNOREF';
    }

    private function sqlTotalPerDokumen(): string
    {
        return 'SELECT d2.SDIDSU AS suid,
                       SUM(d2.SDKELUAR * (d2.SDHARGA - d2.SDDISKON)) AS nilai,
                       COUNT(*) AS baris
                FROM fstokd d2 GROUP BY d2.SDIDSU';
    }

    /**
     * Bagian alkes utk SATU baris IP. Sebanding nilai; kalau dokumennya bernilai 0
     * (tindakan gratis) dibagi rata per baris supaya alkesnya tidak hilang.
     */
    private function ekspresiCogsAlkes(): string
    {
        return 'COALESCE(alk.cogsAlkes, 0) * CASE
                    WHEN COALESCE(tot.nilai, 0) <> 0
                        THEN (d.SDKELUAR * (d.SDHARGA - d.SDDISKON)) / tot.nilai
                    ELSE 1 / GREATEST(COALESCE(tot.baris, 1), 1)
                END';
    }

    /** Kolom ukuran yg sama di SEMUA tingkat - inilah yg menjamin angkanya konsisten. */
    private function ukuran(): array
    {
        $alkes = $this->ekspresiCogsAlkes();

        return [
            DB::raw('SUM(d.SDKELUAR * (d.SDHARGA - d.SDDISKON)) as penjualan'),
            DB::raw('SUM(d.SDKELUAR * COALESCE(i.ICOGS, 0)) as cogsProduk'),
            DB::raw("SUM({$alkes}) as cogsAlkes"),
            // Qty mengecualikan baris kedatangan - aturan yg sama dgn laporan IP Tindakan
            // & Produk (disalin dari CI3); baris itu tetap ikut nilai, tapi bukan qty.
            DB::raw('SUM(CASE WHEN COALESCE(d.SDKEDATANGAN,0)=0 THEN d.SDKELUAR ELSE 0 END) as qty'),
            DB::raw('COUNT(DISTINCT u.SUID) as jmlTransaksi'),
        ];
    }

    /** Lengkapi baris hasil dgn turunannya (markup/PPN/laba). */
    private function lengkapi(object $r): object
    {
        $r->penjualan = (float) $r->penjualan;
        $r->cogsProduk = (float) $r->cogsProduk;
        $r->cogsAlkes = (float) $r->cogsAlkes;

        $r->penjProduk = $r->cogsProduk * self::MARKUP;
        $r->penjAlkes = $r->cogsAlkes * self::MARKUP;
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
                DB::raw('SUM(ad.SDKELUAR * COALESCE(ai.ICOGS,0)) as cogs'),
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
