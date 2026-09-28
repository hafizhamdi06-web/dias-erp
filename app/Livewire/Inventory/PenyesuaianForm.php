<?php

namespace App\Livewire\Inventory;

use App\Models\Branch;
use App\Services\PenyesuaianWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Penyesuaian Barang (PY). Field sesuai spesifikasi user 2026-09-26:
 * header Kontak / Gudang / Jenis Penyesuaian / Uraian / Tanggal / No Transaksi,
 * detail Kode-Nama-Masuk-Keluar-Satuan-Catatan, ringkasan Jumlah Masuk & Jumlah Keluar.
 *
 * **Gudang = cabang user login, tidak bisa diubah** (aturan app: kalau ada pilihan cabang,
 * diisi sesuai cabang user). Nilai ini juga dipakai `SDGUDANG` tiap baris.
 *
 * PY tersimpan SELALU read-only (pola sama modul eksekusi stok lain: SJ/PBC/KMB/TMB/PB) -
 * koreksi lewat "Batalkan" di daftar, lalu input ulang.
 *
 * Lihat docblock `PenyesuaianWriter` utk aturan datanya (satu baris boleh Masuk DAN Keluar).
 */
class PenyesuaianForm extends Component
{
    public ?string $tabKey = null;
    public ?int $pyId = null;
    public bool $locked = false;

    // header
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public ?int $gudang = null;
    public ?string $gudangLabel = null;
    public ?int $jenis = null;
    public ?string $uraian = 'Penyesuaian Barang';
    public string $tanggal = '';
    public ?string $nomor = null;
    public int $status = PenyesuaianWriter::STATUS_AKTIF;

    /** @var array<int,array{item:int,kode:string,nama:string,masuk:float,keluar:float,satuan:?int,satuanKode:string,catatan:?string}> */
    public array $lines = [];

    /** pencarian item utk menambah baris */
    public string $itemQ = '';

    public function mount(?int $pyId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->gudang = (int) ($user->UCABANG ?? 0) ?: null;
        $this->gudangLabel = $this->gudang
            ? (string) DB::table('bgudang')->where('GID', $this->gudang)->value('GNAMA') : null;

        if ($pyId) {
            $this->load($pyId);

            return;
        }

        abort_unless(can_do('inventory/adjust', 'add'), 403);
    }

    private function load(int $id): void
    {
        abort_unless(can_do('inventory/adjust', 'view'), 403);

        $w = app(PenyesuaianWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->pyId = $id;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->gudang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->gudangLabel = $this->gudang
            ? (string) DB::table('bgudang')->where('GID', $this->gudang)->value('GNAMA') : null;
        $this->jenis = $h->SUJENISPENYESUAIAN ? (int) $h->SUJENISPENYESUAIAN : null;
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'masuk'      => (float) $l->SDMASUK,
                'keluar'     => (float) $l->SDKELUAR,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->SDCATATAN,
            ];
        }
    }

    public function addItem(int $id): void
    {
        if ($this->locked) {
            return;
        }
        foreach ($this->lines as $l) {
            if ((int) $l['item'] === $id) {
                $this->itemQ = '';

                return;
            }
        }

        $it = DB::table('bitem as i')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->where('i.IID', $id)
            ->first(['i.IID', 'i.IKODE', 'i.INAMA', 'i.ISATUAN', 's.SKODE as satuan_kode']);
        if (! $it) {
            return;
        }

        $this->lines[] = [
            'item'       => (int) $it->IID,
            'kode'       => $it->IKODE ?? '',
            'nama'       => $it->INAMA ?? '',
            'masuk'      => 0,
            'keluar'     => 0,
            'satuan'     => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'catatan'    => null,
        ];
        $this->itemQ = '';
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
            'tanggal' => ['required', 'date'],
            'gudang'  => ['required', 'integer'],
            'jenis'   => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'gudang.required' => 'Cabang Anda tidak valid - hubungi admin.',
        'jenis.required'  => 'Jenis Penyesuaian wajib dipilih.',
    ];

    public function save(PenyesuaianWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        abort_unless(can_do('inventory/adjust', 'add'), 403);
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $lines[] = [
                'item'    => (int) $l['item'],
                'masuk'   => max(0.0, (float) $l['masuk']),
                'keluar'  => max(0.0, (float) $l['keluar']),
                'satuan'  => $l['satuan'] ?: null,
                'catatan' => $l['catatan'] ?: null,
            ];
        }

        $branch = Branch::query()->where('GID', $this->gudang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create([
            'tanggal'    => $this->tanggal,
            'kontak'     => $this->kontak,
            'gudang'     => (int) $this->gudang,
            'jenis'      => $this->jenis,
            'uraian'     => trim((string) $this->uraian) ?: null,
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
        ], $lines);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->pyId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'inventory/adjust', $this->nomor, 'Buat Penyesuaian ' . $this->nomor);
        $this->dispatch('py-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PY: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Penyesuaian Barang ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('inventory/adjust', 'print')) {
            $this->dispatch('confirm-print',
                message: 'Penyesuaian ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Penyesuaian Barang',
                okText: 'Ya, cetak',
                url: route('inventory.adjust.print', $this->pyId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $items = [];
        if (! $this->locked && trim($this->itemQ) !== '') {
            $q = trim($this->itemQ);
            $items = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($w) => $w->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('IKODE')->limit(15)
                ->get(['IID', 'IKODE', 'INAMA'])->all();
        }

        return view('livewire.inventory.penyesuaian-form', [
            'items'        => $items,
            'jenisList'    => app(PenyesuaianWriter::class)->jenisOptions(),
            'jumlahMasuk'  => array_sum(array_map(fn ($l) => (float) $l['masuk'], $this->lines)),
            'jumlahKeluar' => array_sum(array_map(fn ($l) => (float) $l['keluar'], $this->lines)),
        ]);
    }
}
