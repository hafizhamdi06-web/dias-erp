<?php

namespace App\Livewire\Reports;

/**
 * Tab filter laporan **"Daftar IV Mutasi Detail"** - port VB6 **menu 819**
 * (`amenu.MNAMA` = "Daftar IV Mutasi Detail"). Filter & perilakunya di `IvLaporanBase`;
 * query-nya di `ReportController`.
 */
class IvmMutasiDetail extends IvLaporanBase
{
    public function judul(): string
    {
        return 'Daftar IV Mutasi Detail';
    }

    protected function routeName(): string
    {
        return 'reports.ivm-mutasi-detail';
    }
}
