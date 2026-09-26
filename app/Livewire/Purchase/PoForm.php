<?php

namespace App\Livewire\Purchase;

use App\Models\Branch;
use App\Services\PurchaseOrderWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Purchase Order (PO). Header `esalesorderu` + baris `esalesorderd`. EDITABLE
 * selama `SOUSTATUS=1` (dokumen komitmen, belum final - beda dari SJ/PBC/KMB/TMB/
 * Produksi yg SELALU read-only stlh tersimpan). Lihat docblock `PurchaseOrderWriter`
 * utk detail keputusan riset (ICODING/IPONAMA override, diskon bertingkat terkoreksi,
 * cap anggaran bulanan, Attention teks bebas krn tabel kontakperson tidak ada, dll).
 */
class PoForm extends Component
{
    public ?string $tabKey = null;
    public ?int $poId = null;
    public bool $locked = false;

    // header
    public ?int $vendor = null;
    public ?string $vendorLabel = null;
    public ?string $attention = null; // teks bebas, TIDAK ada lookup (tabel kontakperson tidak ada)
    public ?string $alamat = null;
    public ?int $karyawan = null; // "Bag Pembelian" - dicari manual, BUKAN auto-login
    public ?string $karyawanLabel = null;
    public ?int $termin = null;
    public ?string $terminLabel = null;
    public ?int $uang = null;
    public float $kurs = 1;
    public ?int $gudang = null; // SOUCABANG - auto cabang login
    public int $pajak = 0; // 0=Tanpa Pajak, 1=Blm Termasuk, 2=Sudah Termasuk
    public float $nilaiPajak = 11;
    public float $diskon = 0; // Rp, header-level (SOUDISKON)
    public float $diskonPersen = 0; // % dari Sub Total (SOUDISKONPERSEN)
    /**
     * Field diskon mana yg TERAKHIR diketik user: 'rp' | 'persen'. Dipakai memutuskan
     * field mana yg ikut dihitung ulang saat Sub Total berubah (tambah/hapus/ubah baris),
     * supaya angka yg DIKETIK user tidak pernah ditimpa sendiri oleh sistem.
     */
    public string $diskonMode = 'rp';
    public ?string $noRef = null;
    public ?string $catatan = null;
    public string $tanggal = '';
    public ?string $nomor = null;

    public int $status = 0; // SOUSTATUS: 0 aktif, 2 sebagian diterima, 3 selesai diterima, 9 batal (konfirmasi data nyata - lihat docblock PurchaseOrderWriter)

    /** @var array<int,array{item:int,kode:string,nama:string,qty:float,satuan:?int,satuanKode:string,kemasan:?string,harga:float,disc1:float,disc2:float,disc3:float,discRp:float,total:float,catatan:?string}> */
    public array $lines = [];

    public string $itemQ = '';

    public function mount(?int $poId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $this->gudang = (int) ($user->UCABANG ?? 0) ?: null;

        // Mata uang default RP (permintaan user 2026-09-26). Dicari lewat KODE, bukan
        // di-hardcode ID, supaya tetap benar kalau isi `buang` berubah. VB6 jg default
        // ke baris pertama (`cboUang.ListIndex = 0`) yg kebetulan RP.
        $this->uang = (int) DB::table('buang')->where('UKODE', 'RP')->value('UID') ?: null;

        if ($poId) {
            $this->load($poId);
        }
    }

    private function load(int $id): void
    {
        $w = app(PurchaseOrderWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->poId = $id;
        $this->nomor = $h->SOUNOTRANSAKSI;
        $this->vendor = $h->SOUKONTAK ? (int) $h->SOUKONTAK : null;
        $this->vendorLabel = $this->vendor
            ? (string) DB::table('bkontak')->where('KID', $this->vendor)->value('KNAMA') : null;
        $this->attention = $h->SOUATTENTION;
        $this->alamat = $h->SOUALAMAT;
        $this->karyawan = $h->SOUKARYAWAN ? (int) $h->SOUKARYAWAN : null;
        $this->karyawanLabel = $this->karyawan
            ? (string) DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA') : null;
        $this->termin = $h->SOUTERMIN ? (int) $h->SOUTERMIN : null;
        $this->terminLabel = $this->termin
            ? (string) DB::table('btermin')->where('TID', $this->termin)->value('TKODE') : null;
        $this->uang = $h->SOUUANG ? (int) $h->SOUUANG : null;
        $this->kurs = (float) $h->SOUKURS;
        $this->gudang = $h->SOUCABANG ? (int) $h->SOUCABANG : null;
        $this->pajak = (int) $h->SOUPAJAK;
        $this->nilaiPajak = (float) $h->SOUNILAIPAJAK;
        $this->diskon = (float) $h->SOUDISKON;
        $this->diskonPersen = (float) $h->SOUDISKONPERSEN;
        $this->noRef = $h->SOUNOREF;
        $this->catatan = $h->SOUCATATAN;
        $this->tanggal = substr((string) $h->SOUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->status = (int) $h->SOUSTATUS;
        $this->locked = $this->status !== PurchaseOrderWriter::STATUS_AKTIF;

        foreach ($w->lines($id) as $l) {
            $harga = (float) $l->SODHARGA;
            $disc1 = (float) $l->SODDISKONPERSEN;
            $disc2 = (float) $l->SODDISKONPERSEN2;
            $disc3 = (float) $l->SODDISKONPERSEN3;
            $this->lines[] = [
                'item'       => (int) $l->SODITEM,
                'kode'       => $l->ICODING ?: ($l->IKODE ?? ''),
                'nama'       => $l->IPONAMA ?: ($l->INAMA ?? ('Item #' . $l->SODITEM)),
                'qty'        => (float) $l->SODORDER,
                'satuan'     => $l->SODSATUAN ? (int) $l->SODSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'kemasan'    => $l->SODKEMASAN,
                'harga'      => $harga,
                'disc1'      => $disc1,
                'disc2'      => $disc2,
                'disc3'      => $disc3,
                'discRp'     => (float) $l->SODDISKON,
                'total'      => (float) $l->SODSUBTOTAL,
                'catatan'    => $l->SODCATATAN,
            ];
        }
    }

    /** Livewire hook generic. Vendor berubah (`wire:model.live`) -> auto-isi Alamat +
     *  default Karyawan/Termin (pola sama `SetDataKontak()` VB6 asli). Baris item juga
     *  live-recompute Disc Rp/Total tiap kali Harga/Disc%1/2/3 berubah - pola sama
     *  `JopForm`/`ProduksiForm` sesi ini (server tetap sumber kebenaran akhir saat save). */
    public function updated(string $name, mixed $value): void
    {
        if ($name === 'vendor') {
            $this->onVendorChanged();

            return;
        }

        // Diskon header: 2 arah. Ketik % -> Rp dihitung; ketik Rp -> % dihitung.
        if ($name === 'diskonPersen') {
            $this->diskonMode = 'persen';
            $this->hitungDiskonHeader();

            return;
        }
        if ($name === 'diskon') {
            $this->diskonMode = 'rp';
            $this->hitungDiskonHeader();

            return;
        }

        if (preg_match('/^lines\.(\d+)\.(harga|disc1|disc2|disc3|qty)$/', $name, $m)) {
            $this->recomputeLine((int) $m[1]);
            $this->hitungDiskonHeader(); // Sub Total berubah -> pasangan diskon ikut menyesuaikan
        }
    }

    /** Sub Total = jumlah total baris (dasar perhitungan diskon header & pajak). */
    private function subTotal(): float
    {
        return array_sum(array_map(fn ($l) => (float) $l['total'], $this->lines));
    }

    /**
     * Sinkronkan pasangan diskon Rp <-> %. Yang dihitung ulang adalah field yg TIDAK
     * diketik user terakhir (`$diskonMode`) - jadi angka yg dia ketik tidak pernah
     * bergeser sendiri, termasuk saat Sub Total berubah karena baris ditambah/dihapus.
     *
     * **Lebih lengkap dari VB6**: di `dFrmOrderPembelian` cuma ada arah Rp -> %
     * (`txtDiskon_LostFocus` baris 1329); `txtDiskonPersen_LostFocus` HANYA memformat
     * angka, tidak menghitung Rp. Jadi di VB6 mengetik persen TIDAK mengubah nilai diskon
     * sama sekali - user harus hitung manual. Di sini dua arah (permintaan user 2026-09-26).
     */
    private function hitungDiskonHeader(): void
    {
        $sub = $this->subTotal();

        if ($this->diskonMode === 'persen') {
            $persen = max(0.0, min(100.0, (float) $this->diskonPersen));
            $this->diskonPersen = $persen;
            $this->diskon = round($sub * $persen / 100, 2);

            return;
        }

        $rp = max(0.0, (float) $this->diskon);
        if ($rp > $sub) {
            $rp = $sub; // diskon tidak boleh melebihi Sub Total
        }
        $this->diskon = $rp;
        $this->diskonPersen = $sub > 0 ? round($rp / $sub * 100, 4) : 0.0;
    }

    private function onVendorChanged(): void
    {
        if (! $this->vendor) {
            $this->alamat = null;

            return;
        }

        $k = DB::table('bkontak')
            ->where('KID', $this->vendor)
            ->first(['KNAMA', 'KPEMKARYAWAN', 'KPEMTERMIN', 'K3ALAMAT', 'K3ALAMAT2', 'K3KOTA', 'K3PROPINSI', 'K3KODEPOS']);
        if (! $k) {
            return;
        }

        $this->vendorLabel = $k->KNAMA;
        $this->alamat = trim(implode("\n", array_filter([$k->K3ALAMAT, $k->K3ALAMAT2, trim(($k->K3KOTA ?: '') . ' ' . ($k->K3PROPINSI ? '- ' . $k->K3PROPINSI : '') . ($k->K3KODEPOS ? ' ' . $k->K3KODEPOS : ''))])));

        if ($k->KPEMKARYAWAN) {
            $this->karyawan = (int) $k->KPEMKARYAWAN;
            $this->karyawanLabel = (string) DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA');
        }
        if ($k->KPEMTERMIN) {
            $this->termin = (int) $k->KPEMTERMIN;
            $this->terminLabel = (string) DB::table('btermin')->where('TID', $this->termin)->value('TKODE');
        }
    }

    private function recomputeLine(int $i): void
    {
        if (! isset($this->lines[$i])) {
            return;
        }
        $l = $this->lines[$i];
        $writer = app(PurchaseOrderWriter::class);
        $discRp = $writer->hitungDiskonBaris((float) $l['harga'], (float) $l['disc1'], (float) $l['disc2'], (float) $l['disc3']);
        $this->lines[$i]['discRp'] = $discRp;
        $this->lines[$i]['total'] = (float) $l['qty'] * ((float) $l['harga'] - $discRp);
    }

    public function addItem(int $id): void
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
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.IPOSATUAN')
            ->where('i.IID', $id)
            ->first(['i.IID', 'i.IKODE', 'i.INAMA', 'i.ICODING', 'i.IPONAMA', 'i.IPOSATUAN', 'i.IPOHARGA', 'i.IPOKEMASAN', 's.SKODE as satuan_kode']);
        if (! $it) {
            return;
        }

        $harga = (float) $it->IPOHARGA;
        $this->lines[] = [
            'item'       => (int) $it->IID,
            'kode'       => $it->ICODING ?: $it->IKODE,
            'nama'       => $it->IPONAMA ?: $it->INAMA,
            'qty'        => 1,
            'satuan'     => $it->IPOSATUAN ? (int) $it->IPOSATUAN : null,
            'satuanKode' => $it->satuan_kode ?? '',
            'kemasan'    => $it->IPOKEMASAN,
            'harga'      => $harga,
            'disc1'      => 0,
            'disc2'      => 0,
            'disc3'      => 0,
            'discRp'     => 0,
            'total'      => $harga,
            'catatan'    => null,
        ];
        $this->itemQ = '';
        $this->hitungDiskonHeader(); // Sub Total bertambah
    }

    public function removeLine(int $i): void
    {
        if ($this->locked) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
        $this->hitungDiskonHeader(); // Sub Total berkurang
    }

    protected function rules(): array
    {
        return [
            'vendor'  => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'gudang'  => ['required', 'integer'],
            'termin'  => ['required', 'integer'],
        ];
    }

    protected array $messages = [
        'vendor.required' => 'Vendor wajib diisi.',
        'gudang.required' => 'Gudang wajib diisi.',
        'termin.required' => 'Termin wajib diisi.',
    ];

    public function save(PurchaseOrderWriter $writer): void
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
            $lines[] = [
                'item'    => $l['item'],
                'qty'     => $qty,
                'satuan'  => $l['satuan'] ?: null,
                'kemasan' => $l['kemasan'] ?: null,
                'harga'   => (float) $l['harga'],
                'disc1'   => (float) $l['disc1'],
                'disc2'   => (float) $l['disc2'],
                'disc3'   => (float) $l['disc3'],
                'catatan' => $l['catatan'] ?: null,
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        $header = [
            'SOUTANGGAL'    => $this->tanggal,
            'SOUKONTAK'     => $this->vendor,
            'SOUATTENTION'  => $this->attention ?: null,
            'SOUALAMAT'     => $this->alamat ?: null,
            'SOUKARYAWAN'   => $this->karyawan,
            'SOUTERMIN'     => $this->termin,
            'SOUUANG'       => $this->uang,
            'SOUKURS'       => $this->kurs ?: 1,
            'SOUCABANG'     => $this->gudang,
            'SOUPAJAK'      => $this->pajak,
            'SOUNILAIPAJAK' => $this->nilaiPajak,
            'SOUDISKON'       => $this->diskon,
            'SOUDISKONPERSEN' => $this->diskonPersen,
            'SOUNOREF'      => $this->noRef ?: null,
            'SOUCATATAN'    => $this->catatan ?: null,
        ];

        $branch = Branch::query()->where('GID', $this->gudang)->first(['GALAMAT1', 'GKODE']);
        $meta = [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
        ];

        $res = $this->poId
            ? $writer->update($this->poId, $header, $lines, $meta)
            : $writer->create($header, $lines, $meta);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->poId = $res['id'];
        $this->nomor = $res['nomor'];

        activity_log($this->poId ? 'edit' : 'create', 'purchase/po', $this->nomor, 'Simpan PO ' . $this->nomor);
        $this->dispatch('po-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PO: ' . $this->nomor);

        // Sukses -> toast, lalu TANYA mau cetak sekarang atau tidak (permintaan user
        // 2026-09-26). Kalau user tidak punya hak `print`, pertanyaannya tidak muncul -
        // cukup toast saja.
        $this->dispatch('toast',
            message: 'PO ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('purchase/po', 'print')) {
            $this->dispatch('confirm-print',
                message: 'PO ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Purchase Order',
                okText: 'Ya, cetak',
                url: route('purchase.po.print', $this->poId));
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
                ->where(fn ($b) => $b
                    ->where('IKODE', 'like', "%{$q}%")->orWhere('INAMA', 'like', "%{$q}%")
                    ->orWhere('ICODING', 'like', "%{$q}%")->orWhere('IPONAMA', 'like', "%{$q}%"))
                ->orderBy('INAMA')->limit(15)
                ->get(['IID as id', 'IKODE as kode', 'INAMA as nama', 'ICODING', 'IPONAMA']);
        }

        $subtotal = $this->subTotal();
        // Pajak dihitung SETELAH diskon header - sama VB6 baris 1121
        // (`zSubTotal = lblSubTotal - txtDiskon`).
        $subtotalSetelahDiskon = $subtotal - $this->diskon;
        $writer = app(PurchaseOrderWriter::class);
        $pajakInfo = $writer->hitungPajak($subtotalSetelahDiskon, $this->pajak, $this->nilaiPajak);

        return view('livewire.purchase.po-form', [
            'itemResults'   => $itemResults,
            'branches'      => Branch::options(),
            'uangList'      => DB::table('buang')->orderBy('UKODE')->get(['UID', 'UKODE', 'UNAMA']),
            'nilaiPajakOpt' => [11, 1, 1.1, 10],
            'subtotal'      => $subtotal,
            'totalPajak'    => $pajakInfo['pajak'],
            'totalTransaksi' => $pajakInfo['total'],
        ]);
    }
}
