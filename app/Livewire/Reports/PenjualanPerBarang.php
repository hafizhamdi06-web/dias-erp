<?php

namespace App\Livewire\Reports;

use App\Models\Branch;
use Livewire\Component;

/**
 * Tab filter "Laporan IP Per Barang" - form pilih item/tanggal/cabang, 2 tombol
 * aksi LANGSUNG (bukan set-URL-lalu-klik-link lagi): "Tampilkan PDF" dispatch event
 * browser `report-pdf-ready` (listener global di `dias-helpers.js` buka `window.open`
 * tab baru = preview), "Excel" dispatch `report-excel-download` (listener set
 * `window.location` = attachment header trigger download tanpa pindah halaman).
 * URL mengarah ke route BIASA (`ReportController`), bukan Livewire - lihat docblock
 * `PdfReport`. Pola event ini generik, dipakai ulang utk laporan berikutnya.
 */
class PenjualanPerBarang extends Component
{
    public ?string $tabKey = null;

    public ?int $item = null;
    public ?string $itemLabel = null;
    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;

    public function mount(): void
    {
        $this->from = now()->toDateString();
        $this->to = now()->toDateString();

        $user = auth()->user();
        $allowed = $user->branchIds();
        $this->cabang = $allowed === [] ? ((int) ($user->UCABANG ?? 0) ?: null) : null;
    }

    protected function rules(): array
    {
        return [
            'item' => ['nullable', 'integer'],
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ];
    }

    public function tampilkanPdf(): void
    {
        $this->validate();

        $this->dispatch('report-pdf-ready', url: route('reports.penjualan-per-barang', $this->urlParams()));
    }

    public function unduhExcel(): void
    {
        $this->validate();

        $this->dispatch('report-excel-download', url: route('reports.penjualan-per-barang.excel', $this->urlParams()));
    }

    private function urlParams(): array
    {
        return array_filter([
            'item'   => $this->item,
            'from'   => $this->from,
            'to'     => $this->to,
            'cabang' => $this->cabang,
        ]);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.reports.penjualan-per-barang', [
            'branches' => Branch::options(),
        ]);
    }
}
