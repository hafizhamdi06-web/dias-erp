<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"IP IV Surat Jalan"** - port VB6 **menu 780**
 * (`amenu.MNAMA` = "IP IV Surat Jalan"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvSuratJalan extends IvLaporanBase
{
    public function judul(): string
    {
        return 'IP IV Surat Jalan';
    }

    protected function routeName(): string
    {
        return 'reports.iv-surat-jalan';
    }
}
