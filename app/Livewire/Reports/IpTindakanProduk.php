<?php

namespace App\Livewire\Reports;

use App\Models\Branch;
use Livewire\Component;

/**
 * Tab filter "Laporan IP Tindakan/Produk Per Bulan" - port dari CI3. Pola SAMA
 * `PenjualanPerBarang`: dua tombol yg dispatch event browser (`report-pdf-ready` /
 * `report-excel-download`), URL-nya route BIASA `ReportController`, bukan Livewire.
 *
 * Filter sesuai versi CI3: **Tanggal s/d Tanggal + Cabang (opsional)** - tidak ada filter
 * item, karena laporan ini justru merekap SEMUA tindakan/produk.
 *
 * Default rentang **bulan berjalan** (bukan hari ini spt laporan per-barang) - laporan ini
 * dikelompokkan per bulan, rentang satu hari tidak ada gunanya.
 */
class IpTindakanProduk extends Component
{
    public ?string $tabKey = null;

    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();

        // ATURAN APP (user 2026-09-28): filter Cabang di SEMUA laporan SELALU default ke
        // cabang AKTIF user. `UCABANG` adalah accessor `User` yg sudah menghormati
        // "ganti cabang aktif" lewat session, jadi cukup baca itu - JANGAN dikondisikan
        // lagi ke `branchIds()` (perilaku lama: default "semua cabang" kalau user punya
        // banyak cabang, kebalikan dari yg diminta).
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
    }

    protected function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ];
    }

    public function tampilkanPdf(): void
    {
        $this->validate();

        $this->dispatch('report-pdf-ready', url: route('reports.ip-tindakan-produk', $this->urlParams()));
    }

    public function unduhExcel(): void
    {
        $this->validate();

        $this->dispatch('report-excel-download', url: route('reports.ip-tindakan-produk.excel', $this->urlParams()));
    }

    private function urlParams(): array
    {
        return array_filter([
            'from'   => $this->from,
            'to'     => $this->to,
            'cabang' => $this->cabang,
        ]);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    /**
     * Pilihan cabang dibatasi ke cabang yg boleh dilihat user (aturan user 2026-09-28).
     * Ini HANYA kenyamanan UI - penegaknya tetap `ReportController::cabangLaporan()`,
     * karena parameter `cabang` datang lewat URL dan bisa diketik manual.
     */
    protected function branchOptions()
    {
        $boleh = auth()->user()->visibleBranchIds();

        return $boleh !== []
            ? Branch::active()->whereIn('GID', $boleh)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    public function render()
    {
        return view('livewire.reports.ip-tindakan-produk', [
            'branches' => $this->branchOptions(),
        ]);
    }
}
