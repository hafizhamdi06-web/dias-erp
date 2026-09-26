<?php

namespace App\Livewire\Master;

use App\Models\Branch;
use App\Models\Promo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Master Promo - header (emasterpromou), sesuai spesifikasi "PROMPT.xlsx" (sheet PROMO):
 * Kode, Nama, Tanggal Berlaku (dari-sampai), Jenis Pelanggan, Jenis Promo, Aktif, + pilihan
 * Cabang (`emasterpromoc`) dan Tipe Pelanggan (`emasterpromokontaktipe`) - lihat komentar
 * $cabangPilih/$tipePilih utk detail pemetaan tabel.
 *
 * Detail grid kombinasi (`emasterpromod`) khusus MPUJENISPROMO=1 "Kombinasi 1" & =0 "Biasa"
 * ada di tab terpisah (`PromoKombinasiForm`/`PromoBiasaForm`, dibuka via openKombinasi()/
 * openBiasa()). 3 jenis promo lain (Kombinasi 2/Per Jenis Item/Kombinasi 3) detailnya BELUM
 * digarap - tidak didokumentasikan user sejauh ini.
 */
#[Layout('layouts.app')]
#[Title('Master Promo')]
class PromoManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $search = '';
    public string $fAktif = '1'; // '', '1', '0'
    /** Filter tanggal berlaku - saring promo yg PERIODE-nya (MPUTANGGAL1-2) beririsan dgn
     *  rentang ini. Kosongkan salah satu/kedua utk rentang terbuka (tanpa batas awal/akhir).
     *  Default saat halaman dibuka = 1 s/d akhir bulan berjalan (lihat mount()), bukan kosong -
     *  "Hapus filter" tetap mengosongkan total (bukan kembali ke default bulan ini). */
    public ?string $fTanggalDari = null;
    public ?string $fTanggalSampai = null;
    /** Filter Cabang (bgudang.GID, as string) - saring promo yg PUNYA baris `emasterpromoc`
     *  utk cabang ini. Kosong = tampilkan semua promo apa adanya (TIDAK ikut menerapkan aturan
     *  "kosong=tidak aktif" milik POS - ini murni filter tampilan daftar, bukan pengecekan
     *  keberlakuan promo). */
    public string $fCabang = '';
    public bool $showModal = false;
    public ?int $editingId = null;

    public function mount(): void
    {
        $this->fTanggalDari = now()->startOfMonth()->toDateString();
        $this->fTanggalSampai = now()->endOfMonth()->toDateString();

        $ucabang = (int) (auth()->user()->UCABANG ?? 0);
        $this->fCabang = Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
    }

    public string $MPUKODE = '';
    public ?string $MPUNAMA = null;
    public ?string $MPUTANGGAL1 = null;
    public ?string $MPUTANGGAL2 = null;
    public int $MPUJENISPELANGGAN = 0;
    public int $MPUJENISPROMO = 0;
    public bool $MPUAKTIF = true;

    /**
     * Pilihan Cabang (bgudang.GID, as string) & Tipe Pelanggan (bkontaktipe.KTID, as string)
     * per promo - masing2 disimpan sbg baris terpisah di child table sendiri (BUKAN kolom
     * comma-string di header, pola BEDA dari mis. auser.UCABANGPILIH), sesuai struktur tabel
     * yg sudah ada di skema produksi:
     *   - `emasterpromoc` (13.862 baris nyata): MPCIDU (FK promo) / MPCCABANG (bgudang.GID) /
     *     MPCURUTAN - dikonfirmasi via sample data nyata (beberapa baris per promo, tiap baris
     *     1 GID).
     *   - `emasterpromokontaktipe` (0 baris nyata, tapi struktur & nama tabel eksplisit cocok -
     *     dikonfirmasi ke user krn tabel `emasterpromot` yg awalnya disebut TERNYATA skemanya
     *     sama sekali tidak menyebut tipe pelanggan/kontak): MPKTIDU (FK promo) / MPKTTIPE
     *     (bkontaktipe.KTID) / MPKTURUTAN.
     * Disinkron ulang (delete semua baris lama lalu insert ulang sesuai pilihan skrg) tiap kali
     * save() - simpel & konsisten dgn cara Kombinasi/Biasa "Pilihan" disimpan (bukan API-friendly
     * micro-diff, tapi cukup krn jumlah baris kecil & bukan concurrent-edit hotspot).
     */
    public array $cabangPilih = [];
    public array $tipePilih = [];

    protected function rules(): array
    {
        return [
            'MPUKODE'           => ['required', 'string', 'max:50', Rule::unique('emasterpromou', 'MPUKODE')->ignore($this->editingId, 'MPUID')],
            'MPUNAMA'           => ['nullable', 'string', 'max:100'],
            'MPUTANGGAL1'       => ['nullable', 'date'],
            'MPUTANGGAL2'       => ['nullable', 'date', 'after_or_equal:MPUTANGGAL1'],
            'MPUJENISPELANGGAN' => ['required', 'integer', Rule::in(array_keys(Promo::JENIS_PELANGGAN))],
            'MPUJENISPROMO'     => ['required', 'integer', Rule::in(array_keys(Promo::JENIS_PROMO))],
            'MPUAKTIF'          => ['boolean'],
            'cabangPilih'       => ['array'],
            'cabangPilih.*'     => ['integer'],
            'tipePilih'         => ['array'],
            'tipePilih.*'       => ['integer'],
        ];
    }

    protected array $messages = [
        'MPUKODE.unique'         => 'Kode promo sudah dipakai.',
        'MPUTANGGAL2.after_or_equal' => 'Tanggal sampai tidak boleh sebelum tanggal dari.',
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

    public function updatingFCabang(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'MPUKODE', 'MPUNAMA', 'MPUTANGGAL1', 'MPUTANGGAL2', 'MPUJENISPELANGGAN', 'MPUJENISPROMO', 'cabangPilih', 'tipePilih']);
        $this->MPUAKTIF = true;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $p = Promo::findOrFail($id);

        $this->editingId         = $p->MPUID;
        $this->MPUKODE            = $p->MPUKODE ?? '';
        $this->MPUNAMA            = $p->MPUNAMA;
        $this->MPUTANGGAL1        = $this->cleanDate($p->MPUTANGGAL1);
        $this->MPUTANGGAL2        = $this->cleanDate($p->MPUTANGGAL2);
        $this->MPUJENISPELANGGAN  = (int) $p->MPUJENISPELANGGAN;
        $this->MPUJENISPROMO      = (int) $p->MPUJENISPROMO;
        $this->MPUAKTIF           = (int) $p->MPUAKTIF === 1;

        $this->cabangPilih = DB::table('emasterpromoc')->where('MPCIDU', $id)
            ->orderBy('MPCURUTAN')->pluck('MPCCABANG')->map(fn ($v) => (string) $v)->all();
        $this->tipePilih = DB::table('emasterpromokontaktipe')->where('MPKTIDU', $id)
            ->orderBy('MPKTURUTAN')->pluck('MPKTTIPE')->map(fn ($v) => (string) $v)->all();

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

    public function selectAllTipe(): void
    {
        $this->tipePilih = DB::table('bkontaktipe')->orderBy('KTNAMA')->pluck('KTID')->map(fn ($v) => (string) $v)->all();
    }

    public function clearTipePilih(): void
    {
        $this->tipePilih = [];
    }

    private function cleanDate($v): ?string
    {
        $v = substr((string) $v, 0, 10);

        return ($v === '' || $v === '0000-00-00') ? null : $v;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'MPUKODE'           => $this->MPUKODE,
            'MPUNAMA'           => $this->MPUNAMA ?: null,
            'MPUTANGGAL1'       => $this->MPUTANGGAL1 ?: null,
            'MPUTANGGAL2'       => $this->MPUTANGGAL2 ?: null,
            'MPUJENISPELANGGAN' => $this->MPUJENISPELANGGAN,
            'MPUJENISPROMO'     => $this->MPUJENISPROMO,
            'MPUAKTIF'          => $this->MPUAKTIF ? 1 : 0,
        ];

        $promoId = DB::transaction(function () use ($payload) {
            if ($this->editingId) {
                $payload['MPUMODIFU'] = auth()->id();
                Promo::whereKey($this->editingId)->update($payload);
                $promoId = $this->editingId;
            } else {
                $payload['MPUCREATEU'] = auth()->id();
                $p = Promo::create($payload);
                $promoId = $p->MPUID;
            }

            // Sinkron pilihan Cabang & Tipe Pelanggan: hapus semua baris lama, insert ulang
            // sesuai pilihan skrg (simpel, jumlah baris kecil - bukan hotspot concurrent-edit).
            DB::table('emasterpromoc')->where('MPCIDU', $promoId)->delete();
            $urutan = 1;
            foreach (array_values($this->cabangPilih) as $gid) {
                DB::table('emasterpromoc')->insert(['MPCIDU' => $promoId, 'MPCCABANG' => (int) $gid, 'MPCURUTAN' => $urutan++]);
            }

            DB::table('emasterpromokontaktipe')->where('MPKTIDU', $promoId)->delete();
            $urutan = 1;
            foreach (array_values($this->tipePilih) as $ktid) {
                DB::table('emasterpromokontaktipe')->insert(['MPKTIDU' => $promoId, 'MPKTTIPE' => (int) $ktid, 'MPKTURUTAN' => $urutan++]);
            }

            return $promoId;
        });

        activity_log($this->editingId ? 'update' : 'create', 'master/promo', $promoId, ($this->editingId ? 'Ubah' : 'Tambah') . ' promo ' . $this->MPUKODE);

        session()->flash('status', 'Promo disimpan.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        Promo::whereKey($id)->update(['MPUAKTIF' => 0]);
        activity_log('delete', 'master/promo', $id, 'Nonaktifkan promo');
        session()->flash('status', 'Promo dinonaktifkan.');
    }

    /** Buka tab detail kombinasi (emasterpromod) - hanya relevan utk MPUJENISPROMO=1 "Kombinasi 1". */
    public function openKombinasi(int $id): void
    {
        $kode = (string) Promo::whereKey($id)->value('MPUKODE');
        $this->dispatch('open-tab', cmp: 'master.promo-kombinasi-form', args: ['promoId' => $id],
            label: 'Kombinasi: ' . $kode, icon: 'fas fa-tags');
    }

    /** Buka tab detail promo (emasterpromod) - hanya relevan utk MPUJENISPROMO=0 "Biasa". */
    public function openBiasa(int $id): void
    {
        $kode = (string) Promo::whereKey($id)->value('MPUKODE');
        $this->dispatch('open-tab', cmp: 'master.promo-biasa-form', args: ['promoId' => $id],
            label: 'Detail: ' . $kode, icon: 'fas fa-tags');
    }

    public function render()
    {
        $q = trim($this->search);

        $rows = Promo::query()
            // PENTING: OR harus dibungkus where(closure) - kalau tidak, precedence SQL bikin
            // OR ini "membocorkan" filter lain (aktif/tanggal jadi seperti tidak berlaku utk
            // baris yg cocok kode/nama) krn AND mengikat lebih erat drpd OR mentah.
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('MPUKODE', 'like', "%{$q}%")->orWhere('MPUNAMA', 'like', "%{$q}%")))
            ->when($this->fAktif !== '', fn ($b) => $b->where('MPUAKTIF', $this->fAktif === '1' ? 1 : 0))
            // Irisan rentang: periode promo (MPUTANGGAL1-2) vs rentang filter - promo tanpa
            // tanggal sama sekali (NULL, jarang tp ada) ikut disaring keluar begitu filter diisi,
            // krn memang tidak jelas kapan "berlaku"-nya.
            ->when($this->fTanggalSampai, fn ($b) => $b->where('MPUTANGGAL1', '<=', $this->fTanggalSampai))
            ->when($this->fTanggalDari, fn ($b) => $b->where('MPUTANGGAL2', '>=', $this->fTanggalDari))
            // Cabang: cuma promo yg PUNYA baris emasterpromoc utk cabang ini yg lolos - murni
            // filter tampilan daftar (beda dari aturan "kosong=tidak aktif" di POS, disini
            // kosong filter = tampilkan semua promo apa adanya, tidak menyaring apa2).
            ->when($this->fCabang !== '', fn ($b) => $b->whereExists(
                fn ($q2) => $q2->selectRaw(1)->from('emasterpromoc as c')
                    ->whereColumn('c.MPCIDU', 'emasterpromou.MPUID')
                    ->where('c.MPCCABANG', $this->fCabang)
            ))
            ->orderByDesc('MPUID')
            ->paginate(20);

        return view('livewire.master.promo-manager', [
            'rows'     => $rows,
            'branches' => Branch::options(),
            'tipeList' => DB::table('bkontaktipe')->orderBy('KTNAMA')->get(['KTID', 'KTNAMA']),
        ]);
    }
}
