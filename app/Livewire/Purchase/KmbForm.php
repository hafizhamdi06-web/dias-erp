<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\KmbWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form KMB (Kirim Mutasi Barang). Header fstoku (SUSUMBER='KMB') + baris fstokd.
 * "Satu PR jenis=0 = satu KMB, FULL qty" - KMB SELALU lahir dari menarik SELURUH baris
 * 1 PR yg belum py KMB hidup (`$prId` di mount()), TANPA syarat verifikasi (lihat
 * docblock `KmbWriter`). KMB yg SUDAH tersimpan SELALU read-only, pola sama `SjForm` -
 * cuma bisa dibatalkan lewat KmbList.
 */
class KmbForm extends Component
{
    public ?string $tabKey = null;
    public ?int $kmbId = null;
    public ?int $prId = null; // PR sumber (SUPBUID) - TIDAK bisa diubah stlh dibuat
    /**
     * JOP/JOT sumber (`SUIDJOP`) - ALTERNATIF `$prId`, tidak pernah terisi bersamaan.
     * Jalur ini port dari VB6 `fFrmKirimMutasiBarang::cmdCariNoReff_Click` (permintaan user
     * 2026-10-03) - lihat `KmbWriter::fromJop()`.
     */
    public ?int $jopId = null;
    public ?string $noJop = null;
    public bool $locked = false;

    // header
    /** "Diperintah Oleh" (SUKONTAK) - AUTO dari user login, pola sama PR/PKB/PBC. */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** SUCABANG - cabang PENGIRIM (auser.UCABANG user login SEKARANG). */
    public ?int $cabang = null;
    public ?int $cabangTujuan = null; // SUGUDANGTUJUAN - dari PBUGUDANG PR, tampilan saja
    public ?string $uraian = null;
    public ?string $nomor = null;
    public ?string $noPr = null; // nomor transaksi PR sumber, tampilan saja

    public int $status = 1; // SUSTATUS: 1 aktif, 3 diterima (TMB), 9 batal

    /** @var array<int,array{pbdid:int,item:int,kode:string,nama:string,qtyMinta:float,qty:float,satuan:?int,satuanKode:string,catatan:?string}> */
    public array $lines = [];

    public function mount(?int $kmbId = null, ?int $prId = null, ?int $jopId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->kontak = $user->UKID ? (int) $user->UKID : null;
        $this->kontakLabel = $this->kontak
            ? ((string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', KmbWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($kmbId) {
            $this->load($kmbId);
        } elseif ($prId) {
            $this->pullFromPr($prId);
        } elseif ($jopId) {
            $this->pullFromJop($jopId);
        }
    }

    private function load(int $id): void
    {
        $w = app(KmbWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->kmbId = $id;
        $this->prId = $h->SUPBUID ? (int) $h->SUPBUID : null;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabang = $h->SUCABANG ? (int) $h->SUCABANG : null;
        $this->cabangTujuan = $h->SUGUDANGTUJUAN ? (int) $h->SUGUDANGTUJUAN : null;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->SUURAIAN;
        $this->status = (int) $h->SUSTATUS;
        $this->locked = true; // KMB tersimpan SELALU read-only, lihat docblock kelas
        $this->noPr = $this->prId
            ? (string) DB::table('fpermintaanbarangu')->where('PBUID', $this->prId)->value('PBUNOTRANSAKSI') : null;
        $this->jopId = $h->SUIDJOP ? (int) $h->SUIDJOP : null;
        $this->noJop = $this->jopId
            ? (string) DB::table('fproduksiu')->where('PUID', $this->jopId)->value('PUNOTRANSAKSI') : null;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                // Salah satu saja yg terisi: `pbdid` utk jalur PR, `pdid` utk jalur JOP.
                'pbdid'      => $l->SDPBDID ? (int) $l->SDPBDID : null,
                'pdid'       => $l->SDIDJOP ? (int) $l->SDIDJOP : null,
                'item'       => (int) $l->SDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->SDITEM),
                'qtyMinta'   => (float) $l->SDKELUAR,
                'qty'        => (float) $l->SDKELUAR,
                'satuan'     => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'catatan'    => $l->SDCATATAN,
            ];
        }
    }

    /** KMB BARU - tarik header+baris dari PR jenis=0 yg belum py KMB hidup. */
    private function pullFromPr(int $prId): void
    {
        $w = app(KmbWriter::class);
        $data = $w->fromPr($prId);
        abort_if(! $data, 404, 'PR tidak ditemukan, sudah dibatalkan, atau sudah ada KMB yang berjalan.');

        $this->prId = $prId;
        $this->noPr = $data['header']->PBUNOTRANSAKSI;
        $this->cabangTujuan = $data['header']->PBUGUDANG ? (int) $data['header']->PBUGUDANG : null;
        $this->lines = $data['lines'];
    }

    /**
     * KMB BARU dari JOP/JOT - tarik baris BAHAN (`PDKELUAR`) yg masih bersisa.
     * Port `fFrmKirimMutasiBarang::cmdCariNoReff_Click`, lihat `KmbWriter::fromJop()`.
     */
    private function pullFromJop(int $jopId): void
    {
        $w = app(KmbWriter::class);
        $data = $w->fromJop($jopId);
        abort_if(! $data, 404, 'JOP tidak ditemukan atau sudah ditutup.');

        $this->jopId = $jopId;
        $this->noJop = $data['header']->PUNOTRANSAKSI;
        // Gudang tujuan = cabang yg MENJALANKAN produksi (VB6 baris 633: txtGudangTujuan.Tag
        // = Rs1("PUCABANG")) - beda dari jalur PR yg memakai PBUGUDANG si peminta.
        $this->cabangTujuan = $data['header']->PUCABANG ? (int) $data['header']->PUCABANG : null;
        $this->lines = $data['lines'];
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

    public function save(KmbWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        // Jangan `return` diam-diam - tombol Simpan yg mati tanpa sebab sudah pernah jadi
        // laporan "tidak respon" di modul lain.
        if (! $this->prId && ! $this->jopId) {
            $this->addError('lines', 'KMB harus ditarik dari PR atau JOP - tutup tab ini dan mulai dari daftar KMB.');

            return;
        }
        $this->validate();

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > (float) $l['qtyMinta'] + 0.0001) {
                $this->addError('lines', "Qty kirim untuk {$l['nama']} melebihi qty diminta ({$l['qtyMinta']}).");

                return;
            }
            $lines[] = [
                'pbdid'   => $l['pbdid'] ?? null,
                'pdid'    => $l['pdid'] ?? null,
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
            'SUTANGGAL'       => $this->tanggal,
            'SUKONTAK'        => $this->kontak,
            'SUURAIAN'        => trim((string) $this->uraian) ?: null,
            'SUCABANG'        => $this->cabang,
            'SUGUDANGTUJUAN'  => $this->cabangTujuan,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
            'prId'       => $this->prId,
            'jopId'      => $this->jopId,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->kmbId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'inventory/kmb', $this->nomor, 'Buat KMB ' . $this->nomor
            . ($this->jopId ? ' dari JOP ' . $this->noJop : ' dari PR ' . $this->noPr));
        $this->dispatch('kmb-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'KMB: ' . $this->nomor);

        // Sukses -> toast, lalu tanya mau cetak sekarang (pola sama PO/PB).
        $this->dispatch('toast',
            message: 'Kirim Mutasi Barang ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('inventory/kmb', 'print')) {
            $this->dispatch('confirm-print',
                message: 'KMB ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Kirim Mutasi Barang',
                okText: 'Ya, cetak',
                url: route('inventory.kmb.print', $this->kmbId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        return view('livewire.purchase.kmb-form', [
            'branches' => Branch::options(),
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
        ]);
    }
}
