<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\PbcWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form PBC (Penerimaan Barang Cabang). Header fstoku (SUSUMBER='PBC') + baris
 * fstokd. "Satu PBC = satu SJ, FULL-RECEIPT" - PBC SELALU lahir dari menerima SELURUH
 * isi 1 SJ yg belum diterima (`$sjId` di mount()), TIDAK ada partial (lihat docblock
 * `PbcWriter`). PBC yg SUDAH tersimpan SELALU read-only, pola sama `SjForm` - cuma bisa
 * dibatalkan lewat PbcList, TIDAK ada alur edit-lalu-simpan-ulang.
 */
class PbcForm extends Component
{
    public ?string $tabKey = null;
    public ?int $pbcId = null;
    public ?int $sjId = null; // SJ sumber (SUNOSJAPOTIK) - TIDAK bisa diubah stlh dibuat
    public bool $locked = false;

    // header
    /**
     * "Kontak" (SUKONTAK) - AUTO dari user login (BEDA dari SJ yg manual cari - pola
     * SAMA "Diperintah Oleh"/Karyawan PR/PKB): default-saat-BARU, TETAP nilai tersimpan
     * saat PBC lama dilihat lagi.
     */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** SUCABANG - cabang PENERIMA (auser.UCABANG user login SEKARANG). */
    public ?int $cabang = null;
    public ?string $uraian = null;
    public ?string $nomor = null;
    public ?string $noSj = null; // nomor transaksi SJ sumber, tampilan saja
    public ?int $prId = null; // PR asal (SUPBUID), transitif via SJ->PKB
    public ?string $noPr = null; // nomor transaksi PR asal, tampilan saja

    public int $status = 1; // SUSTATUS: 1 aktif, 9 batal

    /** @var array<int,array{pbdid:?int,item:int,kode:string,nama:string,qtyKirim:float,qty:float,satuan:?int,satuanKode:string,catatan:?string}> */
    public array $lines = [];

    public function mount(?int $pbcId = null, ?int $sjId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->kontak = $user->UKID ? (int) $user->UKID : null;
        $this->kontakLabel = $this->kontak
            ? ((string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', PbcWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($pbcId) {
            $this->load($pbcId);
        } elseif ($sjId) {
            $this->pullFromSj($sjId);
        }
    }

    private function load(int $id): void
    {
        $w = app(PbcWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->pbcId = $id;
        $this->sjId = $h->SUNOSJAPOTIK ? (int) $h->SUNOSJAPOTIK : null;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // PBC tersimpan SELALU read-only, lihat docblock kelas
        $this->noSj = $this->sjId
            ? (string) DB::table('fstoku')->where('SUID', $this->sjId)->value('SUNOTRANSAKSI') : null;
        $this->prId = $h->SUPBUID ? (int) $h->SUPBUID : null;
        $this->noPr = $this->prId
            ? (string) DB::table('fpermintaanbarangu')->where('PBUID', $this->prId)->value('PBUNOTRANSAKSI') : null;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'pbdid'      => $l->SDPBDID ? (int) $l->SDPBDID : null,
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qtyKirim'   => (float) $l->SDMASUK,
                'qty'        => (float) $l->SDMASUK,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->SDCATATAN,
            ];
        }
    }

    /** PBC BARU - tarik header+baris dari SJ yg belum diterima (lihat PbcWriter::fromSj()). */
    private function pullFromSj(int $sjId): void
    {
        $w = app(PbcWriter::class);
        $data = $w->fromSj($sjId);
        abort_if(! $data, 404, 'SJ tidak ditemukan atau sudah diterima/dibatalkan.');

        $this->sjId = $sjId;
        $this->noSj = $data['header']->SUNOTRANSAKSI;
        $this->lines = $data['lines'];

        // PR asal didapat transitif dari baris pertama (SDPBDID -> PBDIDSU) - SEMUA
        // baris SJ SAMA PR-nya (satu SJ = satu PKB = satu PR sumber).
        $firstPbdid = $this->lines[0]['pbdid'] ?? null;
        $prIdFromLine = $firstPbdid ? (int) DB::table('fpermintaanbarangd')->where('PBDID', $firstPbdid)->value('PBDIDSU') : 0;
        $this->prId = $prIdFromLine ?: null;
        $this->noPr = $this->prId
            ? (string) DB::table('fpermintaanbarangu')->where('PBUID', $this->prId)->value('PBUNOTRANSAKSI') : null;
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

    public function save(PbcWriter $writer): void
    {
        if ($this->locked || ! $this->sjId) {
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
            $lines[] = [
                'pbdid'   => $l['pbdid'],
                'item'    => $l['item'],
                'qty'     => $qty,
                'satuan'  => $l['satuan'] ?: null,
                'catatan' => $l['catatan'] ?: null,
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
            'SUPBUID'   => $this->prId,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'sjId'       => $this->sjId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->pbcId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'purchase/pbc', $this->nomor, 'Buat PBC ' . $this->nomor . ' dari SJ ' . $this->noSj);
        $this->dispatch('pbc-saved');
        session()->flash('status', 'PBC ' . $this->nomor . ' tersimpan.');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PBC: ' . $this->nomor);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.pbc-form', [
            'branches' => Branch::options(),
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
        ]);
    }
}
