<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Services\ClosingHarianIp;
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
 *
 * Baris PAKET (`SDDARIPAKET <> 0`) hanya wajib alkes kalau `SDKEDATANGAN > 0` (permintaan
 * user 2026-10-15). Kedatangan ke-0 = baris pembelian paketnya, tindakannya baru dikerjakan
 * di kedatangan berikutnya. Terukur: dari 115 baris paket kedatangan 0, tidak satu pun
 * pernah diinput alkes. Penanda paketnya `SDDARIPAKET`, BUKAN `SDIDPOTONGSTOK` - baris yg
 * hanya punya `SDIDPOTONGSTOK` tetap rutin diinput alkes (115 baris), jadi tetap wajib.
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

    /** Saringan layar - dipakai kueri layar DAN URL ekspor, jadi keduanya selalu sama. */
    private function saring(): array
    {
        return ['dari' => $this->dari, 'sampai' => $this->sampai, 'cabang' => $this->cabang, 'search' => $this->search];
    }

    private function query()
    {
        return app(ClosingHarianIp::class)->query($this->saring());
    }

    /**
     * Unduh Excel - lewat route BIASA (`ReportController::closingIpExcel()`), pola sama
     * `Dashboard\Penjualan::unduhExcel()`: unduhan dari dalam Livewire mengganggu tab
     * Workspace lain. Yg diunduh SELURUH baris sesuai saringan, bukan halaman yg tampil.
     */
    public function unduhExcel(): void
    {
        $this->dispatch('report-excel-download',
            url: route('sales.closing-ip.excel', array_filter($this->saring(), fn ($v) => $v !== '' && $v !== null)));
    }

    public function render()
    {
        abort_unless(can_do('sales/closing-ip', 'view'), 403);

        $svc = app(ClosingHarianIp::class);
        $rows = $svc->urut($this->query())->paginate(50, ClosingHarianIp::KOLOM);

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
