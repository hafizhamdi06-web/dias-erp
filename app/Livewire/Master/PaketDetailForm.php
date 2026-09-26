<?php

namespace App\Livewire\Master;

use App\Models\Package;
use App\Models\PackageDetail;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab detail item paket (epaketd) - daftar item yg dibundel dlm 1 paket
 * (epaketu), dibuka dari PaketManager::openDetail(). Pola sama spt
 * PromoBiasaForm (list baris + modal create/edit per baris).
 *
 * PDQTY = qty kedatangan/sesi PERTAMA, PDQTYTINDAKAN = qty kedatangan ke-2 dst
 * (dikonfirmasi kode POS legacy `pos_2.js`) - relevan hanya kalau
 * epaketu.PUJUMLAH (jumlah kedatangan) > 1. PDHARGA/PDDISKON/PDDISKON2/
 * PDSUBTOTAL dihitung otomatis dari harga jual item saat ini x qty x diskon
 * persen (POS legacy TIDAK PERNAH membaca PDHARGA/PDDISKON/PDDISKON2/
 * PDSUBTOTAL tersimpan - selalu re-price live dari bitem - tapi tetap disimpan
 * di sini spy konsisten & berguna utk laporan/preview).
 */
class PaketDetailForm extends Component
{
    public ?string $tabKey = null;

    public int $packageId;
    public ?string $packageKode = null;
    public ?string $packageNama = null;

    public bool $showModal = false;
    public ?int $editingId = null;

    public ?int $item = null;
    public ?string $itemLabel = null;

    public ?float $qty = null;           // PDQTY - qty kedatangan pertama
    public ?float $qtyTindakan = null;   // PDQTYTINDAKAN - qty kedatangan ke-2 dst
    public ?float $harga = null;         // PDHARGA - auto dari harga jual saat pilih item, bisa diubah manual
    public ?float $diskonPersen1 = null;
    public ?float $diskonPersen2 = null;

    public array $pilihan = [];          // PDPILIHAN - kode item alternatif, pipe-delimited di DB
    public string $pilihanQ = '';

    public bool $cetak = true;           // PDCETAK
    public bool $harga0 = false;         // PDHARGA0 - paksa harga 0 (baris gratis/bonus)
    public ?float $jumlahPaket = null;   // PDJUMLAHPAKET

    public function mount(int $packageId): void
    {
        $p = Package::findOrFail($packageId);
        $this->packageId = $p->PUID;
        $this->packageKode = $p->PUKODE;
        $this->packageNama = $p->PUNAMA;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->qty = 1;
        $this->qtyTindakan = 1;
        $this->jumlahPaket = 1;
        $this->cetak = true;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $d = PackageDetail::where('PDIDU', $this->packageId)->findOrFail($id);

        $this->editingId = $d->PDID;
        $this->item = $d->PDITEM ?: null;
        $this->qty = $d->PDQTY;
        $this->qtyTindakan = $d->PDQTYTINDAKAN;
        $this->harga = $d->PDHARGA;
        $this->diskonPersen1 = $d->PDDISKONPERSEN1;
        $this->diskonPersen2 = $d->PDDISKONPERSEN2;
        $this->pilihan = $this->splitCodes($d->PDPILIHAN);
        $this->cetak = (int) $d->PDCETAK === 1;
        $this->harga0 = (int) $d->PDHARGA0 === 1;
        $this->jumlahPaket = $d->PDJUMLAHPAKET;

        $this->loadItemLabel();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    private function splitCodes(?string $v): array
    {
        $v = trim((string) $v);

        return $v === '' ? [] : array_values(array_filter(explode('|', $v)));
    }

    private function loadItemLabel(): void
    {
        $this->itemLabel = null;
        if ($this->item) {
            $row = DB::table('bitem')->where('IID', $this->item)->first(['IKODE', 'INAMA']);
            $this->itemLabel = $row ? trim($row->IKODE . ' — ' . $row->INAMA) : null;
        }
    }

    /** Dipanggil dari <x-search-select> saat item dipilih - auto-isi harga jual saat ini. */
    public function updatedItem(): void
    {
        $this->loadItemLabel();
        if ($this->item && $this->harga === null) {
            $this->harga = (float) (DB::table('bitem')->where('IID', $this->item)->value('IHARGAJUAL1') ?? 0);
        }
    }

    /** Hasil cari item utk "Pilihan" (alternatif item, multi-add, disimpan sbg kode). */
    private function pilihanSearchResults(string $q)
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        return DB::table('bitem')
            ->where('ISTATUS', 0)
            ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
            ->orderBy('IKODE')
            ->limit(15)
            ->get(['IKODE as kode', 'INAMA as nama']);
    }

    public function addPilihan(string $kode): void
    {
        if (! in_array($kode, $this->pilihan, true)) {
            $this->pilihan[] = $kode;
        }
        $this->pilihanQ = '';
    }

    public function removePilihan(string $kode): void
    {
        $this->pilihan = array_values(array_diff($this->pilihan, [$kode]));
    }

    /** Hitung diskon1/diskon2 (rupiah, bertingkat) & subtotal dari harga x qty x diskon persen. */
    private function computeAmounts(): array
    {
        if ($this->harga0) {
            return ['diskon1' => 0.0, 'diskon2' => 0.0, 'subtotal' => 0.0];
        }

        $base = (float) ($this->harga ?? 0) * (float) ($this->qty ?? 0);
        $d1 = (float) ($this->diskonPersen1 ?? 0);
        $d2 = (float) ($this->diskonPersen2 ?? 0);

        $diskon1 = $base * $d1 / 100;
        $afterD1 = $base - $diskon1;
        $diskon2 = $afterD1 * $d2 / 100;
        $subtotal = $afterD1 - $diskon2;

        return ['diskon1' => $diskon1, 'diskon2' => $diskon2, 'subtotal' => $subtotal];
    }

    public function save(): void
    {
        $this->validate([
            'item'          => ['required', 'integer'],
            'qty'           => ['nullable', 'numeric', 'min:0'],
            'qtyTindakan'   => ['nullable', 'numeric', 'min:0'],
            'harga'         => ['nullable', 'numeric', 'min:0'],
            'diskonPersen1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'diskonPersen2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jumlahPaket'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $amounts = $this->computeAmounts();

        $payload = [
            'PDIDU'            => $this->packageId,
            'PDITEM'           => $this->item,
            'PDQTY'            => $this->qty ?: 0,
            'PDQTYTINDAKAN'    => $this->qtyTindakan ?: 0,
            'PDHARGA'          => $this->harga0 ? 0 : ($this->harga ?: 0),
            'PDDISKONPERSEN1'  => $this->diskonPersen1 ?: 0,
            'PDDISKONPERSEN2'  => $this->diskonPersen2 ?: 0,
            'PDDISKON'         => $amounts['diskon1'],
            'PDDISKON2'        => $amounts['diskon2'],
            'PDSUBTOTAL'       => $amounts['subtotal'],
            'PDPILIHAN'        => $this->pilihan !== [] ? implode('|', $this->pilihan) : null,
            'PDCETAK'          => $this->cetak ? 1 : 0,
            'PDHARGA0'         => $this->harga0 ? 1 : 0,
            'PDJUMLAHPAKET'    => $this->jumlahPaket ?: 0,
        ];

        if ($this->editingId) {
            PackageDetail::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/paket', $this->packageId, 'Ubah baris item paket ' . $this->packageKode);
        } else {
            $payload['PDURUTAN'] = (int) (PackageDetail::where('PDIDU', $this->packageId)->max('PDURUTAN') ?? 0) + 1;
            PackageDetail::create($payload);
            activity_log('create', 'master/paket', $this->packageId, 'Tambah baris item paket ' . $this->packageKode);
        }

        Package::recalcTotal($this->packageId);

        session()->flash('status', 'Baris item paket disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        PackageDetail::where('PDIDU', $this->packageId)->whereKey($id)->delete();
        Package::recalcTotal($this->packageId);
        activity_log('delete', 'master/paket', $this->packageId, 'Hapus baris item paket ' . $this->packageKode);
        session()->flash('status', 'Baris item paket dihapus.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'item', 'itemLabel', 'qty', 'qtyTindakan', 'harga',
            'diskonPersen1', 'diskonPersen2', 'pilihan', 'pilihanQ',
            'cetak', 'harga0', 'jumlahPaket',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        $lines = PackageDetail::where('PDIDU', $this->packageId)->orderBy('PDURUTAN')->get();

        $itemIds = $lines->pluck('PDITEM')->filter()->unique()->values();
        $itemNames = $itemIds->isNotEmpty()
            ? DB::table('bitem')->whereIn('IID', $itemIds)->pluck('IKODE', 'IID')
            : collect();

        $amounts = $this->computeAmounts();

        return view('livewire.master.paket-detail-form', [
            'lines'          => $lines,
            'itemNames'      => $itemNames,
            'previewAmounts' => $amounts,
            'pilihanResults' => $this->pilihanSearchResults($this->pilihanQ),
        ]);
    }
}
