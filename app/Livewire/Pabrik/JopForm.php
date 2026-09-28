<?php

namespace App\Livewire\Pabrik;

use App\Models\Branch;
use App\Services\JopWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Job Order Produksi (JOP). Header `fproduksiu` + baris `fproduksid` (produk
 * jadi + komposisi bahan baku nested per baris). BOLEH diedit selama `PUSTATUS=1` (belum
 * pernah ditarik ke Produksi manapun) - pola sama `PrForm` (dokumen rencana, wajar
 * direvisi), BEDA dari SJ/PBC/KMB/TMB/Produksi yg SELALU read-only (eksekusi stok nyata).
 */
class JopForm extends Component
{
    public ?string $tabKey = null;
    public ?int $jopId = null;
    public bool $locked = false;

    /** "Diperintah Oleh" (PUKONTAK) - AUTO dari user login, pola sama PR/PKB/PBC/KMB/TMB. */
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    /** PUCABANG - Gudang Produksi (auser.UCABANG user login). */
    public ?int $cabang = null;
    public ?int $jenis = null; // PUJENIS - blain 'Jenis Produksi'
    public ?string $uraian = null;
    public ?string $nomor = null;

    public int $status = 1; // PUSTATUS: 1 aktif, 2 sebagian, 3 selesai, 9 batal

    /** @var array<int,array{item:int,kode:string,nama:string,qty:float,qtyPakai:float,satuan:?int,satuanKode:string,catatan:?string,komposisi:list<array>}> */
    public array $lines = [];

    public string $itemQ = '';

    // modal komposisi bahan baku
    public bool $showKomposisi = false;
    public ?int $komposisiLine = null;
    public string $komposisiQ = '';

    public function mount(?int $jopId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->kontak = $user->UKID ? (int) $user->UKID : null;
        $this->kontakLabel = $this->kontak
            ? ((string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
        $this->cabang = (int) ($user->UCABANG ?? 0) ?: null;

        $this->uraian = (string) DB::table('aanomor')
            ->where('NKODE', JopWriter::SUMBER)->value('NKETERANGAN') ?: null;

        if ($jopId) {
            $this->load($jopId);
        }
    }

    private function load(int $id): void
    {
        $w = app(JopWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->jopId = $id;
        $this->nomor = $h->PUNOTRANSAKSI;
        $this->kontak = $h->PUKONTAK ? (int) $h->PUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->cabang = $h->PUCABANG ? (int) $h->PUCABANG : null;
        $this->jenis = $h->PUJENIS ? (int) $h->PUJENIS : null;
        $this->tanggal = substr((string) $h->PUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->uraian = $h->PUURAIAN;
        $this->status = (int) $h->PUSTATUS;
        $this->locked = $this->status !== JopWriter::STATUS_AKTIF;

        $this->lines = $w->linesWithKomposisi($id);
    }

    protected function rules(): array
    {
        return [
            'kontak'  => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'cabang'  => ['required', 'integer'],
            'jenis'   => ['nullable', 'integer'],
        ];
    }

    protected array $messages = [
        'kontak.required' => 'Akun Anda tidak terhubung ke data karyawan (auser.UKID) - hubungi admin.',
        'cabang.required' => 'Cabang Anda tidak valid - hubungi admin.',
    ];

    public function addProdukJadi(int $id): void
    {
        if ($this->locked) {
            return;
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
            'qtyPakai'   => 0,
            'satuan'     => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'catatan'    => null,
            'komposisi'  => [],
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

    /**
     * Livewire hook generic - dipanggil tiap `wire:model.live` berubah. Dipakai utk 2
     * skenario, KEDUANYA harus bikin "Qty Pakai" (`qty`) LANGSUNG ter-update TANPA klik
     * "Hitung Ulang Qty" manual (per permintaan user 2026-09-23, 2 putaran - awalnya cuma
     * `qtyDefault` per baris komposisi, lalu ternyata "Qty Jadi" baris INDUK berubah jg
     * harus mem-rescale SEMUA baris komposisinya, bukan cuma baris yg diketik):
     * 1. `lines.{i}.komposisi.{k}.qtyDefault` berubah -> recompute BARIS itu saja.
     * 2. `lines.{i}.qty` (Qty Jadi baris produk jadi) berubah -> recompute SEMUA baris
     *    komposisi milik baris itu (qty lama jadi stale kalau tetap pakai Qty Jadi lama).
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

    /** Ambil resep default dari `bitembahanbaku` (master BOM AKTIF, bukan `bitempenyusun`
     *  yg mati - lihat docblock `JopWriter`), qty langsung dihitung utk Qty Jadi SAAT INI. */
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

    /** Hitung ulang Qty Pakai semua baris komposisi = Qty Default × Qty Jadi SAAT INI
     *  (pola sama `HitungQtyPakai()` VB6 asli). */
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

    public function save(JopWriter $writer): void
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
            $lines[] = [
                'item'      => $l['item'],
                'qty'       => $qty,
                'satuan'    => $l['satuan'] ?: null,
                'catatan'   => $l['catatan'] ?: null,
                'komposisi' => $komposisi,
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 produk jadi dengan qty > 0.');

            return;
        }

        $header = [
            'PUTANGGAL' => $this->tanggal,
            'PUKONTAK'  => $this->kontak,
            'PUURAIAN'  => trim((string) $this->uraian) ?: null,
            'PUCABANG'  => $this->cabang,
            'PUJENIS'   => $this->jenis,
        ];

        if ($this->jopId) {
            $res = $writer->update($this->jopId, $header, $lines);
            if (! $res['ok']) {
                $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

                return;
            }
            activity_log('edit', 'pabrik/jop', $this->nomor, 'Edit JOP ' . $this->nomor);
            $this->dispatch('toast', message: 'JOP ' . $this->nomor . ' diperbarui.', type: 'success');
        } else {
            $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
            $res = $writer->create($header, $lines, [
                'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
                'tgl'        => $this->tanggal,
            ]);
            if (! $res['ok']) {
                $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

                return;
            }
            $this->jopId = $res['id'];
            $this->nomor = $res['nomor'];
            activity_log('create', 'pabrik/jop', $this->nomor, 'Buat JOP ' . $this->nomor);
            $this->dispatch('toast',
                message: 'Job Order Produksi ' . $this->nomor . ' berhasil disimpan.',
                type: 'success');
        }

        $this->dispatch('jop-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'JOP: ' . $this->nomor);

        if (can_do('pabrik/jop', 'print')) {
            $this->dispatch('confirm-print',
                message: 'JOP ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Job Order Produksi',
                okText: 'Ya, cetak',
                url: route('pabrik.jop.print', $this->jopId));
        }
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

        $komposisiResults = [];
        if (trim($this->komposisiQ) !== '' && ! $this->locked && $this->komposisiLine !== null) {
            $q = trim($this->komposisiQ);
            $komposisiResults = DB::table('bitem')
                ->where('ISTATUS', 0)
                ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%"))
                ->orderBy('INAMA')->limit(15)
                ->get(['IID as id', 'IKODE as kode', 'INAMA as nama']);
        }

        return view('livewire.pabrik.jop-form', [
            'itemResults'      => $itemResults,
            'komposisiResults' => $komposisiResults,
            'jenisList'        => DB::table('blain')->where('ltipe', 'Jenis Produksi')->orderBy('lnama')->get(['lid', 'lnama']),
        ]);
    }
}
