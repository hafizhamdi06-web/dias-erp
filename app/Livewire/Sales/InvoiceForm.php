<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Services\InvoicePenjualanWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Invoice Penjualan (IV). Header `einvoicepenjualanu` + baris `einvoicepenjualand`,
 * ditagih dari SATU Surat Jalan (SJ) per invoice (tombol "Tarik dari SJ" bisa diklik
 * berkali-kali kalau mau gabung >1 SJ pelanggan yg sama, pola sama `PbForm`). Invoice yg
 * SUDAH tersimpan SELALU read-only (pola sama PB/SJ/PBC/KMB/TMB - hapus+buat ulang, bukan
 * edit). Lihat docblock `InvoicePenjualanWriter` utk detail riset eligibility/PPN/dst.
 */
class InvoiceForm extends Component
{
    public ?string $tabKey = null;
    public ?int $invoiceId = null;
    public bool $locked = false;

    // header
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    public ?int $cabang = null;
    public ?string $uraian = 'Invoice Penjualan';
    public ?string $alamat = null;
    public ?string $attention = null;
    public ?string $catatan = null;
    public ?int $termin = null;
    public ?string $terminLabel = null;
    public ?int $karyawan = null;
    public ?string $karyawanLabel = null;
    public ?string $tglJatuhTempo = null;
    public int $jenisPajak = 0; // InvoicePenjualanWriter::PAJAK_*
    public ?string $nomor = null;

    /** @var array<int,array> lihat InvoicePenjualanWriter::fromSj() utk shape tiap baris */
    public array $lines = [];

    // modal picker "Tarik dari SJ"
    public bool $showPicker = false;
    public string $pickerQ = '';

    public function mount(?int $invoiceId = null): void
    {
        $this->tanggal = now()->toDateString();

        $user = auth()->user();
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->cabang = Branch::active()->where('GID', $ucabang)->exists() ? $ucabang : null;

        if ($invoiceId) {
            $this->load($invoiceId);
        }
    }

    private function load(int $id): void
    {
        $w = app(InvoicePenjualanWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->invoiceId = $id;
        $this->nomor = $h->IPUNOTRANSAKSI;
        $this->kontak = $h->IPUKONTAK ? (int) $h->IPUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->tanggal = substr((string) $h->IPUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->cabang = $h->IPUGUDANG ? (int) $h->IPUGUDANG : null;
        $this->uraian = $h->IPUURAIAN;
        $this->alamat = $h->IPUALAMAT;
        $this->attention = $h->IPUATTENTION;
        $this->catatan = $h->IPUCATATAN;
        $this->termin = $h->IPUTERMIN ? (int) $h->IPUTERMIN : null;
        $this->terminLabel = $this->termin
            ? (string) DB::table('btermin')->where('TID', $this->termin)->value('TKODE') : null;
        $this->karyawan = $h->IPUKARYAWAN ? (int) $h->IPUKARYAWAN : null;
        $this->karyawanLabel = $this->karyawan
            ? (string) DB::table('bkontak')->where('KID', $this->karyawan)->value('KNAMA') : null;
        $this->tglJatuhTempo = $h->IPUTGLJATUHTEMPO ? substr((string) $h->IPUTGLJATUHTEMPO, 0, 10) : null;
        $this->jenisPajak = (int) $h->IPUJENISPAJAK;
        $this->locked = true; // Invoice tersimpan SELALU read-only, lihat docblock kelas

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'sdid'       => $l->IPDSJD ? (int) $l->IPDSJD : null,
                'suid'       => $l->IPDSUID ? (int) $l->IPDSUID : null,
                'noSj'       => null,
                'item'       => (int) $l->IPDITEM,
                'kode'       => $l->IKODE ?? '',
                'nama'       => $l->INAMA ?? ('Item #' . $l->IPDITEM),
                'qty'        => (float) $l->IPDKELUAR,
                'satuan'     => $l->IPDSATUAN ? (int) $l->IPDSATUAN : null,
                'satuanKode' => $l->satuan_kode ?? '',
                'satuanD'    => $l->IPDSATUAND ? (int) $l->IPDSATUAND : null,
                'qtyD'       => (float) $l->IPDKELUARD,
                'harga'      => (float) $l->IPDHARGA,
                'disc'       => (float) $l->IPDDISKON,
                'discPersen' => (float) $l->IPDDISKONPERSEN,
                'catatan'    => $l->IPDCATATAN,
            ];
        }
    }

    public function openPicker(): void
    {
        if ($this->locked) {
            return;
        }
        $this->pickerQ = '';
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function pullFromSj(int $suId, InvoicePenjualanWriter $writer): void
    {
        if ($this->locked) {
            return;
        }

        $excludeSdid = array_values(array_filter(array_map(fn ($l) => $l['sdid'], $this->lines)));
        $data = $writer->fromSj($suId, $excludeSdid);
        if (! $data) {
            $this->addError('lines', 'SJ tidak ditemukan atau sudah tidak aktif.');

            return;
        }
        if ($data['lines'] === []) {
            $this->addError('lines', 'Semua baris SJ ' . $data['header']->SUNOTRANSAKSI . ' sudah ditagih.');

            return;
        }

        if (! $this->kontak) {
            $this->kontak = $data['header']->SUKONTAK ? (int) $data['header']->SUKONTAK : null;
            $this->kontakLabel = $data['header']->kontak ?? null;
            $this->alamat = $data['header']->alamat ?? null;
        }

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

    protected function rules(): array
    {
        return [
            'cabang'  => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
        ];
    }

    protected array $messages = [
        'cabang.required' => 'Cabang belum dipilih.',
    ];

    private function totals(): array
    {
        $subtotal = 0.0;
        foreach ($this->lines as $l) {
            $subtotal += ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty'];
        }
        $pajak = $this->jenisPajak === InvoicePenjualanWriter::PAJAK_PPN11
            ? round($subtotal * InvoicePenjualanWriter::TARIF_PPN, 2) : 0.0;

        return ['subtotal' => $subtotal, 'pajak' => $pajak, 'total' => $subtotal + $pajak];
    }

    public function save(InvoicePenjualanWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        if (! $this->kontak) {
            $this->addError('kontak', 'Tarik minimal 1 SJ dulu (pelanggan belum terisi).');

            return;
        }

        $lines = [];
        foreach ($this->lines as $l) {
            $qty = max(0.0, (float) $l['qty']);
            if ($qty <= 0 || ! $l['sdid'] || ! $l['suid']) {
                continue;
            }
            $lines[] = [
                'sdid'       => $l['sdid'],
                'suid'       => $l['suid'],
                'item'       => $l['item'],
                'qty'        => $qty,
                'satuan'     => $l['satuan'] ?: null,
                'satuanD'    => $l['satuanD'] ?: null,
                'qtyD'       => (float) $l['qtyD'],
                'harga'      => (float) $l['harga'],
                'disc'       => (float) $l['disc'],
                'discPersen' => (float) $l['discPersen'],
                'catatan'    => $l['catatan'] ?: null,
            ];
        }

        if ($lines === []) {
            $this->addError('lines', 'Minimal 1 item dengan qty > 0.');

            return;
        }

        $header = [
            'tanggal'       => $this->tanggal,
            'kontak'        => $this->kontak,
            'uraian'        => $this->uraian,
            'karyawan'      => $this->karyawan,
            'catatan'       => $this->catatan,
            'attention'     => $this->attention,
            'alamat'        => $this->alamat,
            'termin'        => $this->termin,
            'tglJatuhTempo' => $this->tglJatuhTempo,
            'jenisPajak'    => $this->jenisPajak,
            'sjId'          => $lines[0]['suid'],
            'cabang'        => $this->cabang,
        ];

        $branch = Branch::query()->where('GID', $this->cabang)->first(['GALAMAT1', 'GKODE']);
        $res = $writer->create($header, $lines, [
            'kodecabang' => (string) (($branch->GALAMAT1 ?? null) ?: ($branch->GKODE ?? 'XX')),
            'tgl'        => $this->tanggal,
        ]);

        if (! $res['ok']) {
            $this->addError('lines', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $this->invoiceId = $res['id'];
        $this->nomor = $res['nomor'];
        $this->locked = true;

        activity_log('create', 'sales/invoice', $this->nomor, 'Buat Invoice ' . $this->nomor);
        $this->dispatch('invoice-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'IV: ' . $this->nomor);
        $this->dispatch('toast',
            message: 'Invoice ' . $this->nomor . ' berhasil disimpan.',
            type: 'success');

        if (can_do('sales/invoice', 'print')) {
            $this->dispatch('confirm-print',
                message: 'Invoice ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Invoice Penjualan',
                okText: 'Ya, cetak',
                url: route('sales.invoice.print', $this->invoiceId));
        }
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $totals = $this->totals();

        return view('livewire.sales.invoice-form', [
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
            'subtotal' => $totals['subtotal'],
            'pajak'    => $totals['pajak'],
            'total'    => $totals['total'],
            'pullable' => $this->showPicker ? app(InvoicePenjualanWriter::class)->pullableSj($this->pickerQ, auth()->user()->branchIds()) : [],
        ]);
    }
}
