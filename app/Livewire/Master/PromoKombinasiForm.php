<?php

namespace App\Livewire\Master;

use App\Models\Promo;
use App\Models\PromoDetail;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab detail kombinasi promo (emasterpromod) - khusus Promo::JENIS_PROMO[1] "Kombinasi 1".
 * Dibuka dari PromoManager utk promo yg MPUJENISPROMO=1.
 *
 * Pemetaan diskon (DIKOREKSI setelah dicek ke data produksi nyata "GCO IV CP AGUSTUS 2026" -
 * screenshot user awalnya terbaca sbg "diskon tier N milik item1/item2", tapi data asli
 * membuktikan setiap item (1-4) punya sepasang diskon (diskon1 & diskon2) MILIKNYA SENDIRI):
 *   Item1 -> diskon1=MPDDISKON,      diskon2=MPDDISKON2
 *   Item2 -> diskon1=MPDDISKONITEM2, diskon2=MPDDISKONITEM22
 *   Item3 -> diskon1=MPDDISKON1KE3,  diskon2=MPDDISKON2KE3
 *   Item4 -> diskon1=MPDDISKON1KE4,  diskon2=MPDDISKON2KE4 (pola konsisten, blm ada contoh
 *            data nyata dgn item4 terisi utk konfirmasi eksplisit)
 *
 * Fungsi (dari penjelasan user, blm diimplementasikan di sini - baru simpan data):
 * "ketika promo baris ini dipilih di POS, tampil item 1/2/3/4 (kalau ada), MPDID disimpan ke
 * fstokd.SDIDPROMO, harga jual tiap item dikalikan diskon 1 & diskon 2 masing2. Item N Pilihan
 * = daftar item alternatif yg bisa dipilih kasir utk slot item N." Integrasi ke POS = tugas
 * terpisah, belum digarap.
 */
class PromoKombinasiForm extends Component
{
    public ?string $tabKey = null;

    public int $promoId;
    public ?string $promoKode = null;
    public ?string $promoNama = null;

    public bool $showModal = false;
    public ?int $editingId = null;

    // Item 1-4 (bitem.IKODE sbg teks) + label tampilan saat edit
    public ?string $item1 = null;
    public ?string $item2 = null;
    public ?string $item3 = null;
    public ?string $item4 = null;
    public array $labels = [];

    // Minimal qty per item
    public ?float $minimal1 = null;
    public ?float $minimal2 = null;
    public ?float $minimal3 = null;
    public ?float $minimal4 = null;

    // Diskon 1 & Diskon 2 milik masing2 item (BUKAN tier lintas-item - dikoreksi setelah
    // dicek ke data produksi nyata "GCO IV CP AGUSTUS 2026": MPDDISKONITEM2 = diskon1 ITEM2
    // sendiri, MPDDISKON1KE3 = diskon1 ITEM3 sendiri, dst. Setiap item punya sepasang diskon
    // (diskon1 & diskon2) miliknya sendiri, item3 & item4 juga punya - bukan cuma item1&2.
    public ?float $diskon1_1 = null; // item1 diskon1
    public ?float $diskon1_2 = null; // item1 diskon2
    public ?float $diskon2_1 = null; // item2 diskon1
    public ?float $diskon2_2 = null; // item2 diskon2
    public ?float $diskon3_1 = null; // item3 diskon1
    public ?float $diskon3_2 = null; // item3 diskon2
    public ?float $diskon4_1 = null; // item4 diskon1
    public ?float $diskon4_2 = null; // item4 diskon2

    // Item Pilihan 1-4 (kumpulan bitem.IKODE, pipe-delimited di DB)
    public array $pilihan1 = [];
    public array $pilihan2 = [];
    public array $pilihan3 = [];
    public array $pilihan4 = [];

    public string $pilihanQ1 = '';
    public string $pilihanQ2 = '';
    public string $pilihanQ3 = '';
    public string $pilihanQ4 = '';

    public function mount(int $promoId): void
    {
        $p = Promo::findOrFail($promoId);
        $this->promoId = $p->MPUID;
        $this->promoKode = $p->MPUKODE;
        $this->promoNama = $p->MPUNAMA;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $d = PromoDetail::where('MPDIDU', $this->promoId)->findOrFail($id);

        $this->editingId = $d->MPDID;
        $this->item1 = $d->MPDKELITEM1;
        $this->item2 = $d->MPDKELITEM2;
        $this->item3 = $d->MPDKELITEM3;
        $this->item4 = $d->MPDKELITEM4;
        $this->minimal1 = $d->MPDTOTALINVOICE1;
        $this->minimal2 = $d->MPDTOTALINVOICE2;
        $this->minimal3 = $d->MPDMINIMALQTY3;
        $this->minimal4 = $d->MPDMINIMALQTY4;
        $this->diskon1_1 = $d->MPDDISKON;
        $this->diskon1_2 = $d->MPDDISKON2;
        $this->diskon2_1 = $d->MPDDISKONITEM2;
        $this->diskon2_2 = $d->MPDDISKONITEM22;
        $this->diskon3_1 = $d->MPDDISKON1KE3;
        $this->diskon3_2 = $d->MPDDISKON2KE3;
        $this->diskon4_1 = $d->MPDDISKON1KE4;
        $this->diskon4_2 = $d->MPDDISKON2KE4;
        $this->pilihan1 = $this->splitCodes($d->MPDPILIHAN1);
        $this->pilihan2 = $this->splitCodes($d->MPDPILIHAN2);
        $this->pilihan3 = $this->splitCodes($d->MPDPILIHAN3);
        $this->pilihan4 = $this->splitCodes($d->MPDPILIHAN4);

        $this->loadLabels();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    private function splitCodes(?string $v): array
    {
        $v = trim((string) $v);

        return $v === '' ? [] : array_values(array_filter(explode('|', $v)));
    }

    private function loadLabels(): void
    {
        $codes = array_values(array_filter([$this->item1, $this->item2, $this->item3, $this->item4]));
        $names = $codes !== [] ? DB::table('bitem')->whereIn('IKODE', $codes)->pluck('INAMA', 'IKODE') : collect();

        foreach (['item1', 'item2', 'item3', 'item4'] as $slot) {
            $kode = $this->$slot;
            $this->labels[$slot] = $kode ? trim($kode . ' — ' . ($names[$kode] ?? '')) : null;
        }
    }

    /** Hasil cari item utk field "Item N Pilihan" (multi-add), dipisah per slot (1-4). */
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

    public function addPilihan(int $slot, string $kode): void
    {
        $prop = "pilihan{$slot}";
        if (! in_array($kode, $this->{$prop}, true)) {
            $this->{$prop}[] = $kode;
        }
        $this->{"pilihanQ{$slot}"} = '';
    }

    public function removePilihan(int $slot, string $kode): void
    {
        $prop = "pilihan{$slot}";
        $this->{$prop} = array_values(array_diff($this->{$prop}, [$kode]));
    }

    public function save(): void
    {
        $this->validate([
            'minimal1' => ['nullable', 'numeric'],
            'minimal2' => ['nullable', 'numeric'],
            'minimal3' => ['nullable', 'numeric'],
            'minimal4' => ['nullable', 'numeric'],
            'diskon1_1' => ['nullable', 'numeric'],
            'diskon1_2' => ['nullable', 'numeric'],
            'diskon2_1' => ['nullable', 'numeric'],
            'diskon2_2' => ['nullable', 'numeric'],
            'diskon3_1' => ['nullable', 'numeric'],
            'diskon3_2' => ['nullable', 'numeric'],
            'diskon4_1' => ['nullable', 'numeric'],
            'diskon4_2' => ['nullable', 'numeric'],
        ]);

        $payload = [
            'MPDIDU'           => $this->promoId,
            'MPDKELITEM1'      => $this->item1 ?: null,
            'MPDKELITEM2'      => $this->item2 ?: null,
            'MPDKELITEM3'      => $this->item3 ?: null,
            'MPDKELITEM4'      => $this->item4 ?: null,
            'MPDTOTALINVOICE1' => $this->minimal1,
            'MPDTOTALINVOICE2' => $this->minimal2,
            'MPDMINIMALQTY3'   => $this->minimal3,
            'MPDMINIMALQTY4'   => $this->minimal4,
            'MPDDISKON'        => $this->diskon1_1,
            'MPDDISKON2'       => $this->diskon1_2,
            'MPDDISKONITEM2'   => $this->diskon2_1,
            'MPDDISKONITEM22'  => $this->diskon2_2,
            'MPDDISKON1KE3'    => $this->diskon3_1,
            'MPDDISKON2KE3'    => $this->diskon3_2,
            'MPDDISKON1KE4'    => $this->diskon4_1,
            'MPDDISKON2KE4'    => $this->diskon4_2,
            'MPDPILIHAN1'      => $this->pilihan1 !== [] ? implode('|', $this->pilihan1) : null,
            'MPDPILIHAN2'      => $this->pilihan2 !== [] ? implode('|', $this->pilihan2) : null,
            'MPDPILIHAN3'      => $this->pilihan3 !== [] ? implode('|', $this->pilihan3) : null,
            'MPDPILIHAN4'      => $this->pilihan4 !== [] ? implode('|', $this->pilihan4) : null,
        ];

        if ($this->editingId) {
            PromoDetail::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/promo', $this->promoId, 'Ubah baris kombinasi promo ' . $this->promoKode);
        } else {
            $payload['MPDURUTAN'] = (int) (PromoDetail::where('MPDIDU', $this->promoId)->max('MPDURUTAN') ?? 0) + 1;
            PromoDetail::create($payload);
            activity_log('create', 'master/promo', $this->promoId, 'Tambah baris kombinasi promo ' . $this->promoKode);
        }

        session()->flash('status', 'Baris kombinasi disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        PromoDetail::where('MPDIDU', $this->promoId)->whereKey($id)->delete();
        activity_log('delete', 'master/promo', $this->promoId, 'Hapus baris kombinasi promo ' . $this->promoKode);
        session()->flash('status', 'Baris kombinasi dihapus.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'item1', 'item2', 'item3', 'item4', 'labels',
            'minimal1', 'minimal2', 'minimal3', 'minimal4',
            'diskon1_1', 'diskon1_2', 'diskon2_1', 'diskon2_2',
            'diskon3_1', 'diskon3_2', 'diskon4_1', 'diskon4_2',
            'pilihan1', 'pilihan2', 'pilihan3', 'pilihan4',
            'pilihanQ1', 'pilihanQ2', 'pilihanQ3', 'pilihanQ4',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        $lines = PromoDetail::where('MPDIDU', $this->promoId)->orderBy('MPDURUTAN')->get();

        // Harga jual (bitem.IHARGAJUAL1) per item terpilih - dicari ulang tiap render (bukan
        // disimpan ke properti) krn item1-4 bisa berubah kapan saja (pilih baru/ganti/hapus)
        // dan cuma butuh 1 query ringan utk 4 kode maksimal.
        $codes = array_values(array_filter([$this->item1, $this->item2, $this->item3, $this->item4]));
        $prices = $codes !== [] ? DB::table('bitem')->whereIn('IKODE', $codes)->pluck('IHARGAJUAL1', 'IKODE') : collect();

        // Struktur per-slot (bukan akses properti dinamis $this->{"item{$n}"} di blade - itu
        // butuh variable-variable yg fragile/deprecated di PHP 8+, jadi disiapkan bersih di sini).
        // Subtotal per item = Minimal Qty x Harga Jual x (1-diskon1/100) x (1-diskon2/100),
        // sesuai permintaan user - gambaran nilai promo ini kalau kombinasi lengkap tercapai.
        $itemSlots = [];
        $totalPromo = 0.0;
        foreach ([1, 2, 3, 4] as $n) {
            $kode = $this->{"item{$n}"};
            $harga = $kode ? (float) ($prices[$kode] ?? 0) : null;
            $qty = (float) ($this->{"minimal{$n}"} ?? 0);
            $d1 = (float) ($this->{"diskon{$n}_1"} ?? 0);
            $d2 = (float) ($this->{"diskon{$n}_2"} ?? 0);
            $subtotal = $kode ? $qty * ($harga ?? 0) * (1 - $d1 / 100) * (1 - $d2 / 100) : null;
            $totalPromo += $subtotal ?? 0;

            $itemSlots[$n] = [
                'value'    => $kode,
                'label'    => $this->labels["item{$n}"] ?? null,
                'harga'    => $harga,
                'subtotal' => $subtotal,
            ];
        }

        $pilihanSlots = [
            1 => ['values' => $this->pilihan1, 'q' => $this->pilihanQ1, 'results' => $this->pilihanSearchResults($this->pilihanQ1)],
            2 => ['values' => $this->pilihan2, 'q' => $this->pilihanQ2, 'results' => $this->pilihanSearchResults($this->pilihanQ2)],
            3 => ['values' => $this->pilihan3, 'q' => $this->pilihanQ3, 'results' => $this->pilihanSearchResults($this->pilihanQ3)],
            4 => ['values' => $this->pilihan4, 'q' => $this->pilihanQ4, 'results' => $this->pilihanSearchResults($this->pilihanQ4)],
        ];

        return view('livewire.master.promo-kombinasi-form', [
            'lines'        => $lines,
            'itemSlots'    => $itemSlots,
            'pilihanSlots' => $pilihanSlots,
            'totalPromo'   => $totalPromo,
        ]);
    }
}
