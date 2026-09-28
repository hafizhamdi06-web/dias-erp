<?php

namespace App\Livewire\Inventory;

use App\Models\Branch;
use App\Services\PengeluaranLainWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Pengeluaran Lain (PL). Kembar `PenyesuaianForm` sesuai permintaan user
 * ("inputan sama seperti Penyesuaian Barang, tapi ini hanya keluar saja"): header
 * Kontak / Gudang / Jenis / Uraian / Tanggal / No Transaksi, detail Kode-Nama-**Keluar**-
 * Satuan-Catatan, ringkasan **Jumlah Keluar** saja.
 *
 * **Gudang = cabang user login, tidak bisa diubah** (aturan app: kalau ada pilihan cabang,
 * diisi sesuai cabang user). Nilai ini juga dipakai `SDGUDANG` tiap baris.
 *
 * PL tersimpan SELALU read-only (pola sama PY/SJ/PBC/KMB/TMB/PB) - koreksi lewat "Batalkan"
 * di daftar, lalu input ulang.
 *
 * Lihat docblock `PengeluaranLainWriter` utk aturan datanya.
 */
class PengeluaranLainForm extends Component
{
    public ?string $tabKey = null;
    public ?int $plId = null;
    public bool $locked = false;

    // header
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public ?int $gudang = null;
    public ?string $gudangLabel = null;
    public ?int $jenis = null;
    public ?string $uraian = 'Pengeluaran Lain';
    public string $tanggal = '';
    public ?string $nomor = null;
    public int $status = PengeluaranLainWriter::STATUS_AKTIF;

    /** @var array<int,array{item:int,kode:string,nama:string,keluar:float,satuan:?int,satuanKode:string,catatan:?string}> */
    public array $lines = [];

    /** pencarian item utk menambah baris */
    public string $itemQ = '';

    public function mount(?int $plId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->gudang = (int) ($user->UCABANG ?? 0) ?: null;
        $this->gudangLabel = $this->gudang
            ? (string) DB::table('bgudang')->where('GID', $this->gudang)->value('GNAMA') : null;

        if ($plId) {
            $this->load($plId);

            return;
        }

        abort_unless(can_do('inventory/pengeluaran-lain', 'add'), 403);
    }

    private function load(int $id): void
    {
        abort_unless(can_do('inventory/pengeluaran-lain', 'view'), 403);

        $w = app(PengeluaranLainWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->plId = $id;
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
        'jenis.required'  => 'Jenis wajib dipilih.',
    ];

    public function save(PengeluaranLainWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        abort_unless(can_do('inventory/pengeluaran-lain', 'add'), 403);
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $lines[] = [
                'item'    => (int) $l['item'],
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

        $this->plId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'inventory/pengeluaran-lain', $this->nomor, 'Buat Pengeluaran Lain ' . $this->nomor);
        $this->dispatch('pl-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PL: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Pengeluaran Lain ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('inventory/pengeluaran-lain', 'print')) {
            $this->dispatch('confirm-print',
                message: 'Pengeluaran ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Pengeluaran Lain',
                okText: 'Ya, cetak',
                url: route('inventory.pengeluaran-lain.print', $this->plId));
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

        return view('livewire.inventory.pengeluaran-lain-form', [
            'items'        => $items,
            'jenisList'    => app(PengeluaranLainWriter::class)->jenisOptions(),
            'jumlahKeluar' => array_sum(array_map(fn ($l) => (float) $l['keluar'], $this->lines)),
        ]);
    }
}
