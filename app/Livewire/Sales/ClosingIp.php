<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Services\AlkesWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Closing Harian IP (permintaan user 2026-10-15): baris TINDAKAN di transaksi IP yg alkesnya
 * BELUM diinput - dicek sebelum tutup hari supaya stok alkes tidak tertinggal.
 *
 * "Tindakan" = item `IADAALKES = 1`, dibatasi juga ke aturan grid tindakan `AlkesForm`
 * (`JENIS_TINDAKAN` + `IRESEP = 0`) supaya tiap baris di sini PASTI muncul di form saat
 * tombol Input Alkes ditekan. Diukur 2026-10-15: alkes HANYA pernah diinput utk item
 * `IADAALKES = 1` (394 dari 497 baris Juni); item tindakan lain (resep, IADAALKES = 0)
 * tidak pernah punya alkes, jadi kalau ikut ditampilkan daftarnya penuh baris yg memang
 * tidak perlu diapa-apakan.
 *
 * "Belum diinput" memakai aturan yg SAMA dgn `AlkesWriter::fromIp()`: `SDIDALKESNYA` kosong
 * atau menunjuk dokumen AL yg sudah batal. Begitu alkes disimpan, baris hilang dari sini.
 *
 * Baris ber-qty 0 dibuang - tindakannya tidak dikerjakan (mis. baris paket), jadi tidak
 * ada alkes yg terpakai.
 */
#[Layout('layouts.app')]
#[Title('Closing Harian IP')]
class ClosingIp extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $dari = '';
    public string $sampai = '';
    public string $search = '';
    /** `''` = semua cabang yg boleh dilihat. Default cabang aktif user. */
    public string $cabang = '';

    public function mount(): void
    {
        $this->dari = now()->toDateString();
        $this->sampai = now()->toDateString();
        $this->cabang = (string) (auth()->user()->UCABANG ?? '');
    }

    public function updatingCabang(): void
    {
        $this->resetPage();
    }

    public function updatingDari(): void
    {
        $this->resetPage();
    }

    public function updatingSampai(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** Buka form Input Alkes untuk transaksi IP itu - pola sama `AlkesList::pilihIp()`. */
    public function inputAlkes(int $ipId): void
    {
        abort_unless(can_do('sales/alkes', 'add'), 403);
        $this->dispatch('open-tab', cmp: 'sales.alkes-form', args: ['ipId' => $ipId],
            label: 'Input Alkes', icon: 'fas fa-syringe');
    }

    #[On('alkes-saved')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    /**
     * Pilihan cabang = cabang yg boleh dilihat user (`visibleBranchIds()`, `[]` = tanpa
     * batas utk super user) - pola sama `LaporanFilterBase::branchOptions()`.
     */
    private function branchOptions()
    {
        $boleh = auth()->user()->visibleBranchIds();

        return $boleh !== []
            ? Branch::active()->whereIn('GID', $boleh)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    private function query()
    {
        $q = trim($this->search);
        $boleh = auth()->user()->visibleBranchIds();
        // Pilihan dari dropdown tetap diperiksa lagi di sini - nilai `cabang` bisa
        // dikirim manual lewat request Livewire, tidak harus dari opsi yg tampil.
        $pilih = (int) $this->cabang;
        if ($pilih && $boleh !== [] && ! in_array($pilih, $boleh, true)) {
            $pilih = 0;
        }

        return DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('fstoku as al', 'al.SUID', '=', 'd.SDIDALKESNYA')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'd.SDDOKTER')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', AlkesWriter::STATUS_BATAL)
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->when($pilih, fn ($b) => $b->where('u.SUCABANG', $pilih),
                fn ($b) => $b->when($boleh !== [], fn ($w) => $w->whereIn('u.SUCABANG', $boleh)))
            ->where('i.IADAALKES', 1)
            ->whereIn('i.IJENISITEM', AlkesWriter::JENIS_TINDAKAN)
            ->where('i.IRESEP', 0)
            ->where('d.SDKELUAR', '>', 0)
            ->where(fn ($b) => $b->whereNull('al.SUID')->orWhere('al.SUSTATUS', AlkesWriter::STATUS_BATAL))
            ->when($this->dari !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->dari))
            ->when($this->sampai !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->sampai))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('i.INAMA', 'like', "%{$q}%")));
    }

    public function render()
    {
        abort_unless(can_do('sales/closing-ip', 'view'), 403);

        $rows = $this->query()
            ->orderBy('u.SUTANGGAL')
            ->orderBy('u.SUNOTRANSAKSI')
            ->orderBy('d.SDURUTAN')
            ->paginate(50, [
                'u.SUID as ipId', 'u.SUNOTRANSAKSI as noIp', 'u.SUTANGGAL as tanggal',
                'k.KNAMA as pasien', 'i.IKODE as kode', 'i.INAMA as tindakan',
                'd.SDID as sdid', 'd.SDKELUAR as qty', 'dr.KNAMA as dokter',
                'u.SUCABANG as cabangId', 'g.GNAMA as cabang',
            ]);

        $ringkas = $this->query()
            ->first([DB::raw('COUNT(*) as baris'), DB::raw('COUNT(DISTINCT u.SUID) as ip')]);

        return view('livewire.sales.closing-ip', [
            'rows'     => $rows,
            'ringkas'  => $ringkas,
            'branches' => $this->branchOptions(),
            // Form Input Alkes hanya menerima IP cabang AKTIF user (`AlkesWriter::fromIp()`),
            // jadi tombolnya hanya muncul utk baris cabang itu.
            'cabangAktif' => (int) (auth()->user()->UCABANG ?? 0),
        ]);
    }
}
