<?php

namespace App\Livewire\Master;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Master Gudang / Cabang (`bgudang`). Layout form MENGIKUTI VB6 `bFrmGudang.frm` persis
 * (urutan & pengelompokan field sesuai screenshot user 2026-09-25).
 *
 * **Pemetaan field VB6 -> kolom** (dibaca dari rutin `Edit` VB6 baris 489-515):
 * Kode=`GKODE`, Default=`GDEFAULT`, Nama=`GNAMA`, Divisi=`GDIVISI` (dari `bdivisi`),
 * Kontak=`GKONTAK`, **Inisial=`GALAMAT1`**, **Alamat=`GALAMAT2`** (BUKAN GALAMAT1!),
 * Kota=`GKOTA`, Provinsi=`GPROPINSI`, Negara=`GNEGARA`, Telepon=`GTELP`, Fax=`GFAX`,
 * Kontak di SJ=`GKONTAKSJ` (-> `bkontak`, VB6 menampilkan KKODE + label KNAMA).
 *
 * **"Inisial" (`GALAMAT1`) SENGAJA READ-ONLY** - sama spt VB6 (kotaknya abu2/disabled) DAN
 * krn kolom ini dipakai SEMUA writer sbg PREFIX NOMOR TRANSAKSI (`nextNumber()`:
 * `PG-PKB26090008`, `RB-PB26070019`, dst - lihat `$branch->GALAMAT1 ?: $branch->GKODE`).
 * Mengubahnya = mengubah penomoran dokumen cabang itu. Kalau suatu saat perlu diubah,
 * harus keputusan sadar + cek dampak ke nomor yg sudah ada.
 *
 * **"Keterangan" ADA di layout VB6 tapi TIDAK TERIKAT KOLOM MANAPUN** - di VB6 barisnya
 * di-comment (`' txtKeterangan = Rs2("GKODE")`) dan tidak ada di array simpan; `bgudang`
 * memang tidak punya kolom keterangan. Ditampilkan (biar layout sama persis) tapi DISABLED
 * + diberi catatan, supaya tidak menipu user seolah isinya tersimpan.
 *
 * **BEDA SENGAJA dari VB6 (2 hal, keduanya perbaikan)**:
 * 1. VB6 `Simpan` aktif cuma menulis 8 kolom (`GKODE, GNAMA, GALAMAT2, GTELP, GCREATEU,
 *    GMODIFU, GMODIFD, GKONTAKSJ`) - daftar lengkapnya ADA tapi DI-COMMENT (baris 430-431),
 *    jadi Divisi/Kontak/Kota/Provinsi/Negara/Fax/Default yg TAMPIL di form legacy TIDAK
 *    pernah tersimpan. Itu jelas kondisi setengah-jadi, bukan maksud; di sini SEMUA field
 *    yg tampil memang disimpan (sesuai daftar lengkap yg di-comment itu).
 * 2. VB6 `chkDefault` menjalankan `update bgudang set GDEFAULT = 0` (mengosongkan SEMUA)
 *    tapi `GDEFAULT` tidak ikut ditulis -> hasilnya TIDAK ADA gudang default sama sekali.
 *    Di sini: kosongkan yg lain LALU set gudang ini = 1, dalam satu transaksi.
 *
 * **TAMBAHAN di luar layout VB6**: checkbox **"Pakai No Batch / Serial"** (`GPAKAISERIAL`) -
 * inilah alasan menu ini diminta user (setelan batch per cabang, lihat `SerialBatch`).
 * Form VB6 tidak punya kontrol ini (di sistem lama diubah langsung lewat DB).
 *
 * **TIDAK ADA tombol Hapus** - `bgudang.GID` dirujuk hampir semua tabel transaksi
 * (`fstokd.SDGUDANG`, `fstoku.SUCABANG`, `auser.UCABANG`, dll) tanpa FK, jadi menghapus =
 * membuat data transaksi yatim. VB6 pun tidak menghapus dari form ini.
 */
#[Layout('layouts.app')]
#[Title('Data Gudang')]
class GudangManager extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $search = '';
    public bool $showModal = false;
    public ?int $editingId = null;

    // --- field form, urutan sesuai layout VB6 ---
    public string $GKODE = '';
    public bool $GDEFAULT = false;
    public ?string $GNAMA = null;
    public ?int $GDIVISI = null;
    public ?string $GKONTAK = null;
    /** Inisial - read-only, lihat docblock kelas. */
    public ?string $GALAMAT1 = null;
    public ?string $GALAMAT2 = null;
    public ?string $GKOTA = null;
    public ?string $GPROPINSI = null;
    public ?string $GNEGARA = null;
    public ?string $GTELP = null;
    public ?string $GFAX = null;
    public ?int $GKONTAKSJ = null;
    public ?string $kontakSjLabel = null;
    /** Tambahan di luar VB6 - alasan menu ini dibuat. */
    public bool $GPAKAISERIAL = false;

    protected function rules(): array
    {
        return [
            'GKODE'        => ['required', 'string', 'max:25', Rule::unique('bgudang', 'GKODE')->ignore($this->editingId, 'GID')],
            'GNAMA'        => ['required', 'string', 'max:150'],
            'GDIVISI'      => ['nullable', 'integer'],
            'GKONTAK'      => ['nullable', 'string', 'max:25'],
            'GALAMAT2'     => ['nullable', 'string', 'max:255'],
            'GKOTA'        => ['nullable', 'string', 'max:25'],
            'GPROPINSI'    => ['nullable', 'string', 'max:25'],
            'GNEGARA'      => ['nullable', 'string', 'max:25'],
            'GTELP'        => ['nullable', 'string', 'max:25'],
            'GFAX'         => ['nullable', 'string', 'max:25'],
            'GKONTAKSJ'    => ['nullable', 'integer'],
            'GDEFAULT'     => ['boolean'],
            'GPAKAISERIAL' => ['boolean'],
        ];
    }

    protected array $messages = [
        'GKODE.required'  => 'Masukkan kode.',
        'GKODE.unique'    => 'Kode ini sudah dipakai gudang lain.',
        'GNAMA.required'  => 'Masukkan nama.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(can_do('master/gudang', 'add'), 403);

        $this->reset([
            'editingId', 'GKODE', 'GNAMA', 'GDIVISI', 'GKONTAK', 'GALAMAT1', 'GALAMAT2',
            'GKOTA', 'GPROPINSI', 'GNEGARA', 'GTELP', 'GFAX', 'GKONTAKSJ', 'kontakSjLabel',
            'GDEFAULT', 'GPAKAISERIAL',
        ]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        abort_unless(can_do('master/gudang', 'edit'), 403);

        $g = Branch::findOrFail($id);

        $this->editingId    = (int) $g->GID;
        $this->GKODE        = (string) ($g->GKODE ?? '');
        $this->GNAMA        = $g->GNAMA;
        $this->GDIVISI      = $g->GDIVISI ? (int) $g->GDIVISI : null;
        $this->GKONTAK      = $g->GKONTAK;
        $this->GALAMAT1     = $g->GALAMAT1;
        $this->GALAMAT2     = $g->GALAMAT2;
        $this->GKOTA        = $g->GKOTA;
        $this->GPROPINSI    = $g->GPROPINSI;
        $this->GNEGARA      = $g->GNEGARA;
        $this->GTELP        = $g->GTELP;
        $this->GFAX         = $g->GFAX;
        $this->GKONTAKSJ    = $g->GKONTAKSJ ? (int) $g->GKONTAKSJ : null;
        $this->GDEFAULT     = (int) $g->GDEFAULT === 1;
        $this->GPAKAISERIAL = (int) $g->GPAKAISERIAL === 1;
        $this->kontakSjLabel = $this->GKONTAKSJ
            ? (string) DB::table('bkontak')->where('KID', $this->GKONTAKSJ)->value('KNAMA') : null;

        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        abort_unless(can_do('master/gudang', $this->editingId ? 'edit' : 'add'), 403);
        $this->validate();

        $payload = [
            'GKODE'        => trim($this->GKODE),
            'GNAMA'        => trim((string) $this->GNAMA),
            'GDIVISI'      => $this->GDIVISI ?: null,
            'GKONTAK'      => $this->GKONTAK ?: null,
            'GALAMAT2'     => $this->GALAMAT2 ?: null,
            'GKOTA'        => $this->GKOTA ?: null,
            'GPROPINSI'    => $this->GPROPINSI ?: null,
            'GNEGARA'      => $this->GNEGARA ?: null,
            'GTELP'        => $this->GTELP ?: null,
            'GFAX'         => $this->GFAX ?: null,
            'GKONTAKSJ'    => $this->GKONTAKSJ ?: null,
            'GDEFAULT'     => $this->GDEFAULT ? 1 : 0,
            'GPAKAISERIAL' => $this->GPAKAISERIAL ? 1 : 0,
            'GMODIFU'      => auth()->id(),
            'GMODIFD'      => now(),
        ];

        // `GALAMAT1` (Inisial) TIDAK pernah ikut ditulis - lihat docblock kelas.
        $id = DB::transaction(function () use ($payload) {
            // Default hanya boleh satu (VB6 mengosongkan semua tapi lupa set yg baru).
            if ($payload['GDEFAULT'] === 1) {
                DB::table('bgudang')->where('GDEFAULT', 1)
                    ->when($this->editingId, fn ($b) => $b->where('GID', '<>', $this->editingId))
                    ->update(['GDEFAULT' => 0]);
            }

            if ($this->editingId) {
                DB::table('bgudang')->where('GID', $this->editingId)->update($payload);

                return $this->editingId;
            }

            $payload['GCREATEU'] = auth()->id();

            return (int) DB::table('bgudang')->insertGetId($payload, 'GID');
        });

        activity_log($this->editingId ? 'update' : 'create', 'master/gudang', $id,
            ($this->editingId ? 'Ubah' : 'Tambah') . ' gudang ' . $payload['GKODE']);

        session()->flash('status', 'Gudang ' . $payload['GKODE'] . ' disimpan.');
        $this->showModal = false;
    }

    public function render()
    {
        abort_unless(can_do('master/gudang', 'view'), 403);

        $q = trim($this->search);

        $rows = DB::table('bgudang as g')
            ->leftJoin('bdivisi as d', 'd.DID', '=', 'g.GDIVISI')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'g.GKONTAKSJ')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('g.GKODE', 'like', "%{$q}%")
                ->orWhere('g.GNAMA', 'like', "%{$q}%")
                ->orWhere('g.GALAMAT1', 'like', "%{$q}%")))
            ->orderBy('g.GKODE')
            ->paginate(20, [
                'g.GID', 'g.GKODE', 'g.GNAMA', 'g.GALAMAT1', 'g.GKOTA', 'g.GTELP',
                'g.GDEFAULT', 'g.GAKTIF', 'g.GPAKAISERIAL',
                'd.DNAMA as divisi', 'k.KNAMA as kontak_sj',
            ]);

        return view('livewire.master.gudang-manager', [
            'rows'    => $rows,
            'divisis' => DB::table('bdivisi')->orderBy('DKODE')->get(['DID', 'DKODE', 'DNAMA']),
        ]);
    }
}
