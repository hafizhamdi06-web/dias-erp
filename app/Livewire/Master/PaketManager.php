<?php

namespace App\Livewire\Master;

use App\Models\Branch;
use App\Models\Package;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Master Paket - header (epaketu). Baris item yg dibundel (epaketd) dikelola di
 * tab terpisah (`PaketDetailForm`, dibuka via openDetail()) - pola sama persis
 * spt Master Promo (PromoManager -> PromoKombinasiForm/PromoBiasaForm).
 *
 * Lihat docblock App\Models\Package utk sumber pemetaan kolom & kolom yg
 * sengaja dilewati di v1.
 */
#[Layout('layouts.app')]
#[Title('Master Paket')]
class PaketManager extends Component
{
    use WithPagination;

    public ?string $tabKey = null;

    public string $search = '';
    public string $fAktif = '1'; // '', '1', '0'
    /** Filter tanggal berlaku - saring paket yg PERIODE-nya (PUTANGGAL1-2) beririsan dgn
     *  rentang ini. Kosongkan salah satu/kedua utk rentang terbuka (tanpa batas awal/akhir).
     *  Default saat halaman dibuka = tanggal 1 s/d akhir bulan berjalan (lihat mount()), bukan
     *  kosong - "Hapus filter" tetap mengosongkan total (bukan kembali ke default bulan ini).
     *  Pola identik PromoManager. */
    public ?string $fTanggalDari = null;
    public ?string $fTanggalSampai = null;

    public bool $showModal = false;
    public ?int $editingId = null;

    public string $PUKODE = '';
    public ?string $PUNAMA = null;
    public ?string $PUTANGGAL1 = null;
    public ?string $PUTANGGAL2 = null;
    public int $PUJENISPELANGGAN = 0;
    public bool $PUAKTIF = true;

    public ?float $PUJUMLAH = null;       // jumlah kedatangan/sesi
    public ?float $PUUMUR = null;         // masa berlaku dlm HARI sejak kedatangan pertama
    public bool $PUPASIENBARU = false;
    public bool $PUKONSULSAJA = false;
    public bool $PUCETAKHEADERSAJA = false;

    public bool $PUPAKAIJAM = false;
    public ?string $PUJAM1 = null;
    public ?string $PUJAM2 = null;

    // Kolom nol-referensi-kode, disimpan apa adanya sesuai instruksi user (AskUserQuestion).
    public bool $PUKOMBINASI = false;
    public bool $PUDAPATKOMISI = false;
    public bool $PUPROMO = false;
    public ?float $PUMAXPASIEN = null;
    public ?float $PUMINTRANSAKSIPERIV = null;
    public bool $PUBERDUA = false;

    /**
     * PUSEMUACABANG + epaketc (PCIDU/PCCABANG/PCURUTAN). Riset kode CI3 menemukan
     * legacy TIDAK PUNYA logika konsisten yg menghubungkan keduanya (satu2nya
     * query nyata yg pakai epaketc adalah INNER JOIN polos, mengabaikan
     * PUSEMUACABANG sama sekali) - explicitly flagged "genuinely unresolved" oleh
     * riset. Krn epaketc kosong total di produksi (nol risiko kompatibilitas),
     * desain di sini SENGAJA memakai konvensi bersih yg SAMA spt Master Promo:
     * PUSEMUACABANG=1 -> berlaku semua cabang (epaketc diabaikan saat pengecekan
     * nanti di POS); PUSEMUACABANG=0 -> hanya cabang yg dipilih, KOSONG = paket
     * TIDAK aktif di cabang manapun.
     */
    public bool $PUSEMUACABANG = false;
    public array $cabangPilih = [];

    public function mount(): void
    {
        $this->fTanggalDari = now()->startOfMonth()->toDateString();
        $this->fTanggalSampai = now()->endOfMonth()->toDateString();
    }

    protected function rules(): array
    {
        return [
            'PUKODE'              => ['required', 'string', 'max:100', Rule::unique('epaketu', 'PUKODE')->ignore($this->editingId, 'PUID')],
            'PUNAMA'              => ['nullable', 'string', 'max:255'],
            'PUTANGGAL1'          => ['nullable', 'date'],
            'PUTANGGAL2'          => ['nullable', 'date', 'after_or_equal:PUTANGGAL1'],
            'PUJENISPELANGGAN'    => ['required', 'integer', Rule::in(array_keys(Package::JENIS_PELANGGAN))],
            'PUAKTIF'             => ['boolean'],
            'PUJUMLAH'            => ['nullable', 'numeric', 'min:0'],
            'PUUMUR'              => ['nullable', 'numeric', 'min:0'],
            'PUJAM1'              => ['nullable'],
            'PUJAM2'              => ['nullable'],
            'PUMAXPASIEN'         => ['nullable', 'numeric', 'min:0'],
            'PUMINTRANSAKSIPERIV' => ['nullable', 'numeric', 'min:0'],
            'cabangPilih'         => ['array'],
            'cabangPilih.*'       => ['integer'],
        ];
    }

    protected array $messages = [
        'PUKODE.unique'          => 'Kode paket sudah dipakai.',
        'PUTANGGAL2.after_or_equal' => 'Tanggal sampai tidak boleh sebelum tanggal dari.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFAktif(): void
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

    public function create(): void
    {
        $this->reset([
            'editingId', 'PUKODE', 'PUNAMA', 'PUTANGGAL1', 'PUTANGGAL2', 'PUJENISPELANGGAN',
            'PUJUMLAH', 'PUUMUR', 'PUPASIENBARU', 'PUKONSULSAJA', 'PUCETAKHEADERSAJA',
            'PUPAKAIJAM', 'PUJAM1', 'PUJAM2', 'PUKOMBINASI', 'PUDAPATKOMISI', 'PUPROMO',
            'PUMAXPASIEN', 'PUMINTRANSAKSIPERIV', 'PUBERDUA', 'PUSEMUACABANG', 'cabangPilih',
        ]);
        $this->PUAKTIF = true;
        $this->PUJUMLAH = 1;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $p = Package::findOrFail($id);

        $this->editingId = $p->PUID;
        $this->PUKODE = $p->PUKODE ?? '';
        $this->PUNAMA = $p->PUNAMA;
        $this->PUTANGGAL1 = $this->cleanDate($p->PUTANGGAL1);
        $this->PUTANGGAL2 = $this->cleanDate($p->PUTANGGAL2);
        $this->PUJENISPELANGGAN = (int) $p->PUJENISPELANGGAN;
        $this->PUAKTIF = (int) $p->PUAKTIF === 1;
        $this->PUJUMLAH = $p->PUJUMLAH;
        $this->PUUMUR = $p->PUUMUR;
        $this->PUPASIENBARU = (int) $p->PUPASIENBARU === 1;
        $this->PUKONSULSAJA = (int) $p->PUKONSULSAJA === 1;
        $this->PUCETAKHEADERSAJA = (int) $p->PUCETAKHEADERSAJA === 1;
        $this->PUPAKAIJAM = (int) $p->PUPAKAIJAM === 1;
        $this->PUJAM1 = $this->cleanTime($p->PUJAM1);
        $this->PUJAM2 = $this->cleanTime($p->PUJAM2);
        $this->PUKOMBINASI = (int) $p->PUKOMBINASI === 1;
        $this->PUDAPATKOMISI = (int) $p->PUDAPATKOMISI === 1;
        $this->PUPROMO = (int) $p->PUPROMO === 1;
        $this->PUMAXPASIEN = $p->PUMAXPASIEN;
        $this->PUMINTRANSAKSIPERIV = $p->PUMINTRANSAKSIPERIV;
        $this->PUBERDUA = (int) $p->PUBERDUA === 1;
        $this->PUSEMUACABANG = (int) $p->PUSEMUACABANG === 1;

        $this->cabangPilih = DB::table('epaketc')->where('PCIDU', $id)
            ->orderBy('PCURUTAN')->pluck('PCCABANG')->map(fn ($v) => (string) $v)->all();

        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function selectAllCabang(): void
    {
        $this->cabangPilih = Branch::options()->pluck('GID')->map(fn ($v) => (string) $v)->all();
    }

    public function clearCabangPilih(): void
    {
        $this->cabangPilih = [];
    }

    private function cleanDate($v): ?string
    {
        $v = substr((string) $v, 0, 10);

        return ($v === '' || $v === '0000-00-00') ? null : $v;
    }

    private function cleanTime($v): ?string
    {
        $v = substr((string) $v, 0, 5);

        return ($v === '' || $v === '00:00') ? null : $v;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'PUKODE'              => $this->PUKODE,
            'PUNAMA'              => $this->PUNAMA ?: null,
            'PUTANGGAL1'          => $this->PUTANGGAL1 ?: null,
            'PUTANGGAL2'          => $this->PUTANGGAL2 ?: null,
            'PUJENISPELANGGAN'    => $this->PUJENISPELANGGAN,
            'PUAKTIF'             => $this->PUAKTIF ? 1 : 0,
            'PUJUMLAH'            => $this->PUJUMLAH ?: 0,
            'PUUMUR'              => $this->PUUMUR ?: 0,
            'PUPASIENBARU'        => $this->PUPASIENBARU ? 1 : 0,
            'PUKONSULSAJA'        => $this->PUKONSULSAJA ? 1 : 0,
            'PUCETAKHEADERSAJA'   => $this->PUCETAKHEADERSAJA ? 1 : 0,
            'PUPAKAIJAM'          => $this->PUPAKAIJAM ? 1 : 0,
            'PUJAM1'              => $this->PUPAKAIJAM ? ($this->PUJAM1 ?: null) : null,
            'PUJAM2'              => $this->PUPAKAIJAM ? ($this->PUJAM2 ?: null) : null,
            'PUKOMBINASI'         => $this->PUKOMBINASI ? 1 : 0,
            'PUDAPATKOMISI'       => $this->PUDAPATKOMISI ? 1 : 0,
            'PUPROMO'             => $this->PUPROMO ? 1 : 0,
            'PUMAXPASIEN'         => $this->PUMAXPASIEN ?: 0,
            'PUMINTRANSAKSIPERIV' => $this->PUMINTRANSAKSIPERIV ?: 0,
            'PUBERDUA'            => $this->PUBERDUA ? 1 : 0,
            'PUSEMUACABANG'       => $this->PUSEMUACABANG ? 1 : 0,
        ];

        $packageId = DB::transaction(function () use ($payload) {
            if ($this->editingId) {
                $payload['PUMODIFU'] = auth()->id();
                Package::whereKey($this->editingId)->update($payload);
                $packageId = $this->editingId;
            } else {
                $payload['PUCREATEU'] = auth()->id();
                $p = Package::create($payload);
                $packageId = $p->PUID;
            }

            // Sinkron pilihan Cabang: hapus semua baris lama, insert ulang sesuai pilihan
            // skrg - pola sama spt emasterpromoc di Master Promo (simpel, jumlah baris
            // kecil). Tetap disinkron walau PUSEMUACABANG=1 (checkbox cuma menyembunyikan
            // tab di UI, bukan menghapus pilihan - kalau nanti dibalik ke 0, pilihan lama
            // masih ada, tidak hilang).
            DB::table('epaketc')->where('PCIDU', $packageId)->delete();
            $urutan = 1;
            foreach (array_values($this->cabangPilih) as $gid) {
                DB::table('epaketc')->insert(['PCIDU' => $packageId, 'PCCABANG' => (int) $gid, 'PCURUTAN' => $urutan++]);
            }

            return $packageId;
        });

        activity_log($this->editingId ? 'update' : 'create', 'master/paket', $packageId, ($this->editingId ? 'Ubah' : 'Tambah') . ' paket ' . $this->PUKODE);

        session()->flash('status', 'Paket disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        Package::whereKey($id)->update(['PUAKTIF' => 0]);
        activity_log('delete', 'master/paket', $id, 'Nonaktifkan paket');
        session()->flash('status', 'Paket dinonaktifkan.');
    }

    /** Buka tab detail item paket (epaketd). */
    public function openDetail(int $id): void
    {
        $kode = (string) Package::whereKey($id)->value('PUKODE');
        $this->dispatch('open-tab', cmp: 'master.paket-detail-form', args: ['packageId' => $id],
            label: 'Item Paket: ' . $kode, icon: 'fas fa-boxes-packing');
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = Package::query()
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('PUKODE', 'like', "%{$q}%")->orWhere('PUNAMA', 'like', "%{$q}%")))
            ->when($this->fAktif !== '', fn ($b) => $b->where('PUAKTIF', $this->fAktif === '1' ? 1 : 0))
            // Irisan rentang: periode paket (PUTANGGAL1-2) vs rentang filter - paket tanpa
            // tanggal sama sekali (NULL) ikut disaring keluar begitu filter diisi, krn memang
            // tidak jelas kapan "berlaku"-nya. Pola identik PromoManager.
            ->when($this->fTanggalSampai, fn ($b) => $b->where('PUTANGGAL1', '<=', $this->fTanggalSampai))
            ->when($this->fTanggalDari, fn ($b) => $b->where('PUTANGGAL2', '>=', $this->fTanggalDari))
            ->orderBy('PUKODE')
            ->paginate(20);

        return view('livewire.master.paket-manager', [
            'rows'     => $rows,
            'branches' => Branch::options(),
        ]);
    }
}
