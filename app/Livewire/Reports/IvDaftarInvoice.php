<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IP IV Daftar Invoice"** - port VB6 **menu 821**
 * (`amenu.MNAMA` = "IP IV Daftar Invoice"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvDaftarInvoice extends IvLaporanBase
{
    public function judul(): string
    {
        return 'IP IV Daftar Invoice';
    }

    protected function routeName(): string
    {
        return 'reports.iv-daftar-invoice';
    }
}
