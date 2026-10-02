<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Models\User;
use App\Services\PkbWriter;
use App\Services\SjWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tab "Surat Jalan (SJ)" - daftar fstoku (SUSUMBER='SJ'). SJ "menarik" sisa qty dari PKB
 * yg belum batal - lihat modal picker di bawah, pola sama VB6 asli
 * `fFrmSuratJalanDepo_CL.frm::cmdCariSJOnline_Click` (jalur customer-PO di file yg sama
 * SENGAJA tidak direplikasi, di luar cakupan alur PR→PKB→SJ→PBC).
 */
class SjList extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fStatus = '';
    public string $fCabang = '';
    public string $fFrom = '';
    public string $fTo = '';

    // modal picker "Tarik dari PKB"
    public bool $showPicker = false;
    public string $pickerQ = '';

    /**
     * Batas baris PKB yg dirender di modal pemilih. TANPA batas, 342 PKB di data nyata membuat
     * satu respons Livewire jadi **196 KB** (vs 3,9 KB saat pemilih tertutup) - di jaringan
     * klinik itu rapuh & lambat, dan dilaporkan user sbg "Failed to fetch" saat menarik PKB.
     * User mempersempit dgn mengetik di kotak cari (penyaringannya di SQL, lihat
     * `PkbWriter::pullableForSj()`).
     */
    private const BATAS_PKB = 25;

    public function mount(): void
    {
        $this->fFrom = now()->startOfMonth()->toDateString();
        $this->fTo = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->fCabang = Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

    private function allowedBranchIds(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->branchIds();
    }

    private function branchOptions()
    {
        $allowed = $this->allowedBranchIds();

        return $allowed !== []
            ? Branch::active()->whereIn('GID', $allowed)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA'])
            : Branch::options();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFStatus(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFFrom(): void { $this->resetPage(); }
    public function updatingFTo(): void { $this->resetPage(); }

    public function openPicker(): void
    {
        if (! can_do('sales/sj', 'add')) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pickPkb(int $pkbId, string $nomor): void
    {
        if (! can_do('sales/sj', 'add')) {
            return;
        }
        $this->showPicker = false;
        $this->dispatch('open-tab', cmp: 'purchase.sj-form', args: ['pkbId' => $pkbId],
            label: 'SJ dari ' . $nomor, icon: 'fas fa-truck-fast');
    }

    public function editSj(int $id, string $nomor): void
    {
        $this->dispatch('open-tab', cmp: 'purchase.sj-form', args: ['sjId' => $id],
            label: 'SJ: ' . $nomor, icon: 'fas fa-truck-fast');
    }

    #[On('sj-saved')]
    public function onSaved(): void
    {
        // re-render
    }

    /**
     * Batalkan SJ - SOFT (fstokd.SDCANCEL=1 + fstoku.SUSTATUS=9, lihat SjWriter::cancel()
     * utk detail pembalikan stok/PBDQTYTERIMA/PKBDQTYPAKAI/status PR).
     */
    public function cancel(int $id, SjWriter $writer): void
    {
        if (! can_do('sales/sj', 'delete')) {
            return;
        }

        $h = $writer->header($id);
        if (! $h || (int) $h->SUSTATUS === SjWriter::STATUS_BATAL) {
            session()->flash('error', 'SJ ini sudah dibatalkan.');

            return;
        }

        if ($writer->cancel($id)) {
            activity_log('cancel', 'sales/sj', $h->SUNOTRANSAKSI, 'Batalkan SJ ' . $h->SUNOTRANSAKSI);
            session()->flash('status', 'SJ dibatalkan.');
        }
    }

    public function render()
    {
        $q = trim($this->search);
        $allowed = $this->allowedBranchIds();

        $pullable = $this->showPicker
            ? app(PkbWriter::class)->pullableForSj($this->pickerQ, self::BATAS_PKB + 1)
            : collect();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bgudang as gc', 'gc.GID', '=', 'u.SUCABANG')
            ->leftJoin('bgudang as gt', 'gt.GID', '=', 'u.SUGUDANGTUJUAN')
            ->leftJoin('fperintahkirimbarangu as pkb', 'pkb.PKBUID', '=', 'u.SUNOSO')
            ->where('u.SUSUMBER', SjWriter::SUMBER)
            // Selalu dibatasi cabang yg BOLEH diakses user (defense-in-depth) - SUCABANG
            // = cabang PEMBUAT SJ (gudang pengirim), konsep sama SUCABANG PosDataList.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('u.SUNOTRANSAKSI', 'like', "%{$q}%")
                ->orWhere('k.KNAMA', 'like', "%{$q}%")
                ->orWhere('pkb.PKBUNOTRANSAKSI', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('u.SUSTATUS', (int) $this->fStatus))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fFrom !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fFrom))
            ->when($this->fTo !== '', fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTo))
            ->orderByDesc('u.SUID')
            ->paginate(20, [
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal',
                'u.SUSTATUS as status', 'k.KNAMA as kontak', 'gc.GNAMA as cabangKirim',
                'gt.GNAMA as cabangTujuan', 'pkb.PKBUNOTRANSAKSI as noPkb',
            ]);

        return view('livewire.purchase.sj-list', [
            'rows'      => $rows,
            'branches'  => $this->branchOptions(),
            // Pemilih PKB DIBATASI - lihat docblock `PkbWriter::pullableForSj()`. Diambil
            // `BATAS_PKB + 1` baris supaya bisa tahu "masih ada lagi" tanpa query COUNT
            // terpisah; baris lebihnya dibuang sebelum dirender.
            'pullable'  => $pullable->take(self::BATAS_PKB),
            'pullableAdaLagi' => $pullable->count() > self::BATAS_PKB,
            'pullableBatas'   => self::BATAS_PKB,
        ]);
    }
}
