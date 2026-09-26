<?php

namespace App\Livewire\Master;

use App\Models\Promo;
use App\Models\PromoDetail;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab detail promo (emasterpromod) - khusus Promo::JENIS_PROMO[0] "Biasa". Dibuka dari
 * PromoManager utk promo yg MPUJENISPROMO=0. Pemetaan 19 field DIKONFIRMASI LANGSUNG oleh user
 * (screenshot tabel kolom+tipe+referensi), lalu DIVERIFIKASI ke data produksi nyata (SHOW
 * COLUMNS + sample baris) sebelum dibangun - lihat memory proyek utk detail penjelasan &
 * temuan data (MPDTOTALINVOICE2 "Seluruh Invoice" mayoritas 0/1 spt spek, tapi ada 5 baris
 * legacy bernilai 2/3 - garbage data, TETAP diperlakukan sbg boolean 0/1 sesuai instruksi user,
 * bukan dianggap state ke-3; MPDITEM1-5 "Item Bonus" dikonfirmasi FK ke bitem.IID (integer,
 * BEDA dari MPDKELITEM1 yg simpan bitem.IKODE sbg teks); MPDPILIHAN1 dikonfirmasi pipe-delimited
 * bitem.IKODE, sama pola dgn Kombinasi 1).
 *
 * "Pilihan" = alternatif item yg jg dianggap cocok utk syarat "Jenis Item" (MPDKELITEM1) -
 * pola sama spt "Item N Pilihan" di Kombinasi 1, cuma disini cuma ada 1 slot krn cuma ada 1
 * item syarat (bukan 4). Harga Jual + hasil setelah diskon ditampilkan sbg PREVIEW saja
 * (murni tampilan, gambaran nilai promo ini kalau dipakai - sama spt fitur serupa di
 * PromoKombinasiForm), tidak disimpan ke kolom manapun.
 *
 * BELUM diimplementasikan (scope kali ini cuma CRUD master data, sama spt Kombinasi 1): logika
 * evaluasi syarat (minimal belanja/qty/tanggal/jam/max pasien) & penerapan diskon+bonus item
 * otomatis di POS.
 */
class PromoBiasaForm extends Component
{
    public ?string $tabKey = null;

    public int $promoId;
    public ?string $promoKode = null;
    public ?string $promoNama = null;

    public bool $showModal = false;
    public ?int $editingId = null;

    // Jenis Item (bitem.IKODE sbg teks) + Pilihan (alternatif, pipe-delimited bitem.IKODE)
    public ?string $kelitem1 = null;
    public ?string $kelitem1Label = null;
    public array $pilihan1 = [];
    public string $pilihanQ1 = '';

    // Syarat & diskon
    public ?float $totalinvoice1 = null; // Minimal Belanja
    public bool $totalinvoice2 = false;  // Seluruh Invoice (checkbox - lihat catatan garbage data di docblock class)
    public ?float $diskon = null;
    public ?float $diskon2 = null;       // Diskon Persen 2
    public ?float $minimalqty = null;    // Min Qty
    public ?float $markup = null;

    // Periode berlaku spesifik baris ini (lebih detail dari tanggal berlaku promo di header)
    public ?string $tanggal = null;
    public ?string $tanggal2 = null;
    public ?string $jam1 = null;
    public ?string $jam2 = null;
    public bool $pakaitanggal = false;   // Pakai Tanggal (checklist)
    public ?float $maxpasien = null;

    // Item Bonus 1-5 (bitem.IID sbg FK integer - BEDA dari kelitem1 yg simpan IKODE teks)
    public ?int $item1 = null;
    public ?int $item2 = null;
    public ?int $item3 = null;
    public ?int $item4 = null;
    public ?int $item5 = null;
    public array $labels = [];

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
        $this->kelitem1 = $d->MPDKELITEM1;
        $this->pilihan1 = $this->splitCodes($d->MPDPILIHAN1);
        $this->totalinvoice1 = $d->MPDTOTALINVOICE1;
        $this->totalinvoice2 = (int) $d->MPDTOTALINVOICE2 !== 0; // >0 dianggap "ya" (lihat catatan garbage 2/3 di docblock)
        $this->diskon = $d->MPDDISKON;
        $this->diskon2 = $d->MPDDISKON2;
        $this->minimalqty = $d->MPDMINIMALQTY;
        $this->markup = $d->MPDMARKUP;
        $this->tanggal = $this->cleanDate($d->MPDTANGGAL);
        $this->tanggal2 = $this->cleanDate($d->MPDTANGGAL2);
        $this->jam1 = $this->cleanTime($d->MPDJAM1);
        $this->jam2 = $this->cleanTime($d->MPDJAM2);
        $this->pakaitanggal = (int) $d->MPDPAKAITANGGAL === 1;
        $this->maxpasien = $d->MPDMAXPASIEN;
        $this->item1 = $d->MPDITEM1 ?: null;
        $this->item2 = $d->MPDITEM2 ?: null;
        $this->item3 = $d->MPDITEM3 ?: null;
        $this->item4 = $d->MPDITEM4 ?: null;
        $this->item5 = $d->MPDITEM5 ?: null;

        $this->loadLabels();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    private function splitCodes(?string $v): array
    {
        $v = trim((string) $v);

        return $v === '' ? [] : array_values(array_filter(explode('|', $v)));
    }

    private function cleanDate($v): ?string
    {
        $v = substr((string) $v, 0, 10);

        return ($v === '' || $v === '0000-00-00') ? null : $v;
    }

    private function cleanTime($v): ?string
    {
        $v = substr((string) $v, 0, 5); // "HH:MM:SS" -> "HH:MM" utk <input type="time">

        return ($v === '' || $v === '00:00') ? null : $v;
    }

    private function loadLabels(): void
    {
        $this->kelitem1Label = null;
        if ($this->kelitem1) {
            $nama = DB::table('bitem')->where('IKODE', $this->kelitem1)->value('INAMA');
            $this->kelitem1Label = trim($this->kelitem1 . ' — ' . ($nama ?? ''));
        }

        $itemIds = array_values(array_filter([$this->item1, $this->item2, $this->item3, $this->item4, $this->item5]));
        $rows = $itemIds !== [] ? DB::table('bitem')->whereIn('IID', $itemIds)->get(['IID', 'IKODE', 'INAMA'])->keyBy('IID') : collect();

        foreach (['item1', 'item2', 'item3', 'item4', 'item5'] as $slot) {
            $id = $this->$slot;
            $this->labels[$slot] = ($id && $rows->has($id)) ? trim($rows[$id]->IKODE . ' — ' . $rows[$id]->INAMA) : null;
        }
    }

    /** Hasil cari item (utk "Pilihan" - alternatif Jenis Item). */
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
        if (! in_array($kode, $this->pilihan1, true)) {
            $this->pilihan1[] = $kode;
        }
        $this->pilihanQ1 = '';
    }

    public function removePilihan(string $kode): void
    {
        $this->pilihan1 = array_values(array_diff($this->pilihan1, [$kode]));
    }

    public function save(): void
    {
        $this->validate([
            'totalinvoice1' => ['nullable', 'numeric'],
            'diskon'        => ['nullable', 'numeric'],
            'diskon2'       => ['nullable', 'numeric'],
            'minimalqty'    => ['nullable', 'numeric'],
            'markup'        => ['nullable', 'numeric'],
            'tanggal'       => ['nullable', 'date'],
            'tanggal2'      => ['nullable', 'date', 'after_or_equal:tanggal'],
            'jam1'          => ['nullable'],
            'jam2'          => ['nullable'],
            'maxpasien'     => ['nullable', 'numeric'],
        ]);

        $payload = [
            'MPDIDU'           => $this->promoId,
            'MPDKELITEM1'      => $this->kelitem1 ?: null,
            'MPDPILIHAN1'      => $this->pilihan1 !== [] ? implode('|', $this->pilihan1) : null,
            'MPDTOTALINVOICE1' => $this->totalinvoice1,
            'MPDTOTALINVOICE2' => $this->totalinvoice2 ? 1 : 0,
            'MPDDISKON'        => $this->diskon,
            'MPDDISKON2'       => $this->diskon2,
            'MPDMINIMALQTY'    => $this->minimalqty,
            'MPDMARKUP'        => $this->markup,
            'MPDTANGGAL'       => $this->tanggal ?: null,
            'MPDTANGGAL2'      => $this->tanggal2 ?: null,
            'MPDJAM1'          => $this->jam1 ?: null,
            'MPDJAM2'          => $this->jam2 ?: null,
            'MPDPAKAITANGGAL'  => $this->pakaitanggal ? 1 : 0,
            'MPDMAXPASIEN'     => $this->maxpasien,
            'MPDITEM1'         => $this->item1 ?: null,
            'MPDITEM2'         => $this->item2 ?: null,
            'MPDITEM3'         => $this->item3 ?: null,
            'MPDITEM4'         => $this->item4 ?: null,
            'MPDITEM5'         => $this->item5 ?: null,
        ];

        if ($this->editingId) {
            PromoDetail::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/promo', $this->promoId, 'Ubah baris promo biasa ' . $this->promoKode);
        } else {
            $payload['MPDURUTAN'] = (int) (PromoDetail::where('MPDIDU', $this->promoId)->max('MPDURUTAN') ?? 0) + 1;
            PromoDetail::create($payload);
            activity_log('create', 'master/promo', $this->promoId, 'Tambah baris promo biasa ' . $this->promoKode);
        }

        session()->flash('status', 'Baris promo disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        PromoDetail::where('MPDIDU', $this->promoId)->whereKey($id)->delete();
        activity_log('delete', 'master/promo', $this->promoId, 'Hapus baris promo biasa ' . $this->promoKode);
        session()->flash('status', 'Baris promo dihapus.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'kelitem1', 'kelitem1Label', 'pilihan1', 'pilihanQ1',
            'totalinvoice1', 'totalinvoice2', 'diskon', 'diskon2', 'minimalqty', 'markup',
            'tanggal', 'tanggal2', 'jam1', 'jam2', 'pakaitanggal', 'maxpasien',
            'item1', 'item2', 'item3', 'item4', 'item5', 'labels',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        $lines = PromoDetail::where('MPDIDU', $this->promoId)->orderBy('MPDURUTAN')->get();

        // Harga jual (bitem.IHARGAJUAL1) item syarat terpilih - preview saja, dicari ulang tiap
        // render (bukan disimpan ke properti) spt pola yg sama di PromoKombinasiForm.
        $harga = $this->kelitem1 ? (float) (DB::table('bitem')->where('IKODE', $this->kelitem1)->value('IHARGAJUAL1') ?? 0) : null;
        $d1 = (float) ($this->diskon ?? 0);
        $d2 = (float) ($this->diskon2 ?? 0);
        $hasilDiskon = $harga !== null ? $harga * (1 - $d1 / 100) * (1 - $d2 / 100) : null;

        // Struktur per-slot Item Bonus (bukan akses properti dinamis $this->{"item{$n}"} di
        // blade - butuh variable-variable yg fragile/deprecated di PHP 8+, disiapkan bersih
        // di sini, sama pola spt $itemSlots di PromoKombinasiForm).
        $itemBonusSlots = [
            1 => ['value' => $this->item1, 'label' => $this->labels['item1'] ?? null],
            2 => ['value' => $this->item2, 'label' => $this->labels['item2'] ?? null],
            3 => ['value' => $this->item3, 'label' => $this->labels['item3'] ?? null],
            4 => ['value' => $this->item4, 'label' => $this->labels['item4'] ?? null],
            5 => ['value' => $this->item5, 'label' => $this->labels['item5'] ?? null],
        ];

        return view('livewire.master.promo-biasa-form', [
            'lines'          => $lines,
            'harga'          => $harga,
            'hasilDiskon'    => $hasilDiskon,
            'itemBonusSlots' => $itemBonusSlots,
            'pilihanResults' => $this->pilihanSearchResults($this->pilihanQ1),
        ]);
    }
}
