<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Isi tab "Dashboard" pada Workspace (bukan full-page).
 */
class DashboardPane extends Component
{
    /** Diteruskan Workspace ke setiap child; tidak dipakai di sini. */
    public ?string $tabKey = null;

    public function render()
    {
        $stats = [
            'user_aktif' => (int) DB::table('auser')->where('UACTIVE', 1)->count(),
            'item_aktif' => (int) DB::table('bitem')->where('ISTATUS', '<>', 0)->count(),
            'pelanggan'  => (int) DB::table('bkontak')->where('KAKTIF', '<>', 0)->count(),
        ];

        return view('livewire.dashboard-pane', ['stats' => $stats, 'today' => now()]);
    }
}
