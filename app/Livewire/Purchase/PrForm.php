<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\PurchaseRequestWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Permintaan Barang. Header fpermintaanbarangu + baris fpermintaanbarangd.
 * Kolom legacy = UPPERCASE.
 */
class PrForm extends Component
{
    public ?string $tabKey = null;
    public ?int $prId = null;
    public bool $locked = false; // sudah diverifikasi -> read-only

    // header
    /**
     * Nama Karyawan - TIDAK PERNAH bisa dipilih/diubah manual oleh user (dropdown/search
     * dihapus, per permintaan user 2026-09-17). Tapi BEDA dari Kasir POS dlm satu hal
     * (dikoreksi user 2026-09-17 stlh sempat salah diimplementasi "selalu user login"):
     * default dari user login (`auser.UKID` -> `bkontak.KID`) HANYA berlaku saat transaksi
     * BARU (`$prId` null). Saat menampilkan/edit data LAMA, nilai yg tampil = `PBUKONTAK`
     * TERSIMPAN (fakta historis siapa peminta asli), BUKAN diganti ke siapa yg SEDANG
     * membuka/mengedit skrg - persis pola CI3 lama (`M_PB_Permintaan_Barang::ubahTransaksi()`
     * menulis ulang `pbukontak` dari value form yg dikirim, bukan re-derive dari sesi).
     */
    public ?int $karyawan = null;
    public ?string $karyawanLabel = null;
    public string $tanggal = '';
    /**
     * 0=Permintaan Barang, 1=Permintaan Pembelian (dikoreksi 2026-09-18 - versi
     * sblmnya SALAH pakai 1/2, ternyata legacy `cboJenisPermintaan.ListIndex` di VB6
     * asli 0-based & LANGSUNG disimpan sbg PBUJENIS mentah2, dikonfirmasi jg dari data
     * REAL hasil import user 1 Agt-skrg: PBUJENIS cuma pernah berisi 0/1, TIDAK PERNAH
     * 2). Nol dampak ke data nyata - dicek dulu, TIDAK ADA baris `fpermintaanbarangu`
     * yg sempat tersimpan pakai skema lama 1/2 sblm fix ini (PR asli baru pernah dibuat
     * via import, blm pernah via form Laravel). OTOMATIS mengikuti `$tujuan` (via
     * `blain.LGUDANGID`), TIDAK bisa dipilih manual oleh user.
     */
    public int $jenis = 0;
    public ?int $tujuan = null;            // blain 'Jenis Permintaan' (kategori: Cabang/Depo/Farmasi/dst)
    /**
     * PBUGUDANG - "Depo/Farmasi" = cabang user LOGIN, SELALU otomatis & TERKUNCI (tidak
     * pernah bisa diubah user, per permintaan user 2026-09-17 poin 3). BUKAN "gudang
     * tujuan (PO Ke)" spt nama variabel PHP-nya terkesan - itu sebenarnya $gudangSumber
     * di bawah (label blade sempat TERTUKAR, dikoreksi 2026-09-17 stlh cross-check ke
     * VB6+CI3+screenshot: PBUGUDANG="Depo/Farmasi" auto, PBUGUDANGSUMBER="PO Ke" dicari).
     */
    public ?int $gudang = null;
    /**
     * PBUGUDANGSUMBER - "Gudang Tujuan (PO Ke)". Kalau `$tujuan` (kategori) py
     * `blain.LGUDANGID` > 0 (Depo/Farmasi/Pabrik/dst) -> OTOMATIS terisi dari situ &
     * TERKUNCI. Kalau `$tujuan`="Cabang" (LGUDANGID=0) -> user WAJIB cari manual
     * (lihat `applyTujuanRules()`).
     */
    public ?int $gudangSumber = null;
    public ?string $uraian = null;
    public ?string $nomor = null;

    // info dokumen (read-only)
    public int $status = 0;                    // PBUSTATUS: 0 belum verifikasi, 1 pending, 2 disetujui
    public ?string $catatanVerifikasi = null;
    public ?string $verifiedBy = null;
    public ?string $verifiedAt = null;

    /** @var array<int,array{item:int,kode:string,nama:string,qty:float,satuan:?int,satuanKode:string,catatan:?string,stok:float,stokManual:float}> */
    public array $lines = [];

    public string $itemQ = '';

    /**
     * Kolom "Real Stok" (stok SISTEM, `bitem.ISTOK{kode}` -> `PBDSTOKREAL`) DISEMBUNYIKAN
     * default (2026-09-24, permintaan user) - beda dari kolom "Stok" BARU (`stokManual` ->
     * `PBDSTOK`, lihat inputnya di grid, SELALU tampil di samping Satuan) yg user isi
     * MANUAL dari hasil hitung fisik/buku catatan sendiri. "Real Stok" (sistem) tetap
     * DIHITUNG/disimpan spt biasa (`refreshStock()`/`addItem()` tidak berubah) - cuma
     * KOLOM TAMPILANNYA disembunyikan sampai user (biasanya verifikator) klik toggle utk
     * mengecek/membandingkan kedua angka itu.
     */
    public bool $showStok = false;

    public function toggleStok(): void
    {
        // Defense-in-depth (2026-09-24, sama pola mount()/save()) - tombol SUDAH digate
        // can_do() di blade, cek ULANG server-side di sini. Khusus verifikator sekarang
        // (bukan "siapa pun"), SAMA persis hak `approve` yg dipakai tombol Verifikasi.
        if (! can_do('inventory/pr', 'approve')) {
            return;
        }
        $this->showStok = ! $this->showStok;
    }

    public function mount(?int $prId = null): void
    {
        // Defense-in-depth (2026-09-24, permintaan user "sesuaikan dengan hak akses") -
        // tombol pembuka (newPr/editPr) di PrList SUDAH digate can_do(), TAPI tab bisa jg
        // dibuka langsung via dispatch('open-tab', ...) client-side - cek ULANG di sini
        // supaya user tanpa hak tetap ditolak walau berhasil trigger event-nya.
        abort_unless(can_do('inventory/pr', $prId ? 'view' : 'add'), 403);

        $this->tanggal = now()->toDateString();

        // Default Karyawan & Depo/Farmasi = user login HANYA utk transaksi BARU (dikoreksi
        // 2026-09-17 - versi sblmnya salah selalu memaksa user login walau lagi tampilkan
        // data lama; user tegaskan "ketika menampilkan data, KARYAWAN dari PBUKONTAK,
        // ketika buat baru baru default UKID user login"). `load()` di bawah, kalau
        // `$prId` ada, akan MENIMPA keduanya dgn nilai tersimpan (`PBUKONTAK`/`PBUGUDANG`).
        $user = auth()->user();
        $this->karyawan = $user->UKID ? (int) $user->UKID : null;
        $this->karyawanLabel = $this->karyawan
            ? ((string) DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->gudang = (int) ($user->UCABANG ?? 0) ?: null;

        // Default Keterangan dari tabel Nomor (aanomor.NKETERANGAN, NID 728 utk PBUSUMBER='RS')
        // - per permintaan user 2026-09-17. Diisi HANYA saat transaksi BARU; load() di bawah
        // menimpa dgn nilai tersimpan kalau ini transaksi lama (PBUURAIAN asli, bukan default).
        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', PurchaseRequestWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($prId) {
            $this->load($prId);
        }
    }

    private function load(int $id): void
    {
        $w = app(PurchaseRequestWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->prId = $id;
        $this->nomor = $h->PBUNOTRANSAKSI;
        // Nama Karyawan & Depo/Farmasi = fakta historis TERSIMPAN, bukan user yg SEDANG
        // membuka/mengedit (dikoreksi 2026-09-17, lihat docblock properti $karyawan di atas).
        // Default dari sesi login di mount() cuma berlaku selama load() BELUM dipanggil
        // (yaitu transaksi baru) - begitu load() jalan, keduanya ditimpa dgn nilai asli ini.
        $this->karyawan = $h->PBUKONTAK ? (int) $h->PBUKONTAK : null;
        $this->karyawanLabel = $this->karyawan
            ? ((string) DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA') ?: null)
            : null;
        $this->gudang = $h->PBUGUDANG ? (int) $h->PBUGUDANG : null;
        $this->tanggal = substr((string) $h->PBUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->jenis = $h->PBUJENIS !== null ? (int) $h->PBUJENIS : 0;
        $this->tujuan = $h->PBUTIPEPERMINTAAN ? (int) $h->PBUTIPEPERMINTAAN : null;
        $this->gudangSumber = $h->PBUGUDANGSUMBER ? (int) $h->PBUGUDANGSUMBER : null;
        $this->uraian = $h->PBUURAIAN;
        $this->status = (int) $h->PBUSTATUS;
        $this->locked = $this->status !== 0;
        $this->catatanVerifikasi = $h->PBUKONFIRMASICATATAN;
        $this->verifiedBy = $h->PBUAPPROVEU ? (string) DB::table('auser')->where('UID', $h->PBUAPPROVEU)->value('UNAMA') : null;
        $this->verifiedAt = $h->PBUKONFIRMASITANGGAL ? substr((string) $h->PBUKONFIRMASITANGGAL, 0, 10) : null;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'item'       => (int) $l->PBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PBDITEM),
                'qty'        => (float) $l->PBDQTY,
                'satuan'     => $l->PBDSATUAN ? (int) $l->PBDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->PBDCATATAN,
                'stok'       => (float) $l->PBDSTOKREAL,
                'stokManual' => (float) $l->PBDSTOK,
            ];
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

    public function updatedGudang(): void
    {
        $this->refreshStock();
    }

    /** User BENAR-BENAR mengganti Tujuan - pilihan gudangSumber lama (hasil cari manual
     *  utk kategori sebelumnya) sudah tidak relevan, kosongkan dulu sblm terapkan ulang. */
    public function updatedTujuan(): void
    {
        $this->gudangSumber = null;
        $this->applyTujuanRules();
    }

    /**
     * Terapkan aturan turunan dari `$tujuan` (kategori Cabang/Depo/Farmasi/dst), per
     * permintaan user 2026-09-17: (1) `$jenis` OTOMATIS - "Cabang" (`blain.LGUDANGID`=0)
     * -> Permintaan Barang, selain itu -> Permintaan Pembelian, TIDAK bisa dipilih manual;
     * (2) `$gudangSumber` ("Gudang Tujuan/PO Ke") DIPAKSA sesuai `blain.LGUDANGID` kalau
     * bukan "Cabang" (override APAPUN nilai sebelumnya - tidak ada skenario user boleh
     * override ini). Kalau "Cabang" (LGUDANGID=0), method ini SENGAJA TIDAK menyentuh
     * `$gudangSumber` sama sekali - itu hasil cari manual user, harus dibiarkan utuh kalau
     * method ini dipanggil ulang di luar konteks "ganti tujuan" (mis. resync defensif di
     * awal save() - lihat `updatedTujuan()` di atas utk kapan HARUS dikosongkan dulu).
     */
    private function applyTujuanRules(): void
    {
        if (! $this->tujuan) {
            $this->jenis = 0;

            return;
        }

        $gid = (int) DB::table('blain')->where('LID', $this->tujuan)->value('LGUDANGID');

        if ($gid > 0) {
            $this->jenis = 1;
            $this->gudangSumber = $gid;
        } else {
            $this->jenis = 0;
        }
    }

    /** Dipakai render() (bukan properti tersimpan) - "PO Ke" terkunci kalau tujuan py gudang tetap. */
    private function isGudangSumberLocked(): bool
    {
        if (! $this->tujuan) {
            return false;
        }

        return (int) DB::table('blain')->where('LID', $this->tujuan)->value('LGUDANGID') > 0;
    }

    public function refreshStock(): void
    {
        if ($this->lines === []) {
            return;
        }
        $stokCol = $this->stokColumn($this->gudang);
        $stok = DB::table('bitem')->whereIn('IID', array_column($this->lines, 'item'))
            ->pluck($stokCol, 'IID');

        foreach ($this->lines as $i => $l) {
            $this->lines[$i]['stok'] = (float) ($stok[$l['item']] ?? 0);
        }
    }

    public function addItem(int $id): void
    {
        foreach ($this->lines as $i => $l) {
            if ($l['item'] === $id) {
                $this->lines[$i]['qty'] += 1;
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

        $stokCol = $this->stokColumn($this->gudang);
        $stok = (float) DB::table('bitem')->where('IID', $id)->value($stokCol);

        $this->lines[] = [
            'item'       => (int) $it->IID,
            'kode'       => $it->IKODE,
            'nama'       => $it->INAMA,
            'qty'        => 1,
            'satuan'     => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'catatan'    => null,
            'stok'       => $stok,
            'stokManual' => 0.0,
        ];
        $this->itemQ = '';
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    protected function rules(): array
    {
        return [
            'karyawan'     => ['required', 'integer'],
            'tanggal'      => ['required', 'date'],
            'jenis'        => ['required', 'in:0,1'],
            'tujuan'       => ['required', 'integer'],
            'gudang'       => ['required', 'integer'],
            'gudangSumber' => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'karyawan.required'     => 'Akun Anda tidak terhubung ke data karyawan (auser.UKID) - hubungi admin.',
        'tujuan.required'       => 'Tujuan wajib dipilih.',
        'gudang.required'       => 'Cabang Anda tidak valid - hubungi admin.',
        'gudangSumber.required' => 'Gudang Tujuan (PO Ke) wajib diisi.',
    ];

    public function save(PurchaseRequestWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        // Defense-in-depth (2026-09-24, sama alasan mount()) - tombol "Simpan" SUDAH
        // digate can_do() di blade, cek ULANG server-side di sini.
        if (! can_do('inventory/pr', $this->prId ? 'edit' : 'add')) {
            $this->addError('lines', 'Anda tidak punya hak akses untuk menyimpan.');

            return;
        }
        // Resync defensif - server-side jadi sumber kebenaran akhir utk jenis/gudangSumber
        // turunan dari tujuan, bukan cuma mengandalkan reaktivitas updatedTujuan() di UI.
        $this->applyTujuanRules();
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($l['item'] <= 0 || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'PBDITEM'     => (int) $l['item'],
                'PBDQTY'      => $qty,
                'PBDQTYD'     => $qty,
                'PBDSATUAN'   => $l['satuan'] ?: null,
                'PBDSATUAND'  => $l['satuan'] ?: null,
                'PBDSTOK'     => (float) ($l['stokManual'] ?? 0),
                'PBDSTOKREAL' => (float) $l['stok'],
                'PBDCATATAN'  => $l['catatan'] ?: null,
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        $header = [
            'PBUTANGGAL'         => $this->tanggal,
            'PBUKONTAK'          => $this->karyawan,
            'PBUKARYAWAN'        => $this->karyawan,
            'PBUGUDANG'          => $this->gudang,
            'PBUGUDANGSUMBER'    => $this->gudangSumber ?: null,
            'PBUTIPEPERMINTAAN'  => $this->tujuan,
            'PBUJENIS'           => $this->jenis,
            'PBUURAIAN'          => trim((string) $this->uraian) ?: null,
        ];

        if ($this->prId) {
            $res = $writer->update($this->prId, $header, $lines);
            $ok = $res['ok'];
            $nomor = $this->nomor;
        } else {
            $branch = Branch::query()->where('GID', $this->gudang)->first(['GALAMAT1', 'GKODE']);
            $res = $writer->create($header, $lines, [
                'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
                'tgl'        => $this->tanggal,
            ]);
            $ok = $res['ok'];
            $nomor = $res['nomor'] ?? null;
            if ($ok) {
                $this->prId = $res['id'];
                $this->nomor = $nomor;
            }
        }

        if (! $ok) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        activity_log($this->prId && $this->nomor === $nomor ? 'update' : 'create', 'inventory/pr', $nomor,
            ($this->prId ? 'Ubah' : 'Buat') . ' permintaan barang ' . $nomor);

        $this->dispatch('pr-saved');
        session()->flash('status', 'Permintaan ' . $nomor . ' tersimpan.');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PR: ' . $nomor);

        // Otomatis tampilkan cetakan "Surat Permintaan Pelanggan" (2026-09-24, permintaan
        // user) - event browser GENERIK yg sama dipakai laporan lain (lihat docblock
        // `Reports\PenjualanPerBarang`), listener global di `dias-helpers.js` sudah ada,
        // TIDAK perlu listener baru. `PrPrintController` (route biasa, bukan Livewire).
        // Digate can_do('print') (2026-09-24) - user tanpa hak cetak tetap bisa simpan,
        // cuma PDF-nya tidak otomatis terbuka.
        if (can_do('inventory/pr', 'print')) {
            $this->dispatch('report-pdf-ready', url: route('inventory.pr.print', $this->prId));
        }
    }

    /** Cetak ulang "Surat Permintaan Pelanggan" - tombol manual, PR harus sudah tersimpan. */
    public function printPr(): void
    {
        if (! $this->prId || ! can_do('inventory/pr', 'print')) {
            return;
        }
        $this->dispatch('report-pdf-ready', url: route('inventory.pr.print', $this->prId));
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $itemResults = [];
        if (trim($this->itemQ) !== '' && ! $this->locked) {
            $q = trim($this->itemQ);
            $itemResults = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('INAMA')->limit(15)
                ->get(['IID as id', 'IKODE as kode', 'INAMA as nama']);
        }

        return view('livewire.purchase.pr-form', [
            'itemResults'        => $itemResults,
            'branches'           => Branch::options(),
            'tujuanList'         => DB::table('blain')->where('ltipe', 'Jenis Permintaan')->orderBy('lnama')->get(['lid', 'lnama']),
            'totalQty'           => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
            'gudangSumberLocked' => $this->isGudangSumberLocked(),
        ]);
    }
}
