<?php

namespace App\Livewire\Fina;

use App\Models\Branch;
use App\Services\KasBankWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Base abstrak form Kas/Bank Masuk/Keluar - 4 subclass tipis, lihat docblock `KasBankWriter`
 * utk detail modul (double-entry, filter COA, field khusus Bank, hard-delete).
 */
abstract class KasBankFormBase extends Component
{
    public ?string $tabKey = null;
    public ?int $id = null;
    public bool $locked = false;

    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    public ?string $nomor = null;
    public ?string $uraian = null;
    public ?int $rekening = null;
    public ?string $rekeningLabel = null;
    public ?int $cabang = null;

    // khusus Bank - lihat isBank(), TIDAK dipakai/ditampilkan utk form Kas.
    public int $tipeBayar = 0; // 0 Tunai, 1 Giro, 2 Transfer
    public ?int $bank = null;
    public ?string $bankLabel = null;
    public ?string $noGiro = null;
    public ?string $tglGiro = null;

    /** @var array<int,array{coa:?int,coaLabel:?string,jumlah:float,catatan:?string}> */
    public array $lines = [];

    abstract public function sumber(): string;

    abstract public function judul(): string;

    abstract public function isBank(): bool;

    abstract public function abilityPath(): string;

    /**
     * Nama rute cetak, atau `null` kalau modulnya belum py cetakan - tombol Cetak lalu
     * disembunyikan. Bank Masuk/Keluar sengaja masih `null`, lihat
     * `KasBankPrintController`.
     */
    public function printRoute(): ?string
    {
        return null;
    }

    public function mount(?int $id = null): void
    {
        $this->tanggal = now()->toDateString();
        $user = auth()->user();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        if ($id) {
            $this->load($id);
        } else {
            $this->uraian = $this->uraianBawaan();
            $this->addLine();
        }
    }

    /**
     * Uraian bawaan dokumen BARU, diambil dari `aanomor.NKETERANGAN` - mis. "Bukti Kas
     * Keluar". Pola sama CI3: `Fina_Kas_Keluar::getketerangan()` mengambil kolom yg sama lalu
     * mengisi field Uraian saat form dibuka/dikosongkan.
     *
     * Dicocokkan `NKODE` = `sumber()` ('KM'/'KK'/'BM'/'BK') DAN `NTABEL='ctransaksiu'`.
     * `NTABEL` ikut dipakai walau keempat kode itu terbukti unik di data sekarang - `NKODE`
     * tidak dijamin unik lintas tabel oleh skemanya.
     *
     * Dokumen LAMA tidak disentuh: `load()` memakai `CUURAIAN` yang tersimpan.
     */
    private function uraianBawaan(): ?string
    {
        $ket = DB::table('aanomor')
            ->where('NKODE', $this->sumber())
            ->where('NTABEL', 'ctransaksiu')
            ->value('NKETERANGAN');

        return $ket !== null && trim((string) $ket) !== '' ? trim((string) $ket) : null;
    }

    private function load(int $id): void
    {
        $w = app(KasBankWriter::class);
        $h = $w->header($this->sumber(), $id);
        abort_if(! $h, 404);

        $this->id = $id;
        $this->nomor = $h->CUNOTRANSAKSI;
        $this->kontak = $h->CUKONTAK ? (int) $h->CUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->tanggal = substr((string) $h->CUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->CUURAIAN;
        $this->rekening = $h->CUREKKAS ? (int) $h->CUREKKAS : null;
        $this->rekeningLabel = $this->rekening
            ? (string) DB::table('bcoa')->where('CID', $this->rekening)->value('CNAMA') : null;
        $this->cabang = $h->CUCABANG ? (int) $h->CUCABANG : null;
        $this->tipeBayar = (int) ($h->CUTIPE ?? 0);
        $this->bank = $h->CUBANK ? (int) $h->CUBANK : null;
        $this->bankLabel = $this->bank
            ? (string) DB::table('bbank')->where('BID', $this->bank)->value('BNAMA') : null;
        $this->noGiro = $h->CUNOGIRO;
        $this->tglGiro = $h->CUTGLTEMPO ? substr((string) $h->CUTGLTEMPO, 0, 10) : null;
        $this->locked = true; // transaksi tersimpan SELALU read-only, cuma bisa dihapus dari list

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'coa'      => (int) $l->CDNOCOA,
                'coaLabel' => trim(($l->CNOCOA ?? '') . ' — ' . ($l->CNAMA ?? '')),
                'jumlah'   => (float) ($this->isMasukArah() ? $l->CDKREDIT : $l->CDDEBIT),
                'catatan'  => $l->CDCATATAN,
            ];
        }
    }

    private function isMasukArah(): bool
    {
        return app(KasBankWriter::class)->isMasuk($this->sumber());
    }

    public function addLine(): void
    {
        if ($this->locked) {
            return;
        }
        $this->lines[] = ['coa' => null, 'coaLabel' => null, 'jumlah' => 0, 'catatan' => null];
    }

    public function removeLine(int $i): void
    {
        if ($this->locked) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    protected function rules(): array
    {
        $rules = [
            'kontak'   => ['required', 'integer'],
            'tanggal'  => ['required', 'date'],
            'rekening' => ['required', 'integer'],
            'cabang'   => ['required', 'integer'],
        ];
        if ($this->isBank()) {
            $rules['tipeBayar'] = ['required', 'in:0,1,2'];
            if ($this->tipeBayar === 2) {
                $rules['bank'] = ['required', 'integer'];
            } elseif ($this->tipeBayar === 1) {
                $rules['noGiro'] = ['required', 'string'];
                $rules['tglGiro'] = ['required', 'date'];
            }
        }

        return $rules;
    }

    protected array $messages = [
        'kontak.required'   => 'Kontak belum diisi.',
        'rekening.required' => 'Rekening belum dipilih.',
        'cabang.required'   => 'Cabang tidak valid - hubungi admin.',
        'bank.required'     => 'Bank belum dipilih (tipe pembayaran Transfer).',
        'noGiro.required'   => 'No. Cek/Giro belum diisi.',
        'tglGiro.required'  => 'Tanggal jatuh tempo belum diisi.',
    ];

    /**
     * Apakah kolom Catatan tiap baris WAJIB diisi. Default `false`; dinyalakan HANYA di
     * `KasKeluarForm` (permintaan user 2026-10-02). Kas Masuk & Bank sengaja tidak ikut -
     * user cuma menyebut Kas Keluar.
     */
    public function catatanWajib(): bool
    {
        return false;
    }

    /**
     * Cek Catatan per baris. Dijalankan TERPISAH dari `rules()`, bukan sbg `lines.*.catatan`
     * => required: aturan berbintang itu akan ikut menghakimi BARIS KOSONG (baris yg akun/
     * jumlahnya belum diisi) - padahal baris semacam itu memang dibuang saat simpan, jadi user
     * akan dipaksa mengisi catatan untuk baris yg tidak akan tersimpan sama sekali.
     *
     * Syarat "baris yg dihitung" SENGAJA disalin persis dari perulangan penyimpanan di bawah
     * (`jumlah > 0 && coa`) - kalau suatu saat syarat itu berubah, ubah DI KEDUANYA, kalau
     * tidak user bisa diblokir oleh baris yg sebenarnya tidak disimpan (atau sebaliknya,
     * baris tersimpan tanpa catatan).
     *
     * Kesalahannya ditempelkan ke `lines.<i>.catatan` supaya munculnya di baris yg bersalah,
     * bukan satu pesan umum di atas tabel.
     */
    private function validasiCatatan(): bool
    {
        if (! $this->catatanWajib()) {
            return true;
        }

        $bersih = true;

        foreach ($this->lines as $i => $l) {
            if ((float) $l['jumlah'] <= 0 || ! $l['coa']) {
                continue;
            }
            if (trim((string) ($l['catatan'] ?? '')) === '') {
                $this->addError('lines.' . $i . '.catatan', 'Catatan wajib diisi.');
                $bersih = false;
            }
        }

        return $bersih;
    }

    public function save(KasBankWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        if (! $this->validasiCatatan()) {
            return;
        }

        $lines = [];
        foreach ($this->lines as $l) {
            $jumlah = max(0.0, (float) $l['jumlah']);
            if ($jumlah <= 0 || ! $l['coa']) {
                continue;
            }
            $lines[] = ['coa' => (int) $l['coa'], 'jumlah' => $jumlah, 'catatan' => $l['catatan'] ?: null];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 baris biaya dengan akun & jumlah > 0.');

            return;
        }

        $header = [
            'kontak'    => $this->kontak,
            'uraian'    => trim((string) $this->uraian) ?: null,
            'rekening'  => $this->rekening,
            'tanggal'   => $this->tanggal,
            'cabang'    => $this->cabang,
            'tipeBayar' => $this->isBank() ? $this->tipeBayar : 0,
            'bank'      => $this->isBank() && $this->tipeBayar === 2 ? $this->bank : null,
            'noGiro'    => $this->isBank() && $this->tipeBayar === 1 ? $this->noGiro : null,
            'tglGiro'   => $this->isBank() && $this->tipeBayar === 1 ? $this->tglGiro : null,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($this->sumber(), $header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->id = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', $this->abilityPath(), $this->nomor, 'Buat ' . $this->judul() . ' ' . $this->nomor);
        $this->dispatch('kasbank-saved');
        session()->flash('status', $this->judul() . ' ' . $this->nomor . ' tersimpan.');
        $this->dispatch('tab-label', key: $this->tabKey, label: $this->judul() . ': ' . $this->nomor);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.fina.kas-bank-form', [
            'judul'      => $this->judul(),
            'isBank'     => $this->isBank(),
            'isMasuk'    => $this->isMasukArah(),
            'printRoute' => $this->printRoute(),
            'abilityPath' => $this->abilityPath(),
            'catatanWajib' => $this->catatanWajib(),
            'total'    => array_sum(array_map(fn ($l) => (float) $l['jumlah'], $this->lines)),
        ]);
    }
}
