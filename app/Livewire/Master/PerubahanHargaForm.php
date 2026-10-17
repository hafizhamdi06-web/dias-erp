<?php

namespace App\Livewire\Master;

use App\Livewire\Reports\DaftarStokBarang;
use App\Services\PerubahanHargaWriter as W;
use App\Support\TabelImport;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tab form Perubahan Harga. Tata letak = VB6 `fFrmPerubahanHarga` (amenu MID 517 ->
 * `aMod_Menu.bas` `Case 517`); aturan data & dua pengaman Terapkan/Kembalikan ada di
 * docblock `PerubahanHargaWriter` - BACA itu dulu sebelum mengubah alurnya.
 *
 * Hak akses (`master/perubahan-harga`):
 *   add / edit -> menyusun & menyimpan dokumen
 *   approve    -> TERAPKAN & KEMBALIKAN (mengubah harga jual yg dipakai kasir - sengaja
 *                 dipisah dari yg menyusun)
 *   delete     -> hapus draft
 *
 * `$dirty`: Terapkan memakai isi dokumen di DATABASE, bukan isi layar. Jadi selama ada
 * perubahan yg belum disimpan, Terapkan ditolak - kalau tidak, yg dipasang ke harga jual
 * bisa beda dari yg terlihat user.
 */
class PerubahanHargaForm extends Component
{
    use WithFileUploads;

    public ?string $tabKey = null;

    public ?int $phId = null;
    public ?string $nomor = null;
    public int $status = W::STATUS_DRAFT;
    public bool $dirty = false;

    /** Terkunci = sudah diterapkan ATAU user tidak berhak menyunting. Dihitung server, tidak bisa diubah klien. */
    #[Locked]
    public bool $locked = false;

    // header
    public string $tanggal = '';
    public string $berlaku = '';
    public ?int $kontak = null;
    public ?string $kontakLabel = null;
    public ?string $uraian = 'Perubahan Harga';

    // proses massal (VB6 baris "Tampilkan Jenis ... Nilai ... % Proses")
    public string $jenis = '0';
    /** Tanpa tipe: isian yg dikosongkan user terkirim sbg string kosong - dibaca lewat (float). */
    public $persen = 0;
    public int $pembulatan = 1000;

    /**
     * HANYA angka - kode/nama/harga sekarang diambil saat render utk baris yg tampil.
     * State ini ikut bolak-balik di SETIAP permintaan Livewire; "Tampilkan" jenis Tindakan =
     * 1.029 baris, dan versi pertama (baris lengkap + semua baris dirender) menghasilkan
     * halaman 2,2 MB. Proyek ini pernah kena ERR_QUIC_PROTOCOL_ERROR dari respons 196 KB.
     *
     * @var array<int,array{item:int,lama:float,baru:float,akhir:float}>
     */
    public array $lines = [];

    /**
     * Grid ditampilkan per halaman (lihat `$lines`). 25, bukan 50: satu baris ber-isian uang
     * ~2 KB, dan kasus terburuk ("Semua jenis" = 1.912 item) state-nya sendiri ~106 KB -
     * 25 baris menjaga respons tetap di bawah 196 KB yg pernah memicu error jaringan.
     */
    public const PER_HALAMAN = 25;
    public int $hal = 1;
    public string $cariBaris = '';

    public string $itemQ = '';

    /**
     * Lampiran bukti (surat/IOM kenaikan, gambar/PDF) utk AUDIT - aturannya di
     * `PerubahanHargaWriter` (wajib sebelum Terapkan, tak bisa dihapus setelah pernah diterapkan).
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $fileLampiran = null;

    // ---- Import Kode / Nama / Harga Baru (permintaan user 2026-10-17) ----
    public bool $showImport = false;
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $fileImport = null;
    /** Ringkasan import terakhir utk ditampilkan di dialog. */
    public ?array $hasilImport = null;

    public function mount(?int $phId = null): void
    {
        $this->tanggal = now()->toDateString();
        $this->berlaku = now()->addDay()->toDateString();

        if ($phId) {
            abort_unless(can_do(PerubahanHargaList::ACL, 'view'), 403);
            $this->load($phId);

            return;
        }

        abort_unless(can_do(PerubahanHargaList::ACL, 'add'), 403);
        $this->hitungKunci();
    }

    private function load(int $id): void
    {
        $w = app(W::class);
        $h = $w->header($id);
        abort_if(! $h, 404);

        $this->phId = $id;
        $this->nomor = $h->SUNOTRANSAKSI;
        $this->status = (int) $h->SUSTATUS;
        $this->tanggal = substr((string) $h->SUTANGGAL, 0, 10);
        $this->berlaku = substr((string) $h->SUTANGGALBERLAKU, 0, 10);
        $this->kontak = $h->SUKONTAK ? (int) $h->SUKONTAK : null;
        $this->kontakLabel = $this->kontak
            ? (string) DB::table('bkontak')->where('KID', $this->kontak)->value('KNAMA') : null;
        $this->uraian = $h->SUURAIAN;
        $this->persen = (float) $h->SUNILAI;

        $this->lines = array_map(fn ($l) => [
            'item'  => (int) $l->SDITEM,
            'lama'  => (float) $l->SDHARGALAMA,
            'baru'  => (float) $l->SDHARGABARU,
            'akhir' => (float) $l->SDHARGAAKHIR,
        ], $w->lines($id));

        $this->dirty = false;
        $this->hitungKunci();
    }

    private function hitungKunci(): void
    {
        $this->locked = $this->status === W::STATUS_DITERAPKAN
            || ! can_do(PerubahanHargaList::ACL, $this->phId ? 'edit' : 'add');
    }

    public function updated($name): void
    {
        if ($name === 'cariBaris') {
            $this->hal = 1;
        }
        if (! in_array($name, ['itemQ', 'jenis', 'pembulatan', 'cariBaris', 'hal', 'fileImport', 'showImport', 'fileLampiran'], true)) {
            $this->dirty = true;
        }
    }

    private function baris(object $it): array
    {
        $h = (float) $it->IHARGAJUAL1;

        return ['item' => (int) $it->IID, 'lama' => $h, 'baru' => $h, 'akhir' => $h];
    }

    private function itemQuery()
    {
        return DB::table('bitem as i')->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->select('i.IID', 'i.IKODE', 'i.INAMA', 'i.IHARGAJUAL1', 's.SKODE as satuan');
    }

    public function addItem(int $id): void
    {
        $this->itemQ = '';
        if ($this->locked || in_array($id, array_column($this->lines, 'item'), true)) {
            return;
        }
        $it = $this->itemQuery()->where('i.IID', $id)->first();
        if ($it) {
            $this->lines[] = $this->baris($it);
            $this->dirty = true;
        }
    }

    /** VB6 `cmdTampilkan_Click`: semua item ber-harga jual > 0 dari jenis terpilih. */
    public function tambahJenis(): void
    {
        if ($this->locked) {
            return;
        }
        $ada = array_flip(array_column($this->lines, 'item'));
        $baru = $this->itemQuery()
            ->where('i.ISTATUS', 0)->where('i.IHARGAJUAL1', '>', 0)
            ->when($this->jenis !== '', fn ($b) => $b->where('i.IJENISITEM', (int) $this->jenis))
            ->orderBy('i.IKODE')->get()
            ->reject(fn ($it) => isset($ada[(int) $it->IID]));

        foreach ($baru as $it) {
            $this->lines[] = $this->baris($it);
        }
        if ($baru->isNotEmpty()) {
            $this->dirty = true;
        }
        $this->dispatch('toast', message: $baru->count() . ' item ditambahkan.', type: 'info');
    }

    public function removeLine(int $i): void
    {
        if ($this->locked) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
        $this->dirty = true;
    }

    public function kosongkan(): void
    {
        if (! $this->locked) {
            $this->lines = [];
            $this->dirty = true;
        }
    }

    /** VB6 `cmdProses_Click`: Harga Baru = lama + %, Setelah Pembulatan = dibulatkan. */
    public function proses(): void
    {
        if ($this->locked) {
            return;
        }
        foreach ($this->lines as $i => $l) {
            [$this->lines[$i]['baru'], $this->lines[$i]['akhir']]
                = W::hitung((float) $l['lama'], (float) $this->persen, $this->pembulatan);
        }
        $this->dirty = true;
    }

    /**
     * Ambil ulang harga jual SEKARANG sebagai Harga Lama. Baris yg harganya berubah dihitung
     * ulang dgn persen & pembulatan yg terpasang; baris lain tidak disentuh (supaya harga
     * akhir yg sudah diedit manual tidak hilang).
     */
    public function segarkanLama(): void
    {
        if ($this->locked || $this->lines === []) {
            return;
        }
        $kini = DB::table('bitem')->whereIn('IID', array_column($this->lines, 'item'))->pluck('IHARGAJUAL1', 'IID');
        $n = 0;
        foreach ($this->lines as $i => $l) {
            $h = (float) ($kini[$l['item']] ?? 0);
            if (abs($h - (float) $l['lama']) >= 0.005) {
                $this->lines[$i]['lama'] = $h;
                [$this->lines[$i]['baru'], $this->lines[$i]['akhir']] = W::hitung($h, (float) $this->persen, $this->pembulatan);
                $n++;
            }
        }
        if ($n) {
            $this->dirty = true;
        }
        $this->dispatch('toast', message: $n ? "{$n} item harga lamanya diperbarui & dihitung ulang - periksa lalu simpan."
            : 'Semua harga lama masih sama dengan harga sekarang.', type: $n ? 'warning' : 'success');
    }

    protected function rules(): array
    {
        return [
            'tanggal'       => ['required', 'date'],
            'berlaku'       => ['required', 'date'],
            'persen'        => ['nullable', 'numeric', 'between:-100,1000'],
            'lines.*.akhir' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected array $messages = [
        'berlaku.required'   => 'Tanggal berlaku wajib diisi.',
        'lines.*.akhir.gt'   => 'Harga akhir harus lebih dari 0.',
    ];

    public function save(W $w): void
    {
        $this->resetErrorBag();
        if ($this->locked) {
            return;
        }
        abort_unless(can_do(PerubahanHargaList::ACL, $this->phId ? 'edit' : 'add'), 403);
        $this->validate();

        $cabang = (int) (auth()->user()->UCABANG ?? 0);
        $g = DB::table('bgudang')->where('GID', $cabang)->first(['GALAMAT1', 'GKODE']);

        $res = $w->save($this->phId, [
            'tanggal'    => $this->tanggal,
            'berlaku'    => $this->berlaku,
            'kontak'     => $this->kontak,
            'uraian'     => trim((string) $this->uraian),
            'persen'     => (float) $this->persen,
            'cabang'     => $cabang,
            'kodecabang' => (string) (($g->GALAMAT1 ?? null) ?: ($g->GKODE ?? 'XX')),
        ], array_map(fn ($l) => ['item' => $l['item'], 'lama' => $l['lama'],
            'baru' => $l['baru'], 'akhir' => $l['akhir']], $this->lines));

        if (! $res['ok']) {
            $this->addError('lines', $res['error']);

            return;
        }

        $baru = ! $this->phId;
        $this->load($res['id']);
        activity_log($baru ? 'create' : 'update', PerubahanHargaList::ACL, $this->nomor,
            ($baru ? 'Buat' : 'Ubah') . ' Perubahan Harga ' . $this->nomor . ' (' . count($this->lines) . ' item)');
        $this->dispatch('ph-saved');
        $this->dispatch('tab-label', key: $this->tabKey, label: 'PH: ' . $this->nomor);
        $this->dispatch('toast', message: 'Perubahan Harga ' . $this->nomor . ' tersimpan (belum diterapkan).', type: 'success');

        if (can_do(PerubahanHargaList::ACL, 'print')) {
            $this->dispatch('confirm-print',
                message: 'Perubahan Harga ' . $this->nomor . ' sudah tersimpan. Cetak dokumennya sekarang?',
                title: 'Cetak Perubahan Harga',
                okText: 'Ya, cetak',
                url: route('master.perubahan-harga.print', $this->phId));
        }
    }

    public function terapkan(W $w): void
    {
        $this->resetErrorBag();
        abort_unless(can_do(PerubahanHargaList::ACL, 'approve'), 403);
        if (! $this->phId || $this->dirty) {
            $this->addError('lines', 'Simpan dulu perubahan di layar sebelum menerapkan harga.');

            return;
        }

        $res = $w->terapkan($this->phId);
        if (! $res['ok']) {
            $this->addError('lines', $res['error'] . ($res['berubah'] ? ' — ' . implode('; ', array_slice($res['berubah'], 0, 10))
                . (count($res['berubah']) > 10 ? '; …' : '') : ''));
            $this->load($this->phId);

            return;
        }

        $this->load($this->phId);
        activity_log('approve', PerubahanHargaList::ACL, $this->nomor,
            'Terapkan Perubahan Harga ' . $this->nomor . ' (' . $res['jumlah'] . ' item)');
        $this->dispatch('ph-saved');
        $this->dispatch('toast', message: 'Harga jual ' . $res['jumlah'] . ' item sudah diubah.', type: 'success');
    }

    public function kembalikan(W $w): void
    {
        $this->resetErrorBag();
        abort_unless(can_do(PerubahanHargaList::ACL, 'approve'), 403);
        if (! $this->phId) {
            return;
        }

        $res = $w->kembalikan($this->phId);
        if (! $res['ok']) {
            $this->addError('lines', $res['error']);

            return;
        }

        $this->load($this->phId);
        activity_log('revert', PerubahanHargaList::ACL, $this->nomor,
            'Kembalikan harga lama ' . $this->nomor . ' (' . $res['jumlah'] . ' item, ' . count($res['dilewati']) . ' dilewati)');
        $this->dispatch('ph-saved');

        if ($res['dilewati']) {
            $this->addError('lines', count($res['dilewati']) . ' item TIDAK dikembalikan karena harganya sudah diubah lagi '
                . 'setelah dokumen ini diterapkan: ' . implode('; ', array_slice($res['dilewati'], 0, 10))
                . (count($res['dilewati']) > 10 ? '; …' : ''));
        }
        $this->dispatch('toast', message: 'Harga lama ' . $res['jumlah'] . ' item sudah dikembalikan.',
            type: $res['dilewati'] ? 'warning' : 'success');
    }

    public function hapus(W $w): void
    {
        $this->resetErrorBag();
        abort_unless(can_do(PerubahanHargaList::ACL, 'delete'), 403);
        if (! $this->phId) {
            return;
        }

        $res = $w->delete($this->phId);
        if (! $res['ok']) {
            $this->addError('lines', $res['error']);

            return;
        }

        activity_log('delete', PerubahanHargaList::ACL, $this->nomor, 'Hapus Perubahan Harga ' . $this->nomor);
        $this->dispatch('ph-saved');
        $this->dispatch('toast', message: 'Perubahan Harga ' . $this->nomor . ' dihapus.', type: 'success');
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    // ======================= Import Kode / Nama / Harga Baru =======================

    public function bukaImport(): void
    {
        if ($this->locked) {
            return;
        }
        $this->reset('fileImport', 'hasilImport');
        $this->resetErrorBag('fileImport');
        $this->showImport = true;
    }

    public function tutupImport(): void
    {
        $this->reset('fileImport', 'hasilImport', 'showImport');
    }

    /**
     * Template CSV (pemisah `;` - yg dipakai Excel berlocale Indonesia, jadi langsung terbuka
     * per kolom). Kalau grid sudah berisi, template DIISI baris grid dgn harga akhirnya -
     * user cukup mengubah kolom Harga Baru di Excel lalu mengimpornya balik.
     */
    public function unduhTemplate()
    {
        $info = DB::table('bitem')->whereIn('IID', array_column($this->lines, 'item') ?: [0])
            ->get(['IID', 'IKODE', 'INAMA'])->keyBy('IID');

        $csv = "\xEF\xBB\xBF" . "Kode;Nama;Harga Baru\r\n";
        $sel = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        foreach ($this->lines as $l) {
            $it = $info->get($l['item']);
            if ($it) {
                $csv .= $sel($it->IKODE) . ';' . $sel($it->INAMA) . ';' . round((float) $l['akhir'], 2) . "\r\n";
            }
        }

        return response()->streamDownload(fn () => print($csv),
            'Template Perubahan Harga' . ($this->nomor ? ' ' . $this->nomor : '') . '.csv',
            ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Baca file -> cocokkan Kode ke `bitem.IKODE` -> pasang Harga Baru sbg harga BARU &
     * AKHIR (TANPA pembulatan: harga dari file dianggap final). Item yg sudah ada di grid
     * diperbarui, yg belum ada ditambahkan dgn Harga Lama = harga jual sekarang.
     *
     * Kolom dicari dari JUDUL di baris pertama ("kode", "nama", "harga baru" / "harga") -
     * urutannya bebas. Kalau baris pertama bukan judul, dipakai posisi A=Kode, B=Nama,
     * C=Harga Baru. Kolom Nama tidak dipakai mencocokkan - hanya dicatat kalau beda dari
     * master (membantu menemukan baris yg tergeser di Excel).
     */
    public function prosesImport(): void
    {
        if ($this->locked) {
            return;
        }
        $this->validate(['fileImport' => ['required', 'file', 'max:5120']],
            ['fileImport.required' => 'Pilih file dulu.', 'fileImport.max' => 'File maksimal 5 MB.']);

        try {
            $baris = TabelImport::baca($this->fileImport->getRealPath(),
                $this->fileImport->getClientOriginalExtension());
        } catch (\Throwable $e) {
            $this->addError('fileImport', $e instanceof \RuntimeException ? $e->getMessage() : 'File tidak bisa dibaca.');

            return;
        }

        // ---- posisi kolom ----
        $kKode = 0; $kNama = 1; $kHarga = 2; $mulai = 0;
        $judul = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $baris[0] ?? []);
        $cari = fn (array $nama) => collect($judul)->search(fn ($j) => in_array($j, $nama, true));
        if (($k = $cari(['kode', 'kode item', 'ikode'])) !== false) {
            $kKode = $k;
            $kNama = $cari(['nama', 'nama item', 'inama']);
            $h = $cari(['harga baru', 'harga', 'harga jual baru']);
            if ($h === false) {
                $this->addError('fileImport', 'Kolom "Harga Baru" tidak ditemukan di baris judul.');

                return;
            }
            $kHarga = $h;
            $mulai = 1;
        }

        $data = [];      // kode(lower) => [harga, nama file, no baris]
        $masalah = [];
        for ($i = $mulai; $i < count($baris); $i++) {
            $no = $i + 1;   // nomor baris spt di Excel
            $kode = trim((string) ($baris[$i][$kKode] ?? ''));
            $harga = TabelImport::angka($baris[$i][$kHarga] ?? null);
            if ($kode === '') {
                $masalah[] = "Baris {$no}: kode kosong";
                continue;
            }
            if ($harga === null || $harga <= 0) {
                $masalah[] = "Baris {$no} ({$kode}): harga baru kosong / tidak valid";
                continue;
            }
            $kunci = mb_strtolower($kode);
            if (isset($data[$kunci])) {
                $masalah[] = "Baris {$no} ({$kode}): kode dobel, dipakai baris terakhir";
            }
            $data[$kunci] = ['harga' => round($harga, 2), 'nama' => $kNama !== false ? trim((string) ($baris[$i][$kNama] ?? '')) : '', 'no' => $no, 'kode' => $kode];
        }

        // ---- cocokkan ke master (collation tidak peka huruf besar/kecil) ----
        $master = collect();
        foreach (array_chunk(array_column($data, 'kode'), 1000) as $potong) {
            $master = $master->merge(DB::table('bitem')->whereIn('IKODE', $potong)
                ->get(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISTATUS']));
        }
        $master = $master->keyBy(fn ($m) => mb_strtolower(trim($m->IKODE)));

        $posisi = [];
        foreach ($this->lines as $i => $l) {
            $posisi[$l['item']] = $i;
        }

        $diperbarui = 0; $ditambah = 0; $catatan = [];
        foreach ($data as $kunci => $d) {
            $m = $master->get($kunci);
            if (! $m) {
                $masalah[] = "Baris {$d['no']} ({$d['kode']}): kode tidak ditemukan di master item";
                continue;
            }
            if ($d['nama'] !== '' && mb_strtolower($d['nama']) !== mb_strtolower(trim((string) $m->INAMA))) {
                $catatan[] = "Baris {$d['no']} ({$m->IKODE}): nama di file \"{$d['nama']}\", di master \"{$m->INAMA}\"";
            }
            if ((int) $m->ISTATUS !== 0) {
                $catatan[] = "Baris {$d['no']} ({$m->IKODE}): item NONAKTIF di master";
            }

            if (isset($posisi[(int) $m->IID])) {
                $i = $posisi[(int) $m->IID];
                $this->lines[$i]['baru'] = $d['harga'];
                $this->lines[$i]['akhir'] = $d['harga'];
                $diperbarui++;
            } else {
                $h = (float) $m->IHARGAJUAL1;
                $this->lines[] = ['item' => (int) $m->IID, 'lama' => $h, 'baru' => $d['harga'], 'akhir' => $d['harga']];
                $posisi[(int) $m->IID] = array_key_last($this->lines);
                $ditambah++;
            }
        }

        if ($diperbarui + $ditambah) {
            $this->dirty = true;
            $this->hal = 1;
        }
        $this->hasilImport = [
            'file'       => $this->fileImport->getClientOriginalName(),
            'diperbarui' => $diperbarui,
            'ditambah'   => $ditambah,
            'masalah'    => $masalah,
            'catatan'    => $catatan,
        ];
        $this->reset('fileImport');
    }

    // ============================ Lampiran (audit) ============================

    /**
     * Yg boleh MENAMBAH lampiran: penyusun (add/edit) DAN penyetuju (approve) - surat yg sudah
     * ditandatangani sering baru ada di tangan penyetuju. Menambah tetap boleh setelah
     * diterapkan (bukti susulan); MENGHAPUS dibatasi writer.
     */
    private function bolehLampir(): bool
    {
        return can_do(PerubahanHargaList::ACL, 'edit') || can_do(PerubahanHargaList::ACL, 'add')
            || can_do(PerubahanHargaList::ACL, 'approve');
    }

    public function unggahLampiran(W $w): void
    {
        $this->resetErrorBag('fileLampiran');
        abort_unless($this->bolehLampir(), 403);
        if (! $this->phId) {
            $this->addError('fileLampiran', 'Simpan dokumen dulu, baru lampirkan surat kenaikannya.');

            return;
        }

        $batas = W::batasUnggahKb();
        $this->validate(
            ['fileLampiran' => ['required', 'file', 'max:' . $batas]],
            [
                'fileLampiran.required' => 'Pilih file surat/IOM dulu.',
                'fileLampiran.max'      => 'File terlalu besar - maksimal ' . self::ukuran($batas * 1024) . '.',
                'fileLampiran.uploaded' => 'File gagal terunggah - kemungkinan lebih besar dari batas server ('
                    . self::ukuran($batas * 1024) . '). Perkecil/kompres filenya lalu coba lagi.',
            ]);

        $nama = $this->fileLampiran->getClientOriginalName();
        $res = $w->simpanLampiran($this->phId, $this->fileLampiran->getRealPath(), $nama);
        $this->reset('fileLampiran');
        if (! $res['ok']) {
            $this->addError('fileLampiran', $res['error']);

            return;
        }

        activity_log('attach', PerubahanHargaList::ACL, $this->nomor, 'Lampirkan "' . $nama . '" ke Perubahan Harga ' . $this->nomor);
        $this->dispatch('toast', message: 'Lampiran "' . $nama . '" tersimpan.', type: 'success');
    }

    public function hapusLampiran(int $lampiranId, W $w): void
    {
        $this->resetErrorBag('fileLampiran');
        abort_unless($this->bolehLampir(), 403);

        // Hanya lampiran milik dokumen YG SEDANG DIBUKA - id dari klien tidak dipercaya.
        if (! DB::table('lv_perubahan_harga_lampiran')->where('id', $lampiranId)->where('suid', $this->phId)->exists()) {
            return;
        }
        $res = $w->hapusLampiran($lampiranId);
        if (! $res['ok']) {
            $this->addError('fileLampiran', $res['error']);

            return;
        }

        activity_log('detach', PerubahanHargaList::ACL, $this->nomor, 'Hapus lampiran "' . $res['nama'] . '" dari Perubahan Harga ' . $this->nomor);
        $this->dispatch('toast', message: 'Lampiran "' . $res['nama'] . '" dihapus.', type: 'success');
    }

    public static function ukuran(int $byte): string
    {
        return $byte >= 1048576 ? rtrim(rtrim(number_format($byte / 1048576, 1, ',', '.'), '0'), ',') . ' MB'
            : max(1, (int) round($byte / 1024)) . ' KB';
    }

    public function closeTab(): void
    {
        $this->dispatch('close-tab', key: $this->tabKey);
    }

    public function render()
    {
        $items = [];
        $q = trim($this->itemQ);
        if (! $this->locked && $q !== '') {
            $items = $this->itemQuery()->where('i.ISTATUS', 0)
                ->where(fn ($w) => $w->where('i.IKODE', 'like', "%{$q}%")->orWhere('i.INAMA', 'like', "%{$q}%"))
                ->orderBy('i.IKODE')->limit(15)->get()->all();
        }

        $w = app(W::class);

        // Info item utk SEMUA baris (satu query, kunci primer) - harga sekarang dibutuhkan
        // semua baris utk peringatan "harga sudah berubah", nama utk pencarian di grid.
        $info = $this->lines === [] ? collect() : DB::table('bitem as i')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->whereIn('i.IID', array_column($this->lines, 'item'))
            ->get(['i.IID', 'i.IKODE', 'i.INAMA', 'i.IHARGAJUAL1', 's.SKODE as satuan'])->keyBy('IID');

        $acuan = $this->status === W::STATUS_DITERAPKAN ? 'akhir' : 'lama';
        $jmlBerubah = 0;
        $cari = mb_strtolower(trim($this->cariBaris));
        $cocok = [];
        foreach ($this->lines as $i => $l) {
            $it = $info->get($l['item']);
            $sekarang = (float) ($it->IHARGAJUAL1 ?? 0);
            if ($acuan === 'lama' && abs($sekarang - $l['lama']) >= 0.005) {
                $jmlBerubah++;
            }
            if ($cari === '' || str_contains(mb_strtolower(($it->IKODE ?? '') . ' ' . ($it->INAMA ?? '')), $cari)) {
                $cocok[$i] = $l + ['kode' => (string) ($it->IKODE ?? ''),
                    'nama' => (string) ($it->INAMA ?? ('Item #' . $l['item'])), 'sekarang' => $sekarang];
            }
        }
        $jmlHal = max(1, (int) ceil(count($cocok) / self::PER_HALAMAN));
        $this->hal = min(max(1, $this->hal), $jmlHal);
        // `array_slice(..., true)` mempertahankan indeks ASLI - itu yg dipakai `lines.{i}.akhir`.
        $tampil = array_slice($cocok, ($this->hal - 1) * self::PER_HALAMAN, self::PER_HALAMAN, true);

        return view('livewire.master.perubahan-harga-form', [
            'items'       => $items,
            'jenisList'   => DaftarStokBarang::JENIS_ITEM,
            'tampil'      => $tampil,
            'jmlCocok'    => count($cocok),
            'jmlHal'      => $jmlHal,
            'totalBaru'   => array_sum(array_column($this->lines, 'baru')),
            'totalAkhir'  => array_sum(array_column($this->lines, 'akhir')),
            'jmlBerubah'  => $jmlBerubah,
            'riwayat'     => $this->phId ? $w->riwayat($this->phId) : [],
            'lampiran'    => $this->phId ? $w->lampiran($this->phId) : [],
            'bolehLampir' => $this->bolehLampir(),
            'bolehHapusLampiran' => $this->phId && $w->bolehHapusLampiran($this->phId),
            'batasLampiran' => self::ukuran(W::batasUnggahKb() * 1024),
            'pernahDiterapkan' => $this->phId
                && DB::table('lv_perubahan_harga_log')->where('suid', $this->phId)->exists(),
            'hariIni'     => now()->toDateString(),
        ]);
    }
}
