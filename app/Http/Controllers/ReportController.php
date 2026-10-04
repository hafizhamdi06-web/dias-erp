<?php

namespace App\Http\Controllers;

use App\Services\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Route/controller BIASA (BUKAN Livewire) yg generate PDF laporan via `PdfReport` - pola
 * wajib per `CLAUDE.md` (siklus hidup Livewire tidak cocok render dokumen besar).
 */
class ReportController extends Controller
{
    /**
     * Cabang yang boleh masuk hasil laporan - **penegak hak akses cabang** (aturan user
     * 2026-09-28: "jika user hanya akses 3 cabang, maka jika dia pilih semua, yang tampil
     * 3 cabang itu").
     *
     * @return list<int> `[]` = tanpa batas (super user). Pemanggil WAJIB memakai
     *                   `whereIn` HANYA kalau hasilnya tidak kosong.
     *
     * Aturannya:
     * - "Semua cabang" (tanpa parameter `cabang`) -> seluruh cabang yg BOLEH DILIHAT user,
     *   bukan seluruh cabang perusahaan.
     * - Pilih satu cabang -> **DIIRISKAN** dgn daftar yg boleh. Irisan kosong -> `[0]`
     *   (GID 0 tidak ada) sehingga hasilnya nihil. Mengembalikan `[]` di situ justru akan
     *   MEMBUKA semua cabang - jebakan yg sama pernah kena di `KartuStok::gudangIds()`.
     *   Penyaringan di sini WAJIB ada krn dropdown cuma UI: parameter `cabang` datang dari
     *   URL dan bisa diketik manual.
     * - Super user tidak dibatasi (lihat `User::visibleBranchIds()`).
     */
    private function cabangLaporan(Request $r): array
    {
        $boleh = auth()->user()->visibleBranchIds();

        if (! $r->filled('cabang')) {
            return $boleh;
        }

        $pilih = $r->integer('cabang');

        if ($boleh !== [] && ! in_array($pilih, $boleh, true)) {
            return [0];
        }

        return [$pilih];
    }

    /**
     * Laporan IP Per Barang (nama tampilan direvisi 2026-09-23 dari "Penjualan Per Barang" -
     * "IP" = kode sumber transaksi `SUSUMBER`, method/route/nama file SENGAJA TETAP
     * `penjualanPerBarang` - cuma label yg berubah, bukan struktur kode) - detail baris
     * penjualan POS (`fstoku`/`fstokd`,
     * `SUSUMBER='IP'`, `SUSTATUS<>9` exclude batal). Item OPSIONAL (2026-09-23, per
     * permintaan user) - kosong = SEMUA item (kolom "Item" ditampilkan per-baris supaya
     * tetap jelas item mana; kalau item DIPILIH, nama/kode item pindah ke subtitle & kolom
     * "Item" disembunyikan spt versi awal - hemat lebar kolom saat sudah discope 1 item).
     *
     * Kolom: [Item - kondisional], No Transaksi, Tanggal, Nama Pasien, No.Hp Pasien, Qty,
     * Harga, Disc 1 (%), Disc 2 (%), Jumlah (= (SDHARGA - SDDISKON) x SDKELUAR - `SDDISKON`
     * = Rp diskon per unit hasil kaskade disc1xdisc2, TIDAK ADA kolom subtotal tersimpan
     * di `fstokd`). Tanggal TETAP wajib (batasi lebar tabel `fstokd`, ~25rb baris IP aktif).
     */
    public function penjualanPerBarang(Request $r)
    {
        return app(PdfReport::class)->preview(
            'reports.penjualan-per-barang',
            $this->dataPenjualanPerBarang($r),
            ['orientasi' => 'L']
        );
    }

    /**
     * Export Excel - pola SAMA PERSIS CI3 (`Laporan.php` mode=3): HTML table dgn
     * `Content-Type: application/vnd.ms-excel` + ekstensi `.xls`, Excel buka itu apa
     * adanya sbg spreadsheet - TIDAK BUTUH library (PhpSpreadsheet TIDAK ADA di
     * environment ini, dicek sblmnya saat baca .xlsx). Data & filter SAMA PERSIS versi
     * PDF (`dataPenjualanPerBarang()` dipakai bersama), cuma view & header response beda.
     */
    public function penjualanPerBarangExcel(Request $r)
    {
        $data = $this->dataPenjualanPerBarang($r);
        $filename = $data['title'] . '.xls';

        return response()
            ->view('reports.penjualan-per-barang-xls', $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @return array{title:string,subtitle:string,showItemColumn:bool,company:array,rows:\Illuminate\Support\Collection,totalQty:float,totalJumlah:float}
     */
    private function dataPenjualanPerBarang(Request $r): array
    {
        $r->validate([
            'item' => ['nullable', 'integer'],
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
            'cabang' => ['nullable', 'integer'],
        ]);

        $item = null;
        if ($r->filled('item')) {
            $item = DB::table('bitem')->where('IID', $r->integer('item'))->first(['IID', 'IKODE', 'INAMA']);
            abort_if(! $item, 404, 'Item tidak ditemukan.');
        }

        // Dipindah ke `cabangLaporan()` (2026-09-28) supaya SATU aturan dipakai semua
        // laporan. Sebelumnya di sini memakai `branchIds()` langsung TANPA pengecualian
        // super user - akibatnya UID 1 (UCABANGPILIH cuma "1") terkunci ke Petogogan.
        $gudang = $this->cabangLaporan($r);

        $rows = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->when($item, fn ($b) => $b->where('d.SDITEM', $item->IID))
            ->whereDate('u.SUTANGGAL', '>=', $r->query('from'))
            ->whereDate('u.SUTANGGAL', '<=', $r->query('to'))
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang))
            ->orderBy('u.SUTANGGAL')->orderBy('u.SUNOTRANSAKSI')
            ->get([
                'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'i.IKODE as itemKode', 'i.INAMA as itemNama',
                'k.KNAMA as pasien', 'k.K1TELP1 as hp',
                'd.SDKELUAR as qty', 'd.SDHARGA as harga',
                'd.SDDISKONPERSEN as disc1', 'd.SDDISKONPERSEN2 as disc2', 'd.SDDISKON as diskonRp',
            ])
            ->map(function ($row) {
                $row->jumlah = ((float) $row->harga - (float) $row->diskonRp) * (float) $row->qty;

                return $row;
            });

        $branch = count($gudang) === 1
            ? DB::table('bgudang')->where('GID', $gudang[0])->value('GNAMA') : null;
        $periode = \Carbon\Carbon::parse($r->query('from'))->format('d/m/Y')
            . ' s/d ' . \Carbon\Carbon::parse($r->query('to'))->format('d/m/Y')
            . ($branch ? ', ' . $branch : '');

        return [
            'title'    => 'Laporan IP Per Barang',
            'subtitle' => $item ? ($item->IKODE . ' — ' . $item->INAMA . ' (' . $periode . ')') : ('Semua Item (' . $periode . ')'),
            'showItemColumn' => $item === null,
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'totalQty' => $rows->sum('qty'),
            'totalJumlah' => $rows->sum('jumlah'),
        ];
    }

    /**
     * Laporan IP Tindakan/Produk Per Bulan - rekap qty, nilai & pasien, dikelompokkan
     * **Cabang > Bulan > Tindakan/Produk**. Port dari CI3
     * (`views/modul/laporan/laporan-ip-tindakan-produk-perbulan.php`, dipasang lewat
     * `application/sql/2026-09-02_laporan-ip-tindakan-produk-perbulan.sql`).
     *
     * **Sumber `SUSUMBER IN ('IP','AL')`** - POS ditambah Alkes Depo, `SUSTATUS <> 9`.
     *
     * ## Tiga aturan hitung yg TIDAK boleh disederhanakan (disalin persis dari CI3)
     * 1. **Qty mengecualikan baris kedatangan**:
     *    `SUM(CASE WHEN IFNULL(SDKEDATANGAN,0)=0 THEN SDKELUAR ELSE 0 END)`. Baris
     *    ber-`SDKEDATANGAN` tetap ikut di kolom Nilai, tapi TIDAK dihitung qty-nya.
     * 2. **Kolom Pasien per baris** = `COUNT(DISTINCT kontak)` **hanya pada baris yg ADA
     *    HARGANYA** (`SDKELUAR*(SDHARGA-SDDISKON) > 0`) - tindakan gratis/bonus tidak
     *    menghitung pasien.
     * 3. **Total pasien per bulan dihitung TERPISAH**, bukan menjumlahkan kolom Pasien:
     *    `COUNT(DISTINCT CONCAT(kontak,'#',tanggal))` - **satu pasien per hari dihitung 1x**
     *    walau bertransaksi berkali-kali. Karena itu total bulan hampir selalu LEBIH KECIL
     *    dari jumlah kolom Pasien di atasnya, dan itu MEMANG BENAR. Baris "Total Cabang" &
     *    "Grand Total" SENGAJA mengosongkan kolom Pasien (CI3 juga) - menjumlahkan angka
     *    per-bulan lintas bulan/cabang akan menghitung pasien yg sama berulang.
     *
     * Nilai = `SUM(SDKELUAR * (SDHARGA - SDDISKON))`; `SDDISKON` = rupiah diskon per unit
     * (hasil kaskade disc1 x disc2), pola sama laporan IP Per Barang.
     */
    public function ipTindakanProduk(Request $r)
    {
        return app(PdfReport::class)->preview(
            'reports.ip-tindakan-produk',
            $this->dataIpTindakanProduk($r),
            ['orientasi' => 'P']
        );
    }

    /** Export Excel - pola sama `penjualanPerBarangExcel()` (HTML table ber-header .xls). */
    public function ipTindakanProdukExcel(Request $r)
    {
        $data = $this->dataIpTindakanProduk($r);

        return response()
            ->view('reports.ip-tindakan-produk-xls', $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $data['title'] . '.xls"');
    }

    /**
     * @return array{title:string,subtitle:string,company:array,grup:array,totalQty:float,totalNilai:float}
     */
    private function dataIpTindakanProduk(Request $r): array
    {
        $r->validate([
            'from'   => ['required', 'date'],
            'to'     => ['required', 'date', 'after_or_equal:from'],
            'cabang' => ['nullable', 'integer'],
        ]);

        $from = $r->query('from');
        $to = $r->query('to');
        $gudang = $this->cabangLaporan($r);

        $dasar = fn () => DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->where('u.SUSTATUS', '<>', 9)
            ->whereIn('u.SUSUMBER', ['IP', 'AL'])
            ->whereBetween('u.SUTANGGAL', [$from, $to])
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang));

        // Detail per cabang > bulan > item.
        $rows = $dasar()
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->groupBy('g.GID', DB::raw("DATE_FORMAT(u.SUTANGGAL,'%Y%m')"), 'd.SDITEM')
            ->orderBy('g.GKODE')->orderByRaw("DATE_FORMAT(u.SUTANGGAL,'%Y%m')")->orderBy('i.INAMA')
            ->get([
                DB::raw('g.GKODE as cabangKode'),
                DB::raw('g.GNAMA as cabangNama'),
                DB::raw("DATE_FORMAT(u.SUTANGGAL,'%Y%m') as ym"),
                DB::raw('MIN(u.SUTANGGAL) as tglPertama'),
                DB::raw('i.INAMA as barang'),
                DB::raw('SUM(CASE WHEN IFNULL(d.SDKEDATANGAN,0)=0 THEN d.SDKELUAR ELSE 0 END) as qty'),
                DB::raw('SUM(d.SDKELUAR*(d.SDHARGA - d.SDDISKON)) as nilai'),
                DB::raw("COUNT(DISTINCT CASE WHEN (d.SDKELUAR*(d.SDHARGA - d.SDDISKON)) > 0 THEN u.SUKONTAK END) as pasien"),
            ]);

        // Pasien per cabang-bulan - query TERPISAH, 1 pasien per hari = 1 (lihat docblock).
        $pasienPeriode = [];
        foreach (
            $dasar()
                ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
                ->whereRaw('(d.SDKELUAR*(d.SDHARGA - d.SDDISKON)) > 0')
                ->groupBy('g.GKODE', DB::raw("DATE_FORMAT(u.SUTANGGAL,'%Y%m')"))
                ->get([
                    DB::raw('g.GKODE as cabangKode'),
                    DB::raw("DATE_FORMAT(u.SUTANGGAL,'%Y%m') as ym"),
                    DB::raw("COUNT(DISTINCT CONCAT(u.SUKONTAK,'#',u.SUTANGGAL)) as pasien"),
                ]) as $p
        ) {
            $pasienPeriode[$p->cabangKode . '|' . $p->ym] = (int) $p->pasien;
        }

        // Susun jadi struktur bersarang supaya blade-nya cuma me-render, tanpa logika grup.
        $grup = [];
        foreach ($rows as $row) {
            $ck = (string) $row->cabangKode;
            $grup[$ck] ??= ['nama' => $row->cabangNama, 'bulan' => [], 'qty' => 0.0, 'nilai' => 0.0];
            $grup[$ck]['bulan'][$row->ym] ??= [
                // BEDA SENGAJA dari CI3: label bulan di-Indonesia-kan ("Juni 2026").
                // CI3 pakai `DATE_FORMAT(...,'%M %Y')` yg selalu Inggris ("June 2026") -
                // laporan berbahasa Indonesia, jadi bulannya ikut diterjemahkan.
                'label'  => \Carbon\Carbon::parse($row->tglPertama)->locale('id')->translatedFormat('F Y'),
                'baris'  => [],
                'qty'    => 0.0,
                'nilai'  => 0.0,
                'pasien' => $pasienPeriode[$ck . '|' . $row->ym] ?? 0,
            ];

            $grup[$ck]['bulan'][$row->ym]['baris'][] = $row;
            $grup[$ck]['bulan'][$row->ym]['qty'] += (float) $row->qty;
            $grup[$ck]['bulan'][$row->ym]['nilai'] += (float) $row->nilai;
            $grup[$ck]['qty'] += (float) $row->qty;
            $grup[$ck]['nilai'] += (float) $row->nilai;
        }

        $branch = count($gudang) === 1
            ? DB::table('bgudang')->where('GID', $gudang[0])->value('GNAMA') : null;

        return [
            'title'      => 'Laporan IP Tindakan-Produk Per Bulan',
            'subtitle'   => \Carbon\Carbon::parse($from)->format('d/m/Y') . ' s/d '
                            . \Carbon\Carbon::parse($to)->format('d/m/Y')
                            . ($branch ? ', ' . $branch : '') . ' | Sumber : IP & AL',
            'company'    => app(PdfReport::class)->companyInfo(),
            'grup'       => $grup,
            'totalQty'   => (float) $rows->sum('qty'),
            'totalNilai' => (float) $rows->sum('nilai'),
        ];
    }

    /**
     * Laporan POS-IP "Daftar Penjualan Tunai" - port dari CI3
     * (`views/modul/laporan/xlap-daftar-penjualan-tunai.php`). Satu baris per transaksi POS
     * (`fstoku` `SUSUMBER='IP'`, `SUSTATUS<>9`), dgn rincian tiap cara bayar.
     */
    public function daftarPenjualanTunai(Request $r)
    {
        return app(PdfReport::class)->preview(
            'reports.daftar-penjualan-tunai',
            $this->dataDaftarPenjualanTunai($r),
            ['orientasi' => 'L', 'size' => 'A4']
        );
    }

    /** Export Excel - pola sama laporan lain (HTML table ber-header .xls). */
    public function daftarPenjualanTunaiExcel(Request $r)
    {
        $data = $this->dataDaftarPenjualanTunai($r);

        return response()
            ->view('reports.daftar-penjualan-tunai-xls', $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $data['title'] . '.xls"');
    }

    /**
     * RUMUS KOLOM - disalin dari CI3 & dicocokkan ke PDF contoh user (01-06-2026, cabang PG):
     *
     *   Kas Nett   = SUTOTALKAS - SUTOTALSISA        Cash Back = SUTOTALSISA
     *   Total Real = KasNett + Debit + Kredit + Transfer + Merchant
     *   Total Semua= TotalReal + DP + Voucher + Piutang + DPSurgery + Surgery - TarikDP
     *   - Tarik DP = F_DP_PERHARI(SUID), DITAMPILKAN negatif
     *
     * `SUTOTALDP` dipakai untuk kolom DP. Di CI3 alias `dpjumlah` ditulis DUA KALI
     * (`sutotaldp` lalu `sudp1`) sehingga yg menang `sudp1` - **tidak berdampak**: kedua
     * kolom itu identik di SELURUH 25.220 baris IP (diperiksa langsung ke DB).
     *
     * BARIS TOTAL KEDUA ("Total TANPA Piutang Surgery") hanya menjumlah baris dgn
     * `SUNILAIPIUTANGBAYAR = 0`.
     *
     * **BEDA DISENGAJA DARI CI3**: di CI3 `Total Semua` baris kedua TIDAK ikut menambahkan
     * Piutang (`$webpiutang` lupa dimasukkan ke `$totalweb`), padahal baris per-transaksi dan
     * baris total pertama menambahkannya. Di sini Piutang IKUT dijumlah supaya konsisten.
     * Terdampak 9 transaksi di data produksi (semua baris ber-Piutang kebetulan
     * `SUNILAIPIUTANGBAYAR=0`), jadi angka baris kedua bisa BEDA dari CI3 pada rentang yg
     * memuat transaksi itu - selisihnya persis sebesar Piutang-nya.
     *
     * @return array{title:string,subtitle:string,company:array,rows:\Illuminate\Support\Collection,total:array,totalTanpa:array}
     */
    private function dataDaftarPenjualanTunai(Request $r): array
    {
        $r->validate([
            'from'     => ['required', 'date'],
            'to'       => ['required', 'date', 'after_or_equal:from'],
            'cabang'   => ['nullable', 'integer'],
            'merchant' => ['nullable', 'string', 'max:50'],
        ]);

        $from = $r->query('from');
        $to = $r->query('to');
        $gudang = $this->cabangLaporan($r);
        $merchant = trim((string) $r->query('merchant'));

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->whereBetween('u.SUTANGGAL', [$from, $to])
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang))
            ->when($merchant !== '', fn ($b) => $b->where('u.SUMERCHANTJENIS', $merchant))
            ->orderBy('u.SUTANGGAL')->orderBy('u.SUNOTRANSAKSI')
            ->get([
                DB::raw('u.SUTANGGAL as tanggal'),
                DB::raw('u.SUNOTRANSAKSI as nomor'),
                DB::raw('k.KNAMA as kontak'),
                DB::raw('IFNULL(u.SUTOTALKAS,0) - IFNULL(u.SUTOTALSISA,0) as kas'),
                DB::raw('IFNULL(u.SUTOTALKARTUDEBIT,0) as debit'),
                DB::raw('IFNULL(u.SUTOTALKARTUKREDIT,0) as kredit'),
                DB::raw('IFNULL(u.SUTOTALTRANSFER,0) as transfer'),
                DB::raw('IFNULL(u.SUMERCHANTJUMLAH,0) as merchant'),
                DB::raw('IFNULL(u.SUTOTALVOUCHER,0) as voucher'),
                DB::raw('IFNULL(u.SUNILAIPIUTANG,0) as piutang'),
                DB::raw('IFNULL(u.SUPENDAPATANDP,0) as dpSurgery'),
                DB::raw('IFNULL(u.SUSURGERYDPPEMBAYARAN,0) as surgery'),
                DB::raw('IFNULL(u.SUTOTALDP,0) as dp'),
                DB::raw('IFNULL(F_DP_PERHARI(u.SUID),0) as tarikDp'),
                DB::raw('IFNULL(u.SUTOTALSISA,0) as cashback'),
                DB::raw('IFNULL(u.SUNILAIPIUTANGBAYAR,0) as piutangBayar'),
            ])
            ->map(function ($x) {
                foreach (['kas', 'debit', 'kredit', 'transfer', 'merchant', 'voucher', 'piutang',
                    'dpSurgery', 'surgery', 'dp', 'tarikDp', 'cashback', 'piutangBayar'] as $f) {
                    $x->$f = (float) $x->$f;
                }
                $x->totalReal = $x->kas + $x->debit + $x->kredit + $x->transfer + $x->merchant;
                $x->totalSemua = $x->totalReal + $x->dp + $x->voucher + $x->piutang
                    + $x->dpSurgery + $x->surgery - $x->tarikDp;

                return $x;
            });

        $jumlahkan = fn ($kumpulan) => [
            'kas'          => (float) $kumpulan->sum('kas'),
            'debit'        => (float) $kumpulan->sum('debit'),
            'kredit'       => (float) $kumpulan->sum('kredit'),
            'transfer'     => (float) $kumpulan->sum('transfer'),
            'merchant'     => (float) $kumpulan->sum('merchant'),
            'totalReal'    => (float) $kumpulan->sum('totalReal'),
            'voucher'      => (float) $kumpulan->sum('voucher'),
            'piutang'      => (float) $kumpulan->sum('piutang'),
            'dpSurgery'    => (float) $kumpulan->sum('dpSurgery'),
            'surgery'      => (float) $kumpulan->sum('surgery'),
            'dp'           => (float) $kumpulan->sum('dp'),
            'tarikDp'      => (float) $kumpulan->sum('tarikDp'),
            'totalSemua'   => (float) $kumpulan->sum('totalSemua'),
            'cashback'     => (float) $kumpulan->sum('cashback'),
            'piutangBayar' => (float) $kumpulan->sum('piutangBayar'),
        ];

        $namaCabang = count($gudang) === 1 && $gudang !== [0]
            ? (string) DB::table('bgudang')->where('GID', $gudang[0])->value('GNAMA')
            : null;

        return [
            'title'    => 'Daftar Penjualan Tunai',
            'subtitle' => \Carbon\Carbon::parse($from)->format('d-m-Y') . ' s/d '
                . \Carbon\Carbon::parse($to)->format('d-m-Y')
                . ($namaCabang ? ' — ' . $namaCabang : '')
                . ($merchant !== '' ? ' — Merchant: ' . $merchant : ''),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'total'    => $jumlahkan($rows),
            // Baris kedua: hanya transaksi TANPA pembayaran piutang.
            'totalTanpa' => $jumlahkan($rows->filter(fn ($x) => $x->piutangBayar == 0.0)),
        ];
    }

    /* =====================================================================================
     | TIGA laporan Persediaan port VB6 (2026-10-03): menu 417, 451, 499.
     | Nomor menunya dicek langsung ke tabel `amenu`, bukan ditebak - user menyebut "459" utk
     | laporan serial, padahal 459 = "Apotik"; yg benar **499**.
     ===================================================================================== */

    /**
     * **Stok Per Hari** (VB6 menu 417) - `zfFrmFilterLaporanStok.frm` query baris 1388,
     * filter baris 1193.
     */
    public function stokPerHari(Request $r)
    {
        return app(PdfReport::class)->preview('reports.stok-per-hari', $this->dataStokPerHari($r),
            ['size' => 'A4', 'orientasi' => 'L', 'marginTop' => 14, 'marginBottom' => 14]);
    }

    public function stokPerHariExcel(Request $r)
    {
        return $this->kirimExcel('reports.stok-per-hari-xls', $this->dataStokPerHari($r));
    }

    /**
     * **DUA LAPIS FILTER** - inilah bagian yg paling mudah salah di laporan ini:
     * - `pFlt`  -> SUBQUERY mutasi atas `fstokd` (cabang = `SDGUDANG = n`);
     * - `pFlt2` -> query LUAR atas `bitem` (cabang = **`ICABANG LIKE '%|n|%'`**, kolom
     *   pipa-delimit berisi daftar cabang tempat item itu berlaku - BUKAN `SDGUDANG`).
     *
     * Jadi item tetap muncul walau TIDAK ada mutasi di periode itu (LEFT JOIN + `IFNULL 0`) -
     * memang disengaja, laporannya menampilkan seluruh item cabang tsb beserta saldo awalnya.
     *
     * Aturan gudang **1 & 6 digabung** sama spt menu 318. Rumus keluar memakai `IF(SDDARIPAKET
     * <> 0 AND SDKEDATANGAN = 0, 0, sdkeluar)` - baris dari paket diabaikan, lihat
     * `dataDaftarStokBarang()`.
     *
     * `ISALDOX` = saldo awal dari master item. **Mayoritas 0** (hanya 105 dari 5.580 item
     * terisi di data nyata) - itu isi masternya, bukan bug; Stok Akhir = Saldo Awal + Masuk
     * - Keluar jadi sama dengan mutasi bersih untuk kebanyakan item.
     */
    private function dataStokPerHari(Request $r): array
    {
        $r->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'cabang' => ['nullable', 'integer'], 'jenisItem' => ['nullable', 'integer'],
            'item' => ['nullable', 'integer'], 'jenisProduk' => ['nullable', 'integer'],
        ]);

        $gudang = $this->cabangLaporan($r);
        if ($gudang === [1] || $gudang === [6]) {
            $gudang = [1, 6];
        }

        $keluar = 'IF(d.SDDARIPAKET <> 0 AND d.SDKEDATANGAN = 0, 0, d.SDKELUAR)';

        // --- lapis dalam: mutasi per item di periode & gudang terpilih ---
        $mutasi = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('d.SDCANCEL', 0)->where('d.SDID', '<>', 0)
            ->whereBetween('u.SUTANGGAL', [$r->query('from'), $r->query('to')])
            ->when($gudang !== [], fn ($b) => $b->whereIn('d.SDGUDANG', $gudang))
            ->when($r->filled('jenisItem'), fn ($b) => $b->where('i.IJENISITEM', $r->integer('jenisItem')))
            ->when($r->filled('item'), fn ($b) => $b->where('i.IID', $r->integer('item')))
            ->when($r->filled('jenisProduk'), fn ($b) => $b->where('i.IJENISPRODUK', $r->integer('jenisProduk')))
            ->when($r->boolean('stokSaja'), fn ($b) => $b->where('i.ITIPEITEM', 0))
            ->when($r->boolean('aktifSaja'), fn ($b) => $b->where('i.ISTATUS', 0))
            ->groupBy('d.SDITEM')
            ->select('d.SDITEM', DB::raw('SUM(d.SDMASUK) as masuk'), DB::raw("SUM({$keluar}) as keluar"));

        // --- lapis luar: master item, cabangnya dicocokkan ke ICABANG (pipa-delimit) ---
        $rows = DB::table('bitem as i')
            ->leftJoinSub($mutasi, 'm', 'm.SDITEM', '=', 'i.IID')
            ->when($gudang !== [], fn ($b) => $b->where(function ($w) use ($gudang) {
                foreach ($gudang as $g) {
                    $w->orWhere('i.ICABANG', 'like', '%|' . (int) $g . '|%');
                }
            }))
            ->when($r->filled('jenisItem'), fn ($b) => $b->where('i.IJENISITEM', $r->integer('jenisItem')))
            ->when($r->filled('item'), fn ($b) => $b->where('i.IID', $r->integer('item')))
            ->when($r->filled('jenisProduk'), fn ($b) => $b->where('i.IJENISPRODUK', $r->integer('jenisProduk')))
            ->when($r->boolean('stokSaja'), fn ($b) => $b->where('i.ITIPEITEM', 0))
            ->when($r->boolean('aktifSaja'), fn ($b) => $b->where('i.ISTATUS', 0))
            ->orderBy('i.INAMA')
            ->get([
                DB::raw('i.IKODE as kode'), DB::raw('i.INAMA as nama'),
                DB::raw('IFNULL(i.ISALDOX,0) as saldoAwal'),
                DB::raw('IFNULL(m.masuk,0) as masuk'), DB::raw('IFNULL(m.keluar,0) as keluar'),
                DB::raw('IFNULL(i.ICOGS,0) as cogs'), DB::raw('IFNULL(i.IHARGAJUAL1,0) as hargaJual'),
            ])
            ->map(function ($x) {
                $x->akhir = (float) $x->saldoAwal + (float) $x->masuk - (float) $x->keluar;

                return $x;
            });

        return [
            'title'    => 'Laporan Stok Per Hari',
            'subtitle' => \Carbon\Carbon::parse($r->query('from'))->format('d/m/Y') . ' s/d '
                . \Carbon\Carbon::parse($r->query('to'))->format('d/m/Y')
                . ($this->namaCabang($gudang) ? ' — ' . $this->namaCabang($gudang) : ''),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'total'    => [
                'saldoAwal' => (float) $rows->sum('saldoAwal'), 'masuk' => (float) $rows->sum('masuk'),
                'keluar' => (float) $rows->sum('keluar'), 'akhir' => (float) $rows->sum('akhir'),
            ],
        ];
    }

    /**
     * **Daftar Surat Jalan Barang** (VB6 menu 451) - `zdFrmFilterLaporanDaftar2.frm` query
     * baris 802, filter baris 746.
     *
     * **AWAS**: `zfFrmFilterLaporanStok.frm` juga punya `Case 451` (baris 1385) dgn query lebih
     * sedikit kolomnya - itu BUKAN yg dipakai; `aMod_Menu.bas` baris 323 mengarahkan menu 451
     * ke form `...LaporanDaftar`.
     */
    public function daftarSuratJalan(Request $r)
    {
        return app(PdfReport::class)->preview('reports.daftar-surat-jalan', $this->dataDaftarSuratJalan($r),
            ['size' => 'A4', 'orientasi' => 'L', 'marginTop' => 14, 'marginBottom' => 14]);
    }

    public function daftarSuratJalanExcel(Request $r)
    {
        return $this->kirimExcel('reports.daftar-surat-jalan-xls', $this->dataDaftarSuratJalan($r));
    }

    private function dataDaftarSuratJalan(Request $r): array
    {
        $r->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'noDari' => ['nullable', 'string', 'max:50'], 'noSampai' => ['nullable', 'string', 'max:50'],
            'cabang' => ['nullable', 'integer'], 'gudangTujuan' => ['nullable', 'integer'],
            'coa2021' => ['nullable', 'integer'], 'pt' => ['nullable', 'integer'],
        ]);

        $gudang = $this->cabangLaporan($r);
        $noDari = trim((string) $r->query('noDari'));
        $noSampai = trim((string) $r->query('noSampai'));

        $rows = DB::table('fstokd as d')
            ->leftJoin('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bkontak as pel', 'pel.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bkontak as sal', 'sal.KID', '=', 'u.SUKARYAWAN')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            ->leftJoin('bgudang as ga', 'ga.GID', '=', 'u.SUCABANG')
            ->leftJoin('bnamapt as pt', 'pt.NPID', '=', 'gt.GPT')
            ->where('u.SUSUMBER', 'SJ')
            ->whereBetween('u.SUTANGGAL', [$r->query('from'), $r->query('to')])
            // VB6: no transaksi KE-2 hanya dipakai kalau tanggal ke-2 juga diisi (baris 755).
            // Di sini pakai aturan yg lebih jelas: ada keduanya = rentang, satu saja = persis.
            ->when($noDari !== '' && $noSampai !== '',
                fn ($b) => $b->whereBetween('u.SUNOTRANSAKSI', [$noDari, $noSampai]))
            ->when($noDari !== '' && $noSampai === '', fn ($b) => $b->where('u.SUNOTRANSAKSI', $noDari))
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang))
            ->when($r->filled('gudangTujuan'), fn ($b) => $b->where('u.SUGUDANGTUJUAN', $r->integer('gudangTujuan')))
            ->when($r->filled('coa2021'), fn ($b) => $b->where('i.ICOA2021', $r->integer('coa2021')))
            ->when($r->filled('pt'), fn ($b) => $b->where('pt.NPID', $r->integer('pt')))
            ->orderBy('u.SUTANGGAL')->orderBy('u.SUNOTRANSAKSI')->orderBy('d.SDURUTAN')
            ->get([
                DB::raw('u.SUNOTRANSAKSI as nomor'), DB::raw('u.SUTANGGAL as tanggal'),
                DB::raw('i.IKODE as kode'), DB::raw('i.INAMA as nama'), DB::raw('s.SKODE as satuan'),
                DB::raw('IFNULL(d.SDKELUAR,0) as qty'),
                DB::raw('pel.KNAMA as pelanggan'), DB::raw('sal.KNAMA as sales'),
                DB::raw('ga.GKODE as gudangAsal'), DB::raw('gt.GKODE as gudangTujuan'),
                DB::raw('pt.NPNAMA as pt'), DB::raw('IFNULL(i.ICOGS,0) as cogs'),
            ]);

        return [
            'title'    => 'Daftar Surat Jalan Barang',
            'subtitle' => \Carbon\Carbon::parse($r->query('from'))->format('d/m/Y') . ' s/d '
                . \Carbon\Carbon::parse($r->query('to'))->format('d/m/Y')
                . ($this->namaCabang($gudang) ? ' — ' . $this->namaCabang($gudang) : ''),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'totalQty' => (float) $rows->sum('qty'),
        ];
    }

    /**
     * **Daftar Stok Barang Serial** (VB6 menu **499**, bukan 459 - lihat catatan di atas) -
     * `zfFrmFilterLaporanStok.frm` query baris 1484, filter baris 1243 (SAMA PERSIS dgn menu
     * 318, termasuk tanggal TUNGGAL sbg cut-off).
     *
     * Hanya item ber-`ISERIAL=1`; jumlah per nomor serial = `SUM(ISHMASUK - ISHKELUAR)` dari
     * `bitemserialhistori`, yg ditautkan ke baris stok lewat `ISHIDFSTOKD = SDID`.
     */
    public function daftarStokSerial(Request $r)
    {
        return app(PdfReport::class)->preview('reports.daftar-stok-serial', $this->dataDaftarStokSerial($r),
            ['size' => 'A4', 'orientasi' => 'L', 'marginTop' => 14, 'marginBottom' => 14]);
    }

    public function daftarStokSerialExcel(Request $r)
    {
        return $this->kirimExcel('reports.daftar-stok-serial-xls', $this->dataDaftarStokSerial($r));
    }

    private function dataDaftarStokSerial(Request $r): array
    {
        $r->validate([
            'tanggal' => ['required', 'date'], 'cabang' => ['nullable', 'integer'],
            'jenisItem' => ['nullable', 'integer'], 'item' => ['nullable', 'integer'],
            'jenisProduk' => ['nullable', 'integer'], 'coa2021' => ['nullable', 'integer'],
            'pt' => ['nullable', 'integer'],
        ]);

        $gudang = $this->cabangLaporan($r);
        if ($gudang === [1] || $gudang === [6]) {
            $gudang = [1, 6];
        }

        $jumlah = 'SUM(h.ISHMASUK - h.ISHKELUAR)';

        $rows = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->join('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->join('bgudang as g', 'g.GID', '=', 'd.SDGUDANG')
            ->join('bitemserialhistori as h', 'h.ISHIDFSTOKD', '=', 'd.SDID')
            ->join('bitemserial as sr', fn ($j) => $j->on('sr.ISID', '=', 'h.ISHIDSERIAL')
                ->whereColumn('sr.ISITEM', 'd.SDITEM'))
            ->where('d.SDCANCEL', 0)
            ->where('i.ISERIAL', 1)
            ->whereDate('u.SUTANGGAL', '<=', $r->query('tanggal'))
            ->when($gudang !== [], fn ($b) => $b->whereIn('d.SDGUDANG', $gudang))
            ->when($r->filled('jenisItem'), fn ($b) => $b->where('i.IJENISITEM', $r->integer('jenisItem')))
            ->when($r->filled('item'), fn ($b) => $b->where('i.IID', $r->integer('item')))
            ->when($r->filled('jenisProduk'), fn ($b) => $b->where('i.IJENISPRODUK', $r->integer('jenisProduk')))
            ->when($r->filled('coa2021'), fn ($b) => $b->where('i.ICOA2021', $r->integer('coa2021')))
            ->when($r->filled('pt'), fn ($b) => $b->where('g.GPT', $r->integer('pt')))
            ->when($r->boolean('stokSaja'), fn ($b) => $b->where('i.ITIPEITEM', 0))
            ->when($r->boolean('aktifSaja'), fn ($b) => $b->where('i.ISTATUS', 0))
            ->groupBy('i.IID', 'i.IKODE', 'i.INAMA', 'i.IHARGAJUAL1', 'i.IHARGABELI', 's.SKODE',
                'sr.ISNOSERIAL', 'sr.ISTGLEXPIRED')
            ->when($r->boolean('nol0'), fn ($b) => $b->havingRaw("{$jumlah} <> 0"))
            ->orderBy('i.INAMA')->orderBy('sr.ISNOSERIAL')
            ->get([
                DB::raw('i.IKODE as kode'), DB::raw('i.INAMA as nama'), DB::raw('s.SKODE as satuan'),
                DB::raw('sr.ISNOSERIAL as noSerial'), DB::raw('sr.ISTGLEXPIRED as expired'),
                DB::raw("{$jumlah} as jumlah"),
                DB::raw('IFNULL(i.IHARGAJUAL1,0) as hargaJual'), DB::raw('IFNULL(i.IHARGABELI,0) as hargaBeli'),
            ]);

        return [
            'title'    => 'Daftar Stok Barang Serial',
            'tanggal'  => \Carbon\Carbon::parse($r->query('tanggal'))->format('d/m/Y'),
            'subtitle' => $this->namaCabang($gudang),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'total'    => (float) $rows->sum('jumlah'),
        ];
    }

    /**
     * **IP Kedatangan Pasien** (VB6 menu 531) - `zdFrmFilterLaporanDaftar2_lama.frm`
     * (form `zeFrmFilterLaporanDaftar`), fungsi `pSQLString` `Case 531` baris **3166**.
     *
     * **JEBAKAN: `pSQL` ditimpa EMPAT KALI** di `Case 531` (3144, 3149, 3154, 3166). Di VB6
     * yg berlaku yg TERAKHIR - tiga yg awal kode mati. Yg benar = agregat **per pasien** dari
     * subquery per-baris, BUKAN daftar per-transaksi seperti tiga versi awal.
     */
    public function ipKedatanganPasien(Request $r)
    {
        return app(PdfReport::class)->preview('reports.ip-kedatangan-pasien', $this->dataIpKedatanganPasien($r),
            ['size' => 'A4', 'orientasi' => 'L', 'marginTop' => 14, 'marginBottom' => 14]);
    }

    public function ipKedatanganPasienExcel(Request $r)
    {
        return $this->kirimExcel('reports.ip-kedatangan-pasien-xls', $this->dataIpKedatanganPasien($r));
    }

    /**
     * ## Apa yg dihitung
     * Kunci kedatangan = **`CONCAT(SUTANGGAL, SUKONTAK)`** - satu pasien pada satu TANGGAL =
     * satu kedatangan, berapa pun jumlah transaksi/barisnya hari itu. Dari situ:
     * - `kedatangan`  = `COUNT(DISTINCT kunci)`
     * - `berbayar`    = idem, tapi hanya baris ber-`subtotal > 0`
     * - `denganDokter`= idem + `sddokter` terisi + `IRESEPITTER = 0`
     *   (nama aslinya di VB6 `SUTOTALKAS`/`SUTOTALKARTUKREDIT` - itu nama field Crystal yg
     *   DIPAKAI ULANG, sama sekali bukan nilai kas/kartu kredit. Diberi nama bermakna di sini.)
     * - `SUTOTALKARTUDEBIT` di VB6 dipatok `0` - tidak diikutkan, tidak ada artinya.
     *
     * ## Rumus `subtotal` per baris (disalin apa adanya)
     * - `ikelompok2020 = 8`  -> `(SDKELUAR*(SDHARGA-SDDISKON)) - SDBAYARDP`
     * - `ikelompok2020 = 10` DAN `F_SUBTOTAL_SURGERY(SUID) <> 0` -> diprorata:
     *   `baris / totalSurgery * (totalSurgery - SUNILAIPIUTANG - SUSURGERYDPPEMBAYARAN)`
     * - selain itu -> `SDKELUAR * (SDHARGA - SDDISKON)`
     *
     * `F_SUBTOTAL_SURGERY` fungsi DB legacy (ADA, dicek) - dipanggil apa adanya, JANGAN
     * diterjemahkan ulang ke PHP.
     *
     * JOIN `bwilayah` dipakai utk kecamatan DAN kota (versi awal yg mati memakai `bwilayah3`
     * utk kota - jangan ikut yg itu).
     */
    private function dataIpKedatanganPasien(Request $r): array
    {
        $r->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'cabang' => ['nullable', 'integer'], 'jenisKunjungan' => ['nullable', 'integer'],
        ]);

        $gudang = $this->cabangLaporan($r);

        $subtotal = '(CASE WHEN i.IKELOMPOK2020 = 8 THEN (d.SDKELUAR*(d.SDHARGA-d.SDDISKON)) - d.SDBAYARDP'
            . ' WHEN i.IKELOMPOK2020 = 10 AND F_SUBTOTAL_SURGERY(u.SUID) <> 0'
            . ' THEN (d.SDKELUAR*(d.SDHARGA-d.SDDISKON)) / F_SUBTOTAL_SURGERY(u.SUID)'
            . ' * (F_SUBTOTAL_SURGERY(u.SUID) - u.SUNILAIPIUTANG - u.SUSURGERYDPPEMBAYARAN)'
            . ' ELSE (d.SDKELUAR*(d.SDHARGA-d.SDDISKON)) END)';

        $dalam = DB::table('fstoku as u')
            ->join('bkontak as p', 'p.KID', '=', 'u.SUKONTAK')
            ->join('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->join('fstokd as d', 'd.SDIDSU', '=', 'u.SUID')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bwilayah as kec', 'kec.BWID', '=', 'p.K1KECAMATAN')
            ->leftJoin('bwilayah as kot', 'kot.BWID', '=', 'p.K1KOTA')
            ->where('u.SUSTATUS', '<>', 9)
            ->where('u.SUSUMBER', 'IP')
            ->whereBetween('u.SUTANGGAL', [$r->query('from'), $r->query('to')])
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang))
            ->when($r->filled('jenisKunjungan'), fn ($b) => $b->where('u.SUDKKWALKIN', $r->integer('jenisKunjungan')))
            // Daftar kelompok "tindakan" disalin apa adanya dari VB6 baris 2587.
            ->when($r->boolean('tindakanSaja'), fn ($b) => $b->whereIn('i.IKELOMPOK2020', [1, 2, 3, 4, 10, 11, 12]))
            ->when($r->boolean('adaDokterSaja'), fn ($b) => $b->whereRaw('COALESCE(d.SDDOKTER,0) <> 0'))
            // VB6 baris 2586: "Tanpa NH" = buang cabang 32 (National Hospital).
            ->when($r->boolean('tanpaNh'), fn ($b) => $b->where('u.SUCABANG', '<>', 32))
            ->select([
                DB::raw('d.SDDOKTER as sddokter'), DB::raw('i.IRESEPITTER as iresepitter'),
                DB::raw('CONCAT(u.SUTANGGAL, u.SUKONTAK) as kunci'),
                DB::raw('u.SUKONTAK as kontak'), DB::raw('p.KKODE as kode'), DB::raw('p.KNAMA as nama'),
                DB::raw('p.KTGLLAHIR as lahir'), DB::raw('p.K1TELP1 as telp'),
                DB::raw('p.KCREATED as dibuat'), DB::raw('p.KJENISKELAMIN as jk'),
                DB::raw('p.KIDPASIEN as idPasien'), DB::raw('p.KBARULAMA as baruLama'),
                DB::raw('u.SUTANGGAL as tanggal'),
                DB::raw('kec.BNAMA as kecamatan'), DB::raw('kot.BNAMA as kota'), DB::raw('g.GKODE as cabang'),
                DB::raw("{$subtotal} as subtotal"),
            ]);

        $rows = DB::query()->fromSub($dalam, 'a')
            ->groupBy('nama', 'kontak', 'kode', 'lahir', 'telp', 'dibuat', 'jk', 'idPasien',
                'baruLama', 'kecamatan', 'kota', 'cabang')
            ->orderBy('nama')
            ->get([
                'kontak', 'kode', 'nama', 'lahir', 'telp', 'dibuat', 'jk', 'idPasien', 'baruLama',
                'kecamatan', 'kota', 'cabang',
                DB::raw('SUM(subtotal) as nilai'),
                DB::raw('MAX(tanggal) as terakhir'),
                DB::raw('COUNT(DISTINCT kunci) as kedatangan'),
                DB::raw('COUNT(DISTINCT CASE WHEN subtotal > 0 THEN kunci END) as berbayar'),
                DB::raw('COUNT(DISTINCT CASE WHEN subtotal > 0 AND COALESCE(sddokter,0) <> 0'
                    . ' AND iresepitter = 0 THEN kunci END) as denganDokter'),
            ]);

        return [
            'title'    => 'IP Kedatangan Pasien',
            'subtitle' => \Carbon\Carbon::parse($r->query('from'))->format('d/m/Y') . ' s/d '
                . \Carbon\Carbon::parse($r->query('to'))->format('d/m/Y')
                . ($this->namaCabang($gudang) ? ' — ' . $this->namaCabang($gudang) : ''),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'total'    => [
                'pasien'       => $rows->count(),
                'kedatangan'   => (int) $rows->sum('kedatangan'),
                'berbayar'     => (int) $rows->sum('berbayar'),
                'denganDokter' => (int) $rows->sum('denganDokter'),
                'nilai'        => (float) $rows->sum('nilai'),
            ],
        ];
    }

    /**
     * **IP Penjualan Per Dokter** (VB6 menu 583) - `zdFrmFilterLaporanDaftar2_lama.frm`,
     * `pSQLString` `Case 583` baris **3215**. `pSQL` di sini hanya ditugaskan SEKALI (enam
     * varian lain baris 3203-3210 DIKOMENTARI) - beda dari menu 531, sudah diperiksa.
     */
    public function ipPenjualanPerDokter(Request $r)
    {
        return app(PdfReport::class)->preview('reports.ip-penjualan-per-dokter', $this->dataIpPenjualanPerDokter($r),
            ['size' => 'A4', 'orientasi' => 'L', 'marginTop' => 14, 'marginBottom' => 14]);
    }

    public function ipPenjualanPerDokterExcel(Request $r)
    {
        return $this->kirimExcel('reports.ip-penjualan-per-dokter-xls', $this->dataIpPenjualanPerDokter($r));
    }

    /**
     * ## Siapa "dokter"-nya
     * JOIN-nya **bukan** `SDDOKTER` langsung: `CASE WHEN COALESCE(SDREFERAL,0) <> 0 THEN
     * SDREFERAL ELSE SDDOKTER END` - kalau barisnya punya dokter PERUJUK, yg diakui perujuknya.
     * Lalu disaring `dokter.KJENISKARYAWAN IN (3,4)` (116 kontak di data ini) dan
     * `COALESCE(SDBARISKEPALA,0) = 0` (buang baris kepala/paket).
     *
     * **Saringan `KJENISKARYAWAN IN (3,4)` itu KERAS** - baris yg dokternya bukan tipe 3/4
     * hilang sama sekali. Di data uji, SEMUA baris September tersaring habis karenanya
     * (Agustus normal: 30 dokter / 313 pasien). Kalau user melapor "kosong", cek dulu tipe
     * karyawan dokternya di master, jangan buru-buru menyalahkan query.
     *
     * ## Rumus nilai (disalin apa adanya)
     * `IRESEP=1` -> `(SDKELUAR*(SDHARGA-SDDISKON)) + COALESCE(F_SUBTOTAL(SUID),0)`,
     * selain itu `SDKELUAR*(SDHARGA-SDDISKON)`.
     * **CATATAN**: `F_SUBTOTAL(SUID)` itu nilai SE-TRANSAKSI, ditambahkan per BARIS resep -
     * kalau satu transaksi punya >1 baris resep, nilainya ikut terhitung berulang. Itu
     * perilaku VB6 apa adanya; JANGAN "diperbaiki" diam-diam, hasilnya akan beda dari
     * aplikasi lama.
     *
     * `alkes` = `F_TOTAL_ALKES_BY_URUTAN(SUID, urutan)` kecuali `IKELOMPOK2020` 5 atau 7.
     * Urutannya `SDURUTANAWAL` kalau terisi, selain itu `SDURUTAN`.
     *
     * `jumlahpasien` = `CONCAT(SUKONTAK, SUTANGGAL)` -> dihitung `COUNT(DISTINCT ...)`, jadi
     * satu pasien pada satu tanggal = satu pasien (pola sama menu 531).
     *
     * Kelompok = `'Product'` kalau `IRESEP=1`, selain itu `IK2KODE`.
     */
    private function dataIpPenjualanPerDokter(Request $r): array
    {
        $r->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'cabang' => ['nullable', 'integer'],
        ]);

        $gudang = $this->cabangLaporan($r);
        $rinci = $r->boolean('rinciKelompok');

        $nilai = "CASE WHEN i.IRESEP = 1 THEN (d.SDKELUAR*(d.SDHARGA-d.SDDISKON)) + COALESCE(F_SUBTOTAL(u.SUID),0)"
            . ' ELSE (d.SDKELUAR*(d.SDHARGA-d.SDDISKON)) END';
        $alkes = 'CASE WHEN i.IKELOMPOK2020 <> 5 AND i.IKELOMPOK2020 <> 7 THEN COALESCE(F_TOTAL_ALKES_BY_URUTAN('
            . 'u.SUID, CASE WHEN d.SDURUTANAWAL = 0 THEN d.SDURUTAN ELSE d.SDURUTANAWAL END), 0) ELSE 0 END';

        $dalam = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->join('bkontak as dok', fn ($j) => $j->on(DB::raw('dok.KID'), '=',
                DB::raw('CASE WHEN COALESCE(d.SDREFERAL,0) <> 0 THEN d.SDREFERAL ELSE d.SDDOKTER END')))
            ->leftJoin('bitemkelompok2020 as k', 'k.IK2ID', '=', 'i.IKELOMPOK2020')
            ->where('u.SUSTATUS', '<>', 9)
            ->where('u.SUSUMBER', 'IP')
            ->whereIn('dok.KJENISKARYAWAN', [3, 4])
            ->whereRaw('COALESCE(d.SDBARISKEPALA,0) = 0')
            ->whereBetween('u.SUTANGGAL', [$r->query('from'), $r->query('to')])
            ->when($gudang !== [], fn ($b) => $b->whereIn('u.SUCABANG', $gudang))
            ->when($r->boolean('tindakanSaja'), fn ($b) => $b->whereIn('i.IKELOMPOK2020', [1, 2, 3, 4, 10, 11, 12]))
            ->when($r->boolean('tanpaNh'), fn ($b) => $b->where('u.SUCABANG', '<>', 32))
            ->select([
                DB::raw('dok.KKODE as kodeDokter'), DB::raw('dok.KNAMA as namaDokter'),
                DB::raw("CASE WHEN i.IRESEP = 1 THEN 'Product' ELSE k.IK2KODE END as kelompok"),
                DB::raw('CONCAT(u.SUKONTAK, u.SUTANGGAL) as kunciPasien'),
                DB::raw('IFNULL(d.SDKELUAR,0) as qty'),
                DB::raw("{$nilai} as nilai"),
                DB::raw("{$alkes} as alkes"),
            ]);

        $grup = $rinci ? ['kodeDokter', 'namaDokter', 'kelompok'] : ['kodeDokter', 'namaDokter'];

        $rows = DB::query()->fromSub($dalam, 'a')
            ->groupBy($grup)
            ->orderBy('namaDokter')
            ->when($rinci, fn ($b) => $b->orderBy('kelompok'))
            ->get(array_merge($grup, [
                DB::raw('COUNT(DISTINCT kunciPasien) as pasien'),
                DB::raw('SUM(qty) as qty'),
                DB::raw('SUM(nilai) as nilai'),
                DB::raw('SUM(alkes) as alkes'),
            ]));

        return [
            'title'    => 'IP Penjualan Per Dokter',
            'subtitle' => \Carbon\Carbon::parse($r->query('from'))->format('d/m/Y') . ' s/d '
                . \Carbon\Carbon::parse($r->query('to'))->format('d/m/Y')
                . ($this->namaCabang($gudang) ? ' — ' . $this->namaCabang($gudang) : ''),
            'company'  => app(PdfReport::class)->companyInfo(),
            'rinci'    => $rinci,
            'rows'     => $rows,
            'total'    => [
                'baris'  => $rows->count(),
                'pasien' => (int) $rows->sum('pasien'),
                'qty'    => (float) $rows->sum('qty'),
                'nilai'  => (float) $rows->sum('nilai'),
                'alkes'  => (float) $rows->sum('alkes'),
            ],
        ];
    }

    /** Nama cabang utk subjudul - `null` kalau lebih dari satu / tidak dibatasi. */
    private function namaCabang(array $gudang): ?string
    {
        if ($gudang === [1, 6]) {
            return 'Petogogan + Online';
        }

        return count($gudang) === 1 && $gudang !== [0]
            ? (string) DB::table('bgudang')->where('GID', $gudang[0])->value('GNAMA')
            : null;
    }

    /** Pola kirim Excel yg sama utk semua laporan (HTML table ber-header .xls). */
    private function kirimExcel(string $view, array $data)
    {
        return response()->view($view, $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $data['title'] . '.xls"');
    }

    /**
     * Laporan Persediaan "Daftar Stok Barang" - judul cetakan **"Laporan Real Stok Barang"**
     * (beda dari nama menunya, ikut contoh cetakan user). Port dari VB6 menu **318**.
     */
    public function daftarStokBarang(Request $r)
    {
        return app(PdfReport::class)->preview(
            'reports.daftar-stok-barang',
            $this->dataDaftarStokBarang($r),
            ['size' => 'A4', 'orientasi' => 'P', 'marginTop' => 14, 'marginBottom' => 14]
        );
    }

    public function daftarStokBarangExcel(Request $r)
    {
        $data = $this->dataDaftarStokBarang($r);

        return response()
            ->view('reports.daftar-stok-barang-xls', $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $data['title'] . '.xls"');
    }

    /**
     * QUERY DISALIN dari VB6 `zfFrmFilterLaporanStok.frm` baris 1392 (SQL) + 1243 (filter):
     *
     *   SUM(SDMASUK - IF(SDDARIPAKET<>0 AND SDKEDATANGAN=0, 0, SDKELUAR))
     *
     * `IF(...)` itu BUKAN hiasan: baris yg berasal dari PAKET (`SDDARIPAKET<>0`) dan bukan
     * kedatangan (`SDKEDATANGAN=0`) keluarnya **diabaikan** - komponen paket sudah terhitung
     * lewat baris paketnya sendiri, kalau ikut dikurangi stoknya dobel. Jangan disederhanakan
     * jadi `SDMASUK - SDKELUAR`.
     *
     * Semua join `INNER` - **disengaja, ikut VB6**: item tanpa `ICOA2021` yg cocok di
     * `bcoatipe_perpt`, tanpa satuan, atau tanpa gudang TIDAK muncul. Mengubahnya jadi
     * `LEFT JOIN` akan memunculkan baris yg di cetakan lama tidak ada.
     *
     * `GROUP BY`-nya panjang & memuat kolom harga (`ihargabeli2`, `icogspabrik`, `ihargadepo`,
     * `icogs`, `ihargajual1`) - disalin APA ADANYA. Efeknya satu item bisa jadi >1 baris kalau
     * harganya pernah beda; itulah perilaku cetakan lama, jangan "dirapikan" jadi group per
     * item saja.
     *
     * ## Gabungan gudang 1 & 6
     * VB6: kalau cabang yg dipilih 1 (Petogogan) ATAU 6 (Online), filternya jadi
     * `SDGUDANG IN (1,6)` - stok Online memang dihitung menyatu dgn Petogogan. Ditiru persis.
     *
     * ## "Stok 0 Tidak Tampil"
     * Di VB6 dikirim sbg parameter laporan (`STOK0`) dan disaring di Crystal Report, bukan di
     * SQL. Di sini disaring `HAVING stok <> 0` - hasil akhirnya sama, tapi jauh lebih ringan.
     *
     * Cabang tetap ditegakkan `cabangLaporan()` - parameter URL bisa diketik manual.
     *
     * @return array{title:string,subtitle:string,company:array,rows:\Illuminate\Support\Collection,total:float,tanggal:string}
     */
    private function dataDaftarStokBarang(Request $r): array
    {
        $r->validate([
            'tanggal'     => ['required', 'date'],
            'cabang'      => ['nullable', 'integer'],
            'jenisItem'   => ['nullable', 'integer'],
            'item'        => ['nullable', 'integer'],
            'jenisProduk' => ['nullable', 'integer'],
            'coa2021'     => ['nullable', 'integer'],
            'pt'          => ['nullable', 'integer'],
        ]);

        $tanggal = $r->query('tanggal');
        $gudang = $this->cabangLaporan($r);

        // Aturan VB6: memilih gudang 1 atau 6 berarti KEDUANYA. Hanya berlaku saat user
        // memilih SATU cabang - "Semua cabang" sudah mencakup keduanya.
        if ($gudang === [1] || $gudang === [6]) {
            $gudang = [1, 6];
        }

        $stok = 'IFNULL(SUM(d.SDMASUK - IF(d.SDDARIPAKET <> 0 AND d.SDKEDATANGAN = 0, 0, d.SDKELUAR)), 0)';

        $rows = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->join('bcoatipe_perpt as ct', 'ct.CTTIPEID', '=', 'i.ICOA2021')
            ->join('bgudang as g', 'g.GID', '=', 'd.SDGUDANG')
            ->join('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->where('u.SUSTATUS', '<>', 9)
            ->where('d.SDCANCEL', 0)
            ->where('u.SUSUMBER', '<>', '')
            ->whereDate('u.SUTANGGAL', '<=', $tanggal)
            ->when($gudang !== [], fn ($b) => $b->whereIn('d.SDGUDANG', $gudang))
            ->when($r->filled('jenisItem'), fn ($b) => $b->where('i.IJENISITEM', $r->integer('jenisItem')))
            ->when($r->filled('item'), fn ($b) => $b->where('i.IID', $r->integer('item')))
            ->when($r->filled('jenisProduk'), fn ($b) => $b->where('i.IJENISPRODUK', $r->integer('jenisProduk')))
            ->when($r->filled('coa2021'), fn ($b) => $b->where('i.ICOA2021', $r->integer('coa2021')))
            ->when($r->filled('pt'), fn ($b) => $b->where('g.GPT', $r->integer('pt')))
            ->when($r->boolean('stokSaja'), fn ($b) => $b->where('i.ITIPEITEM', 0))
            ->when($r->boolean('aktifSaja'), fn ($b) => $b->where('i.ISTATUS', 0))
            ->groupBy('i.IHARGABELI2', 'i.ICOGSPABRIK', 'g.GKODE', 's.SKODE', 'i.IHARGADEPO',
                'i.ICOGS', 'i.INAMA', 'ct.CTNAMA', 'i.IHARGAJUAL1', 'i.IKODE')
            ->when($r->boolean('stok0'), fn ($b) => $b->havingRaw("{$stok} <> 0"))
            ->orderBy('g.GKODE')->orderBy('i.IKODE')
            ->get([
                DB::raw('g.GKODE as gudang'),
                DB::raw('ct.CTNAMA as jenis'),
                DB::raw('i.IKODE as kode'),
                DB::raw('i.INAMA as nama'),
                DB::raw('s.SKODE as satuan'),
                DB::raw("{$stok} as stok"),
            ]);

        $namaCabang = count($gudang) === 1 && $gudang !== [0]
            ? (string) DB::table('bgudang')->where('GID', $gudang[0])->value('GNAMA')
            : ($gudang === [1, 6] ? 'Petogogan + Online' : null);

        return [
            // Judul CETAKAN beda dari nama MENU ("Daftar Stok Barang") - ikut contoh user.
            'title'    => 'Laporan Real Stok Barang',
            'tanggal'  => \Carbon\Carbon::parse($tanggal)->format('d/m/Y'),
            'subtitle' => $namaCabang,
            'company'  => app(PdfReport::class)->companyInfo(),
            'rows'     => $rows,
            'total'    => (float) $rows->sum('stok'),
        ];
    }
}
