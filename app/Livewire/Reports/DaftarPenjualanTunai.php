<?php

namespace App\Livewire\Reports;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab filter laporan POS-IP "Daftar Penjualan Tunai" - port dari CI3. Pola SAMA
 * `IpTindakanProduk`: dua tombol yg dispatch event browser (`report-pdf-ready` /
 * `report-excel-download`), URL-nya route BIASA `ReportController`, bukan Livewire.
 *
 * Filter mengikuti CI3: Tanggal s/d Tanggal, Cabang, Jenis Merchant (opsional).
 * **Filter Kontak yg ada di CI3 TIDAK dibuat** - butuh pencarian `bkontak` (315rb+ baris)
 * lewat `<x-search-select>`; belum ada permintaannya, gampang ditambah kalau perlu.
 *
 * Default rentang **hari ini s/d hari ini** - sesuai pemakaian laporan ini (rekap kas harian
 * per cabang), dan sama dgn contoh PDF yg dikirim user (1 hari).
 */
class DaftarPenjualanTunai extends Component
{
    public ?string $tabKey = null;

    public string $from = '';
    public string $to = '';
    public ?int $cabang = null;
    public string $merchant = '';

    public function mount(): void
    {
        $this->from = now()->toDateString();
        $this->to = now()->toDateString();

        // ATURAN APP (user 2026-09-28): filter Cabang di SEMUA laporan SELALU default ke
        // cabang AKTIF user - lihat catatan panjang di `IpTindakanProduk::mount()`.
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

        $this->dispatch('report-pdf-ready', url: route('reports.daftar-penjualan-tunai', $this->urlParams()));
    }

    public function unduhExcel(): void
    {
        $this->validate();

        $this->dispatch('report-excel-download', url: route('reports.daftar-penjualan-tunai.excel', $this->urlParams()));
    }

    private function urlParams(): array
    {
        return array_filter([
            'from'     => $this->from,
            'to'       => $this->to,
            'cabang'   => $this->cabang,
            'merchant' => $this->merchant,
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
        return view('livewire.reports.daftar-penjualan-tunai', [
            'branches'  => $this->branchOptions(),
            // `fstoku.SUMERCHANTJENIS` menyimpan KODE teks (MCKODE), bukan MCID - pola sama
            // dgn POS (lihat docblock `$pay['merchant']` di `PosTerminal`).
            'merchants' => DB::table('bmerchant')->orderBy('MCNAMA')->get(['MCKODE', 'MCNAMA']),
        ]);
    }
}
