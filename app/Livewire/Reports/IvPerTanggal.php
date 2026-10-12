<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IP IV Per Tanggal"** - port VB6 **menu 796**
 * (`amenu.MNAMA` = "IP IV Per Tanggal"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvPerTanggal extends IvLaporanBase
{
    public function judul(): string
    {
        return 'IP IV Per Tanggal';
    }

    protected function routeName(): string
    {
        return 'reports.iv-per-tanggal';
    }
}
