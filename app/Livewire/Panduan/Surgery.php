<?php

namespace App\Livewire\Panduan;

use Livewire\Component;

/**
 * Panduan > Transaksi Surgery (permintaan user 2026-10-16) - halaman statis utk kasir &
 * admin klinik, mengikuti tampilan Kasir/POS LARAVEL (`Sales\PosTerminal`).
 *
 * Label tombol & pesan kesalahan di panduan DISALIN dari POS. Kalau label/aturannya diubah
 * (`PosTerminal`, `App\Services\Surgery`), PERBARUI panduan ini juga - panduan yg tidak
 * cocok dgn layar lebih berbahaya daripada tidak ada panduan.
 */
class Surgery extends Component
{
    public ?string $tabKey = null;

    public function render()
    {
        abort_unless(can_do('panduan/surgery', 'view'), 403);

        return view('livewire.panduan.surgery');
    }
}
