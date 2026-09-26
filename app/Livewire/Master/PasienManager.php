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
#[Title('Data Pasien')]
class PasienManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $search = '';
    public string $fKategori = '';   // '', 'tunai', 'member', 'semua'
    public string $fCabang = '';
    public string $fAktif = '1';

    public bool $showModal = false;
    public ?int $editingId = null;

    public string $kode = '';
    public string $nama = '';
    public int $kategori = 14;
    public ?string $nomember = null;
    public ?string $idpasien = null;
    public ?string $noktp = null;
    public int $cabang = 0;
    public ?string $tglkontrak = null;
    public ?string $tgllahir = null;
    public ?string $tempatlahir = null;
    public ?string $pekerjaan = null;
    public int $kelamin = 0;
    public int $barulama = 0;

    public ?string $alamat = null;
    public ?int $kota = null;
    public ?int $kecamatan = null;
    public ?string $telp = null;
    public ?string $email = null;

    public ?string $nokartu = null;
    public ?string $kodetada = null;
    public ?int $karyawan = null;
    public ?int $karyawantraining = null;
    public ?int $marketingsource = null;
    public ?string $insider = null;
    public bool $aktif = true;

    public array $labels = [];

    protected function rules(): array
    {
        return [
            'kode'     => ['required', 'string', 'max:100', Rule::unique('bkontak', 'KKODE')->ignore($this->editingId, 'KID')],
            'nama'     => ['required', 'string', 'max:255'],
            'kategori' => ['required', 'integer'],
            'cabang'   => ['nullable', 'integer'],
            'email'    => ['nullable', 'email', 'max:50'],
            'tgllahir' => ['nullable', 'date'],
            'tglkontrak' => ['nullable', 'date'],
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
    public function updatingFKategori(): void { $this->resetPage(); }
    public function updatingFCabang(): void { $this->resetPage(); }
    public function updatingFAktif(): void { $this->resetPage(); }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $p = Contact::findOrFail($id);

        $this->editingId    = $p->KID;
        $this->kode         = $p->KKODE ?? '';
        $this->nama         = $p->KNAMA ?? '';
        $this->kategori     = (int) $p->KTIPE ?: 14;
        $this->nomember     = $p->KNOMEMBER;
        $this->idpasien     = $p->KIDPASIEN;
        $this->noktp        = $p->KNOKTP;
        $this->cabang       = (int) $p->KCABANG;
        $this->tglkontrak   = $this->cleanDate($p->KTGLKONTRAK);
        $this->tgllahir     = $this->cleanDate($p->KTGLLAHIR);
        $this->tempatlahir  = $p->KTEMPATLAHIR;
        $this->pekerjaan    = $p->KPEKERJAAN;
        $this->kelamin      = (int) $p->KJENISKELAMIN;
        $this->barulama     = (int) $p->KBARULAMA;

        $this->alamat       = $p->K1ALAMAT;
        $this->kota         = $p->K1KOTA ? (int) $p->K1KOTA : null;
        $this->kecamatan    = $p->K1KECAMATAN ? (int) $p->K1KECAMATAN : null;
        $this->telp         = $p->K1TELP1;
        $this->email        = $p->K1EMAIL;

        $this->nokartu      = $p->KCARD;
        $this->kodetada     = $p->KKODELAMA;
        $this->karyawan     = $p->KKARYAWAN ? (int) $p->KKARYAWAN : null;
        $this->karyawantraining = $p->KKARYAWANTRAINING ? (int) $p->KKARYAWANTRAINING : null;
        $this->marketingsource  = $p->KMARKETINGSOURCE ? (int) $p->KMARKETINGSOURCE : null;
        $this->insider      = $p->KREFF;
        $this->aktif        = (int) $p->KAKTIF !== 0;

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
            'kota'      => $this->kota ? DB::table('bwilayah')->where('bwid', $this->kota)->value('bnama') : null,
            'kecamatan' => $this->kecamatan ? DB::table('bwilayah')->where('bwid', $this->kecamatan)->value('bnama') : null,
            'karyawan'  => $this->karyawan ? DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA') : null,
            'karyawantraining' => $this->karyawantraining ? DB::table('bkontak')->where('KID', $this->karyawantraining)->value('KNAMA') : null,
            'marketingsource'  => $this->marketingsource ? DB::table('blain')->where('lid', $this->marketingsource)->value('lkode') : null,
        ];
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'KKODE'         => $this->kode,
            'KNAMA'         => $this->nama,
            'KTIPE'         => $this->kategori ?: 14,
            'KNOMEMBER'     => $this->nomember ?: null,
            'KIDPASIEN'     => $this->idpasien ?: null,
            'KNOKTP'        => $this->noktp ?: null,
            'KCABANG'       => $this->cabang ?: 0,
            'KTGLKONTRAK'   => $this->tglkontrak ?: null,
            'KTGLLAHIR'     => $this->tgllahir ?: null,
            'KTEMPATLAHIR'  => $this->tempatlahir ?: null,
            'KPEKERJAAN'    => $this->pekerjaan ?: null,
            'KJENISKELAMIN' => $this->kelamin ?: 0,
            'KBARULAMA'     => $this->barulama ?: 0,

            'K1ALAMAT'      => $this->alamat ?: null,
            'K1KOTA'        => $this->kota ?: null,
            'K1KECAMATAN'   => $this->kecamatan ?: null,
            'K1TELP1'       => $this->telp ?: null,
            'K1EMAIL'       => $this->email ?: null,

            'KCARD'             => $this->nokartu ?: null,
            'KKODELAMA'         => $this->kodetada ?: null,
            'KKARYAWAN'         => $this->karyawan ?: null,
            'KKARYAWANTRAINING' => $this->karyawantraining ?: null,
            'KMARKETINGSOURCE'  => $this->marketingsource ?: null,
            'KREFF'             => $this->insider ?: null,
            'KAKTIF'            => $this->aktif ? 1 : 0,
        ];

        if ($this->editingId) {
            $payload['KMODIFU'] = auth()->id();
            Contact::whereKey($this->editingId)->update($payload);
            activity_log('update', 'master/pasien', $this->editingId, 'Ubah pasien ' . $this->nama);
        } else {
            $payload['KCREATEU'] = auth()->id();
            $p = Contact::create($payload);
            activity_log('create', 'master/pasien', $p->KID, 'Tambah pasien ' . $this->nama);
        }

        session()->flash('status', 'Data pasien disimpan.');
        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        Contact::whereKey($id)->update(['KAKTIF' => 0, 'KMODIFU' => auth()->id()]);
        activity_log('delete', 'master/pasien', $id, 'Nonaktifkan pasien');
        session()->flash('status', 'Pasien dinonaktifkan.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'kode', 'nama', 'nomember', 'idpasien', 'noktp', 'cabang',
            'tglkontrak', 'tgllahir', 'tempatlahir', 'pekerjaan', 'kelamin', 'barulama',
            'alamat', 'kota', 'kecamatan', 'telp', 'email',
            'nokartu', 'kodetada', 'karyawan', 'karyawantraining', 'marketingsource', 'insider', 'labels',
        ]);
        $this->kategori = 14;
        $this->aktif = true;
        $this->cabang = (int) $this->defaultCabang();
        $this->resetErrorBag();
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = Contact::query()
            ->from('bkontak as A')
            ->leftJoin('bkontaktipe as T', 'A.KTIPE', '=', 'T.KTID')
            ->leftJoin('bgudang as G', 'A.KCABANG', '=', 'G.GID')
            ->leftJoin('bwilayah as W', 'A.K1KOTA', '=', 'W.bwid')
            ->whereIn('A.KTIPE', Contact::pasienTypeIds() ?: [14, 12])
            ->when($q !== '', function ($b) use ($q) {
                // Jangan LIKE '%q%' di KNAMA (315rb+ baris, bisa timeout) - pakai FULLTEXT.
                Contact::applySearch($b, $q, 'A', ['KNOKTP']);
            })
            ->when($this->fKategori === 'tunai', fn ($b) => $b->where('A.KTIPE', 14))
            ->when($this->fKategori === 'member', fn ($b) => $b->where('A.KTIPE', 12))
            ->when($this->fCabang !== '', fn ($b) => $b->where('A.KCABANG', (int) $this->fCabang))
            ->when($this->fAktif === '1', fn ($b) => $b->where('A.KAKTIF', '<>', 0))
            // Jangan ORDER BY nama saat ada pencarian (FULLTEXT + sort = filesort, lambat).
            ->when($q === '', fn ($b) => $b->orderBy('A.KNAMA'))
            ->paginate(20, [
                'A.KID', 'A.KKODE', 'A.KNAMA', 'A.KIDPASIEN', 'A.KNOKTP', 'A.K1TELP1', 'A.KAKTIF',
                'T.KTNAMA as kategori', 'G.GNAMA as cabang', 'W.bnama as kota',
            ]);

        return view('livewire.master.pasien-manager', [
            'rows'      => $rows,
            'kategoris' => ContactType::where('KTPASIEN', 1)->orderBy('KTNAMA')->get(['KTID', 'KTNAMA']),
            'branches'  => Branch::options(),
        ]);
    }
}
