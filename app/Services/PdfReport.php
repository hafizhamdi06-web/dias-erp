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
     * @param array{size?:string,orientasi?:string,marginLeft?:float,marginTop?:float,marginBottom?:float} $opt
     *        size: A4/A3/Letter/Legal (default A4). orientasi: P/L (default P) - konvensi
     *        sama CI3 `areport.ARPAPERSIZE`/`ARPAPERORINTED`.
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
        ini_set('pcre.backtrack_limit', '5000000');

        // Render tabel besar (ribuan baris - mis. laporan "semua item" tanpa filter) lewat
        // mpdf MEMAKAN MEMORI JAUH lbh besar drpd ukuran HTML mentahnya (parsing tiap sel
        // jadi objek internal) - ditemukan nyata 2026-09-23: 2.750 baris SAJA (2 hari data)
        // sudah menghabiskan default 128M. Dinaikkan scope-per-request (TIDAK ubah php.ini
        // global, TIDAK pengaruhi request lain) - kalau laporan lain ternyata masih OOM di
        // limit ini, naikkan lagi di sini (bukan di controller masing2).
        ini_set('memory_limit', '512M');

        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf;
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
