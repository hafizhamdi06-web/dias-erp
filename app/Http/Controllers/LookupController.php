<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint JSON untuk komponen <x-search-select> (Alpine): daftar besar / remote.
 * Semua butuh login (grup 'auth' di routes), tanpa filter hak menu.
 *
 * Format response: [{ id, text }]
 */
class LookupController extends Controller
{
    /** bwilayah level kota: bkode pola "xx.xx" (91rb baris -> selalu di-filter). */
    public function wilayahKota(Request $r)
    {
        return $this->wilayah($r, "bkode LIKE '__.__'");
    }

    /** bwilayah level kecamatan: bkode pola "xx.xx.xx". */
    public function wilayahKecamatan(Request $r)
    {
        return $this->wilayah($r, "bkode LIKE '__.__.__'");
    }

    private function wilayah(Request $r, string $levelWhere)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bwilayah')
            ->selectRaw('bwid AS id, bnama AS text')
            ->whereRaw($levelWhere)
            ->when($q !== '', fn ($b) => $b->where('bnama', 'like', "%{$q}%"))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('bwid', $id))
            ->orderBy('bnama')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** Karyawan aktif (bkontak KTIPE=4). */
    public function karyawan(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bkontak')
            ->selectRaw("KID AS id, CONCAT(KNAMA, IFNULL(CONCAT(' — ', KKODE), '')) AS text")
            ->where('KTIPE', 4)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('KNAMA', 'like', "%{$q}%")->orWhere('KKODE', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('KID', $id))
            ->orderBy('KNAMA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** Vendor/Supplier aktif (bkontak KTIPE=6 "SUPPLIER", dikonfirmasi bkontaktipe) -
     *  dipakai field "Vendor" Purchase Order. */
    public function vendor(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bkontak')
            ->selectRaw("KID AS id, CONCAT(KNAMA, IFNULL(CONCAT(' — ', KKODE), '')) AS text")
            ->where('KTIPE', 6)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('KNAMA', 'like', "%{$q}%")->orWhere('KKODE', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('KID', $id))
            ->orderBy('KNAMA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** Termin pembayaran (btermin, tabel kecil - 9 baris) - dipakai field "Termin" PO. */
    public function termin(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('btermin')
            ->selectRaw('TID AS id, TKODE AS text')
            ->when($q !== '', fn ($b) => $b->where('TKODE', 'like', "%{$q}%"))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('TID', $id))
            ->orderBy('TKODE')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /**
     * Kontak aktif TANPA batasan tipe (bkontak, 315rb+ baris) - dipakai field yg blm jelas
     * harus dibatasi tipe apa (mis. Training/Farmasi/Farmasi Asisten/Sales Marketing/Klinik
     * Lain/Teman di form "Data Lainnya" POS - kolom fstoku terkait blm pernah dipakai di
     * produksi, jadi tipe kontak yg tepat blm bisa diverifikasi ke data nyata, dibiarkan
     * generik dulu). WAJIB pakai `Contact::applySearch()` (FULLTEXT) - JANGAN `LIKE '%q%'`
     * polos di KNAMA, bkontak terlalu besar (lihat gotcha yg sudah didokumentasikan).
     */
    public function kontak(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $id = $r->query('id');

        // Resolve label by id SAJA (tanpa q) - fetch langsung by primary key, JANGAN gabung
        // lewat `where('KAKTIF',...)->orWhere('KID',$id)` (kalau q kosong, filter KAKTIF<>0
        // cocok ke HAMPIR SEMUA baris bkontak, jadi OR itu jadi tidak berarti & baris target
        // bisa "kalah" ketiban 30 baris lain hasil `orderBy('KNAMA')` - ditemukan via test,
        // bkontak terlalu besar utk pola id-bypass yg dipakai lookup lain di app ini).
        if ($q === '' && $id) {
            $row = DB::table('bkontak')->where('KID', $id)->first(['KID as id', 'KNAMA as text']);

            return response()->json($row ? [$row] : []);
        }

        $query = DB::table('bkontak')->where('KAKTIF', '<>', 0);
        \App\Models\Contact::applySearch($query, $q, 'bkontak');

        $rows = $query
            ->when($id, fn ($b) => $b->orWhere('KID', $id))
            ->orderBy('KNAMA')
            ->limit(30)
            ->get(['KID as id', 'KNAMA as text']);

        return response()->json($rows);
    }

    /**
     * Item aktif (bitem ISTATUS=0), utk field yg simpan IKODE sbg teks (bukan IID) - mis.
     * Master Promo Kombinasi (MPDKELITEM1-4/MPDPILIHAN1-4).
     */
    public function itemKode(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bitem')
            ->selectRaw("IKODE AS id, CONCAT(IKODE, ' — ', INAMA) AS text")
            ->where('ISTATUS', 0)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('IKODE', $id))
            ->orderBy('IKODE')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /**
     * Item aktif (bitem ISTATUS=0), utk field yg simpan IID sbg FK integer (bukan IKODE sbg
     * teks) - mis. Master Promo Biasa "Item Bonus" (MPDITEM1-5). Beda dari itemKode() di atas.
     */
    /**
     * Item yg MEMAKAI batch/serial (`bitem.ISERIAL=1`) - utk form Data Histori Serial.
     * Dipisah dari `itemId()` supaya user tidak bisa memilih item yg mustahil punya batch.
     */
    public function itemSerial(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bitem')
            ->selectRaw("IID AS id, CONCAT(IKODE, ' — ', INAMA) AS text")
            ->where('ISERIAL', 1)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('IID', $id))
            ->orderBy('IKODE')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    public function itemId(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bitem')
            ->selectRaw("IID AS id, CONCAT(IKODE, ' — ', INAMA) AS text")
            ->where('ISTATUS', 0)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('IID', $id))
            ->orderBy('IKODE')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** blain berdasarkan LTIPE (Marketing Source / Kelompok FU / dll). */
    public function lain(Request $r, string $tipe)
    {
        $map = [
            'marketing-source' => 'Marketing Source',
            'kelompok-fu'      => 'Kelompok FU',
        ];
        $ltipe = $map[$tipe] ?? null;
        abort_if($ltipe === null, 404);

        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('blain')
            ->selectRaw("lid AS id, CONCAT_WS(' - ', lkode, lnama) AS text")
            ->where('ltipe', $ltipe)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('lkode', 'like', "%{$q}%")->orWhere('lnama', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('lid', $id))
            ->orderBy('lkode')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** auser aktif untuk field "User Login" karyawan. */
    public function user(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('auser')
            ->selectRaw("UID AS id, CONCAT(UKODE, ' — ', IFNULL(UNAMA, '')) AS text")
            ->where('UACTIVE', 1)
            // OR dibungkus where(closure) - kalau tidak, "UNAMA LIKE" leak lolos dari filter
            // UACTIVE=1 di atas krn AND mengikat lebih erat drpd OR (bug nyata, ditemukan
            // 2026-09-15 saat mengecek pola serupa di PromoManager).
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('UKODE', 'like', "%{$q}%")->orWhere('UNAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('UID', $id))
            ->orderBy('UKODE')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /**
     * `bcoa` akun "rekening" utk form Kas/Bank Masuk/Keluar - `?tipe=kas` (CTIPE=0, grup
     * "KAS") atau `?tipe=bank` (CTIPE=1, grup "BANK"). Hanya akun leaf (`CGD='D'`, bukan
     * header/grup) yg bisa diposting, pola sama CI3 `Select_Master::view_coa_kas()` -
     * BEDA: CI3 filter cuma `ctipe=0` (jadi akun Bank sebenarnya TIDAK BISA dipilih lewat
     * situ, makanya CI3 py form Bank terpisah dgn query lain) - di sini kita bikin 2 filter
     * eksplisit `tipe=kas`/`tipe=bank` yg BENAR sesuai kelompoknya masing2. `tipe=all`
     * (atau param dikosongkan) = TANPA filter CTIPE - dipakai `PengajuanDanaForm` yg boleh
     * pilih rekening COA apa saja (pola VB6 `cFrmPengajuanDana::cboRekKas`, cuma filter
     * `CGD='D'`, TIDAK filter CTIPE).
     */
    /**
     * SEMUA COA (tanpa filter `CGD`) - utk memilih INDUK di master COA. Beda dari
     * `coaRekening()` yg sengaja cuma baris detail (`CGD='D'`), krn induk justru biasanya
     * baris grup. `exclude` = CID yg sedang diedit (tidak boleh jadi induk dirinya sendiri).
     */
    public function coaSemua(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $exclude = (int) $r->query('exclude', 0);

        $rows = DB::table('bcoa')
            ->selectRaw("CID AS id, CONCAT(CNOCOA, ' — ', CNAMA) AS text")
            ->where('CACTIVE', 1)
            ->when($exclude > 0, fn ($b) => $b->where('CID', '<>', $exclude))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('CNOCOA', 'like', "%{$q}%")->orWhere('CNAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('CID', $id))
            ->orderBy('CNOCOA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    public function coaRekening(Request $r)
    {
        $tipe = $r->query('tipe');
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bcoa')
            ->selectRaw("CID AS id, CONCAT(CNOCOA, ' — ', CNAMA) AS text")
            ->when($tipe === 'kas', fn ($b) => $b->where('CTIPE', 0))
            ->when($tipe === 'bank', fn ($b) => $b->where('CTIPE', 1))
            ->where('CGD', 'D')->where('CACTIVE', 1)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('CNOCOA', 'like', "%{$q}%")->orWhere('CNAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('CID', $id))
            ->orderBy('CNOCOA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /**
     * `bcoa` akun "biaya" (baris detail) utk form Kas/Bank - `?arah=masuk` (whitelist
     * `CKASMASUK=1`) atau `?arah=keluar` (`CKASKELUAR=1`), pola SAMA persis CI3
     * `view_coa_kasmasuk()`/`view_coa_kaskeluar()` - whitelist ini SUDAH dikurasi di data
     * master nyata (bukan tebakan kita), dipakai APA ADANYA utk KEDUA form Kas & Bank
     * (CI3 tidak punya flag terpisah utk versi Bank).
     */
    public function coaBiaya(Request $r)
    {
        $kolom = $r->query('arah') === 'keluar' ? 'CKASKELUAR' : 'CKASMASUK';
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bcoa')
            ->selectRaw("CID AS id, CONCAT(CNOCOA, ' — ', CNAMA) AS text")
            ->where($kolom, 1)->where('CGD', 'D')->where('CACTIVE', 1)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('CNOCOA', 'like', "%{$q}%")->orWhere('CNAMA', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('CID', $id))
            ->orderBy('CNOCOA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /**
     * `bkontak` kategori "KEUANGAN" (`bkontaktipe.KTID=16`, 113 baris data nyata - isinya
     * rekening bank/finance counterparty, BUKAN kontak umum) utk field "Kontak" form
     * Pengajuan Dana, pola SAMA VB6 `cFrmPengajuanDana::cmdCariKontak_Click`
     * (`cboKategori` di-set ke "KEUANGAN" sblm buka picker kontak).
     */
    public function kontakKeuangan(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bkontak')
            ->selectRaw('KID AS id, KNAMA AS text')
            ->where('KTIPE', 16)->where('KAKTIF', '<>', 0)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('KNAMA', 'like', "%{$q}%")->orWhere('KKODE', 'like', "%{$q}%")))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('KID', $id))
            ->orderBy('KNAMA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    /** `bbank` (master bank fisik, mis. BCA/Mandiri) utk field "Bank" form Bank Masuk/Keluar (tipe bayar=Transfer). */
    public function bank(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        $rows = DB::table('bbank')
            ->selectRaw('BID AS id, BNAMA AS text')
            ->when($q !== '', fn ($b) => $b->where('BNAMA', 'like', "%{$q}%"))
            ->when($id = $r->query('id'), fn ($b) => $b->orWhere('BID', $id))
            ->orderBy('BNAMA')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }
}
