<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IVM Daftar Invoice Mutasi"** - port VB6 **menu 822**
 * (`amenu.MNAMA` = "IVM Daftar Invoice Mutasi"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvmDaftarInvoice extends IvLaporanBase
{
    public function judul(): string
    {
        return 'IVM Daftar Invoice Mutasi';
    }

    protected function routeName(): string
    {
        return 'reports.ivm-daftar-invoice';
    }
}
