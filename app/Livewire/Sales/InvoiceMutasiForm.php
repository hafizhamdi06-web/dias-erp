<?php

namespace App\Livewire\Sales;

use App\Services\InvoicePenjualanMutasiWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tab form Invoice Penjualan Mutasi (IVM). Header `einvoicepenjualanu` (`IPUSUMBER='IVM'`)
 * + baris `einvoicepenjualand`, ditagih dari SATU TMB per invoice. Gudang asal & tujuan
 * OTOMATIS terisi dari TMB yg ditarik (BUKAN pilihan manual, beda dari `InvoiceForm`/IV yg
 * py dropdown Gudang) - lihat docblock `InvoicePenjualanMutasiWriter` utk detail riset.
 * Invoice tersimpan SELALU read-only, pola sama semua modul transaksi lain.
 */
class InvoiceMutasiForm extends Component
{
    public ?string $tabKey = null;
    public ?int $invoiceId = null;
    public bool $locked = false;

    // header - gudangAsal/gudangTujuan OTOMATIS dari TMB yg ditarik
    public ?int $gudangAsal = null;
    public ?string $gudangAsalLabel = null;
    public ?int $gudangTujuan = null;
    public ?string $gudangTujuanLabel = null;

    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public string $tanggal = '';
    public ?string $uraian = 'Invoice Penjualan Mutasi';
    public ?string $alamat = null;
    public ?string $attention = null;
    public ?string $catatan = null;
    public ?int $termin = null;
    public ?string $terminLabel = null;
    public ?int $karyawan = null;
    public ?string $karyawanLabel = null;
    public ?string $tglJatuhTempo = null;
    public int $jenisPajak = 0; // InvoicePenjualanMutasiWriter::PAJAK_*
    public ?string $nomor = null;

    /** @var array<int,array> lihat InvoicePenjualanMutasiWriter::fromTmb() utk shape tiap baris */
    public array $lines = [];

    // modal picker "Tarik dari TMB"
    public bool $showPicker = false;
    public string $pickerQ = '';

    public function mount(?int $invoiceId = null): void
    {
        $this->tanggal = now()->toDateString();

        if ($invoiceId) {
            $this->load($invoiceId);
        }
    }

    private function load(int $id): void
    {
        $w = app(InvoicePenjualanMutasiWriter::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->invoiceId = $id;
        $this->nomor = $h->IPUNOTRANSAKSI;
        $this->kontak = $h->IPUKONTAK ? (int) $h->IPUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->tanggal = substr((string) $h->IPUTANGGAL, 0, 10) ?: now()->toDateString();
        $this->gudangAsal = $h->IPUGUDANG ? (int) $h->IPUGUDANG : null;
        $this->gudangAsalLabel = $this->gudangAsal
            ? (string) DB::table('bgudang')->where('GID', $this->gudangAsal)->value('GNAMA') : null;
        $this->gudangTujuan = $h->IPUGUDANGTUJUAN ? (int) $h->IPUGUDANGTUJUAN : null;
        $this->gudangTujuanLabel = $this->gudangTujuan
            ? (string) DB::table('bgudang')->where('GID', $this->gudangTujuan)->value('GNAMA') : null;
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
        $this->locked = true;

        foreach ($w->lines($id) as $l) {
            $this->lines[] = [
                'sdid'       => $l->IPDSJD ? (int) $l->IPDSJD : null,
                'suid'       => $l->IPDSUID ? (int) $l->IPDSUID : null,
                'noTmb'      => null,
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

    public function pullFromTmb(int $suId, InvoicePenjualanMutasiWriter $writer): void
    {
        if ($this->locked) {
            return;
        }

        $excludeSdid = array_values(array_filter(array_map(fn ($l) => $l['sdid'], $this->lines)));
        $data = $writer->fromTmb($suId, $excludeSdid);
        if (! $data) {
            $this->addError('lines', 'TMB tidak ditemukan atau sudah tidak aktif.');

            return;
        }
        if ($data['lines'] === []) {
            $this->addError('lines', 'Semua baris TMB ' . $data['header']->SUNOTRANSAKSI . ' sudah ditagih.');

            return;
        }

        if (! $this->gudangAsal && $data['header']->gudangAsal) {
            $this->gudangAsal = (int) $data['header']->gudangAsal;
            $this->gudangAsalLabel = (string) DB::table('bgudang')->where('GID', $this->gudangAsal)->value('GNAMA');
        }
        if (! $this->gudangTujuan && $data['header']->gudangTujuan) {
            $this->gudangTujuan = (int) $data['header']->gudangTujuan;
            $this->gudangTujuanLabel = (string) DB::table('bgudang')->where('GID', $this->gudangTujuan)->value('GNAMA');
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
            'tanggal' => ['required', 'date'],
        ];
    }

    private function totals(): array
    {
        $subtotal = 0.0;
        foreach ($this->lines as $l) {
            $subtotal += ((float) $l['harga'] - (float) $l['disc']) * (float) $l['qty'];
        }
        $pajak = $this->jenisPajak === InvoicePenjualanMutasiWriter::PAJAK_PPN11
            ? round($subtotal * InvoicePenjualanMutasiWriter::TARIF_PPN, 2) : 0.0;

        return ['subtotal' => $subtotal, 'pajak' => $pajak, 'total' => $subtotal + $pajak];
    }

    public function save(InvoicePenjualanMutasiWriter $writer): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate();

        if (! $this->gudangAsal || ! $this->gudangTujuan) {
            $this->addError('lines', 'Tarik minimal 1 TMB dulu (gudang asal/tujuan belum terisi).');

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
            'tmbId'         => $lines[0]['suid'],
            'gudangAsal'    => $this->gudangAsal,
            'gudangTujuan'  => $this->gudangTujuan,
        ];

        $branch = DB::table('bgudang')->where('GID', $this->gudangAsal)->first(['GALAMAT1', 'GKODE']);
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

        activity_log('create', 'sales/invoice-mutasi', $this->nomor, 'Buat Invoice Mutasi ' . $this->nomor);
        $this->dispatch('invoice-mutasi-saved');
        session()->flash('status', 'Invoice Mutasi ' . $this->nomor . ' tersimpan.');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'IVM: ' . $this->nomor);
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $totals = $this->totals();

        return view('livewire.sales.invoice-mutasi-form', [
            'totalQty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $this->lines)),
            'subtotal' => $totals['subtotal'],
            'pajak'    => $totals['pajak'],
            'total'    => $totals['total'],
            'pullable' => $this->showPicker
                ? app(InvoicePenjualanMutasiWriter::class)->pullableTmb($this->pickerQ, auth()->user()->branchIds())
                : [],
        ]);
    }
}
