<?php

namespace App\Livewire\Master;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Item2;
use App\Models\ItemGroup;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Data Item POS - list + modal (xl). Menulis bitem + bitem2 (I2COAPENDAPATAN).
 * Kolom bitem = UPPERCASE (lihat CLAUDE.md).
 *
 * Form diringkas (permintaan user 2026-09-10). Field yang DIHAPUS dari form
 * tidak ditulis ke DB (nilai lama dipertahankan saat edit), kecuali:
 *   - ISATUAN (satuan default)  -> saat save disamakan dengan ISATUAND (satuan dasar)
 *   - IKATEGORI                 -> diset 0 hanya saat item baru
 * Tabel bitemdaps tidak lagi disentuh dari sini.
 */
class ItemManager extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    /** field form => kolom bitem (skalar langsung). */
    private const MAP = [
        'kode' => 'IKODE', 'nama' => 'INAMA', 'namaweb' => 'ICOMERSIALNAME',
        'status' => 'ISTATUS', 'qtyperbox' => 'IQTYPERBOX', 'satuand' => 'ISATUAND',
        'tipepersediaan' => 'ITIPEITEM', 'model' => 'IMODEL', 'berat' => 'IBERAT',
        'hargajual1' => 'IHARGAJUAL1', 'hargakaryawan' => 'IHARGAJUALKARYAWAN',
        'hargaweb' => 'IHARGAWEB', 'hargaweb2' => 'IHARGAWEB2', 'diskon' => 'IDISKON',
        'cogs' => 'ICOGS', 'cogspo' => 'ICOGS_PO',
        'hargapo' => 'IPOHARGA', 'qtypo' => 'IPOQTY', 'kemasan' => 'IPOKEMASAN',
        'coding' => 'ICODING', 'namapo' => 'IPONAMA',
    ];

    /** field form => kolom bitem (FK, NULL bila kosong). */
    private const MAP_FK = [
        'jenisitem' => 'IJENISITEM', 'jenisitemcoa' => 'IJENISITEMCOA',
        'kelompokbaru' => 'IKELOMPOKBARU', 'kelompok2020' => 'IKELOMPOK2020',
        'kelompok21' => 'IKELOMPOK21', 'kelompok23' => 'IKELOMPOK23',
        'coa2021' => 'ICOA2021', 'komisi2020' => 'IKOMISI2020', 'jenisweb' => 'IJENISDIWEB',
    ];

    /** field form => kolom bitem (checkbox 0/1). */
    private const MAP_CHK = [
        'serial' => 'ISERIAL',
        'tidakdihitungjumlahpasien' => 'IJENIS',
        'bisasharing' => 'ISHARING', 'cetak' => 'ISUBKATEGORI',
        'promo' => 'IPROMO', 'bhp' => 'IBHP', 'resep' => 'IRESEP',
    ];

    private const STRING_FIELDS = ['kode', 'nama', 'namaweb', 'model', 'kemasan', 'coding', 'namapo'];
    private const INT_FIELDS = ['status', 'tipepersediaan', 'qtyperbox', 'satuand'];

    // ---- Filter list ----
    public string $search = '';
    public string $fStatus = '0'; // default: Aktif
    public string $fKelompok = '';
    public string $fTipe = '';

    // ---- Modal form ----
    public bool $showModal = false;
    public ?int $editingId = null;
    public array $f = [];
    public array $cabang = [];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFStatus(): void { $this->resetPage(); }
    public function updatingFKelompok(): void { $this->resetPage(); }
    public function updatingFTipe(): void { $this->resetPage(); }

    private function blankForm(): array
    {
        $f = [];
        foreach (array_keys(self::MAP) as $k) {
            $f[$k] = in_array($k, self::STRING_FIELDS, true) ? '' : 0;
        }
        $f['status'] = 0;
        $f['tipepersediaan'] = 0;
        $f['qtyperbox'] = 1;
        foreach (array_keys(self::MAP_FK) as $k) { $f[$k] = null; }
        foreach (array_keys(self::MAP_CHK) as $k) { $f[$k] = false; }
        $f['jeniscoapendapatan'] = null; // bitem2

        return $f;
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->f = $this->blankForm();
        $this->cabang = [];
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $item = Item::with('extra')->findOrFail($id);

        $f = $this->blankForm();
        foreach (self::MAP as $field => $col) {
            $v = $item->{$col};
            $f[$field] = in_array($field, self::STRING_FIELDS, true) ? (string) $v : $v;
        }
        foreach (self::MAP_FK as $field => $col) {
            $f[$field] = $item->{$col} ? (int) $item->{$col} : null;
        }
        foreach (self::MAP_CHK as $field => $col) {
            $f[$field] = (int) $item->{$col} === 1;
        }
        $f['jeniscoapendapatan'] = $item->extra->I2COAPENDAPATAN ?? null;

        $this->f = $f;
        $this->editingId = (int) $item->IID;
        $this->cabang = $item->branchIds();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    protected function rules(): array
    {
        return [
            'f.kode' => ['required', 'string', 'max:100', Rule::unique('bitem', 'IKODE')->ignore($this->editingId, 'IID')],
            'f.nama' => ['required', 'string', 'max:255'],
            'f.status' => ['required', 'integer', 'in:0,1,2'],
            'f.tipepersediaan' => ['required', 'integer', 'in:0,1'],
            'f.satuand' => ['required', 'integer', 'min:1'],
        ];
    }

    protected array $messages = [
        'f.kode.required'    => 'Kode wajib diisi.',
        'f.kode.unique'      => 'Kode item sudah dipakai.',
        'f.nama.required'    => 'Nama wajib diisi.',
        'f.satuand.required' => 'Satuan dasar wajib dipilih.',
        'f.satuand.min'      => 'Satuan dasar wajib dipilih.',
    ];

    public function save(): void
    {
        $this->validate();

        $num = fn ($v) => is_numeric(str_replace(',', '', (string) $v)) ? (float) str_replace(',', '', (string) $v) : 0;

        $data = [];
        foreach (self::MAP as $field => $col) {
            $v = $this->f[$field] ?? null;
            if (in_array($field, self::STRING_FIELDS, true)) {
                $data[$col] = $v !== '' && $v !== null ? $v : null;
            } elseif (in_array($field, self::INT_FIELDS, true)) {
                $data[$col] = (int) $v;
            } else {
                $data[$col] = $num($v);
            }
        }
        foreach (self::MAP_FK as $field => $col) {
            $data[$col] = ! empty($this->f[$field]) ? (int) $this->f[$field] : null;
        }
        foreach (self::MAP_CHK as $field => $col) {
            $data[$col] = ! empty($this->f[$field]) ? 1 : 0;
        }

        // Satuan default disamakan dengan satuan dasar.
        $data['ISATUAN'] = (int) $this->f['satuand'];
        $data['ICABANG'] = Item::packBranchIds($this->cabang);

        DB::transaction(function () use ($data) {
            if ($this->editingId) {
                $data['IMODIFU'] = auth()->id();
                Item::whereKey($this->editingId)->update($data);
                $iid = $this->editingId;
            } else {
                $data['ICREATEU'] = auth()->id();
                $data['IKATEGORI'] = 0;
                $iid = Item::insertGetId($data);
            }

            Item2::updateOrCreate(
                ['I2IDITEM' => $iid],
                ['I2COAPENDAPATAN' => ! empty($this->f['jeniscoapendapatan']) ? (int) $this->f['jeniscoapendapatan'] : null]
            );

            activity_log($this->editingId ? 'update' : 'create', 'master/item', $iid, ($this->editingId ? 'Ubah' : 'Tambah') . ' item ' . $this->f['nama']);
        });

        session()->flash('status', 'Data item disimpan.');
        $this->showModal = false;
    }

    public function selectAllBranches(): void
    {
        $this->cabang = Branch::options()->pluck('GID')->map(fn ($v) => (string) $v)->all();
    }

    public function clearBranches(): void
    {
        $this->cabang = [];
    }

    public function delete(int $id): void
    {
        if (! can_do('master/item', 'delete')) {
            return;
        }

        DB::transaction(function () use ($id) {
            DB::table('bitemdaps')->where('IDITEM', $id)->delete();
            DB::table('bitem2')->where('I2IDITEM', $id)->delete();
            DB::table('bitem')->where('IID', $id)->delete();
        });

        activity_log('delete', 'master/item', $id, 'Hapus item');
        session()->flash('status', 'Item dihapus.');
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = DB::table('bitem as A')
            ->leftJoin('bsatuan as B', 'A.ISATUAN', '=', 'B.SID')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('A.IKODE', 'like', "%{$q}%")->orWhere('A.INAMA', 'like', "%{$q}%")))
            ->when($this->fStatus !== '', fn ($b) => $b->where('A.ISTATUS', (int) $this->fStatus))
            ->when($this->fKelompok !== '', fn ($b) => $b->where('A.IKELOMPOK2020', (int) $this->fKelompok))
            ->when($this->fTipe !== '', fn ($b) => $b->where('A.ITIPEITEM', (int) $this->fTipe))
            ->orderBy('A.INAMA')
            ->paginate(25, [
                'A.IID as iid', 'A.IKODE as ikode', 'A.INAMA as inama', 'A.ISTATUS as istatus',
                'A.ITIPEITEM as itipeitem', 'A.IHARGAJUAL1 as ihargajual1', 'B.SKODE as satuan',
            ]);

        return view('livewire.master.item-manager', [
            'rows'       => $rows,
            'units'      => Unit::options(),
            'kelompok'   => ItemGroup::options(),
            'jenisItem'  => DB::table('bitemjenis')->orderBy('JKODE')->get(['JID', 'JKODE']),
            'coaPend'    => DB::table('bcoatipe_pendapatan')->orderBy('CTNAMA')->get(['CTID', 'CTNAMA']),
            'coaPerpt'   => DB::table('bcoatipe_perpt')->orderBy('CTNAMA')->get(['CTID', 'CTNAMA']),
            'kelompok21' => DB::table('bitemkelompok2021')->orderBy('IK21KODE')->get(['IK21ID', 'IK21KODE']),
            'kelompok23' => DB::table('bitemkelompok2023')->orderBy('IK23KODE')->get(['IK23ID', 'IK23KODE']),
            'jenisWeb'   => DB::table('bitemjenisweb')->orderBy('IJKODE')->get(['IJID', 'IJKODE']),
            'branches'   => Branch::options(),
            'chkFields'  => array_keys(self::MAP_CHK),
        ]);
    }
}
