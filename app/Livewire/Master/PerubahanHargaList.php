<?php

namespace App\Livewire\Master;

use App\Services\PerubahanHargaWriter as W;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar Perubahan Harga (`gperubahanhargau` SUSUMBER='PH'). Aturan datanya di docblock
 * `PerubahanHargaWriter`.
 *
 * **Harga jual `bitem` berlaku di SEMUA cabang**, jadi daftar ini SENGAJA tidak dibatasi
 * cabang user (beda dari dokumen stok) - `SUCABANG` cuma mencatat cabang pembuatnya.
 *
 * Penanda **"Jatuh tempo"**: Tgl Berlaku sudah tiba tapi dokumen belum diterapkan - harga
 * TIDAK berubah sendiri (tidak ada penjadwal), jadi ini pengingatnya.
 */
class PerubahanHargaList extends Component
{
    use WithPagination;

    public const ACL = 'master/perubahan-harga';

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fFrom = '';
    public string $fTo = '';

    public function mount(): void
    {
        abort_unless(can_do(self::ACL, 'view'), 403);
        $this->fFrom = now()->subMonths(3)->startOfMonth()->toDateString();
        $this->fTo = now()->addMonths(3)->endOfMonth()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'fStatus', 'fFrom', 'fTo'], true)) {
            $this->resetPage();
        }
    }

    public function newDoc(): void
    {
        abort_unless(can_do(self::ACL, 'add'), 403);
        $this->dispatch('open-tab', cmp: 'master.perubahan-harga-form',
            label: 'Perubahan Harga Baru', icon: 'fas fa-tags');
    }

    public function openDoc(int $id, string $nomor): void
    {
        abort_unless(can_do(self::ACL, 'view'), 403);
        $this->dispatch('open-tab', cmp: 'master.perubahan-harga-form', args: ['phId' => $id],
            label: 'PH: ' . $nomor, icon: 'fas fa-tags');
    }

    #[On('ph-saved')]
    public function onSaved(): void
    {
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('gperubahanhargau as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('auser as us', 'us.UID', '=', 'u.SUCREATEU')
            ->where('u.SUSUMBER', W::SUMBER)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('u.SUURAIAN', 'like', "%{$q}%")))
            ->when($this->fStatus === 'tempo', fn ($b) => $b
                ->where('u.SUSTATUS', '<>', W::STATUS_DITERAPKAN)
                ->where('u.SUTANGGALBERLAKU', '<=', now()->toDateString()))
            ->when($this->fStatus !== '' && $this->fStatus !== 'tempo',
                fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fFrom !== '', fn ($b) => $b->where('u.SUTANGGALBERLAKU', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->where('u.SUTANGGALBERLAKU', '<=', $this->fTo))
            ->orderByDesc('u.SUTANGGALBERLAKU')->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUTANGGALBERLAKU as berlaku', 'u.SUSTATUS as status', 'u.SUURAIAN as uraian',
                'u.SUNILAI as persen', 'k.KNAMA as kontak', 'us.UNAMA as pembuat',
                DB::raw('(SELECT COUNT(*) FROM gperubahanhargad d WHERE d.SDIDSU = u.SUID) as jmlItem'),
                DB::raw("(SELECT MAX(l.created_at) FROM lv_perubahan_harga_log l
                          WHERE l.suid = u.SUID AND l.aksi = 'terapkan') as diterapkan"),
            ]);

        return view('livewire.master.perubahan-harga-list', [
            'rows'    => $rows,
            'hariIni' => now()->toDateString(),
        ]);
    }
}
