<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\PbWriter;
use App\Services\SerialBatch;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form PB (Penerimaan Barang Supplier Di Depo). Header `fstoku` (SUSUMBER='PB') +
 * baris `fstokd`. BEDA dari PBC (satu SJ, full-receipt): PB bisa **tarik dari BEBERAPA PO
 * berbeda** ke satu transaksi yg sama (tombol "Tarik dari PO" bisa diklik berkali-kali,
 * pola sama VB6 asli `cmdCariNoReff_Click`), qty per baris EDITABLE (partial receive wajar
 * - PO besar sering diterima bertahap). PB yg SUDAH tersimpan SELALU read-only (pola sama
 * SJ/PBC/KMB/TMB), tidak ada alur edit-lalu-simpan-ulang. Lihat docblock `PbWriter` utk
 * detail riset (simplifikasi qty 1-field, kenapa harga/diskon dikunci, dll).
 */
class PbForm extends Component
{
    public ?string $tabKey = null;
    public ?int $pbId = null;
    public bool $locked = false;

    // header
    public ?int $vendor = null;
    public ?string $vendorLabel = null;
    public string $tanggal = '';
    /**
     * SUCABANG - gudang tujuan penerimaan. **SELALU cabang user login, tidak bisa diubah**
     * (permintaan user 2026-09-26, sejalan aturan app "kalau ada pilihan cabang, diisi
     * sesuai cabang user"). Dulu dropdown bebas tapi dibatasi 3 gudang Depo.
     */
    public ?int $gudang = null;
    public ?string $gudangLabel = null;
    public ?string $noReff = null;
    public ?string $catatan = 'Penerimaan Barang';
    public ?string $nomor = null;

    public int $status = 1; // SUSTATUS: 1 aktif, 9 batal

    /** @var array<int,array> lihat PbWriter::fromPo() utk shape tiap baris */
    public array $lines = [];

    // modal picker "Tarik dari PO"
    public bool $showPicker = false;
    public string $pickerQ = '';
    /**
     * Filter CABANG PEMBUAT PO di picker (kosong = semua). Picker ini memang SENGAJA
     * lintas cabang - PO dibuat terpusat, sedangkan barangnya diterima di gudang Depo
     * yg dipilih terpisah di field "Gudang Tujuan" (lihat docblock `PbWriter`).
     */
    public string $pickerCabang = '';

    // modal "No Batch" (item dgn bitem.ISERIAL=1) - padanan dialog VB6 di fFrmPenerimaanBarang
    public bool $showBatch = false;
    /** index baris di $lines yg sedang diisi batch-nya */
    public ?int $batchIdx = null;
    /** @var list<array{noBatch:string,expired:?string,qty:float}> buffer batch di modal */
    public array $batchRows = [];
    public string $bNo = '';
    public string $bExp = '';
    public $bQty = null;

    public function mount(?int $pbId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->gudang = $ucabang ?: null;
        $this->gudangLabel = $this->gudang
            ? (string) DB::table('bgudang')->where('GID', $this->gudang)->value('GNAMA') : null;

        if ($pbId) {
            $this->load($pbId);
        }
    }

    private function load(int $id): void
    {
        $w = app(PbWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->pbId = $id;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->vendor = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->vendorLabel = $this->vendor
            ? (string) DB::table('bkontak')->where('KID', $this->vendor)->value('KNAMA') : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->gudang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->gudangLabel = $this->gudang
            ? (string) DB::table('bgudang')->where('GID', $this->gudang)->value('GNAMA') : null;
        $this->noReff = $h->SUNOREF;
        $this->catatan = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // PB tersimpan SELALU read-only, lihat docblock kelas

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'sodid'           => $l->SDSODID ? (int) $l->SDSODID : null,
                'noPo'            => $l->no_po ?? '',
                'item'            => (int) $l->SDITEM,
                'kode'            => $l->IKODE ?? '',
                'nama'            => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'sisaPoUnit'      => null,
                'satuanPoKode'    => '',
                'satuanPo'        => $l->SDSATUANPB ? (int) $l->SDSATUANPB : null,
                'rasio'           => 1.0,
                'satuanDasar'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanDasarKode' => $l->satuan_kode ?? '',
                'qty'             => (float) $l->SDMASUK,
                'harga'           => (float) $l->SDHARGA,
                'disc1'           => (float) $l->SDDISKON,
                'disc2'           => (float) $l->SDDISKONPERSEN,
                'disc3'           => (float) $l->SDDISKONPERSEN2,
                'disc4'           => (float) $l->SDDISKONPERSEN3,
                'catatan'         => $l->SDCATATAN,
                'serial'          => (int) ($l->ISERIAL ?? 0) === 1
                                     && app(SerialBatch::class)->gudangPakaiSerial($this->gudang),
                'batches'         => $l->batches ?? [],
            ];
        }
    }

    /** Buka modal No Batch utk satu baris. */
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

    /** Tombol ">" di dialog VB6 - tambah satu batch ke daftar. */
    public function addBatchRow(): void
    {
        $this->resetErrorBag(['bNo', 'bQty', 'batchRows']); // jangan sisakan pesan sebelumnya
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

        $this->batchRows[] = [
            'noBatch' => $no,
            'expired' => $this->bExp ?: null,
            'qty'     => $qty,
        ];
        $this->bNo = '';
        $this->bQty = null;
    }

    /** Tombol "X" di dialog VB6 - buang satu batch dari daftar. */
    public function removeBatchRow(int $i): void
    {
        unset($this->batchRows[$i]);
        $this->batchRows = array_values($this->batchRows);
    }

    /** Simpan buffer batch ke baris - total HARUS sama dgn qty diterima baris itu. */
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
            $this->addError('batchRows', 'Total Serial (' . rtrim(rtrim(number_format($total, 2, ',', '.'), '0'), ',')
                . ') harus sama dengan Jumlah Product (' . rtrim(rtrim(number_format($qtyBaris, 2, ',', '.'), '0'), ',') . ').');

            return;
        }

        $this->lines[$this->batchIdx]['batches'] = array_values($this->batchRows);
        $this->closeBatch();
    }

    public function openPicker(): void
    {
        if ($this->locked) {
            return;
        }
        if (! $this->gudang) {
            $this->addError('gudang', 'Pilih gudang tujuan dulu.');

            return;
        }
        $this->pickerQ = '';
        $this->pickerCabang = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pullFromPo(int $poId, PbWriter $writer): void
    {
        if ($this->locked) {
            return;
        }

        $excludeSodid = array_values(array_filter(array_map(fn ($l) => $l['sodid'], $this->lines)));
        $data = $writer->fromPo($poId, $excludeSodid, $this->gudang);
        if (! $data) {
            $this->addError('lines', 'PO tidak ditemukan atau sudah tidak aktif.');

            return;
        }
        if ($data['lines'] === []) {
            $this->addError('lines', 'Semua baris PO ' . $data['header']->SOUNOTRANSAKSI . ' sudah ditarik / tidak ada sisa.');

            return;
        }

        $this->vendor = $data['header']->SOUKONTAK ? (int) $data['header']->SOUKONTAK : null;
        $this->vendorLabel = $data['header']->vendor ?? null;

        foreach ($data['lines'] as $l) {
            $this->lines[] = $l;
        }

        $this->showPicker = false;
    }

    public function removeLine(int $idx): void
    {
        if ($this->locked) {
            return;
        }
        unset($this->lines[$idx]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Wajib-batch dibaca ulang dari DB (gudang `GPAKAISERIAL` + item `ISERIAL`), tidak
     * percaya flag yg dibawa state form.
     */
    private function lineIsSerial(int $item): bool
    {
        return app(SerialBatch::class)->wajibBatch([$item], $this->gudang) !== [];
    }

    /**
     * Gudang tujuan diganti setelah item ditarik -> kewajiban batch ikut berubah (setelan
     * `bgudang.GPAKAISERIAL` beda per gudang), jadi flag tiap baris dihitung ulang dan
     * batch yg terlanjur diisi dibuang kalau gudang barunya tidak memakai batch.
     */
    public function updatedGudang(): void
    {
        if ($this->lines === [] || $this->locked) {
            return;
        }

        $serial = app(SerialBatch::class);
        $pakai = $serial->gudangPakaiSerial($this->gudang);

        foreach ($this->lines as $i => $l) {
            $wajib = $pakai && $serial->serialItems([(int) $l['item']]) !== [];
            $this->lines[$i]['serial'] = $wajib;
            if (! $wajib) {
                $this->lines[$i]['batches'] = [];
            }
        }
    }

    /**
     * Qty baris berubah -> batch yg sudah diisi jadi tidak valid lagi (totalnya pasti beda),
     * jadi dikosongkan supaya user sadar harus isi ulang, bukan ditolak saat simpan.
     */
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

    protected function rules(): array
    {
        return [
            'gudang'  => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
        ];
    }

    protected array $messages = [
        'gudang.required' => 'Cabang Anda tidak valid - hubungi admin.',
    ];

    public function save(PbWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        if (! $this->vendor) {
            $this->addError('vendor', 'Tarik minimal 1 PO dulu (supplier belum terisi).');

            return;
        }

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'sodid'       => $l['sodid'],
                'item'        => $l['item'],
                'qty'         => $qty,
                'rasio'       => (float) $l['rasio'],
                'satuanPo'    => $l['satuanPo'] ?: null,
                'satuanDasar' => $l['satuanDasar'] ?: null,
                'harga'       => (float) $l['harga'],
                'disc1'       => (float) $l['disc1'],
                'disc2'       => (float) $l['disc2'],
                'disc3'       => (float) $l['disc3'],
                'disc4'       => (float) $l['disc4'],
                'catatan'     => $l['catatan'] ?: null,
                'nama'        => $l['nama'] ?? null,
                'batches'     => ! empty($l['serial']) ? array_values($l['batches'] ?? []) : [],
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        // Cek batch di sini juga (bukan cuma di writer) supaya pesannya menunjuk item yg salah
        // sebelum transaksi dibuka. Writer tetap validasi ulang - itu yg jadi pagar terakhir.
        foreach ($lines as $l) {
            if ($l['batches'] === [] && $this->lineIsSerial($l['item'])) {
                $this->addError('lines', 'Item "' . ($l['nama'] ?: $l['item']) . '" wajib diisi No Batch (klik tombol Batch di barisnya).');

                return;
            }
            if ($l['batches'] !== []) {
                $total = array_sum(array_map(fn ($b) => (float) $b['qty'], $l['batches']));
                if (abs($total - (float) $l['qty']) > 0.0001) {
                    $this->addError('lines', 'Total No Batch item "' . ($l['nama'] ?: $l['item'])
                        . '" tidak sama dengan qty diterima - buka tombol Batch dan perbaiki.');

                    return;
                }
            }
        }

        $header = [
            'SUTANGGAL' => $this->tanggal,
            'SUKONTAK'  => $this->vendor,
            'SUNOREF'   => trim((string) $this->noReff) ?: null,
            'SUURAIAN'  => trim((string) $this->catatan) ?: null,
            'SUCABANG'  => $this->gudang,
        ];

        $branch = Branch::query()->where('GID', $this->gudang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->pbId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'purchase/receipt', $this->nomor, 'Buat PB ' . $this->nomor);
        $this->dispatch('pb-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PB: ' . $this->nomor);

        // Sukses -> toast, lalu tanya mau cetak sekarang (pola sama PO).
        $this->dispatch('toast',
            message: 'Penerimaan Barang ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('purchase/receipt', 'print')) {
            $this->dispatch('confirm-print',
                message: 'PB ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Penerimaan Barang',
                okText: 'Ya, cetak',
                url: route('purchase.pb.print', $this->pbId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.pb-form', [
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
            'pullable' => $this->showPicker
                ? app(PbWriter::class)->pullablePo($this->pickerQ, $this->pickerCabang !== '' ? (int) $this->pickerCabang : null)
                : [],
            'cabangPo' => $this->showPicker ? app(PbWriter::class)->cabangPunyaPo() : [],
        ]);
    }
}
