<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"Daftar Invoice Mutasi"** - port VB6 **menu 788**
 * (`amenu.MNAMA` = "Daftar Invoice Mutasi"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvmDaftarInvoiceMutasi extends IvLaporanBase
{
    public function judul(): string
    {
        return 'Daftar Invoice Mutasi';
    }

    protected function routeName(): string
    {
        return 'reports.ivm-daftar-invoice-mutasi';
    }
}
