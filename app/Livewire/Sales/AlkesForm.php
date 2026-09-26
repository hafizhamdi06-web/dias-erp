<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Services\AlkesWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Input Alkes Depo. Alur VB6 `eFrmPOS_DEPO_2`: pilih transaksi IP -> grid ATAS
 * berisi baris TINDAKAN-nya -> pilih satu tindakan -> grid BAWAH terisi daftar alkes
 * default dari `bitemalkes` -> centang/ubah qty -> simpan. **Satu simpan = satu dokumen AL
 * untuk satu tindakan** (lihat docblock `AlkesWriter`).
 *
 * AL yg SUDAH tersimpan read-only (pola sama modul eksekusi stok lain) - koreksi lewat
 * "Batalkan" di daftar, lalu input ulang.
 */
class AlkesForm extends Component
{
    public ?string $tabKey = null;
    public ?int $alkesId = null;
    public bool $locked = false;

    // sumber
    public ?int $ipId = null;
    public ?string $noIp = null;
    public ?string $tglIp = null;
    public ?int $cabang = null;
    public ?string $cabangLabel = null;
    public ?int $kontak = null;
    public ?string $pelanggan = null;

    public string $tanggal = '';
    public ?string $nomor = null;
    public int $status = AlkesWriter::STATUS_AKTIF;

    /** Baris tindakan di IP (grid atas). @var array<int,array> */
    public array $tindakan = [];
    /** `fstokd.SDID` tindakan yg sedang diinput. */
    public ?int $tindakanSdid = null;
    public ?string $tindakanNama = null;

    /** Baris alkes (grid bawah). @var array<int,array> */
    public array $lines = [];

    /** pencarian item alkes manual */
    public string $itemQ = '';

    public function mount(?int $ipId = null, ?int $alkesId = null): void
    {
        $this->tanggal = now()->toDateString();
        $this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
        $this->cabangLabel = $this->cabang
            ? (string) DB::table('bgudang')->where('GID', $this->cabang)->value('GNAMA') : null;

        if ($alkesId) {
            $this->load($alkesId);

            return;
        }

        abort_unless(can_do('sales/alkes', 'add'), 403);
        abort_if(! $ipId, 404, 'Transaksi IP belum dipilih.');
        $this->pullFromIp($ipId);
    }

    private function load(int $id): void
    {
        abort_unless(can_do('sales/alkes', 'view'), 403);

        $w = app(AlkesWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->alkesId = $id;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->noIp = $h->SUNOREF;
        $this->ipId = $h->SUIDUALKES ? (int) $h->SUIDUALKES : null;
        $this->cabang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->cabangLabel = $this->cabang
            ? (string) DB::table('bgudang')->where('GID', $this->cabang)->value('GNAMA') : null;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->pelanggan = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true;

        foreach ($w->lines($id) as $l) {
            $this->tindakanSdid ??= $l->SDIDUALKES ? (int) $l->SDIDUALKES : null;
            $this->lines[] = [
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qty'        => (float) $l->SDKELUAR,
                'qtyDefault' => (float) $l->SDQTYDASAR,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'pilih'      => true,
            ];
        }

        if ($this->tindakanSdid) {
            $this->tindakanNama = (string) DB::table('fstokd as d')
                ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
                ->where('d.SDID', $this->tindakanSdid)->value('i.INAMA');
        }
    }

    private function pullFromIp(int $ipId): void
    {
        $data = app(AlkesWriter::class)->fromIp($ipId, $this->cabang);
        abort_if(! $data, 404, 'Transaksi IP tidak ditemukan atau bukan milik cabang Anda.');

        $this->ipId = $ipId;
        $this->noIp = $data['header']->SUNOTRANSAKSI;
        $this->tglIp = substr((string) $data['header']->SUTANGGAL, 0, 10);
        $this->tanggal = $this->tglIp ?: $this->tanggal; // VB6: tanggal AL ikut tanggal IP
        $this->kontak = $data['header']->SUKONTAK ? (int) $data['header']->SUKONTAK : null;
        $this->pelanggan = $data['header']->pelanggan;
        $this->cabang = (int) $data['header']->SUCABANG;
        $this->cabangLabel = (string) DB::table('bgudang')->where('GID', $this->cabang)->value('GNAMA');

        $this->tindakan = array_map(fn ($t) => (array) $t, $data['lines']);

        // Kalau cuma ada 1 tindakan yg belum diinput, langsung pilihkan - hemat 1 klik.
        $belum = array_values(array_filter($this->tindakan, fn ($t) => ! $t['alkesSuid']));
        if (count($belum) === 1) {
            $this->pilihTindakan($belum[0]['sdid']);
        }
    }

    /** Pilih baris tindakan -> isi grid alkes dari resep `bitemalkes`. */
    public function pilihTindakan(int $sdid): void
    {
        if ($this->locked) {
            return;
        }

        $t = collect($this->tindakan)->firstWhere('sdid', $sdid);
        if (! $t) {
            return;
        }
        if ($t['alkesSuid']) {
            $this->addError('lines', 'Tindakan ini sudah punya alkes (' . $t['alkesNomor'] . ').');

            return;
        }

        $this->resetErrorBag();
        $this->tindakanSdid = $sdid;
        $this->tindakanNama = $t['nama'];
        $this->lines = app(AlkesWriter::class)->defaultAlkes((int) $t['item']);
        $this->itemQ = '';
    }

    public function togglePilih(int $i): void
    {
        if ($this->locked || ! isset($this->lines[$i])) {
            return;
        }
        $this->lines[$i]['pilih'] = ! $this->lines[$i]['pilih'];
    }

    public function pilihSemua(bool $nilai): void
    {
        if ($this->locked) {
            return;
        }
        foreach ($this->lines as $i => $l) {
            $this->lines[$i]['pilih'] = $nilai;
        }
    }

    /** Tambah alkes di luar resep (`SDQTYDASAR`=0). */
    public function tambahItem(int $itemId): void
    {
        if ($this->locked || ! $this->tindakanSdid) {
            return;
        }
        foreach ($this->lines as $i => $l) {
            if ((int) $l['item'] === $itemId) {
                $this->lines[$i]['pilih'] = true;
                $this->itemQ = '';

                return;
            }
        }

        $row = app(AlkesWriter::class)->manualAlkes($itemId);
        if ($row) {
            $this->lines[] = $row;
        }
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

    public function save(AlkesWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        abort_unless(can_do('sales/alkes', 'add'), 403);

        if (! $this->tindakanSdid) {
            $this->addError('lines', 'Pilih baris tindakan dulu.');

            return;
        }

        $dipilih = [];
        foreach ($this->lines as $l) {
            if (! $l['pilih']) {
                continue;
            }
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            $dipilih[] = [
                'item'       => (int) $l['item'],
                'qty'        => $qty,
                'qtyDefault' => (float) ($l['qtyDefault'] ?? 0),
                'satuan'     => $l['satuan'] ?: null,
            ];
        }

        if ($dipilih === []) {
            $this->addError('lines', 'Centang minimal 1 alkes dengan qty lebih dari 0.');

            return;
        }

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create([
            'ipId'         => (int) $this->ipId,
            'tindakanSdid' => (int) $this->tindakanSdid,
            'tanggal'      => $this->tanggal,
            'kodecabang'   => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
        ], $dipilih);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->alkesId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'sales/alkes', $this->nomor,
            'Input Alkes ' . $this->nomor . ' untuk IP ' . $this->noIp);
        $this->dispatch('alkes-saved');
        $this->dispatch('toast', message: 'Alkes ' . $this->nomor . ' tersimpan.', type: 'success');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'Alkes: ' . $this->nomor);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $items = [];
        if (! $this->locked && $this->tindakanSdid && trim($this->itemQ) !== '') {
            $q = trim($this->itemQ);
            $items = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($w) => $w->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('IKODE')->limit(15)
                ->get(['IID', 'IKODE', 'INAMA'])->all();
        }

        return view('livewire.sales.alkes-form', [
            'items'    => $items,
            'totalQty' => array_sum(array_map(fn ($l) => $l['pilih'] ? (float) $l['qty'] : 0, $this->lines)),
        ]);
    }
}
