<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\SerialBatch;
use App\Services\SjWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form SJ (Surat Jalan). Header fstoku (SUSUMBER='SJ') + baris fstokd. "Satu SJ =
 * satu PKB" - SJ SELALU lahir dari menarik sisa qty 1 PKB yg belum batal (`$pkbId` di
 * mount()), TIDAK ada input item bebas. SJ yg SUDAH tersimpan SELALU read-only (cuma
 * bisa dibatalkan lewat SjList, TIDAK ada alur edit-lalu-simpan-ulang - beda sengaja
 * dari PrForm/PkbForm yg masih bisa diedit selama status awal, krn SJ = konfirmasi
 * pengiriman fisik yg sudah terjadi, bukan dokumen draft).
 */
class SjForm extends Component
{
    public ?string $tabKey = null;
    public ?int $sjId = null;
    public ?int $pkbId = null; // PKB sumber (SUNOSO) - TIDAK bisa diubah stlh dibuat
    public bool $locked = false;

    // header
    /**
     * "Kontak" (SUKONTAK) - SATU-SATUNYA field yg genuinely dicari manual di modul
     * Pembelian ini (pola asli VB6 `cmdCariKontak_Click`), TIDAK PERNAH auto-derive dari
     * PKB/PR. WAJIB diisi user sblm simpan.
     */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** SUCABANG - cabang PEMBUAT SJ, SAMA gudang yg buat PKB terkait (auser.UCABANG user login). */
    public ?int $cabangKirim = null;
    /** SUGUDANGTUJUAN - read-only, di-copy dari PBUGUDANG PR asal (transitif via PKB). */
    public ?int $cabangTujuan = null;
    public ?string $cabangTujuanLabel = null;
    public ?string $uraian = null;
    public ?string $nomor = null;
    public ?string $noPkb = null; // nomor transaksi PKB sumber, tampilan saja

    public int $status = 1; // SUSTATUS: 0 pending (jarang), 1 aktif, 9 batal

    /** @var array<int,array{sodid:int,pbdid:?int,item:int,kode:string,nama:string,sisa:float,qty:float,satuan:?int,satuanKode:string,qtyPerBox:float,catatan:?string,stok:float,serial:bool,batches:array}> */
    public array $lines = [];

    // modal "Pilih Serial" (item bitem.ISERIAL=1) - padanan dialog VB6 fFrmSuratJalanDepo_CL
    public bool $showBatch = false;
    /** index baris di $lines yg sedang dipilih batch-nya */
    public ?int $batchIdx = null;
    /**
     * Batch yg tersedia di gudang asal utk item baris itu, PLUS jumlah yg dipilih user.
     *
     * @var list<array{isid:int,noBatch:string,expired:?string,tersedia:float,pilih:bool,qty:float}>
     */
    public array $batchRows = [];

    public function mount(?int $sjId = null, ?int $pkbId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->cabangKirim = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', SjWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($sjId) {
            $this->load($sjId);
        } elseif ($pkbId) {
            $this->pullFromPkb($pkbId);
        }
    }

    private function load(int $id): void
    {
        $w = app(SjWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->sjId = $id;
        $this->pkbId = $h->SUNOSO ? (int) $h->SUNOSO : null;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabangKirim = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->cabangTujuan = $h->SUGUDANGTUJUAN ? (int) $h->SUGUDANGTUJUAN : null;
        $this->cabangTujuanLabel = $this->cabangTujuan
            ? (string) DB::table('bgudang')->where('GID', $this->cabangTujuan)->value('GNAMA') : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // SJ tersimpan SELALU read-only, lihat docblock kelas
        $this->noPkb = $this->pkbId
            ? (string) DB::table('fperintahkirimbarangu')->where('PKBUID', $this->pkbId)->value('PKBUNOTRANSAKSI') : null;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'sodid'      => (int) $l->SDSODID,
                'pbdid'      => $l->SDPBDID ? (int) $l->SDPBDID : null,
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'sisa'       => (float) $l->SDKELUAR,
                'qty'        => (float) $l->SDKELUAR,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                // Konversi tampilan saja - lihat `SjWriter::fromPkb()`.
                'qtyPerBox'  => (float) ($l->IQTYPERBOX ?? 0),
                'catatan'    => $l->SDCATATAN,
                'stok'       => 0,
                'serial'     => (int) ($l->ISERIAL ?? 0) === 1
                                && app(SerialBatch::class)->gudangPakaiSerial($this->cabangKirim),
                'batches'    => $l->batches ?? [],
            ];
        }
    }

    /**
     * Buka dialog "Pilih Serial" - daftar batch yg MASIH ADA STOKNYA di gudang asal
     * (`SerialBatch::available()`), ditandai mana yg sudah dipilih. Batch yg sudah dipakai
     * baris LAIN di SJ ini ikut dikurangi dari kolom "Tersedia" spy user tidak memilih
     * stok yg sama dua kali (validasi keras tetap ada di writer saat simpan).
     */
    public function openBatch(int $idx, SerialBatch $serial): void
    {
        if (! isset($this->lines[$idx]) || ! $this->cabangKirim) {
            return;
        }

        $line = $this->lines[$idx];

        // SJ tersimpan = tampilkan APA ADANYA yg dikirim dulu. Tidak boleh ambil dari
        // available(), krn batch yg stoknya sudah habis terpakai tidak muncul di sana.
        if ($this->locked) {
            $this->batchRows = array_map(fn ($b) => [
                'isid'     => 0,
                'noBatch'  => $b['noBatch'],
                'expired'  => $b['expired'],
                'tersedia' => (float) $b['qty'],
                'pilih'    => true,
                'qty'      => (float) $b['qty'],
            ], array_values($line['batches'] ?? []));
            $this->batchIdx = $idx;
            $this->showBatch = true;

            return;
        }

        $dipakaiBarisLain = [];
        foreach ($this->lines as $k => $l) {
            if ($k === $idx || (int) $l['item'] !== (int) $line['item']) {
                continue;
            }
            foreach ($l['batches'] ?? [] as $b) {
                $dipakaiBarisLain[$b['noBatch']] = ($dipakaiBarisLain[$b['noBatch']] ?? 0) + (float) $b['qty'];
            }
        }

        $terpilih = [];
        foreach ($line['batches'] ?? [] as $b) {
            $terpilih[$b['noBatch']] = (float) $b['qty'];
        }

        $this->batchRows = [];
        foreach ($serial->available((int) $line['item'], (int) $this->cabangKirim) as $b) {
            $sisa = $b->tersedia - ($dipakaiBarisLain[$b->noBatch] ?? 0);
            if ($sisa <= 0 && ! isset($terpilih[$b->noBatch])) {
                continue;
            }
            $this->batchRows[] = [
                'isid'     => $b->isid,
                'noBatch'  => $b->noBatch,
                'expired'  => $b->expired,
                'tersedia' => $sisa,
                'pilih'    => isset($terpilih[$b->noBatch]),
                'qty'      => $terpilih[$b->noBatch] ?? 0.0,
            ];
        }

        $this->batchIdx = $idx;
        $this->resetErrorBag(['batchRows']);
        $this->showBatch = true;
    }

    public function closeBatch(): void
    {
        $this->showBatch = false;
        $this->batchIdx = null;
        $this->batchRows = [];
    }

    /** Centang/lepas satu batch - saat dicentang, qty-nya diisi sebanyak yg masih kurang. */
    public function toggleBatch(int $i): void
    {
        if (! isset($this->batchRows[$i])) {
            return;
        }

        $this->resetErrorBag(['batchRows']);
        $this->batchRows[$i]['pilih'] = ! $this->batchRows[$i]['pilih'];
        if (! $this->batchRows[$i]['pilih']) {
            $this->batchRows[$i]['qty'] = 0.0;

            return;
        }

        $kurang = $this->sisaDibutuhkan($i);
        $this->batchRows[$i]['qty'] = min($kurang > 0 ? $kurang : 0.0, (float) $this->batchRows[$i]['tersedia']);
    }

    /**
     * Tombol "Isi Ulang Serial" (VB6) - kosongkan pilihan lalu isi otomatis dari batch yg
     * kedaluwarsanya PALING DEKAT dulu (FEFO) sampai qty baris terpenuhi.
     */
    public function isiUlangBatch(): void
    {
        if ($this->batchIdx === null || ! isset($this->lines[$this->batchIdx])) {
            return;
        }

        $sisa = (float) $this->lines[$this->batchIdx]['qty'];
        foreach ($this->batchRows as $i => $r) {
            $ambil = min($sisa, (float) $r['tersedia']);
            $this->batchRows[$i]['pilih'] = $ambil > 0;
            $this->batchRows[$i]['qty'] = max(0.0, $ambil);
            $sisa -= $ambil;
        }
        $this->resetErrorBag(['batchRows']);
    }

    /** Qty yg masih kurang kalau baris ke-$abaikan belum dihitung. */
    private function sisaDibutuhkan(?int $abaikan = null): float
    {
        $butuh = $this->batchIdx !== null ? (float) $this->lines[$this->batchIdx]['qty'] : 0.0;
        foreach ($this->batchRows as $i => $r) {
            if ($i === $abaikan || ! $r['pilih']) {
                continue;
            }
            $butuh -= (float) $r['qty'];
        }

        return round($butuh, 4);
    }

    /** OK di dialog VB6 - total yg dipilih HARUS sama dgn qty baris. */
    public function applyBatch(): void
    {
        if ($this->batchIdx === null || ! isset($this->lines[$this->batchIdx])) {
            $this->closeBatch();

            return;
        }

        $this->resetErrorBag(['batchRows']); // jangan sisakan pesan percobaan sebelumnya
        $dipilih = [];
        foreach ($this->batchRows as $r) {
            $qty = (float) $r['qty'];
            if (! $r['pilih'] || $qty <= 0) {
                continue;
            }
            if ($qty > (float) $r['tersedia'] + 0.0001) {
                $this->addError('batchRows', "Batch {$r['noBatch']} hanya tersedia " . $this->angka($r['tersedia']) . '.');

                return;
            }
            $dipilih[] = ['noBatch' => $r['noBatch'], 'expired' => $r['expired'], 'qty' => $qty];
        }

        $qtyBaris = (float) $this->lines[$this->batchIdx]['qty'];
        $total = array_sum(array_column($dipilih, 'qty'));
        if (abs($total - $qtyBaris) > 0.0001) {
            $this->addError('batchRows', 'Jumlah Serial di Pilih (' . $this->angka($total)
                . ') harus sama dengan Jumlah Product (' . $this->angka($qtyBaris) . ').');

            return;
        }

        $this->lines[$this->batchIdx]['batches'] = $dipilih;
        $this->closeBatch();
    }

    private function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    /**
     * Qty baris berubah -> batch yg sudah dipilih tidak valid lagi (totalnya pasti beda),
     * jadi dikosongkan supaya user sadar harus pilih ulang.
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

    /** SJ BARU - tarik header+baris dari PKB yg masih ada sisa (lihat SjWriter::fromPkb()). */
    private function pullFromPkb(int $pkbId): void
    {
        $w = app(SjWriter::class);
        $data = $w->fromPkb($pkbId, $this->cabangKirim);
        abort_if(! $data, 404, 'PKB tidak ditemukan atau sudah dibatalkan.');

        $this->pkbId = $pkbId;
        $this->noPkb = $data['header']->PKBUNOTRANSAKSI;

        // Kontak penerima IKUT DITARIK dari PKB (`PKBUKONTAK`), sama spt gudang tujuan.
        // Sebelum 2026-10-02 ini TIDAK disalin, padahal `kontak` WAJIB di `rules()` - akibatnya
        // SJ baru SELALU ditolak "Kontak penerima wajib diisi." dan user harus mencarinya
        // sendiri (dilaporkan user: "belum bisa membuat SJ"). Diperiksa di data nyata: SELURUH
        // 342 PKB yg bisa ditarik punya `PKBUKONTAK` terisi, jadi isian ini hampir selalu benar.
        // Tetap BISA DIGANTI user - ini isian awal, bukan kunci.
        $kontakPkb = (int) ($data['header']->PKBUKONTAK ?? 0);
        if ($kontakPkb > 0) {
            $this->kontak = $kontakPkb;
            $this->kontakLabel = (string) DB::table('bkontak')->where('KID', $kontakPkb)->value('KNAMA') ?: null;
        }

        $this->cabangTujuan = $data['tujuanGudang'];
        $this->cabangTujuanLabel = $this->cabangTujuan
            ? (string) DB::table('bgudang')->where('GID', $this->cabangTujuan)->value('GNAMA') : null;

        $stokCol = $this->stokColumn($this->cabangKirim);
        foreach ($data['lines'] as $l) {
            $l['stok'] = $l['item'] ? (float) DB::table('bitem')->where('IID', $l['item'])->value($stokCol) : 0;
            $this->lines[] = $l;
        }
    }

    private function stokColumn(?int $gid): string
    {
        if (! $gid) {
            return 'ISTOKPG';
        }
        $col = (string) (DB::selectOne('SELECT F_KOLOMGUDANG(?) AS c', [$gid])->c ?? '');

        return preg_match('/^ISTOK[A-Z0-9]+$/', $col) ? $col : 'ISTOKPG';
    }

    protected function rules(): array
    {
        return [
            'kontak'       => ['required', 'integer'],
            'tanggal'      => ['required', 'date'],
            'cabangKirim'  => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'kontak.required'      => 'Kontak penerima wajib diisi.',
        'cabangKirim.required' => 'Cabang Anda tidak valid - hubungi admin.',
    ];

    public function save(SjWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        // DULU `return` diam-diam juga saat `! $this->pkbId` - tombol Simpan jadi mati tanpa
        // sebab yg terlihat. SJ memang HARUS berasal dari PKB, tapi katakan alasannya.
        if (! $this->pkbId) {
            $this->addError('lines', 'Surat Jalan harus ditarik dari PKB - tutup tab ini dan mulai dari tombol "SJ Baru".');

            return;
        }
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > (float) $l['sisa'] + 0.0001) {
                $this->addError('lines', "Qty dikirim untuk {$l['nama']} melebihi sisa yang tersedia ({$l['sisa']}).");

                return;
            }
            if (! empty($l['serial'])) {
                $batches = array_values($l['batches'] ?? []);
                if ($batches === []) {
                    $this->addError('lines', "Batch untuk {$l['nama']} belum dipilih - klik tombol No Batch di barisnya.");

                    return;
                }
                $total = array_sum(array_map(fn ($b) => (float) $b['qty'], $batches));
                if (abs($total - $qty) > 0.0001) {
                    $this->addError('lines', "Total batch {$l['nama']} tidak sama dengan qty dikirim - buka tombol No Batch dan perbaiki.");

                    return;
                }
            }

            $lines[] = [
                'sodid'   => $l['sodid'],
                'pbdid'   => $l['pbdid'],
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
            'SUTANGGAL'        => $this->tanggal,
            'SUKONTAK'         => $this->kontak,
            'SUURAIAN'         => trim((string) $this->uraian) ?: null,
            'SUCABANG'         => $this->cabangKirim,
            'SUGUDANGTUJUAN'   => $this->cabangTujuan,
        ];

        $branch = Branch::query()->where('GID', $this->cabangKirim)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'pkbId'      => $this->pkbId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->sjId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'sales/sj', $this->nomor, 'Buat SJ ' . $this->nomor . ' dari PKB ' . $this->noPkb);
        $this->dispatch('sj-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'SJ: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Surat Jalan ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('sales/sj', 'print')) {
            $this->dispatch('confirm-print',
                message: 'Surat Jalan ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Surat Jalan',
                okText: 'Ya, cetak',
                url: route('sales.sj.print', $this->sjId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.sj-form', [
            'branches' => Branch::options(),
            'cabangKirimLabel' => $this->cabangKirim
                ? (string) DB::table('bgudang')->where('GID', $this->cabangKirim)->value('GNAMA') : null,
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
            // Total box utk footer - hanya baris yg PUNYA isi per box > 1 yg dihitung
            // (lihat komentar konversi di blade). Tampilan saja, tidak disimpan.
            'totalBox' => array_sum(array_map(
                fn ($l) => (float) ($l['qtyPerBox'] ?? 0) > 1
                    ? (float) $l['qty'] / (float) $l['qtyPerBox'] : 0.0,
                $this->lines
            )),
        ]);
    }
}
