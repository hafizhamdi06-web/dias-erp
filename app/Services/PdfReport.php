<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

/**
 * Infrastruktur dasar laporan PDF, pola SAMA persis dgn CI3 (`dias-online-app`,
 * `application/controllers/Laporan.php`) yg SUDAH jalan di produksi dgn `mpdf/mpdf ^8.0.10`
 * - kita pakai `^8.2` (v8.3.1 terinstall), API sama, tinggal port. Belum ada laporan
 * spesifik yg dibuat pakai ini - baru infrastruktur (service + layout komponen).
 *
 * Pemanggil bikin view laporan sbg konten `<x-reports.layout>` (lihat
 * `resources/views/components/reports/layout.blade.php`), lalu panggil
 * `PdfReport::preview()` (tampil di browser) atau `::download()` (paksa unduh) dari route/
 * controller biasa (BUKAN dari Livewire component - render PDF besar tidak cocok
 * dgn siklus hidup Livewire, sama spt CI3 yg pakai controller method terpisah).
 */
class PdfReport
{
    /**
     * @param array{size?:string,orientasi?:string,marginLeft?:float,marginTop?:float,marginBottom?:float,perBagian?:int} $opt
     *        size: A4/A3/Letter/Legal (default A4). orientasi: P/L (default P) - konvensi
     *        sama CI3 `areport.ARPAPERSIZE`/`ARPAPERORINTED`.
     *        perBagian: >0 = tabel panjang ditulis BERTAHAP, lihat `tulisBertahap()`.
     */
    public function make(string $view, array $data = [], array $opt = []): Mpdf
    {
        $size = $opt['size'] ?? 'A4';
        $orientasi = $opt['orientasi'] ?? 'P';

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => $size . '-' . $orientasi,
            'margin_left'   => $opt['marginLeft'] ?? 10,
            'margin_top'    => $opt['marginTop'] ?? 10,
            'margin_bottom' => $opt['marginBottom'] ?? 12,
            'tempDir'       => storage_path('app/mpdf'),
        ]);

        $mpdf->SetTitle($data['title'] ?? 'Laporan');
        $mpdf->SetAuthor(config('app.name'));

        // Tabel laporan legacy bisa sangat panjang/lebar - backtrack limit default PHP
        // sering kurang utk regex internal mpdf saat parsing HTML besar (pola sama
        // persis CI3 Laporan.php, confirmed perlu di produksi).
        ini_set('pcre.backtrack_limit', '100000000');

        // Render tabel besar (ribuan baris - mis. laporan "semua item" tanpa filter) lewat
        // mpdf MEMAKAN MEMORI JAUH lbh besar drpd ukuran HTML mentahnya (parsing tiap sel
        // jadi objek internal) - ditemukan nyata 2026-09-23: 2.750 baris SAJA (2 hari data)
        // sudah menghabiskan default 128M. Dinaikkan scope-per-request (TIDAK ubah php.ini
        // global, TIDAK pengaruhi request lain). Laporan yg memang bisa ribuan baris
        // sebaiknya pakai `perBagian` (di bawah) - itu menahan memori, bukan menaikkan batas.
        ini_set('memory_limit', '512M');

        $perBagian = (int) ($opt['perBagian'] ?? 0);
        if ($perBagian > 0 && isset($data['rows'])) {
            $this->tulisBertahap($mpdf, $view, $data, $perBagian);
        } else {
            $mpdf->WriteHTML(view($view, $data)->render());
        }

        return $mpdf;
    }

    /**
     * Menulis tabel panjang ke mpdf dlm BEBERAPA panggilan `WriteHTML()`, sepotong
     * `$perBagian` baris.
     *
     * Kenapa: mpdf menahan SELURUH tabel sbg objek di memori sampai `</table>`, jadi memori
     * tumbuh linear dgn jumlah baris - terukur 2026-10-04 di laporan keluarga IV:
     * **2.851 baris (satu bulan data nyata) menghabiskan 512M dan OOM**. Dgn dipotong per 400
     * baris, laporan yg SAMA selesai di **~156 MB** dan memori nyaris tidak tumbuh lagi setelah
     * potongan pertama (130 -> 142 MB sepanjang 8 potongan) - yg tersisa cuma buffer halaman
     * PDF-nya. Jadi memori berhenti jadi batas; yg tersisa batas WAKTU (~7 ms/baris), dan itu
     * dijaga `ReportController::BATAS_BARIS_PDF`.
     *
     * KONTRAK VIEW-nya: view menerima `bagianPertama`/`bagianTerakhir` dan harus
     *   - `<head>`, header perusahaan, judul, deklarasi footer: HANYA saat `bagianPertama`
     *   - `<pagebreak />` sebelum tabelnya saat BUKAN `bagianPertama` - supaya `<thead>`
     *     potongan berikutnya jatuh di ATAS halaman (tanpa itu header kolom muncul di
     *     tengah halaman, kelihatan seperti salah cetak)
     *   - baris TOTAL: HANYA saat `bagianTerakhir`
     *   - lebar SETIAP kolom ditulis eksplisit - tiap potongan adalah `<table>` sendiri, kalau
     *     ada kolom yg lebarnya diserahkan ke mpdf, lebarnya bisa beda antar potongan dan
     *     kolomnya tidak lurus.
     * `$data['total']` tetap total SELURUH laporan (dihitung di controller), bukan per potongan.
     */
    private function tulisBertahap(Mpdf $mpdf, string $view, array $data, int $perBagian): void
    {
        $rows = $data['rows'];
        $jumlah = $rows->count();

        // Tanpa data tetap dirender sekali - kalau tidak, `chunk()` tidak menghasilkan apa pun
        // dan PDF-nya kosong melompong (user tidak tahu laporannya memang nihil atau rusak).
        if ($jumlah === 0) {
            $mpdf->WriteHTML(view($view, $data + ['bagianPertama' => true, 'bagianTerakhir' => true])->render());

            return;
        }

        $ke = 0;
        foreach ($rows->chunk($perBagian) as $potong) {
            $ke++;
            $mpdf->WriteHTML(view($view, array_merge($data, [
                'rows'           => $potong->values(),
                'bagianPertama'  => $ke === 1,
                'bagianTerakhir' => $ke * $perBagian >= $jumlah,
            ]))->render());
            unset($potong);
        }
    }

    public function preview(string $view, array $data = [], array $opt = []): Response
    {
        return $this->respond($this->make($view, $data, $opt), $data, Destination::INLINE);
    }

    public function download(string $view, array $data = [], array $opt = []): Response
    {
        return $this->respond($this->make($view, $data, $opt), $data, Destination::DOWNLOAD);
    }

    private function respond(Mpdf $mpdf, array $data, string $mode): Response
    {
        $filename = ($data['title'] ?? 'laporan') . '.pdf';
        $disposition = $mode === Destination::DOWNLOAD ? 'attachment' : 'inline';

        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
        ]);
    }

    /**
     * Info perusahaan utk header laporan (tabel legacy `ainfo`, SELALU 1 baris, pola
     * SAMA CI3 `M_Settings_Info::infoPerusahaan()`).
     *
     * @return array{nama:string,alamat:string,telepon:string,email:string}
     */
    public function companyInfo(): array
    {
        $row = DB::table('ainfo')->first();

        return [
            'nama'    => $row->inama ?? '',
            'alamat'  => trim(($row->ialamat1 ?? '') . ' ' . ($row->ikota ?? '') . ' ' . ($row->ipropinsi ?? '')),
            'telepon' => $row->itelepon1 ?? '',
            'email'   => $row->iemail ?? '',
        ];
    }
}
