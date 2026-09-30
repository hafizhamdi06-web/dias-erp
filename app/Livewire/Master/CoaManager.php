<?php

namespace App\Livewire\Master;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Master Chart of Accounts (`bcoa`). VB6 asli: `bFrmCOA.frm`.
 *
 * **Pemetaan field** (dari `zField` VB6 baris 575 + rutin `Edit` baris 816-850):
 * Nomor=`CNOCOA` (UNIQUE di DB), Nama=`CNAMA`, Tipe=`CTIPE` (index combo, lihat `TIPE`),
 * Sub Dari=`CSUBDARI` (checkbox) + induknya=`CPARENT`, Mata Uang=`CUANG` (->`buang`),
 * Divisi=`CDIVISI` (->`bdivisi`), Bank=`CBANK` (->`bbank`), G/D=`CGD`.
 *
 * **3 kolom TURUNAN, dihitung sistem - bukan input user** (persis VB6):
 * 1. `CDC` (Debit/Kredit) dari `CTIPE`: tipe 0-6, 12, 13, 15 -> 'D', selain itu -> 'C'
 *    (VB6 baris 569-571).
 * 2. `CLEVEL` = level induk + 1 (VB6 baris 590-591). Kalau tanpa induk -> 1 (VB6 membiarkan
 *    default 0, TAPI semua baris root di data nyata ber-level 1 - ikut datanya).
 * 3. Induk OTOMATIS jadi grup: `update bcoa set CGD='G' where CID=<induk>` (VB6 baris 587).
 *    Jadi `CGD` boleh dipilih user ('D' detail / 'G' grup) tapi bisa ditimpa jadi 'G' begitu
 *    COA lain menjadikannya induk - itu memang maunya VB6.
 *
 * **`CURUTAN` SELALU 0** - di VB6 variabel `pUrutan` dideklarasikan tapi TIDAK PERNAH diisi
 * (baris 550 & 576), dan benar: 423/423 baris nyata `CURUTAN=0`. Kolom mati, ditulis 0.
 *
 * **`CNAMA1`/`CNAMA2`/`CNAMA3` (combo "Header 1/2/3" di VB6) TIDAK ADA di database ini** -
 * ada di `zField` VB6 tapi tidak ada di `SHOW CREATE TABLE bcoa`, jadi form VB6 versi itu
 * pasti error / dipakai di DB lain. TIDAK direplikasi.
 *
 * **Urutan tampil = `CNOCOA`** - penomoran COA sudah hierarkis (`1-01-02-01-00`), jadi sort
 * string saja sudah menghasilkan pohon yg benar; indentasi list pakai `CLEVEL`.
 *
 * **DEFER**: tab "Saldo Awal" VB6 (grid saldo awal per kontak) - itu menulis `ctransaksiu`
 * (`CUSUMBER='SA'`) + `bcoaSA` + memanggil fungsi DB `PCHAPUSSALDOAWAL`, yaitu DOKUMEN
 * TRANSAKSI, bukan master. Di luar scope "buat menu COA"; perlu riset sendiri.
 *
 * **TIDAK ADA tombol Hapus** - `bcoa.CID` dirujuk jurnal/transaksi (`cjurnald`, `ctransaksiu`,
 * `bcoatipe*`, dll). Dinonaktifkan lewat checkbox Aktif (`CACTIVE`) - kolom ini SUDAH dipakai
 * menyaring semua lookup COA modul Finance (`LookupController::coaRekening()`), jadi ini
 * mekanisme "hapus" yg aman. (Di VB6 `chkActive` ada tapi di-comment.)
 */
#[Layout('layouts.app')]
#[Title('Chart of Account')]
class CoaManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    /** Daftar tipe COA - URUTAN = nilai `CTIPE` (index combo VB6 baris 725-741). JANGAN diacak. */
    public const TIPE = [
        0  => 'Kas',
        1  => 'Bank',
        2  => 'Piutang',
        3  => 'Persediaan',
        4  => 'Aktiva Lancar Lainnya',
        5  => 'Aktiva Tetap',
        6  => 'Akumulasi Penyusutan',
        7  => 'Hutang',
        8  => 'Hutang Lancar Lainnya',
        9  => 'Hutang Jangka Panjang',
        10 => 'Modal',
        11 => 'Pendapatan',
        12 => 'Harga Pokok Penjualan',
        13 => 'Biaya',
        14 => 'Pendapatan Lain-Lain',
        15 => 'Biaya Lain-Lain',
        16 => 'Cash Back',
    ];

    /** Tipe yg saldo normalnya DEBIT (VB6 baris 570). Sisanya kredit. */
    public const TIPE_DEBIT = [0, 1, 2, 3, 4, 5, 6, 12, 13, 15];

    /**
     * Tipe yang tergolong "akun biaya": Harga Pokok Penjualan, Biaya, Biaya Lain-Lain.
     * Dipakai `LookupController::coaBiaya()` membatasi baris detail Kas/Bank KELUAR
     * (permintaan user 2026-09-30) - lihat docblock method itu.
     */
    public const TIPE_BIAYA = [12, 13, 15];

    public string $search = '';
    public string $fTipe = '';
    public string $fAktif = '1';

    public bool $showModal = false;
    public ?int $editingId = null;

    // --- field form ---
    public string $CNOCOA = '';
    public ?string $CNAMA = null;
    public ?int $CTIPE = null;
    public bool $CSUBDARI = false;
    public ?int $CPARENT = null;
    public ?string $parentLabel = null;
    public ?int $CUANG = null;
    public ?int $CDIVISI = null;
    public ?int $CBANK = null;
    public string $CGD = 'D';
    public bool $CACTIVE = true;

    /**
     * Ceklist "Dipakai di Kas Masuk / Kas Keluar" (`bcoa.CKASMASUK` / `CKASKELUAR`).
     *
     * INI BUKAN penanda akun kas/bank. Akun kas/bank ditentukan `CTIPE` (0=Kas, 1=Bank)
     * lewat `LookupController::coaRekening()`. Dua ceklist ini menyaring **AKUN LAWAN**
     * (baris detail) di form Kas/Bank Masuk & Keluar - `LookupController::coaBiaya()`,
     * pola sama CI3 `view_coa_kasmasuk()` / `view_coa_kaskeluar()`.
     *
     * Filternya SUDAH jalan sejak awal; yg belum ada cuma cara menyuntingnya dari
     * aplikasi - sebelum ini daftarnya cuma bisa diubah langsung di DB.
     */
    public bool $CKASMASUK = false;
    public bool $CKASKELUAR = false;

    protected function rules(): array
    {
        return [
            'CNOCOA'   => ['required', 'string', 'max:25', Rule::unique('bcoa', 'CNOCOA')->ignore($this->editingId, 'CID')],
            'CNAMA'    => ['required', 'string', 'max:150'],
            'CTIPE'    => ['required', 'integer'],
            'CSUBDARI' => ['boolean'],
            'CPARENT'  => ['nullable', 'integer', 'required_if:CSUBDARI,true'],
            'CUANG'    => ['nullable', 'integer'],
            'CDIVISI'  => ['nullable', 'integer'],
            'CBANK'    => ['nullable', 'integer'],
            'CGD'      => ['required', 'in:D,G'],
            'CACTIVE'  => ['boolean'],
            'CKASMASUK'  => ['boolean'],
            'CKASKELUAR' => ['boolean'],
        ];
    }

    protected array $messages = [
        'CNOCOA.required'   => 'Masukkan nomor COA.',
        'CNOCOA.unique'     => 'Nomor COA ini sudah dipakai.',
        'CNAMA.required'    => 'Masukkan nama.',
        'CTIPE.required'    => 'Masukkan tipe.',
        'CPARENT.required_if' => 'Induk COA belum dipilih.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFTipe(): void
    {
        $this->resetPage();
    }

    public function updatingFAktif(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(can_do('master/coa', 'add'), 403);

        $this->reset([
            'editingId', 'CNOCOA', 'CNAMA', 'CTIPE', 'CSUBDARI', 'CPARENT', 'parentLabel',
            'CUANG', 'CDIVISI', 'CBANK',
        ]);
        $this->CGD = 'D';
        $this->CACTIVE = true;
        $this->CKASMASUK = false;
        $this->CKASKELUAR = false;
        // VB6 default mata uang "Rp" (baris 745).
        $this->CUANG = (int) DB::table('buang')->where('UKODE', 'Rp')->value('UID') ?: null;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        abort_unless(can_do('master/coa', 'edit'), 403);

        $c = DB::table('bcoa')->where('CID', $id)->first();
        abort_if(! $c, 404);

        $this->editingId  = (int) $c->CID;
        $this->CNOCOA     = (string) $c->CNOCOA;
        $this->CNAMA      = $c->CNAMA;
        $this->CTIPE      = $c->CTIPE !== null ? (int) $c->CTIPE : null;
        $this->CSUBDARI   = (int) $c->CSUBDARI === 1;
        $this->CPARENT    = $c->CPARENT ? (int) $c->CPARENT : null;
        $this->CUANG      = $c->CUANG ? (int) $c->CUANG : null;
        $this->CDIVISI    = $c->CDIVISI ? (int) $c->CDIVISI : null;
        $this->CBANK      = $c->CBANK ? (int) $c->CBANK : null;
        $this->CGD        = in_array($c->CGD, ['D', 'G'], true) ? $c->CGD : 'D';
        $this->CACTIVE    = (int) $c->CACTIVE === 1;
        $this->CKASMASUK  = (int) $c->CKASMASUK === 1;
        $this->CKASKELUAR = (int) $c->CKASKELUAR === 1;
        $this->parentLabel = $this->CPARENT
            ? (string) DB::table('bcoa')->where('CID', $this->CPARENT)
                ->selectRaw("CONCAT(CNOCOA, ' — ', CNAMA) as t")->value('t')
            : null;

        $this->resetErrorBag();
        $this->showModal = true;
    }

    /**
     * Induk tidak boleh dirinya sendiri ATAU keturunannya sendiri - kalau lolos, pohon COA
     * jadi melingkar dan query rekursif apa pun ngehang. VB6 tidak mengeceknya.
     */
    private function indukMelingkar(int $parentId): bool
    {
        if (! $this->editingId) {
            return false;
        }
        if ($parentId === $this->editingId) {
            return true;
        }

        $cursor = $parentId;
        for ($i = 0; $i < 20 && $cursor; $i++) { // batas 20 = jauh di atas level maks nyata (5)
            $cursor = (int) DB::table('bcoa')->where('CID', $cursor)->value('CPARENT');
            if ($cursor === $this->editingId) {
                return true;
            }
        }

        return false;
    }

    public function save(): void
    {
        abort_unless(can_do('master/coa', $this->editingId ? 'edit' : 'add'), 403);
        $this->validate();

        $parent = $this->CSUBDARI ? $this->CPARENT : null;

        if ($parent && $this->indukMelingkar((int) $parent)) {
            $this->addError('CPARENT', 'Induk tidak boleh COA ini sendiri atau turunannya.');

            return;
        }

        $payload = [
            'CNOCOA'   => trim($this->CNOCOA),
            'CNAMA'    => trim((string) $this->CNAMA),
            'CSUBDARI' => $this->CSUBDARI ? 1 : 0,
            'CPARENT'  => $parent,
            'CURUTAN'  => 0, // kolom mati di VB6, lihat docblock
            'CUANG'    => $this->CUANG ?: null,
            'CDIVISI'  => $this->CDIVISI ?: null,
            'CTIPE'    => (int) $this->CTIPE,
            'CDC'      => in_array((int) $this->CTIPE, self::TIPE_DEBIT, true) ? 'D' : 'C',
            'CBANK'    => $this->CBANK ?: null,
            'CGD'      => $this->CGD,
            'CACTIVE'  => $this->CACTIVE ? 1 : 0,
            'CKASMASUK'  => $this->CKASMASUK ? 1 : 0,
            'CKASKELUAR' => $this->CKASKELUAR ? 1 : 0,
            'CMODIFU'  => auth()->id(),
            'CMODIFD'  => now(),
        ];

        $id = DB::transaction(function () use ($payload, $parent) {
            // CLEVEL turunan dari induk (VB6 baris 590-591).
            $payload['CLEVEL'] = $parent
                ? (int) DB::table('bcoa')->where('CID', $parent)->value('CLEVEL') + 1
                : 1;

            if ($this->editingId) {
                DB::table('bcoa')->where('CID', $this->editingId)->update($payload);
                $id = $this->editingId;
            } else {
                $payload['CCREATEU'] = auth()->id();
                $id = (int) DB::table('bcoa')->insertGetId($payload, 'CID');
            }

            // Begitu dijadikan induk, COA itu OTOMATIS jadi grup (VB6 baris 587).
            if ($parent) {
                DB::table('bcoa')->where('CID', $parent)->update(['CGD' => 'G']);
            }

            return $id;
        });

        activity_log($this->editingId ? 'update' : 'create', 'master/coa', $id,
            ($this->editingId ? 'Ubah' : 'Tambah') . ' COA ' . $payload['CNOCOA']);

        session()->flash('status', 'COA ' . $payload['CNOCOA'] . ' disimpan.');
        $this->showModal = false;
    }

    public function render()
    {
        abort_unless(can_do('master/coa', 'view'), 403);

        $q = trim($this->search);

        $rows = DB::table('bcoa as c')
            ->leftJoin('bcoa as p', 'p.CID', '=', 'c.CPARENT')
            ->leftJoin('bdivisi as d', 'd.DID', '=', 'c.CDIVISI')
            ->leftJoin('buang as u', 'u.UID', '=', 'c.CUANG')
            ->leftJoin('bbank as b', 'b.BID', '=', 'c.CBANK')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('c.CNOCOA', 'like', "%{$q}%")
                ->orWhere('c.CNAMA', 'like', "%{$q}%")))
            ->when($this->fTipe !== '', fn ($w) => $w->where('c.CTIPE', (int) $this->fTipe))
            ->when($this->fAktif !== '', fn ($w) => $w->where('c.CACTIVE', (int) $this->fAktif))
            ->orderBy('c.CNOCOA')
            ->paginate(30, [
                'c.CID', 'c.CNOCOA', 'c.CNAMA', 'c.CTIPE', 'c.CDC', 'c.CGD', 'c.CLEVEL',
                'c.CSUBDARI', 'c.CACTIVE',
                'p.CNOCOA as induk', 'd.DNAMA as divisi', 'u.UKODE as uang', 'b.BNAMA as bank',
            ]);

        return view('livewire.master.coa-manager', [
            'rows'     => $rows,
            'tipeList' => self::TIPE,
            'divisis'  => DB::table('bdivisi')->orderBy('DKODE')->get(['DID', 'DKODE', 'DNAMA']),
            'uangs'    => DB::table('buang')->orderBy('UKODE')->get(['UID', 'UKODE', 'UNAMA']),
            'banks'    => DB::table('bbank')->orderBy('BKODE')->get(['BID', 'BKODE', 'BNAMA']),
        ]);
    }
}
