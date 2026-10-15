<?php

namespace App\Livewire\Master;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Data Harga Marketplace (permintaan user 2026-10-15) - utk PENGECEKAN & PERUBAHAN harga
 * khusus marketplace `bitem2.I2HARGAJUALMP`, yg dipakai POS cabang Marketplace (lihat
 * `PosTerminal::kolomHarga()`).
 *
 * HANYA kolom harga marketplace yg bisa diubah - harga jual 1 tampil sbg pembanding saja.
 * Simpan mengikuti CI3 `M_Master_Item_POS::updateHargaMp()`: UPDATE `bitem2` kalau baris
 * item sudah ada, INSERT kalau belum, dan catat ke `aauserlog` (harga lama -> baru). Kalau
 * nilainya sama dgn yg tersimpan, TIDAK ada yg ditulis (permintaan user: "hanya mengupdate
 * jika memang ada perubahan").
 */
#[Layout('layouts.app')]
#[Title('Data Harga Marketplace')]
class HargaMarketplace extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    /** '' semua | 'kosong' belum ada harga MP | 'beda' beda dgn harga jual 1 | 'sama' */
    public string $fStatus = '';
    /** Default hanya item yg boleh dijual di cabang Marketplace (`bitem.ICABANG`). */
    public bool $hanyaMarketplace = true;

    /** Baris yg sedang diubah (`bitem.IID`) & nilai isiannya. */
    public ?int $editId = null;
    public float $editHarga = 0;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'fStatus', 'hanyaMarketplace'], true)) {
            $this->resetPage();
            $this->batal();
        }
    }

    public function ubah(int $id): void
    {
        abort_unless(can_do('master/harga-mp', 'edit'), 403);
        $this->editId = $id;
        $this->editHarga = $this->hargaMp($id);
    }

    public function batal(): void
    {
        $this->editId = null;
        $this->editHarga = 0;
    }

    public function simpan(): void
    {
        abort_unless(can_do('master/harga-mp', 'edit'), 403);
        if (! $this->editId) {
            return;
        }

        $this->validate(['editHarga' => ['required', 'numeric', 'min:0']], [], ['editHarga' => 'Harga marketplace']);

        $item = DB::table('bitem')->where('IID', $this->editId)->first(['IID', 'IKODE', 'INAMA']);
        if (! $item) {
            $this->dispatch('toast', message: 'Item tidak ditemukan.', type: 'error');
            $this->batal();

            return;
        }

        $lama = $this->hargaMp($item->IID);
        $baru = round((float) $this->editHarga, 2);

        if (abs($lama - $baru) < 0.005) {
            $this->dispatch('toast', message: 'Tidak ada perubahan harga - tidak disimpan.', type: 'info');
            $this->batal();

            return;
        }

        $user = auth()->user();
        $ket = 'Update Harga MP: ' . $item->IKODE . ' ' . $item->INAMA
            . ' | Lama: ' . number_format($lama, 0, ',', '.')
            . ' -> Baru: ' . number_format($baru, 0, ',', '.');

        DB::transaction(function () use ($item, $baru, $user, $ket) {
            // UPDATE semua baris item itu - `bitem2` punya segelintir baris ganda per item,
            // kalau cuma satu yg diubah, `MAX()` di POS bisa tetap membaca nilai lama.
            $ada = DB::table('bitem2')->where('I2IDITEM', $item->IID)->update(['I2HARGAJUALMP' => $baru]);
            if (! $ada && ! DB::table('bitem2')->where('I2IDITEM', $item->IID)->exists()) {
                DB::table('bitem2')->insert(['I2IDITEM' => $item->IID, 'I2HARGAJUALMP' => $baru]);
            }

            // Log legacy dipakai bersama CI3 - teksnya disamakan dgn CI3 supaya riwayat
            // perubahan dari kedua aplikasi terbaca seragam.
            DB::table('aauserlog')->insert([
                'ULUSER'     => (int) $user->UID,
                'ULUSERNAME' => $user->UNAMA ?: $user->UKODE,
                'ULCOMPUTER' => (string) request()->ip(),
                'ULDATE'     => now(),
                'ULTIME'     => now(),
                'ULACTIVITY' => mb_substr($ket, 0, 255),
                'ULLEVEL'    => 2,
            ]);
        });

        activity_log('update', 'master/harga-mp', (string) $item->IKODE, $ket);
        $this->dispatch('toast', message: 'Harga marketplace ' . $item->IKODE . ' disimpan.', type: 'success');
        $this->batal();
    }

    private function hargaMp(int $id): float
    {
        return (float) DB::table('bitem2')->where('I2IDITEM', $id)->max('I2HARGAJUALMP');
    }

    private function query()
    {
        $q = trim($this->search);
        // `MAX()` per item - `bitem2` bisa berbaris ganda (lihat `PosTerminal::kolomHarga()`).
        $mp = DB::table('bitem2')->groupBy('I2IDITEM')
            ->select('I2IDITEM', DB::raw('MAX(I2HARGAJUALMP) as hargaMp'));
        $gid = (int) config('pos.cabang_marketplace');

        return DB::table('bitem as i')
            ->leftJoinSub($mp, 'b2', 'b2.I2IDITEM', '=', 'i.IID')
            ->where('i.ISTATUS', 0)
            // Pola sama `PosTerminal::applyIcabangFilter()`.
            ->when($this->hanyaMarketplace, fn ($b) => $b->whereRaw('i.ICABANG REGEXP ?', ['[|\r\n]' . $gid . '\|']))
            ->when($this->fStatus === 'kosong', fn ($b) => $b->whereRaw('COALESCE(b2.hargaMp, 0) = 0'))
            ->when($this->fStatus === 'beda', fn ($b) => $b->whereRaw('COALESCE(b2.hargaMp, 0) > 0 AND b2.hargaMp <> i.IHARGAJUAL1'))
            ->when($this->fStatus === 'sama', fn ($b) => $b->whereRaw('b2.hargaMp = i.IHARGAJUAL1'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('i.IKODE', 'like', "%{$q}%")
                ->orWhere('i.INAMA', 'like', "%{$q}%")
                ->orWhere('i.IBARCODE', 'like', "%{$q}%")));
    }

    public function render()
    {
        abort_unless(can_do('master/harga-mp', 'view'), 403);

        return view('livewire.master.harga-marketplace', [
            'rows' => $this->query()->orderBy('i.INAMA')->paginate(50, [
                'i.IID as id', 'i.IKODE as kode', 'i.INAMA as nama',
                'i.IHARGAJUAL1 as hargaJual1', DB::raw('COALESCE(b2.hargaMp, 0) as hargaMp'),
            ]),
            'bisaUbah' => can_do('master/harga-mp', 'edit'),
        ]);
    }
}
