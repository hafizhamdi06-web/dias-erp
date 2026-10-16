<?php

namespace App\Livewire\Panduan;

use Livewire\Component;

/**
 * Panduan > Kasir / POS (permintaan user 2026-10-16) - cara pakai POS sehari-hari utk kasir.
 *
 * Label, tombol pintas & pesan DISALIN dari `Sales\PosTerminal` + blade-nya. Kalau POS
 * berubah (tombol pintas, alur bayar, dst), PERBARUI panduan ini juga.
 */
class Pos extends Component
{
    public ?string $tabKey = null;

    public function render()
    {
        abort_unless(can_do('panduan/pos', 'view'), 403);

        return view('livewire.panduan.pos');
    }
}
