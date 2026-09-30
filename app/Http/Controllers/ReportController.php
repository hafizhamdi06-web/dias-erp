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
}
