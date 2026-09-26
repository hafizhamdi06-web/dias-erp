<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Data POS - daftar SEMUA transaksi POS (`fstoku` SUSUMBER='IP'), lintas kasir & cabang, utk
 * Manager memantau keseluruhan transaksi. BEDA dari "Riwayat Hari Ini" di `PosTerminal` yg
 * dibatasi ketat ke kasir yg login & hari ini saja (`todayHistory()`) - ini murni READ-ONLY,
 * tidak ada edit/hapus disini (edit transaksi tetap lewat POS, dibatasi pemilik/hari yg sama
 * per desain yg sudah ada, sengaja tidak dibuka disini).
 */
#[Layout('layouts.app')]
#[Title('Data POS')]
class PosDataList extends Component
{
    use WithPagination;

    public string $fNomor = '';
    public string $fPasien = '';
    public string $fStatus = '1'; // '' = semua, '1' = aktif (default), '0' = batal
    public string $fCabang = '';
    /** Default HARI INI s/d HARI INI (beda dari konvensi bulan-berjalan di PromoManager/
     *  PaketManager) - transaksi POS bisa sangat banyak dalam 1 hari, per permintaan user
     *  2026-09-16. "Hapus filter" mengosongkan total (bukan kembali ke default). */
    public ?string $fTanggalDari = null;
    public ?string $fTanggalSampai = null;

    public function mount(): void
    {
        $this->fTanggalDari = now()->toDateString();
        $this->fTanggalSampai = now()->toDateString();

        // Default cabang = cabang aktif user (auser.UCABANG) - resolusi SAMA PERSIS spt
        // PosTerminal::mount(), per permintaan user 2026-09-16 ("default cabang aktif").
        /** @var User $user */
        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->fCabang = Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

    /**
     * Cabang yg boleh dipilih user ini (`auser.UCABANGPILIH` via `User::branchIds()`) - KOSONG
     * (NULL di DB) berarti TIDAK dibatasi, boleh semua cabang aktif (konvensi yg sama dipakai
     * di seluruh app, mis. admin4 UCABANGPILIH=NULL -> semua cabang). Per permintaan user
     * 2026-09-16 ("filter cabang berisi cabang user pilihan").
     */
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
            ? Branch::active()->whereIn('GID', $allowed)->orderBy('GNAMA')->get(['GID', 'GKODE', 'GNAMA', 'GALAMAT1'])
            : Branch::options();
    }

    public function updatingFNomor(): void
    {
        $this->resetPage();
    }

    public function updatingFPasien(): void
    {
        $this->resetPage();
    }

    public function updatingFStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFCabang(): void
    {
        $this->resetPage();
    }

    public function updatingFTanggalDari(): void
    {
        $this->resetPage();
    }

    public function updatingFTanggalSampai(): void
    {
        $this->resetPage();
    }

    public function clearTanggalFilter(): void
    {
        $this->fTanggalDari = null;
        $this->fTanggalSampai = null;
        $this->resetPage();
    }

    /* ---------------- Modal Detail (baris fstokd, murni tampilan) ---------------- */

    public bool $showDetailModal = false;
    public ?int $detailSuid = null;
    public ?string $detailNomor = null;
    public ?string $detailPasien = null;

    public function openDetailModal(int $suid): void
    {
        $header = DB::table('fstoku as u')
            ->leftJoin('bkontak as p', 'p.KID', '=', 'u.SUKONTAK')
            ->where('u.SUID', $suid)
            ->first(['u.SUNOTRANSAKSI', 'p.KNAMA as pasien']);

        if (! $header) {
            return;
        }

        $this->detailSuid = $suid;
        $this->detailNomor = $header->SUNOTRANSAKSI;
        $this->detailPasien = $header->pasien;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->detailSuid = null;
        $this->detailNomor = null;
        $this->detailPasien = null;
    }

    /**
     * Baris `fstokd` transaksi terpilih - kode/nama (join bitem), dokter/operator (join bkontak
     * 2x), label Promo (emasterpromod->emasterpromou via SDIDPROMO) & Paket (epaketu via
     * SDIDPOTONGSTOK) - pola resolusi label SAMA PERSIS spt `PosTerminal::editTransaction()`.
     * Subtotal dihitung dgn RUMUS SAMA spt form POS (`PosTerminal::checkout()`): qty x harga x
     * (1-dis1%) x (1-dis2%) - pakai dis1/dis2 yg SUDAH TERSIMPAN apa adanya (nilai final saat
     * transaksi itu dibuat, termasuk diskon member yg sudah "dibakukan" saat itu), TIDAK dihitung
     * ulang dari aturan member SEKARANG (ini tampilan histori, bukan re-kalkulasi transaksi baru).
     */
    private function detailLines(int $suid)
    {
        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bkontak as op', 'op.KID', '=', 'd.SDKARYAWAN')
            ->leftJoin('bkontak as dok', 'dok.KID', '=', 'd.SDDOKTER')
            ->where('d.SDIDSU', $suid)
            ->orderBy('d.SDURUTAN')
            ->get([
                'i.IKODE', 'i.INAMA', 'd.SDKELUAR', 'd.SDHARGA', 'd.SDDISKONPERSEN', 'd.SDDISKONPERSEN2',
                'op.KNAMA as operator', 'dok.KNAMA as dokter',
                'd.SDIDPROMO', 'd.SDIDPOTONGSTOK', 'd.SDCATATANKOLI', 'd.SDKEDATANGAN',
            ]);

        $mpdIds = $lines->pluck('SDIDPROMO')->filter()->unique()->values()->all();
        $promoLabelMap = $mpdIds !== []
            ? DB::table('emasterpromod as d')->join('emasterpromou as u', 'u.MPUID', '=', 'd.MPDIDU')
                ->whereIn('d.MPDID', $mpdIds)->pluck('u.MPUKODE', 'd.MPDID')
            : collect();

        $puIds = $lines->pluck('SDIDPOTONGSTOK')->filter()->unique()->values()->all();
        $paketLabelMap = $puIds !== []
            ? DB::table('epaketu')->whereIn('PUID', $puIds)->pluck('PUKODE', 'PUID')
            : collect();

        return $lines->map(function ($l) use ($promoLabelMap, $paketLabelMap) {
            $harga = (float) $l->SDHARGA;
            $qty = (float) $l->SDKELUAR;
            $d1 = (float) $l->SDDISKONPERSEN;
            $d2 = (float) $l->SDDISKONPERSEN2;
            $subtotal = $qty * $harga * (1 - $d1 / 100) * (1 - $d2 / 100);

            return (object) [
                'kode'          => $l->IKODE,
                'nama'          => $l->INAMA,
                'qty'           => $qty,
                'harga'         => $harga,
                'dis1'          => $d1,
                'dis2'          => $d2,
                'subtotal'      => $subtotal,
                'operator'      => $l->operator,
                'dokter'        => $l->dokter,
                'promo_label'   => $l->SDIDPROMO ? ($promoLabelMap[(int) $l->SDIDPROMO] ?? null) : null,
                'paket_label'   => $l->SDIDPOTONGSTOK ? ($paketLabelMap[(int) $l->SDIDPOTONGSTOK] ?? null) : null,
                'sdcatatankoli' => $l->SDCATATANKOLI,
                'sdkedatangan'  => $l->SDKEDATANGAN,
            ];
        });
    }

    public function render()
    {
        $nomor = trim($this->fNomor);
        $pasien = trim($this->fPasien);
        $allowed = $this->allowedBranchIds();

        $rows = DB::table('fstoku as u')
            ->leftJoin('bkontak as p', 'p.KID', '=', 'u.SUKONTAK')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKARYAWAN')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', 'IP')
            // Selalu dibatasi cabang yg BOLEH diakses user (defense-in-depth, bukan cuma
            // batasan tampilan dropdown $fCabang) - kosong ($allowed=[]) = tidak dibatasi.
            ->when($allowed !== [], fn ($b) => $b->whereIn('u.SUCABANG', $allowed))
            ->when($nomor !== '', fn ($b) => $b->where('u.SUNOTRANSAKSI', 'like', "%{$nomor}%"))
            // bkontak 315rb+ baris - JANGAN LIKE '%q%' polos di KNAMA, wajib lewat
            // Contact::applySearch() (FULLTEXT idx_ft_knama) - lihat aturan proyek.
            ->when($pasien !== '', fn ($b) => Contact::applySearch($b, $pasien, 'p'))
            ->when($this->fStatus !== '', fn ($b) => $this->fStatus === '1'
                ? $b->where('u.SUSTATUS', '<>', 9)
                : $b->where('u.SUSTATUS', 9))
            ->when($this->fCabang !== '', fn ($b) => $b->where('u.SUCABANG', $this->fCabang))
            ->when($this->fTanggalDari, fn ($b) => $b->whereDate('u.SUTANGGAL', '>=', $this->fTanggalDari))
            ->when($this->fTanggalSampai, fn ($b) => $b->whereDate('u.SUTANGGAL', '<=', $this->fTanggalSampai))
            ->orderByDesc('u.SUID')
            ->paginate(25, [
                'u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUTOTALTRANSAKSI', 'u.SUSTATUS',
                'p.KNAMA as pasien', 'k.KNAMA as kasir', 'g.GNAMA as cabang',
            ]);

        return view('livewire.sales.pos-data-list', [
            'rows'        => $rows,
            'branches'    => $this->branchOptions(),
            'detailLines' => $this->showDetailModal && $this->detailSuid ? $this->detailLines($this->detailSuid) : collect(),
        ]);
    }
}
