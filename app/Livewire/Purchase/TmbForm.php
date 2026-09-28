<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\SerialBatch;
use App\Services\TmbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form TMB (Terima Mutasi Barang). Header fstoku (SUSUMBER='TMB') + baris fstokd.
 * "Satu KMB = satu TMB, FULL-RECEIPT" - TMB SELALU lahir dari menerima SELURUH isi 1
 * KMB yg belum diterima (`$kmbId` di mount()), TIDAK ada partial (lihat docblock
 * `TmbWriter`). TMB yg SUDAH tersimpan SELALU read-only, pola sama `PbcForm`.
 */
class TmbForm extends Component
{
    public ?string $tabKey = null;
    public ?int $tmbId = null;
    public ?int $kmbId = null; // KMB sumber (SUPRUID) - TIDAK bisa diubah stlh dibuat
    public bool $locked = false;

    // header
    /** "Diperintah Oleh" (SUKONTAK) - AUTO dari user login, pola sama PBC. */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** SUCABANG - cabang PENERIMA (auser.UCABANG user login SEKARANG). */
    public ?int $cabang = null;
    public ?string $uraian = null;
    public ?string $nomor = null;
    public ?string $noKmb = null; // nomor transaksi KMB sumber, tampilan saja
    public ?string $noPr = null; // nomor transaksi PR asal (transitif via KMB), tampilan saja

    public int $status = 1; // SUSTATUS: 1 aktif, 9 batal

    // modal "No Batch" (gudang penerima berbatch + item ISERIAL=1) - pola sama PbForm:
    // barang masuk, jadi user MENGETIK no batch dari fisik barang.
    public bool $showBatch = false;
    public ?int $batchIdx = null;
    /** @var list<array{noBatch:string,expired:?string,qty:float}> */
    public array $batchRows = [];
    public string $bNo = '';
    public string $bExp = '';
    public $bQty = null;

    /** @var array<int,array{item:int,kode:string,nama:string,qtyKirim:float,qty:float,satuan:?int,satuanKode:string,catatan:?string}> */
    public array $lines = [];

    public function mount(?int $tmbId = null, ?int $kmbId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->kontak = $user->UKID ? (int) $user->UKID : null;
        $this->kontakLabel = $this->kontak
            ? ((string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', TmbWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($tmbId) {
            $this->load($tmbId);
        } elseif ($kmbId) {
            $this->pullFromKmb($kmbId);
        }
    }

    private function load(int $id): void
    {
        $w = app(TmbWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->tmbId = $id;
        $this->kmbId = $h->SUPRUID ? (int) $h->SUPRUID : null;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // TMB tersimpan SELALU read-only, lihat docblock kelas
        $this->fillTraceability();

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qtyKirim'   => (float) $l->SDMASUK,
                'qty'        => (float) $l->SDMASUK,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->SDCATATAN,
                'serial'     => (int) ($l->ISERIAL ?? 0) === 1
                                && app(SerialBatch::class)->gudangPakaiSerial($this->cabang),
                'batches'    => $l->batches ?? [],
            ];
        }
    }

    /** Wajib-batch = gudang PENERIMA berbatch + item ber-ISERIAL=1 (dibaca ulang dari DB). */
    private function wajibBatch(int $item): bool
    {
        return app(SerialBatch::class)->wajibBatch([$item], $this->cabang) !== [];
    }

    public function openBatch(int $idx): void
    {
        if (! isset($this->lines[$idx])) {
            return;
        }
        $this->batchIdx = $idx;
        $this->batchRows = array_values($this->lines[$idx]['batches'] ?? []);
        $this->bNo = '';
        $this->bExp = now()->toDateString();
        $this->bQty = null;
        $this->resetErrorBag(['bNo', 'bExp', 'bQty', 'batchRows']);
        $this->showBatch = true;
    }

    public function closeBatch(): void
    {
        $this->showBatch = false;
        $this->batchIdx = null;
        $this->batchRows = [];
    }

    public function addBatchRow(): void
    {
        $this->resetErrorBag(['bNo', 'bQty', 'batchRows']);
        $no = trim($this->bNo);
        $qty = (float) $this->bQty;

        if ($no === '') {
            $this->addError('bNo', 'No Batch belum diisi.');

            return;
        }
        if ($qty <= 0) {
            $this->addError('bQty', 'Jumlah harus lebih dari 0.');

            return;
        }

        foreach ($this->batchRows as $i => $r) {
            if (strcasecmp(trim($r['noBatch']), $no) === 0) {
                $this->batchRows[$i]['qty'] = (float) $r['qty'] + $qty;
                $this->bNo = '';
                $this->bQty = null;

                return;
            }
        }

        $this->batchRows[] = ['noBatch' => $no, 'expired' => $this->bExp ?: null, 'qty' => $qty];
        $this->bNo = '';
        $this->bQty = null;
    }

    public function removeBatchRow(int $i): void
    {
        unset($this->batchRows[$i]);
        $this->batchRows = array_values($this->batchRows);
    }

    public function applyBatch(): void
    {
        if ($this->batchIdx === null || ! isset($this->lines[$this->batchIdx])) {
            $this->closeBatch();

            return;
        }

        $this->resetErrorBag(['batchRows']);
        $qtyBaris = (float) $this->lines[$this->batchIdx]['qty'];
        $total = array_sum(array_map(fn ($r) => (float) $r['qty'], $this->batchRows));

        if (abs($total - $qtyBaris) > 0.0001) {
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
            $this->addError('batchRows', 'Total Batch (' . $fmt($total)
                . ') harus sama dengan Qty Diterima (' . $fmt($qtyBaris) . ').');

            return;
        }

        $this->lines[$this->batchIdx]['batches'] = array_values($this->batchRows);
        $this->closeBatch();
    }

    /** Qty baris berubah -> batch tidak valid lagi, dikosongkan (pola sama PbForm). */
    public function updatedLines($value, $key): void
    {
        if (! str_ends_with((string) $key, '.qty')) {
            return;
        }
        $idx = (int) explode('.', (string) $key)[0];
        if (! empty($this->lines[$idx]['batches'])) {
            $this->lines[$idx]['batches'] = [];
        }
    }

    /** TMB BARU - tarik header+baris dari KMB yg belum diterima (lihat TmbWriter::fromKmb()). */
    private function pullFromKmb(int $kmbId): void
    {
        $w = app(TmbWriter::class);
        $data = $w->fromKmb($kmbId);
        abort_if(! $data, 404, 'KMB tidak ditemukan atau sudah diterima/dibatalkan.');

        $this->kmbId = $kmbId;
        $this->noKmb = $data['header']->SUNOTRANSAKSI;
        $this->lines = $data['lines'];
        $this->fillTraceability();
    }

    /** No PR asal - TRANSITIF via KMB->SUPBUID (TMB sendiri tidak py referensi PR). */
    private function fillTraceability(): void
    {
        if (! $this->kmbId) {
            return;
        }
        $this->noKmb ??= (string) DB::table('fstoku')->where('SUID', $this->kmbId)->value('SUNOTRANSAKSI') ?: null;
        $prId = DB::table('fstoku')->where('SUID', $this->kmbId)->value('SUPBUID');
        $this->noPr = $prId
            ? (string) DB::table('fpermintaanbarangu')->where('PBUID', $prId)->value('PBUNOTRANSAKSI') : null;
    }

    protected function rules(): array
    {
        return [
            'kontak'  => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'cabang'  => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'kontak.required' => 'Akun Anda tidak terhubung ke data karyawan (auser.UKID) - hubungi admin.',
        'cabang.required' => 'Cabang Anda tidak valid - hubungi admin.',
    ];

    public function save(TmbWriter $writer): void
    {
        if ($this->locked || ! $this->kmbId) {
            return;
        }
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > (float) $l['qtyKirim'] + 0.0001) {
                $this->addError('lines', "Qty diterima untuk {$l['nama']} melebihi qty dikirim ({$l['qtyKirim']}).");

                return;
            }
            if ($this->wajibBatch((int) $l['item'])) {
                $batches = array_values($l['batches'] ?? []);
                if ($batches === []) {
                    $this->addError('lines', "Item {$l['nama']} wajib diisi No Batch - klik tombol Batch di barisnya.");

                    return;
                }
                $total = array_sum(array_map(fn ($b) => (float) $b['qty'], $batches));
                if (abs($total - $qty) > 0.0001) {
                    $this->addError('lines', "Total No Batch {$l['nama']} tidak sama dengan qty diterima - buka tombol Batch dan perbaiki.");

                    return;
                }
            }

            $lines[] = [
                'item'    => $l['item'],
                'nama'    => $l['nama'],
                'qty'     => $qty,
                'satuan'  => $l['satuan'] ?: null,
                'catatan' => $l['catatan'] ?: null,
                'batches' => ! empty($l['serial']) ? array_values($l['batches'] ?? []) : [],
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        $header = [
            'SUTANGGAL' => $this->tanggal,
            'SUKONTAK'  => $this->kontak,
            'SUURAIAN'  => trim((string) $this->uraian) ?: null,
            'SUCABANG'  => $this->cabang,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'kmbId'      => $this->kmbId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->tmbId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'inventory/tmb', $this->nomor, 'Buat TMB ' . $this->nomor . ' dari KMB ' . $this->noKmb);
        $this->dispatch('tmb-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'TMB: ' . $this->nomor);

        // Sukses -> toast, lalu tanya mau cetak sekarang (pola sama PO/PB/KMB).
        $this->dispatch('toast',
            message: 'Terima Mutasi Barang ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('inventory/tmb', 'print')) {
            $this->dispatch('confirm-print',
                message: 'TMB ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Terima Mutasi Barang',
                okText: 'Ya, cetak',
                url: route('inventory.tmb.print', $this->tmbId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.tmb-form', [
            'branches' => Branch::options(),
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
        ]);
    }
}
