<?php

namespace App\Livewire\Fina;

use App\Models\Branch;
use App\Services\PengajuanDanaWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Pengajuan Dana. Lihat docblock `PengajuanDanaWriter` utk detail modul (tabel
 * terpisah `ctransaksipu`/`ctransaksipd`, trigger `CDDIBUATPENGAJUAN`, dll).
 */
class PengajuanDanaForm extends Component
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

    /** @var array<int,array{cdid:int,coa:int,coaLabel:string,jumlah:float,catatan:?string,noKk:string}> */
    public array $lines = [];

    public function mount(?int $id = null): void
    {
        $this->tanggal = now()->toDateString();
        $user = auth()->user();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        if ($id) {
            $this->load($id);
        }
    }

    private function load(int $id): void
    {
        $w = app(PengajuanDanaWriter::class);
        $h = $w->header($id);
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
        $this->locked = true;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'cdid'     => (int) $l->CDBKKID,
                'coa'      => (int) $l->CDNOCOA,
                'coaLabel' => trim(($l->CNOCOA ?? '') . ' — ' . ($l->CNAMA ?? '')),
                'jumlah'   => (float) $l->CDDEBIT,
                'catatan'  => $l->CDCATATAN,
                'noKk'     => $l->no_kk ?? '',
            ];
        }
    }

    public function updatedRekening(): void
    {
        if ($this->locked) {
            return;
        }
        $this->rekeningLabel = $this->rekening
            ? (string) DB::table('bcoa')->where('CID', $this->rekening)->value('CNAMA') : null;
    }

    public function tarikData(PengajuanDanaWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        if (! $this->rekening) {
            $this->addError('rekening', 'Pilih rekening dulu sebelum menarik data.');

            return;
        }
        if (! $this->cabang) {
            $this->addError('cabang', 'Cabang tidak valid - hubungi admin.');

            return;
        }

        $existing = array_column($this->lines, 'cdid');
        $pulled = $writer->pullableKk((int) $this->rekening, (int) $this->cabang);
        $added = 0;
        foreach ($pulled as $p) {
            if (in_array($p['cdid'], $existing, true)) {
                continue;
            }
            $this->lines[] = $p;
            $added++;
        }

        if ($added === 0 && $this->lines === []) {
            session()->flash('info', 'Tidak ada data Kas Keluar yang belum ditarik untuk rekening ini.');
        }
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
        return [
            'kontak'   => ['required', 'integer'],
            'tanggal'  => ['required', 'date'],
            'rekening' => ['required', 'integer'],
            'cabang'   => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'kontak.required'   => 'Kontak belum diisi.',
        'rekening.required' => 'Rekening belum dipilih.',
        'cabang.required'   => 'Cabang tidak valid - hubungi admin.',
    ];

    public function save(PengajuanDanaWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        if ($this->lines === []) {
            $this->addError('lines', 'Belum ada data yang ditarik. Klik "Tarik Data" dulu.');

            return;
        }

        $lines = [];
        foreach ($this->lines as $l) {
            $jumlah = max(0.0, (float) $l['jumlah']);
            if ($jumlah <= 0) {
                continue;
            }
            $lines[] = ['cdid' => (int) $l['cdid'], 'coa' => (int) $l['coa'], 'jumlah' => $jumlah, 'catatan' => $l['catatan'] ?: null];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 baris dengan jumlah > 0.');

            return;
        }

        $header = [
            'kontak'   => $this->kontak,
            'uraian'   => trim((string) $this->uraian) ?: null,
            'rekening' => $this->rekening,
            'tanggal'  => $this->tanggal,
            'cabang'   => $this->cabang,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
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

        activity_log('create', 'finance/pengajuan-dana', $this->nomor, 'Buat Pengajuan Dana ' . $this->nomor);
        $this->dispatch('pengajuan-dana-saved');
        session()->flash('status', 'Pengajuan Dana ' . $this->nomor . ' tersimpan.');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'Pengajuan Dana: ' . $this->nomor);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.fina.pengajuan-dana-form', [
            'total' => array_sum(array_map(fn ($l) => (float) $l['jumlah'], $this->lines)),
        ]);
    }
}
