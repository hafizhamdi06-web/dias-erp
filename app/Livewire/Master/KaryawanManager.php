<?php

namespace App\Livewire\Master;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Data Karyawan')]
class KaryawanManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    /** Checkbox tab "Seting POS" -> kolom bkontak. */
    private const POS_FLAGS = [
        'doktersmy'      => 'KDOKTERSMY',
        'salesmarketing' => 'KSALESMARKETING',
        'aos'            => 'KAOS',
        'dokterbedah'    => 'KDOKTERBEDAH',
        'reseller'       => 'KRESELLER',
        'dokterpj'       => 'KDOKTERPJ',
        'kolomdokter'    => 'KTAMPILDIDOKTER',
        'kolomperawat'   => 'KTAMPILDIPERAWAT',
        'kolomresep'     => 'KTAMPILDIRESEP',
        'dokterinsider'  => 'KDOKTERINSIDER',
    ];

    public string $search = '';
    public string $fCabang = '';
    public string $fAktif = '1';

    public bool $showModal = false;
    public ?int $editingId = null;

    // Header
    public string $kode = '';
    public string $nama = '';
    public int $kategori = 4;
    public int $jeniskaryawan = 0;
    public int $cabang = 0;
    public bool $aktif = true;

    // Tab POS
    public array $flags = [];

    // Tab Alamat & Identitas
    public ?string $alamat = null;
    public ?int $kota = null;
    public ?int $kecamatan = null;
    public ?string $nohp = null;
    public ?string $email = null;
    public int $kelamin = 0;
    public ?string $tgllahir = null;
    public ?string $noktp = null;
    public ?int $user = null;
    public ?string $tgljoin = null;

    // Tab Payroll
    public ?string $nik = null;
    public ?string $namapanjang = null;
    public ?string $kodeinsider = null;
    public ?int $kelompokfu = null;

    // label awal untuk search-select saat edit
    public array $labels = [];

    protected function rules(): array
    {
        return [
            'kode'      => ['required', 'string', 'max:100', Rule::unique('bkontak', 'KKODE')->ignore($this->editingId, 'KID')],
            'nama'      => ['required', 'string', 'max:255'],
            'kategori'  => ['required', 'integer'],
            'cabang'    => ['nullable', 'integer'],
            'email'     => ['nullable', 'email', 'max:50'],
            'tgllahir'  => ['nullable', 'date'],
            'tgljoin'   => ['nullable', 'date'],
        ];
    }

    public function mount(): void
    {
        $this->fCabang = $this->defaultCabang();
    }

    /** Cabang login user (kalau aktif) - dipakai default filter list & field form baru. */
    private function defaultCabang(): string
    {
        $ucabang = (int) (auth()->user()->UCABANG ?? 0);

        return Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFAktif(): void { $this->resetPage(); }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $k = Contact::findOrFail($id);

        $this->editingId     = $k->KID;
        $this->kode          = $k->KKODE ?? '';
        $this->nama          = $k->KNAMA ?? '';
        $this->kategori      = (int) $k->KTIPE ?: 4;
        $this->jeniskaryawan = (int) $k->KJENISKARYAWAN;
        $this->cabang        = (int) $k->KCABANG;
        $this->aktif         = (int) $k->KAKTIF !== 0;

        foreach (self::POS_FLAGS as $key => $col) {
            $this->flags[$key] = (int) $k->{$col} === 1;
        }

        $this->alamat      = $k->K1ALAMAT;
        $this->kota        = $k->K1KOTA ? (int) $k->K1KOTA : null;
        $this->kecamatan   = $k->K1KECAMATAN ? (int) $k->K1KECAMATAN : null;
        $this->nohp        = $k->K1TELP1;
        $this->email       = $k->K1EMAIL;
        $this->kelamin     = (int) $k->KJENISKELAMIN;
        $this->tgllahir    = $this->cleanDate($k->KTGLLAHIR);
        $this->noktp       = $k->KNOKTP;
        $this->user        = $k->KUSER ? (int) $k->KUSER : null;
        $this->tgljoin     = $this->cleanDate($k->KTGLJOIN);

        $this->nik         = $k->KIDEMPLOYEE;
        $this->namapanjang = $k->KNAMAEMPLOYEE;
        $this->kodeinsider = $k->KKODEINSIDER;
        $this->kelompokfu  = $k->KKELOMPOKFU ? (int) $k->KKELOMPOKFU : null;

        $this->loadLabels();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    private function cleanDate($v): ?string
    {
        $v = substr((string) $v, 0, 10);

        return ($v === '' || $v === '0000-00-00') ? null : $v;
    }

    private function loadLabels(): void
    {
        $this->labels = [
            'kota'       => $this->kota ? DB::table('bwilayah')->where('bwid', $this->kota)->value('bnama') : null,
            'kecamatan'  => $this->kecamatan ? DB::table('bwilayah')->where('bwid', $this->kecamatan)->value('bnama') : null,
            'user'       => $this->user ? DB::table('auser')->where('UID', $this->user)->value('UKODE') : null,
            'kelompokfu' => $this->kelompokfu ? DB::table('blain')->where('lid', $this->kelompokfu)->value('lkode') : null,
        ];
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'KKODE'          => $this->kode,
            'KNAMA'          => $this->nama,
            'KTIPE'          => $this->kategori ?: 4,
            'KJENISKARYAWAN' => $this->jeniskaryawan ?: 0,
            'KCABANG'        => $this->cabang ?: 0,
            'KAKTIF'         => $this->aktif ? 1 : 0,

            'K1ALAMAT'       => $this->alamat ?: null,
            'K1KOTA'         => $this->kota ?: null,
            'K1KECAMATAN'    => $this->kecamatan ?: null,
            'K1TELP1'        => $this->nohp ?: null,
            'K1EMAIL'        => $this->email ?: null,
            'KJENISKELAMIN'  => $this->kelamin ?: 0,
            'KTGLLAHIR'      => $this->tgllahir ?: null,
            'KNOKTP'         => $this->noktp ?: null,
            'KUSER'          => $this->user ?: null,
            'KTGLJOIN'       => $this->tgljoin ?: null,

            'KIDEMPLOYEE'    => $this->nik ?: null,
            'KNAMAEMPLOYEE'  => $this->namapanjang ?: null,
            'KKODEINSIDER'   => $this->kodeinsider ?: null,
            'KKELOMPOKFU'    => $this->kelompokfu ?: null,
        ];

        foreach (self::POS_FLAGS as $key => $col) {
            $payload[$col] = ! empty($this->flags[$key]) ? 1 : 0;
        }

        if ($this->editingId) {
            $payload['KMODIFU'] = auth()->id();
            Contact::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/karyawan', $this->editingId, 'Ubah karyawan ' . $this->nama);
        } else {
            $payload['KCREATEU'] = auth()->id();
            $k = Contact::create($payload);
            activity_log('create', 'master/karyawan', $k->KID, 'Tambah karyawan ' . $this->nama);
        }

        session()->flash('status', 'Data karyawan disimpan.');
        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        Contact::whereKey($id)->update(['KAKTIF' => 0, 'KMODIFU' => auth()->id()]);
        activity_log('delete', 'master/karyawan', $id, 'Nonaktifkan karyawan');
        session()->flash('status', 'Karyawan dinonaktifkan.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'kode', 'nama', 'jeniskaryawan', 'cabang', 'flags',
            'alamat', 'kota', 'kecamatan', 'nohp', 'email', 'kelamin', 'tgllahir', 'noktp', 'user', 'tgljoin',
            'nik', 'namapanjang', 'kodeinsider', 'kelompokfu', 'labels',
        ]);
        $this->kategori = 4;
        $this->aktif = true;
        $this->cabang = (int) $this->defaultCabang();
        $this->resetErrorBag();
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = Contact::query()
            ->from('bkontak as A')
            ->leftJoin('bkontakjenis as J', 'A.KJENISKARYAWAN', '=', 'J.KJID')
            ->leftJoin('bgudang as G', 'A.KCABANG', '=', 'G.GID')
            ->where('A.KTIPE', Contact::TIPE_KARYAWAN)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('A.KKODE', 'like', "%{$q}%")
                ->orWhere('A.KNAMA', 'like', "%{$q}%")
                ->orWhere('A.K1TELP1', 'like', "%{$q}%")))
            ->when($this->fCabang !== '', fn ($b) => $b->where('A.KCABANG', (int) $this->fCabang))
            ->when($this->fAktif === '1', fn ($b) => $b->where('A.KAKTIF', '<>', 0))
            ->orderBy('A.KNAMA')
            ->paginate(20, ['A.KID', 'A.KKODE', 'A.KNAMA', 'A.K1TELP1', 'A.KAKTIF', 'J.KJNAMA as jenis', 'G.GNAMA as cabang']);

        return view('livewire.master.karyawan-manager', [
            'rows'      => $rows,
            'kategoris' => ContactType::orderBy('KTNAMA')->get(['KTID', 'KTNAMA']),
            'jenisList' => DB::table('bkontakjenis')->orderBy('KJNAMA')->get(['KJID', 'KJNAMA']),
            'branches'  => Branch::options(),
            'posFlags'  => array_keys(self::POS_FLAGS),
        ]);
    }
}
