<?php

namespace App\Livewire\Pabrik;

use App\Models\Branch;
use App\Services\ProduksiWriter;
use App\Services\SerialBatch;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Produksi (PRO). Header `fstoku` + baris `fstokd` (bahan baku KELUAR + produk
 * jadi MASUK langsung ke Gudang Jadi - lihat docblock `ProduksiWriter`). BOLEH ditarik
 * dari 1 Job Order (`$jopId` di mount(), opsional, sisa per baris) ATAU dibuat BEBAS
 * (freeform, tanpa `$jopId`/`$produksiId`). SELALU read-only stlh tersimpan (pola sama
 * SJ/PBC/KMB/TMB - eksekusi stok nyata, bukan draft).
 */
class ProduksiForm extends Component
{
    public ?string $tabKey = null;
    public ?int $produksiId = null;
    public ?int $jopId = null; // JOP sumber (SUIDJOP) - opsional, TIDAK bisa diubah stlh dibuat
    public ?string $noJop = null;
    public bool $locked = false;

    /** "Diperintah Oleh" (SUKONTAK) - AUTO dari user login. */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** SUCABANG - Gudang Produksi (auser.UCABANG user login), tempat bahan baku dikonsumsi. */
    public ?int $cabang = null;
    /** SUGUDANGTUJUAN - Gudang Jadi, WAJIB dicari manual (tujuan hasil produksi). */
    public ?int $gudangJadi = null;
    public ?string $gudangJadiLabel = null;
    /** SUGUDANGASAL - Gudang Sample, OPSIONAL. */
    public ?int $gudangSample = null;
    public ?string $gudangSampleLabel = null;
    public ?string $uraian = null;
    public ?string $nomor = null;

    public int $status = 1; // SUSTATUS: 1 aktif, 9 batal

    /** @var array<int,array{pdid:?int,item:int,kode:string,nama:string,qty:float,qtySisa:?float,satuan:?int,satuanKode:string,catatan:?string,komposisi:list<array>}> */
    public array $lines = [];

    public string $itemQ = '';
    public string $gudangQ = '';

    // modal komposisi bahan baku
    public bool $showKomposisi = false;
    public ?int $komposisiLine = null;
    public string $komposisiQ = '';

    // modal "No Batch" produk jadi (Gudang Jadi berbatch + item ISERIAL=1) - pola sama PbForm:
    // produk jadi = stok BARU, jadi user MENGETIK no batch, bukan memilih yg sudah ada.
    public bool $showBatch = false;
    public ?int $batchIdx = null;
    /** @var list<array{noBatch:string,expired:?string,qty:float}> */
    public array $batchRows = [];
    public string $bNo = '';
    public string $bExp = '';
    public $bQty = null;

    public function mount(?int $produksiId = null, ?int $jopId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->kontak = $user->UKID ? (int) $user->UKID : null;
        $this->kontakLabel = $this->kontak
            ? ((string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', ProduksiWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($produksiId) {
            $this->load($produksiId);
        } elseif ($jopId) {
            $this->pullFromJop($jopId);
        }
    }

    private function load(int $id): void
    {
        $w = app(ProduksiWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->produksiId = $id;
        $this->jopId = $h->SUIDJOP ? (int) $h->SUIDJOP : null;
        $this->noJop = $this->jopId
            ? (string) DB::table('fproduksiu')->where('PUID', $this->jopId)->value('PUNOTRANSAKSI') : null;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->gudangJadi = $h->SUGUDANGTUJUAN ? (int) $h->SUGUDANGTUJUAN : null;
        $this->gudangJadiLabel = $this->gudangJadi
            ? (string) DB::table('bgudang')->where('GID', $this->gudangJadi)->value('GNAMA') : null;
        $this->gudangSample = $h->SUGUDANGASAL ? (int) $h->SUGUDANGASAL : null;
        $this->gudangSampleLabel = $this->gudangSample
            ? (string) DB::table('bgudang')->where('GID', $this->gudangSample)->value('GNAMA') : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // Produksi tersimpan SELALU read-only, lihat docblock kelas

        foreach ($w->linesWithKomposisi($id) as $l) {
            $this->lines[] = [
                'pdid'       => $l['idJop'],
                'item'       => $l['item'],
                'kode'       => $l['kode'],
                'nama'       => $l['nama'],
                'qty'        => $l['qty'],
                'qtySisa'    => null,
                'satuan'     => $l['satuan'],
                'satuanKode' => $l['satuanKode'],
                'catatan'    => $l['catatan'],
                'komposisi'  => $l['komposisi'],
                'serial'     => $l['serial'] && app(SerialBatch::class)->gudangPakaiSerial($this->gudangJadi),
                'batches'    => $l['batches'],
            ];
        }
    }

    /** Wajib-batch = gudang JADI berbatch + item ber-ISERIAL=1 (dibaca ulang dari DB). */
    private function wajibBatch(int $item): bool
    {
        return app(SerialBatch::class)->wajibBatch([$item], $this->gudangJadi) !== [];
    }

    /**
     * Gudang Jadi berganti -> kewajiban batch tiap baris ikut berubah (setelan
     * `GPAKAISERIAL` beda per gudang). Hitung ulang & buang batch yg terlanjur diisi kalau
     * gudang barunya tidak berbatch (pola sama `PbForm::updatedGudang()`).
     */
    private function syncFlagBatch(): void
    {
        if ($this->lines === [] || $this->locked) {
            return;
        }

        $serial = app(SerialBatch::class);
        $wajib = $serial->wajibBatch(array_map(fn ($l) => (int) $l['item'], $this->lines), $this->gudangJadi);

        foreach ($this->lines as $i => $l) {
            $perlu = in_array((int) $l['item'], $wajib, true);
            $this->lines[$i]['serial'] = $perlu;
            if (! $perlu) {
                $this->lines[$i]['batches'] = [];
            }
        }
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
                . ') harus sama dengan Qty Diproduksi (' . $fmt($qtyBaris) . ').');

            return;
        }

        $this->lines[$this->batchIdx]['batches'] = array_values($this->batchRows);
        $this->closeBatch();
    }

    /** Produksi BARU - tarik header+baris dari JOP yg masih ada sisa (lihat ProduksiWriter::fromJop()). */
    private function pullFromJop(int $jopId): void
    {
        $w = app(ProduksiWriter::class);
        $data = $w->fromJop($jopId, $this->gudangJadi);
        abort_if(! $data, 404, 'Job Order tidak ditemukan, sudah selesai ditarik, atau sudah dibatalkan.');

        $this->jopId = $jopId;
        $this->noJop = $data['header']->PUNOTRANSAKSI;
        $this->lines = $data['lines'];
    }

    protected function rules(): array
    {
        return [
            'kontak'     => ['required', 'integer'],
            'tanggal'    => ['required', 'date'],
            'cabang'     => ['required', 'integer'],
            'gudangJadi' => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'kontak.required'     => 'Akun Anda tidak terhubung ke data karyawan (auser.UKID) - hubungi admin.',
        'cabang.required'     => 'Cabang Anda tidak valid - hubungi admin.',
        'gudangJadi.required' => 'Gudang Jadi (tujuan hasil produksi) wajib diisi.',
    ];

    public function pickGudangJadi(int $id, string $nama): void
    {
        if ($this->locked) {
            return;
        }
        $this->gudangJadi = $id;
        $this->gudangJadiLabel = $nama;
        $this->gudangQ = '';
        $this->syncFlagBatch(); // setelan batch ikut gudang tujuan
    }

    public function pickGudangSample(int $id, string $nama): void
    {
        if ($this->locked) {
            return;
        }
        $this->gudangSample = $id;
        $this->gudangSampleLabel = $nama;
        $this->gudangQ = '';
    }

    public function clearGudangSample(): void
    {
        if ($this->locked) {
            return;
        }
        $this->gudangSample = null;
        $this->gudangSampleLabel = null;
    }

    public function addProdukJadi(int $id): void
    {
        if ($this->locked || $this->jopId) {
            return; // baris dari JOP sudah tetap - freeform baru boleh nambah bebas
        }
        foreach ($this->lines as $l) {
            if ($l['item'] === $id) {
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
            'pdid'       => null,
            'item'       => (int) $it->IID,
            'kode'       => $it->IKODE,
            'nama'       => $it->INAMA,
            'qty'        => 1,
            'qtySisa'    => null,
            'satuan'     => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'catatan'    => null,
            'komposisi'  => [],
            'serial'     => $this->wajibBatch((int) $it->IID),
            'batches'    => [],
        ];
        $this->itemQ = '';
    }

    public function removeLine(int $i): void
    {
        if ($this->locked || $this->jopId) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Livewire hook generic - dipanggil tiap `wire:model.live` berubah. Dipakai utk 2
     * skenario, KEDUANYA harus bikin "Qty Pakai" (`qty`) LANGSUNG ter-update TANPA klik
     * "Hitung Ulang Qty" manual (per permintaan user 2026-09-23, 2 putaran - pola SAMA
     * `JopForm`, lihat docblock method itu):
     * 1. `lines.{i}.komposisi.{k}.qtyDefault` berubah -> recompute BARIS itu saja.
     * 2. `lines.{i}.qty` (Qty Diproduksi baris produk jadi) berubah -> recompute SEMUA
     *    baris komposisi milik baris itu.
     */
    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^lines\.(\d+)\.komposisi\.(\d+)\.qtyDefault$/', $name, $m)) {
            $i = (int) $m[1];
            $k = (int) $m[2];
            if (isset($this->lines[$i])) {
                $qtyJadi = (float) $this->lines[$i]['qty'];
                $this->lines[$i]['komposisi'][$k]['qty'] = (float) $value * $qtyJadi;
            }

            return;
        }

        if (preg_match('/^lines\.(\d+)\.qty$/', $name, $m)) {
            $i = (int) $m[1];
            if (isset($this->lines[$i])) {
                $qtyJadi = (float) $value;
                foreach ($this->lines[$i]['komposisi'] as $k => $row) {
                    $this->lines[$i]['komposisi'][$k]['qty'] = (float) $row['qtyDefault'] * $qtyJadi;
                }
                // Qty berubah -> total batch pasti tidak cocok lagi; kosongkan supaya user
                // sadar harus isi ulang, bukan baru ditolak saat simpan (pola sama PbForm).
                if (! empty($this->lines[$i]['batches'])) {
                    $this->lines[$i]['batches'] = [];
                }
            }
        }
    }

    public function openKomposisi(int $i): void
    {
        $this->komposisiLine = $i;
        $this->komposisiQ = '';
        $this->showKomposisi = true;
    }

    public function closeKomposisi(): void
    {
        $this->showKomposisi = false;
        $this->komposisiLine = null;
    }

    public function ambilResepDefault(): void
    {
        if ($this->locked || $this->komposisiLine === null) {
            return;
        }
        $i = $this->komposisiLine;
        $produkJadiItem = $this->lines[$i]['item'];
        $qtyJadi = (float) $this->lines[$i]['qty'];

        $rows = DB::table('bitembahanbaku as bb')
            ->join('bitem as i', 'i.IID', '=', 'bb.IPIDBB')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'bb.IPIDSATUAN')
            ->where('bb.IPIDB', $produkJadiItem)
            ->orderBy('bb.IPIDURUTAN')
            ->get(['i.IID', 'i.IKODE', 'i.INAMA', 'bb.IPIDQTY', 'bb.IPIDSATUAN', 's.SKODE as satuan_kode']);

        $komposisi = [];
        foreach ($rows as $r) {
            $qtyDefault = (float) $r->IPIDQTY;
            $komposisi[] = [
                'item'       => (int) $r->IID,
                'kode'       => $r->IKODE,
                'nama'       => $r->INAMA,
                'qtyDefault' => $qtyDefault,
                'qty'        => $qtyDefault * $qtyJadi,
                'satuan'     => $r->IPIDSATUAN ? (int) $r->IPIDSATUAN : null,
                'satuanKode' => $r->satuan_kode ?? '',
                'catatan'    => null,
            ];
        }

        $this->lines[$i]['komposisi'] = $komposisi;
    }

    public function hitungUlangKomposisi(): void
    {
        if ($this->locked || $this->komposisiLine === null) {
            return;
        }
        $i = $this->komposisiLine;
        $qtyJadi = (float) $this->lines[$i]['qty'];

        foreach ($this->lines[$i]['komposisi'] as $k => $row) {
            $this->lines[$i]['komposisi'][$k]['qty'] = (float) $row['qtyDefault'] * $qtyJadi;
        }
    }

    public function addKomposisiItem(int $id): void
    {
        if ($this->locked || $this->komposisiLine === null) {
            return;
        }
        $i = $this->komposisiLine;
        foreach ($this->lines[$i]['komposisi'] as $k) {
            if ($k['item'] === $id) {
                $this->komposisiQ = '';

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

        $this->lines[$i]['komposisi'][] = [
            'item'       => (int) $it->IID,
            'kode'       => $it->IKODE,
            'nama'       => $it->INAMA,
            'qtyDefault' => 0,
            'qty'        => 0,
            'satuan'     => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'catatan'    => null,
        ];
        $this->komposisiQ = '';
    }

    public function removeKomposisiItem(int $k): void
    {
        if ($this->locked || $this->komposisiLine === null) {
            return;
        }
        $i = $this->komposisiLine;
        unset($this->lines[$i]['komposisi'][$k]);
        $this->lines[$i]['komposisi'] = array_values($this->lines[$i]['komposisi']);
    }

    public function save(ProduksiWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            if ($l['qtySisa'] !== null && $qty > (float) $l['qtySisa'] + 0.0001) {
                $this->addError('lines', "Qty produksi untuk {$l['nama']} melebihi sisa Job Order ({$l['qtySisa']}).");

                return;
            }
            $komposisi = [];
            foreach ($l['komposisi'] as $k) {
                $kq = max(0.0, (float) $k['qty']);
                if ($kq <= 0) {
                    continue;
                }
                $komposisi[] = [
                    'item'    => $k['item'],
                    'qty'     => $kq,
                    'satuan'  => $k['satuan'] ?: null,
                    'catatan' => $k['catatan'] ?: null,
                ];
            }
            if ($this->wajibBatch((int) $l['item'])) {
                $batches = array_values($l['batches'] ?? []);
                if ($batches === []) {
                    $this->addError('lines', "Produk jadi {$l['nama']} wajib diisi No Batch - klik tombol Batch di barisnya.");

                    return;
                }
                $total = array_sum(array_map(fn ($b) => (float) $b['qty'], $batches));
                if (abs($total - $qty) > 0.0001) {
                    $this->addError('lines', "Total No Batch {$l['nama']} tidak sama dengan qty diproduksi - buka tombol Batch dan perbaiki.");

                    return;
                }
            }

            $lines[] = [
                'pdid'      => $l['pdid'],
                'item'      => $l['item'],
                'nama'      => $l['nama'],
                'qty'       => $qty,
                'satuan'    => $l['satuan'] ?: null,
                'catatan'   => $l['catatan'] ?: null,
                'komposisi' => $komposisi,
                'batches'   => ! empty($l['serial']) ? array_values($l['batches'] ?? []) : [],
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 produk jadi dengan qty > 0.');

            return;
        }

        $header = [
            'SUTANGGAL'      => $this->tanggal,
            'SUKONTAK'       => $this->kontak,
            'SUURAIAN'       => trim((string) $this->uraian) ?: null,
            'SUCABANG'       => $this->cabang,
            'SUGUDANGTUJUAN' => $this->gudangJadi,
            'SUGUDANGASAL'   => $this->gudangSample,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'jopId'      => $this->jopId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->produksiId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'pabrik/produksi', $this->nomor, 'Buat Produksi ' . $this->nomor . ($this->noJop ? ' dari JOP ' . $this->noJop : ' (bebas)'));
        $this->dispatch('produksi-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'Produksi: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Produksi ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('pabrik/produksi', 'print')) {
            $this->dispatch('confirm-print',
                message: 'Produksi ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Produksi',
                okText: 'Ya, cetak',
                url: route('pabrik.produksi.print', $this->produksiId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $itemResults = [];
        if (trim($this->itemQ) !== '' && ! $this->locked && ! $this->jopId) {
            $q = trim($this->itemQ);
            $itemResults = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('INAMA')->limit(15)
                ->get(['IID as id', 'IKODE as kode', 'INAMA as nama']);
        }

        $komposisiResults = [];
        if (trim($this->komposisiQ) !== '' && ! $this->locked && $this->komposisiLine !== null) {
            $q = trim($this->komposisiQ);
            $komposisiResults = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('INAMA')->limit(15)
                ->get(['IID as id', 'IKODE as kode', 'INAMA as nama']);
        }

        $gudangResults = [];
        if (trim($this->gudangQ) !== '' && ! $this->locked) {
            $q = trim($this->gudangQ);
            $gudangResults = DB::table('bgudang')
                ->where(fn ($b) => $b->where('GKODE', 'like', "%{$q}%")->orWhere('GNAMA', 'like', "%{$q}%"))
                ->orderBy('GNAMA')->limit(15)
                ->get(['GID as id', 'GKODE as kode', 'GNAMA as nama']);
        }

        return view('livewire.pabrik.produksi-form', [
            'itemResults'      => $itemResults,
            'komposisiResults' => $komposisiResults,
            'gudangResults'    => $gudangResults,
            'totalQty'         => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
        ]);
    }
}
