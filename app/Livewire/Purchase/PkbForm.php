<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\PkbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form PKB (Perintah Kirim Barang). Header fperintahkirimbarangu + baris
 * fperintahkirimbarangd. "Satu PKB = satu PR" - PKB SELALU lahir dari menarik data 1
 * PR yg sudah Disetujui (`$prId` di mount()), TIDAK ada input item bebas spt PrForm.
 * Kolom legacy = UPPERCASE.
 */
class PkbForm extends Component
{
    public ?string $tabKey = null;
    public ?int $pkbId = null;
    public ?int $prId = null; // PR sumber (PKBUNORS) - TIDAK bisa diubah stlh dibuat
    public bool $locked = false;

    // header
    /**
     * "Diperintah Oleh" (PKBUKONTAK/PKBUKARYAWAN) - pola SAMA `PrForm::$karyawan`:
     * default user login HANYA saat PKB baru dibuat (dari picker PR), TETAP nilai
     * tersimpan saat PKB lama dibuka/diedit lagi (bukan ikut siapa yg SEDANG membuka).
     */
    public ?int $diperintah = null;
    public ?string $diperintahLabel = null;
    public string $tanggal = '';
    /**
     * PKBUGUDANG - cabang PEMBUAT PKB (gudang pengirim/pusat), BUKAN cabang PR peminta -
     * pola sama `PrForm::$gudang` (auser.UCABANG user login, default-saat-baru /
     * tetap-tersimpan-saat-edit).
     */
    public ?int $gudang = null;
    /**
     * Info REFERENSI dari PR sumber (read-only, di-copy sekali saat PKB dibuat dari
     * `PBUGUDANG`/`PBUTIPEPERMINTAAN` PR - lihat docblock kelas PkbWriter soal deviasi
     * ini dari VB6 asli). "Kirim ke cabang mana" & "kategori tujuan permintaan".
     */
    public ?int $tujuanCabang = null;
    public ?string $tujuanCabangLabel = null;
    public ?int $tujuanKategori = null;
    public ?string $tujuanKategoriLabel = null;
    public ?string $uraian = null;
    public ?string $nomor = null;
    public ?string $noPr = null; // nomor transaksi PR sumber, tampilan saja

    // info dokumen (read-only)
    public int $status = 0; // PKBUSTATUS: 0 baru, 9 batal

    /** @var array<int,array{rsidd:int,item:int,kode:string,nama:string,sisa:float,qty:float,satuan:?int,satuanKode:string,catatan:?string,stok:float,saldo3:float,saldo1:float}> */
    public array $lines = [];

    public function mount(?int $pkbId = null, ?int $prId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->diperintah = $user->UKID ? (int) $user->UKID : null;
        $this->diperintahLabel = $this->diperintah
            ? ((string) DB::table('bkontak')->where('KID', $this->diperintah)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->gudang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', PkbWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($pkbId) {
            $this->load($pkbId);
        } elseif ($prId) {
            $this->pullFromPr($prId);
        }
    }

    private function load(int $id): void
    {
        $w = app(PkbWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->pkbId = $id;
        $this->prId = $h->PKBUNORS ? (int) $h->PKBUNORS : null;
        $this->nomor = $h->PKBUNOTRANSAKSI;
        $this->diperintah = $h->PKBUKONTAK ? (int) $h->PKBUKONTAK : null;
        $this->diperintahLabel = $this->diperintah
            ? ((string) DB::table('bkontak')->where('KID', $this->diperintah)->value('KNAMA') ?: null)
            : null;
        $this->gudang = $h->PKBUGUDANG ? (int) $h->PKBUGUDANG : null;
        $this->tujuanCabang = $h->PKBUGUDANGSUMBER ? (int) $h->PKBUGUDANGSUMBER : null;
        $this->tujuanCabangLabel = $this->tujuanCabang
            ? (string) DB::table('bgudang')->where('GID', $this->tujuanCabang)->value('GNAMA') : null;
        $this->tujuanKategori = $h->PKBUTIPEPERMINTAAN ? (int) $h->PKBUTIPEPERMINTAAN : null;
        $this->tujuanKategoriLabel = $this->tujuanKategori
            ? (string) DB::table('blain')->where('LID', $this->tujuanKategori)->value('LKODE') : null;
        $this->tanggal = substr((string) $h->PKBUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->PKBUURAIAN;
        $this->status = (int) $h->PKBUSTATUS;
        $this->locked = $this->status !== 0;
        $this->noPr = $this->prId
            ? (string) DB::table('fpermintaanbarangu')->where('PBUID', $this->prId)->value('PBUNOTRANSAKSI') : null;

        $stokCol = $this->stokColumn($this->gudang);
        foreach ($w->lines($id) as $l) {
            $stok = $l->PKBDITEM ? (float) DB::table('bitem')->where('IID', $l->PKBDITEM)->value($stokCol) : 0;
            $this->lines[] = [
                'rsidd'      => (int) $l->PKBDRSIDD,
                'item'       => (int) $l->PKBDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->PKBDITEM),
                'sisa'       => (float) $l->PKBDQTY,
                'qty'        => (float) $l->PKBDQTY,
                'satuan'     => $l->PKBDSATUAN ? (int) $l->PKBDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->PKBDCATATAN,
                'stok'       => $stok,
                'saldo3'     => (float) $l->PKBD3BULAN,
                'saldo1'     => (float) $l->PKBD1BULAN,
            ];
        }
    }

    /** PKB BARU - tarik header+baris dari PR yg sudah Disetujui (lihat PkbWriter::fromPr()). */
    private function pullFromPr(int $prId): void
    {
        $w = app(PkbWriter::class);
        $data = $w->fromPr($prId);
        abort_if(! $data, 404, 'PR tidak ditemukan atau belum Disetujui.');

        $this->prId = $prId;
        $h = $data['header'];
        $this->noPr = $h->PBUNOTRANSAKSI;
        $this->tujuanCabang = $h->PBUGUDANG ? (int) $h->PBUGUDANG : null;
        $this->tujuanCabangLabel = $this->tujuanCabang
            ? (string) DB::table('bgudang')->where('GID', $this->tujuanCabang)->value('GNAMA') : null;
        $this->tujuanKategori = $h->PBUTIPEPERMINTAAN ? (int) $h->PBUTIPEPERMINTAAN : null;
        $this->tujuanKategoriLabel = $this->tujuanKategori
            ? (string) DB::table('blain')->where('LID', $this->tujuanKategori)->value('LKODE') : null;

        $stokCol = $this->stokColumn($this->gudang);
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

    public function updatedGudang(): void
    {
        if ($this->lines === []) {
            return;
        }
        $stokCol = $this->stokColumn($this->gudang);
        $stok = DB::table('bitem')->whereIn('IID', array_column($this->lines, 'item'))->pluck($stokCol, 'IID');
        foreach ($this->lines as $i => $l) {
            $this->lines[$i]['stok'] = (float) ($stok[$l['item']] ?? 0);
        }
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    protected function rules(): array
    {
        return [
            'diperintah' => ['required', 'integer'],
            'tanggal'    => ['required', 'date'],
            'gudang'     => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'diperintah.required' => 'Akun Anda tidak terhubung ke data karyawan (auser.UKID) - hubungi admin.',
        'gudang.required'     => 'Cabang Anda tidak valid - hubungi admin.',
    ];

    public function save(PkbWriter $writer): void
    {
        if ($this->locked || ! $this->prId) {
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
                $this->addError('lines', "Qty dikirim untuk {$l['nama']} melebihi sisa yang diminta ({$l['sisa']}).");

                return;
            }
            $lines[] = [
                'rsidd'   => $l['rsidd'],
                'item'    => $l['item'],
                'qty'     => $qty,
                'satuan'  => $l['satuan'] ?: null,
                'catatan' => $l['catatan'] ?: null,
                'saldo3'  => $l['saldo3'] ?? 0,
                'saldo1'  => $l['saldo1'] ?? 0,
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        $header = [
            'PKBUTANGGAL'        => $this->tanggal,
            'PKBUKONTAK'         => $this->diperintah,
            'PKBUKARYAWAN'       => $this->diperintah,
            'PKBUGUDANG'         => $this->gudang,
            'PKBUGUDANGSUMBER'   => $this->tujuanCabang,
            'PKBUTIPEPERMINTAAN' => $this->tujuanKategori,
            'PKBUURAIAN'         => trim((string) $this->uraian) ?: null,
        ];

        $branch = Branch::query()->where('GID', $this->gudang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'prId'       => $this->prId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->pkbId = $res['id'];
        $this->nomor = $res['nomor'];

        activity_log('create', 'purchase/pkb', $this->nomor, 'Buat PKB ' . $this->nomor . ' dari PR ' . $this->noPr);
        $this->dispatch('pkb-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PKB: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Perintah Kirim Barang ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('purchase/pkb', 'print')) {
            $this->dispatch('confirm-print',
                message: 'PKB ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Perintah Kirim Barang',
                okText: 'Ya, cetak',
                url: route('purchase.pkb.print', $this->pkbId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.pkb-form', [
            'branches' => Branch::options(),
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
        ]);
    }
}
