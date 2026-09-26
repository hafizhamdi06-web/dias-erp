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

        $allowed = auth()->user()->branchIds();

        $rows = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->when($item, fn ($b) => $b->where('d.SDITEM', $item->IID))
            ->whereDate('u.SUTANGGAL', '>=', $r->query('from'))
            ->whereDate('u.SUTANGGAL', '<=', $r->query('to'))
            ->when($r->filled('cabang'), fn ($b) => $b->where('u.SUCABANG', $r->integer('cabang')))
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
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

        $branch = $r->filled('cabang') ? DB::table('bgudang')->where('GID', $r->integer('cabang'))->value('GNAMA') : null;
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
}
