<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Models\User;
use App\Services\LegacyAuth;
use App\Services\PosSaleWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Kasir / POS - Penjualan Tunai. Menulis ke fstoku/fstokd (trigger DB posting stok+jurnal).
 * v1: item + keranjang + pelanggan (wajib) + diskon member + operator/dokter/no ref-ic per baris
 * (item tindakan) + pembayaran tunai/debit/kredit/transfer/merchant/voucher/DP + label Promo
 * (cari & tandai SUPROMO, BUKAN mesin hitung diskon otomatis - lihat komentar $showPromoModal)
 * + poin + riwayat hari ini (cetak ulang / "edit" = muat ulang ke keranjang, transaksi lama
 * batal saat disimpan).
 * Belum: paket, harga per level pelanggan, rekam medis, kalkulasi diskon promo otomatis.
 */
class PosTerminal extends Component
{
    /** bitem.IJENISITEM yang wajib isi Operator + Dokter + No Ref/IC per baris. */
    private const JENIS_PERLU_OPERATOR = [1, 4, 6, 10];

    /** bitem.IID yang tergolong "item DP" (IJENISITEM=14) - satu2nya baris yg boleh dijadikan sumber pembayaran DP. */
    private const ITEM_DP = [2387, 3235, 3238, 3239, 3572];

    /** Pilihan "Jenis Kartu" - daftar tetap sesuai CI3 (inline HTML, bukan dari tabel), TIDAK sama antara debit & kredit. */
    private const JENIS_KARTU_DEBIT = ['DEBIT', 'MAESTRO', 'SWITCHING'];
    private const JENIS_KARTU_KREDIT = ['DEBIT', 'MAESTRO', 'SWITCHING', 'MASTER', 'VISA'];

    public ?string $tabKey = null;

    public ?int $branchId = null;

    /** @var array<int,array{item:int,kode:string,nama:string,qty:float,harga:float,dis1:float,dis2:float,satuan:?int,tipe:int,stok:float,jenisitem:int,sdkaryawan:?int,sdkaryawan_label:?string,sddokter:?int,sddokter_label:?string,sdnoref:?string,sdlantai2:?string,sdidpromo:?int,promo_label:?string,is_bonus:bool,sddaripaket:int,sdidpotongstok:?int,sdcatatankoli:?string,sdkedatangan:?int,sdsodurutan:?int,paket_label:?string,sdpaket_pasien:?int}> */
    public array $cart = [];

    // alur Operator -> Dokter -> No Ref/IC (dipicu saat item ITEM.IJENISITEM tertentu ditambahkan)
    public ?int $odLine = null;
    public bool $showOperatorModal = false;
    public bool $showDokterModal = false;
    public bool $showRefModal = false;
    public string $odQ = '';
    public int $odHighlight = 0;
    public string $refNo = '';
    public string $refIc = '';
    /** Antrean baris cart LAIN yg jg butuh Operator/Dokter/Ref, direview berantai satu per satu
     *  setelah baris $odLine yg sedang aktif selesai/dilewati - dipakai saat BANYAK baris
     *  ditambahkan sekaligus (mis. applyKombinasi()), bukan cuma 1 item spt addItem() biasa. */
    public array $odQueue = [];

    /**
     * Harga Jual, Disk 1, Disk 2 di baris keranjang TERKUNCI (readonly) secara default - HANYA
     * bisa diubah setelah baris itu "dibuka" via re-auth username+password (siapapun yg punya
     * hak Approve pada menu `sales/pos`, TIDAK harus sesi kasir yg sedang login - mis. supervisor
     * ketik kredensialnya sendiri di layar kasir tanpa perlu logout/login ulang). Per permintaan
     * user 2026-09-15. Lingkup per-BARIS (bukan per-cart/sesi), TIDAK expire otomatis (tetap
     * terbuka sampai baris dihapus/keranjang dikosongkan/checkout selesai) - direset di semua
     * titik yg sudah mereset $cart (clearCart/editTransaction/checkout sukses), & di-reindex di
     * removeLine() spy tidak salah baris kalau baris lain di atasnya dihapus.
     */
    public array $unlockedLines = [];
    public bool $showUnlockModal = false;
    public ?int $unlockLine = null;
    public string $unlockUser = '';
    public string $unlockPassword = '';

    public ?int $custId = null;
    public ?string $custLabel = null;
    public bool $memberActive = false;
    public ?string $memberInfo = null;

    /** Kasir yg login (auser.UKID -> bkontak.KID) - otomatis, bukan dipilih manual. */
    public ?int $kasirId = null;
    public ?string $kasirLabel = null;
    public ?string $catatan = null;

    /**
     * Catatan Rekam Medis -> `fstoku.SUREKAMMEDIS` (varchar 255). WAJIB diisi, diperiksa saat
     * simpan (permintaan user 2026-09-29).
     *
     * Kolomnya SUDAH ADA & memang dipakai: terisi di **26.314 dari 26.322** transaksi POS
     * (99,97%) di data produksi - jadi mewajibkannya mengikuti kebiasaan yg sudah berjalan,
     * bukan aturan baru. BEDA dari `$catatan` (`SUCATATAN`, catatan bebas di "Data Lainnya").
     */
    public ?string $rekamMedis = null;

    /**
     * Modal "Data Lainnya" - field header `fstoku` tambahan di luar form utama. Pemetaan
     * kolom dikonfirmasi user via tabel excel (2026-09-15), lalu diverifikasi ke `SHOW COLUMNS
     * FROM fstoku` (semua 14 kolom ADA) - TAPI SEMUA kolom ini 0 baris terisi di data produksi
     * (fitur baru, blm pernah dipakai sama sekali), jadi target FK-nya TIDAK BISA diverifikasi
     * ke data nyata spt field lain di app ini biasanya. **Dikonfirmasi user (2026-09-15)**:
     * Training/Farmasi/Farmasi Asisten/Sales Marketing = `bkontak` KTIPE=4 (karyawan) - pakai
     * ULANG `lookup.karyawan` yg sudah ada. Klinik Lain/Teman BELUM dikonfirmasi - sementara
     * `bkontak` TANPA batasan tipe (lookup baru `lookup.kontak`, FULLTEXT via
     * `Contact::applySearch()`, krn `bkontak` 315rb+ baris - JANGAN `LIKE '%q%'` polos).
     * SUCATATAN sengaja TIDAK dibuat properti baru - berbagi `$catatan` yg sudah ada (field
     * yg sama, cuma ditampilkan jg di modal ini).
     * `SUIDMEDLIB`/`SULMCID` ("ID Medlib"/"ID PRO") readonly - rujukan sistem lain
     * (`official_nmw.ops_medical.medId` dst per catatan user), TIDAK diisi dari form ini.
     */
    public bool $showOtherDataModal = false;
    public ?int $suTraining = null;
    public ?string $suTrainingLabel = null;
    public ?int $suFarmasi = null;
    public ?string $suFarmasiLabel = null;
    public ?int $suFarmasiAsisten = null;
    public ?string $suFarmasiAsistenLabel = null;
    public ?int $suSalesMarketing = null;
    public ?string $suSalesMarketingLabel = null;
    public ?int $suKlinikLain = null;
    public ?string $suKlinikLainLabel = null;
    public ?int $suTeman = null;
    public ?string $suTemanLabel = null;
    public ?string $suKodeTele = null;
    public ?float $suReviewNilai = null;
    public ?string $suReviewCatatan = null;
    public bool $suKonsulSaja = false;
    public bool $suKonsulKemarin = false;
    /** Readonly - rujukan sistem lain, cuma ditampilkan (kalau ada nilainya saat edit transaksi lama). */
    public ?int $suIdMedlib = null;
    public ?int $suLmcId = null;

    public array $pay = [
        'tunai'    => 0,
        // 'jenis' = Jenis Kartu (daftar tetap, lihat JENIS_KARTU_DEBIT). 'bank_lain' = teks bebas
        // kalau bank tidak ada di daftar bbank - disimpan ke fstoku.SUATTENTION (kolom legacy
        // dipakai ulang persis spt CI3, bukan kolom baru, supaya konsisten dgn data CI3/CI4).
        'debit'    => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null, 'jenis' => null, 'bank_lain' => ''],
        // sama spt debit, tapi 'bank_lain' -> fstoku.SUNOFAKTURPAJAK (kolom legacy CI3 jg).
        'kredit'   => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null, 'jenis' => null, 'bank_lain' => ''],
        'transfer' => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null],
        // 'jenis' = bmerchant.MCKODE (fstoku.SUMERCHANTJENIS disimpan sbg kode teks, bukan MCID).
        'merchant' => ['jumlah' => 0, 'no' => '', 'jenis' => null],
        // Voucher: dicari berdasarkan pelanggan terpilih (bvoucher.VKONTAK), bukan diketik.
        // 'vid' = bvoucher.VID (disimpan ke fstoku.SUSTATUSKIRIM - nama kolom legacy menyesatkan,
        // isinya ID voucher, bukan status kirim). 'program' = jenis voucher (blain via VJENIS),
        // 'nama' = nama pemilik voucher (bkontak via VKONTAK).
        'voucher'  => ['jumlah' => 0, 'no' => '', 'vid' => null, 'program' => null, 'nama' => null],
        // DP: tukar sisa saldo baris "item DP" (bitem.IJENISITEM=14) yg sudah dibeli pelanggan
        // hari ini, dicari berdasarkan pelanggan terpilih (spt voucher, bukan diketik).
        // 'sdid' = fstokd.SDID baris DP asal (disimpan ke fstoku.SUDPID). 'no' = no. transaksi
        // asal baris DP itu (info tampilan saja, tidak disimpan). 'jenis' = fstoku.SUJENISDP -
        // legacy pakai ulang daftar bmerchant sbg pilihannya (bukan master khusus, quirk asli).
        'dp' => ['jumlah' => 0, 'sdid' => null, 'no' => null, 'nama_item' => null, 'jenis' => null],
    ];

    // pencarian
    public string $itemQ = '';
    public string $custQ = '';

    // modal pencarian (F2 item, F1 pelanggan) + navigasi keyboard
    public bool $showItemModal = false;
    public bool $showCustModal = false;
    public int $itemHighlight = 0;
    public int $custHighlight = 0;

    /** ringkasan transaksi terakhir (tampilkan struk + tombol cetak) */
    public array $lastReceipt = [];

    /** Riwayat transaksi POS hari ini milik kasir yg login (utk cetak ulang / edit). */
    public bool $showHistoryModal = false;

    /**
     * ID & no. transaksi asal saat keranjang dimuat dari "Edit". Transaksi lama TETAP AKTIF
     * (belum dibatalkan) selama ini belum null - baru dibatalkan bersamaan dgn checkout()
     * (satu transaksi DB atomik lewat PosSaleWriter::replace()). Dikosongkan lagi setelah
     * checkout sukses / keranjang dikosongkan / transaksi baru.
     */
    public ?int $editingFromId = null;
    public ?string $editingFromNomor = null;

    /**
     * Modal Pembayaran (F8) - mengikuti alur VB6: keranjang disusun dulu, baru dialog bayar
     * dibuka, lalu OK menyimpan. Sebelumnya panel bayar selalu tampil memanjang di kolom kanan
     * (permintaan user 2026-09-28: "untuk pembayaran dibuat form modal").
     *
     * TIDAK menambah jenis bayar - isinya persis 7 jenis yg sudah ada (tunai, debit, kredit,
     * transfer, merchant, voucher, DP), cuma ditata dua kolom spt dialog lama.
     */
    public bool $showPayModal = false;

    /** Modal cari voucher (F5) - hanya vocher milik pelanggan yg sudah dipilih, sisa saldo > 0. */
    public bool $showVoucherModal = false;
    public int $voucherHighlight = 0;

    /** Modal cari DP (F6) - baris item DP milik pelanggan terpilih, diinput HARI INI, sisa saldo > 0. */
    public bool $showDpModal = false;
    public int $dpHighlight = 0;

    /**
     * Modal "Harga Khusus" (F10) - cari master promo (emasterpromou) yg aktif & berlaku hari
     * ini. Label di layar "Harga Khusus", tapi nama variabel/tabel TETAP promo - yg berubah
     * cuma captionnya (permintaan user 2026-09-30), skemanya tidak.
     * SATU transaksi boleh pakai LEBIH DARI SATU promo (F10 bisa dipencet berkali-kali) - tiap
     * kode yg dipilih dikumpulkan ke $promos (list teks, dedup), digabung jadi LABEL referensi
     * di fstoku.SUPROMO (kolom varchar tunggal legacy, dipisah ", " - bukan mesin hitung diskon
     * otomatis utk promo tipe "Biasa" dst). Utk Kombinasi 1 yg punya baris `emasterpromod`,
     * lihat $activeKombinasiPromoId - diskon aktual baris situ SUDAH otomatis dari situ, promo
     * tipe lain diskonnya tetap manual via dis1/dis2 yg sudah ada di baris cart.
     */
    public bool $showPromoModal = false;
    public int $promoHighlight = 0;
    public string $promoQ = '';
    /** @var list<string> kode promo yg sudah dipilih (bisa lebih dari 1), utk header/SUPROMO. */
    public array $promos = [];

    /**
     * Alur "Terapkan Kombinasi" - khusus promo Promo::JENIS_PROMO[1] "Kombinasi 1" yg punya
     * baris `emasterpromod`. Dipicu otomatis dari pickPromo(): kalau promo yg dipilih tipe ini
     * & punya baris, tampilkan item 1-4 (yg ada datanya) beserta diskon1/diskon2 masing2 - kasir
     * bisa tukar tiap slot ke alternatif dari "Item N Pilihan" kalau ada, lalu tambahkan sekaligus
     * ke keranjang. MPDID baris terpilih disimpan ke cart[].sdidpromo -> fstokd.SDIDPROMO saat
     * checkout. Diskon1/diskon2 diterapkan sbg dis1/dis2 baris (masih bisa diedit manual di
     * keranjang spt baris lain). BUKAN mesin promo penuh (kondisi cabang/tanggal/dst di
     * emasterpromou TIDAK dievaluasi disini - kasir yg menentukan promo mana yg berlaku, sama
     * spt alur "cari promo" biasa).
     */
    public bool $showKombinasiModal = false;
    public int $kombinasiHighlight = 0;
    public bool $showKombinasiApplyModal = false;
    /** @var array{mpdid?:int,slots?:array<int,array{chosen:?string,options:list<array{kode:string,nama:string,harga:float}>,qty:float,d1:float,d2:float}>} */
    public array $kombApply = [];

    /** Promo Kombinasi 1 yg SEDANG diproses lewat modal Pilih Baris/Terapkan Kombinasi (state
     *  sementara alur ini saja - beda dari $promos yg daftar SEMUA promo yg sudah diterapkan). */
    public ?int $activeKombinasiPromoId = null;
    public ?string $activeKombinasiKode = null;

    /**
     * Alur "Terapkan Promo" - khusus promo Promo::JENIS_PROMO[0] "Biasa" yg punya baris
     * `emasterpromod`. Dipicu otomatis dari pickPromo() (sama persis spt Kombinasi 1, cuma
     * jenis promo beda) - tampilkan "Jenis Item" (bisa ditukar ke alternatif dari "Pilihan")
     * dgn diskon1/diskon2 baris itu, "Item Bonus" 1-5 (ditambahkan GRATIS - harga 0, kasir bisa
     * ubah manual di keranjang kalau perlu), dan info SYARAT (minimal belanja/qty/tanggal/
     * jam/max pasien/seluruh invoice) sbg TEKS INFORMASI SAJA - TIDAK dicek/divalidasi otomatis
     * (sama filosofi spt Kombinasi 1: kasir yg menentukan apakah syarat promo ini terpenuhi,
     * bukan mesin promo penuh). "Seluruh Invoice" jg CUMA info - diskon TETAP diterapkan ke
     * baris "Jenis Item" saja (tidak ada mekanisme diskon level-invoice di fstoku/fstokd).
     */
    public bool $showBiasaModal = false;
    public int $biasaHighlight = 0;
    public bool $showBiasaApplyModal = false;
    /** @var array{mpdid?:int,item?:?array{chosen:?string,options:list<array{kode:string,nama:string,harga:float}>,qty:float,d1:float,d2:float},bonus?:array<int,array{iid:int,kode:string,nama:string,harga:float,include:bool,qty:float}>,syarat?:array} */
    public array $biasaApply = [];

    /** Promo Biasa yg SEDANG diproses lewat modal Pilih Baris/Terapkan Promo (state sementara,
     *  sama pola spt $activeKombinasiPromoId/$activeKombinasiKode). */
    public ?int $activeBiasaPromoId = null;
    public ?string $activeBiasaKode = null;

    /**
     * Modal "Daftar Paket" (F11, dulu "Cari Paket") - epaketu/epaketd/epaketc. Model bisnis digali lewat diskusi
     * panjang dgn user + diverifikasi silang ke data transaksi produksi nyata (lihat plan
     * "Tarik Paket (Package Redemption) di POS" utk rincian lengkap) - BUKAN dugaan dari kode
     * CI3 semata (CI3 sendiri TIDAK PUNYA layar/fitur ini, cuma baca read-only).
     *
     * Paket `PUJUMLAH>1` butuh Nomer Paket (nomor tiket kertas kunjungan fisik, diinput MANUAL
     * kasir, TIDAK unik sendirian - lihat pengecekan tabrakan di lookupPaketInstance()) + tracking
     * kuota per baris `epaketd` (independen per item, maksimal PUJUMLAH+1 kejadian). **BELI vs
     * TARIK adalah 2 ALUR BERBEDA** (dikoreksi user 2026-09-16, revisi dari anggapan awal "satu
     * mekanisme tanpa checklist"): kombinasi (Nomer+PUID+pasien) BELUM ADA riwayat sama sekali ->
     * **Beli Paket**, SEMUA baris `epaketd` otomatis masuk keranjang TANPA checklist, qty `PDQTY`
     * seragam, kedatangan=0 (lihat addPaketBeliRows()). SUDAH ADA riwayat -> **Tarik Paket**,
     * checklist item yg msh ada sisa kuota, qty `PDQTYTINDAKAN` seragam utk SEMUA baris yg
     * ditampilkan (bukan per-item lagi - PDQTY HANYA dipakai di event Beli, PDQTYTINDAKAN dipakai
     * di SEMUA event Tarik apapun, terlepas riwayat item itu sendiri). Paket `PUJUMLAH<=1`
     * (sekali pakai) TIDAK butuh Nomer Paket/tracking sama sekali - langsung tambah semua baris
     * (lihat addPaketSimple()), sama pola spt Beli Paket tapi tanpa pelacakan lanjutan.
     *
     * Mekanisme penyimpanan pakai kolom `fstokd` legacy yg SUDAH ADA & TERPAKAI nyata di data
     * produksi (bukan skema baru): SDDARIPAKET (1 kalau paket PUJUMLAH>1), SDIDPOTONGSTOK (=
     * epaketu.PUID), SDCATATANKOLI (= Nomer Paket), SDKEDATANGAN (indeks kedatangan 0,1,2,...),
     * SDSODURUTAN (= epaketd.PDID, dikonfirmasi cocok 100% ke data nyata). Pola arsitektur SAMA
     * spt DP (live-aggregate dari histori fstokd, BUKAN kolom saldo tersimpan).
     */
    public bool $showPaketModal = false;
    public int $paketHighlight = 0;
    public string $paketQ = '';

    /** Setelah paket dipilih & PUJUMLAH>1: tahap input Nomer Paket, lalu tahap centang baris. */
    public bool $showPaketNomorModal = false;
    public bool $showPaketTarikModal = false;
    public ?int $paketPuid = null;
    public ?string $paketKode = null;
    public ?string $paketNama = null;
    public string $paketNomor = '';
    /** @var list<array{pdid:int,item:int,kode:string,nama:string,sisa:int,qty:float,kedatangan:int}> hasil lookupPaketInstance() - baris yg MASIH ADA sisa kuota saja. */
    public array $paketRows = [];
    /** @var array<int,bool> PDID => tercentang (kasir pilih baris mana yg diambil hari ini). */
    public array $paketChecked = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // Cabang = cabang user (auser.UCABANG), TETAP - tidak ada UI utk mengubahnya.
        $ucabang = (int) ($user->UCABANG ?? 0);
        $this->branchId = Branch::active()->where('GID', $ucabang)->exists() ? $ucabang : null;

        // Kasir = pegawai yg terhubung ke user login (auser.UKID -> bkontak.KID), otomatis.
        $this->kasirId = $user->UKID ? (int) $user->UKID : null;
        $this->kasirLabel = $this->kasirId
            ? ((string) DB::table('bkontak')->where('KID', $this->kasirId)->value('KNAMA') ?: $user->displayName())
            : $user->displayName();
    }

    private function branch(): ?object
    {
        if (! $this->branchId) {
            return null;
        }

        return Branch::query()->where('GID', $this->branchId)->first(['GID', 'GKODE', 'GNAMA', 'GALAMAT1']);
    }

    private function stokColumn(int $gid): string
    {
        $col = (string) (DB::selectOne('SELECT F_KOLOMGUDANG(?) AS c', [$gid])->c ?? '');

        return preg_match('/^ISTOK[A-Z0-9]+$/', $col) ? $col : 'ISTOKPG';
    }

    /* ---------------- Modal item (F2) ---------------- */

    public function openItemModal(): void
    {
        $this->showItemModal = true;
        $this->itemHighlight = 0;
    }

    public function closeItemModal(): void
    {
        $this->showItemModal = false;
        $this->itemQ = '';
    }

    public function updatedItemQ(): void
    {
        $this->itemHighlight = 0;
    }

    /** @return \Illuminate\Support\Collection */
    private function itemSearchResults()
    {
        $q = trim($this->itemQ);
        if ($q === '') {
            return collect();
        }

        $branch = $this->branch();
        $stokCol = $branch ? $this->stokColumn((int) $branch->GID) : 'ISTOKPG';

        return DB::table('bitem')
            ->where('ISTATUS', 0)
            ->when($branch, fn ($b) => $this->applyIcabangFilter($b, (int) $branch->GID))
            ->where(fn ($b) => $b->where('IKODE', 'like', "%{$q}%")
                ->orWhere('INAMA', 'like', "%{$q}%")
                ->orWhere('IBARCODE', 'like', "%{$q}%"))
            ->orderBy('INAMA')
            ->limit(20)
            ->get(['IID as id', 'IKODE as kode', 'INAMA as nama', 'IHARGAJUAL1 as harga', 'ITIPEITEM as tipe', DB::raw("{$stokCol} AS stok")]);
    }

    /**
     * Filter item yg boleh dijual di cabang $gid, berdasarkan `bitem.ICABANG` (daftar GID
     * dipisah pipe, mis. "|1|2|3|"). Dipakai REGEXP (bukan LIKE) - ditemukan ~1.638 baris data
     * nyata (29% katalog) yg ICABANG-nya rusak: kata "ICABANG" + CRLF nyasar menggantikan pipe
     * pembatas PERTAMA (mis. "|ICABANG\r\n1|2|3|...|43|") - kalau pakai LIKE '%|1|%' polos,
     * item2 ini salah dianggap TIDAK boleh dijual di cabang GID=1 (Petogogan, cabang aktif
     * sungguhan) padahal daftarnya jelas mencantumkan 1. Delimiter kiri diterima "|" ATAU CR
     * ATAU LF supaya tahan korupsi ini; delimiter kanan tetap wajib "|" (verified: tidak ada
     * false-positive spt gid=1 ke-match di baris yg daftarnya memang mulai dari 2).
     */
    private function applyIcabangFilter($query, int $gid)
    {
        return $query->whereRaw('ICABANG REGEXP ?', ['[|\r\n]' . $gid . '\|']);
    }

    public function moveItemHighlight(int $delta): void
    {
        $count = $this->itemSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->itemHighlight = max(0, min($count - 1, $this->itemHighlight + $delta));
    }

    /** Enter di kotak cari item: pilih baris yang sedang di-highlight (alur scan cepat, atau lanjut Operator/Dokter). */
    public function pickItemHighlighted(): void
    {
        $results = $this->itemSearchResults()->values();
        $row = $results->get($this->itemHighlight);
        if (! $row) {
            return;
        }

        $this->pickItemById((int) $row->id);
    }

    /* ---------------- Modal pelanggan (F1) ---------------- */

    public function openCustModal(): void
    {
        $this->showCustModal = true;
        $this->custHighlight = 0;
    }

    public function closeCustModal(): void
    {
        $this->showCustModal = false;
        $this->custQ = '';
    }

    public function updatedCustQ(): void
    {
        $this->custHighlight = 0;
    }

    /** @return \Illuminate\Support\Collection */
    private function custSearchResults()
    {
        $q = trim($this->custQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $query = DB::table('bkontak as k')
            ->leftJoin('bkontaktipe as t', 't.KTID', '=', 'k.KTIPE')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'k.KCABANG')
            ->where('k.KAKTIF', '<>', 0);

        // PENTING: jangan LIKE '%q%' di KNAMA (full scan 315rb+ baris, bisa timeout).
        // Pakai FULLTEXT idx_ft_knama + prefix-LIKE utk kolom lain (lihat Contact::applySearch).
        \App\Models\Contact::applySearch($query, $q, 'k');

        // Jangan ORDER BY saat ada pencarian (FULLTEXT + sort = filesort, lambat).
        return $query->limit(15)
            ->get(['k.KID as id', 'k.KKODE as kode', 'k.KNAMA as nama', 'k.K1TELP1 as telp', 't.KTNAMA as tipe', 'g.GKODE as cabang']);
    }

    public function moveCustHighlight(int $delta): void
    {
        $count = $this->custSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->custHighlight = max(0, min($count - 1, $this->custHighlight + $delta));
    }

    public function pickCustHighlighted(): void
    {
        $results = $this->custSearchResults()->values();
        $row = $results->get($this->custHighlight);
        if (! $row) {
            return;
        }

        $this->pickCustomer((int) $row->id);
        $this->showCustModal = false;
    }

    /* ---------------- Item ---------------- */

    /** @return bool true kalau baris baru butuh alur Operator/Dokter/Ref (modal item lalu ditutup, bukan direfocus). */
    public function addItem(int $id): bool
    {
        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return false;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);

        // ICABANG (bitem) = daftar GID cabang yg boleh menjual item ini - dicek ulang di sini
        // (bukan cuma di itemSearchResults()) sbg pengaman kalau ID item datang dari luar hasil
        // pencarian normal (mis. wire:click lama/hasil stale).
        $it = $this->applyIcabangFilter(
            DB::table('bitem')->where('IID', $id)->where('ISTATUS', 0),
            (int) $branch->GID
        )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', 'IDISKON', DB::raw("{$stokCol} AS stok")]);

        if (! $it) {
            return false;
        }

        if ((int) $it->ITIPEITEM === 0 && (float) $it->stok <= 0) {
            $this->dispatch('pos-toast', msg: 'Stok 0: ' . $it->INAMA . ' tidak bisa dijual.', type: 'warning');

            return false;
        }

        foreach ($this->cart as $i => $line) {
            if ($line['item'] === $id) {
                $this->cart[$i]['qty'] += 1;

                return false;
            }
        }

        $dis1 = $this->memberActive ? (float) $it->IDISKON : 0.0;
        $jenis = (int) $it->IJENISITEM;

        $this->cart[] = [
            'item'             => (int) $it->IID,
            'kode'             => $it->IKODE,
            'nama'             => $it->INAMA,
            'qty'              => 1,
            'harga'            => (float) $it->IHARGAJUAL1,
            'dis1'             => $dis1,
            'dis2'             => 0.0,
            'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
            'tipe'             => (int) $it->ITIPEITEM,
            'stok'             => (float) $it->stok,
            'jenisitem'        => $jenis,
            'sdkaryawan'       => null,
            'sdkaryawan_label' => null,
            'sddokter'         => null,
            'sddokter_label'   => null,
            'sdnoref'          => null,
            'sdlantai2'        => null,
            'sdidpromo'        => null,
            'promo_label'      => null,
            'is_bonus'         => false,
            'sddaripaket'      => 0,
            'sdidpotongstok'   => null,
            'sdcatatankoli'    => null,
            'sdkedatangan'     => null,
            'sdsodurutan'      => null,
            'paket_label'      => null,
            'sdpaket_pasien'   => null,
        ];

        $this->itemQ = '';

        $this->startOdFlowForLines([count($this->cart) - 1]);

        return $this->showOperatorModal;
    }

    /** Dipakai modal item (klik mouse maupun Enter) supaya alur Operator/Dokter bisa langsung menutup modal item. */
    public function pickItemById(int $id): void
    {
        $opened = $this->addItem($id);
        $this->itemQ = '';
        $this->itemHighlight = 0;

        if ($opened) {
            $this->showItemModal = false;
        } else {
            $this->dispatch('refocus-item');
        }
    }

    public function removeLine(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);

        // $unlockedLines dikunci per-INDEX baris, jadi harus di-reindex bareng $cart (baris
        // di atas $i turun 1 posisi) supaya status buka-kunci tidak nempel ke baris yg salah.
        unset($this->unlockedLines[$i]);
        $shifted = [];
        foreach ($this->unlockedLines as $idx => $v) {
            $shifted[$idx > $i ? $idx - 1 : $idx] = $v;
        }
        $this->unlockedLines = $shifted;
    }

    /* ---------------- Buka kunci Harga/Diskon per baris (re-auth username+password) ---------------- */

    /** Klik ikon gembok di baris yg masih terkunci - buka modal re-auth. No-op kalau sudah terbuka (pakai lockLine() utk itu). */
    public function openUnlockModal(int $i): void
    {
        if (! isset($this->cart[$i]) || ! empty($this->unlockedLines[$i])) {
            return;
        }

        $this->unlockLine = $i;
        $this->unlockUser = '';
        $this->unlockPassword = '';
        $this->resetErrorBag('unlock');
        $this->showUnlockModal = true;
    }

    /**
     * Klik ikon gembok di baris yg SUDAH terbuka - kunci lagi (mengunci TIDAK butuh
     * password, cuma MEMBUKA yg butuh - asimetris spt lock/unlock pd umumnya). Dipicu jg
     * setelah user selesai mengubah harga/dis1/dis2 di baris itu, per permintaan user
     * 2026-09-15 "setelah user merubah harga diskon... klik icon gembok, terkunci kembali".
     */
    public function lockLine(int $i): void
    {
        unset($this->unlockedLines[$i]);
    }

    public function closeUnlockModal(): void
    {
        $this->showUnlockModal = false;
        $this->unlockLine = null;
        $this->unlockUser = '';
        $this->unlockPassword = '';
    }

    /**
     * Verifikasi username+password yg diketik (BUKAN login ulang - sesi kasir yg sedang login
     * TIDAK berubah, lihat `LegacyAuth::verify()`), lalu cek user itu punya hak "Approve" pada
     * menu `sales/pos` (`Acl::canUserRoute()`, jg TANPA peduli siapa yg sedang login skrg).
     * Kalau lolos, buka kunci baris itu & catat activity log (siapa yg approve, baris apa).
     */
    public function submitUnlock(LegacyAuth $auth): void
    {
        $this->resetErrorBag('unlock');

        if ($this->unlockLine === null || ! isset($this->cart[$this->unlockLine])) {
            $this->closeUnlockModal();

            return;
        }

        $approver = $auth->verify($this->unlockUser, $this->unlockPassword);
        if (! $approver) {
            $this->addError('unlock', 'Username atau password salah.');

            return;
        }

        if (! app('acl')->canUserRoute((int) $approver->UID, 'sales/pos', 'approve')) {
            $this->addError('unlock', 'User ini tidak punya hak "Approve" utk membuka kunci harga/diskon POS.');

            return;
        }

        $this->unlockedLines[$this->unlockLine] = true;
        $itemNama = $this->cart[$this->unlockLine]['nama'] ?? '';
        activity_log(
            'unlock_harga',
            'sales/pos',
            $this->unlockLine,
            'Buka kunci harga/diskon baris "' . $itemNama . '" - disetujui oleh ' . $approver->UKODE
        );

        // Jg dicatat ke tabel legacy `aauserlog` (dipakai bersama CI3/CI4, dikonfirmasi via
        // sample data nyata masih aktif dipakai spt baris "Penjualan Tunai ..."/"Update SKU ...")
        // - BUKAN pengganti activity_log() di atas, keduanya jalan. ULUSER/ULUSERNAME sengaja
        // diisi identitas SUPERVISOR/approver (bukan kasir yg sedang login sesi ini) - CI3 asli
        // py celah audit disini (dikonfirmasi lewat riset kode: "uluser dicatat = user sesi
        // yg login, BUKAN supervisor yg approve"), sengaja DIPERBAIKI supaya jelas SIAPA yg
        // benar2 mengotorisasi, nama kasir yg sedang bertransaksi disebut di teks aktivitasnya.
        DB::table('aauserlog')->insert([
            'ULUSER'     => (int) $approver->UID,
            'ULUSERNAME' => $approver->UNAMA ?: $approver->UKODE,
            'ULCOMPUTER' => (string) request()->ip(),
            'ULDATE'     => now(),
            'ULTIME'     => now(),
            'ULACTIVITY' => 'Buka kunci harga/diskon baris "' . $itemNama . '" pada transaksi kasir ' . ($this->kasirLabel ?? '-'),
            'ULLEVEL'    => 2,
        ]);

        $this->showUnlockModal = false;
        $this->unlockUser = '';
        $this->unlockPassword = '';
    }

    /* ---------------- Operator / Dokter / No Ref & IC (per baris, item tertentu) ---------------- */

    private function openOperatorFlow(int $lineIndex): void
    {
        $this->odLine = $lineIndex;
        $this->odQ = '';
        $this->odHighlight = 0;
        $this->showOperatorModal = true;
    }

    /**
     * Mulai alur Operator->Dokter->Ref utk sekumpulan baris cart SEKALIGUS - dipakai baik utk
     * 1 baris (addItem(), spt sebelumnya) maupun BANYAK baris bersamaan (applyKombinasi() -
     * beberapa item ditambahkan dalam satu aksi, tiap baris yg jenisitem-nya wajib operator
     * direview SATU PER SATU berantai lewat $odQueue, bukan cuma baris pertama). Baris yg
     * jenisitem-nya tidak wajib operator otomatis di-skip dari antrean.
     */
    private function startOdFlowForLines(array $lineIndexes): void
    {
        $queue = [];
        foreach ($lineIndexes as $i) {
            if (! isset($this->cart[$i])) {
                continue;
            }
            if (in_array((int) ($this->cart[$i]['jenisitem'] ?? -1), self::JENIS_PERLU_OPERATOR, true)) {
                $queue[] = $i;
            }
        }

        if ($queue === []) {
            return;
        }

        $this->odQueue = $queue;
        $this->advanceOdQueue();
    }

    /** Lanjut ke baris berikutnya di $odQueue (kalau ada) - dipanggil tiap satu baris selesai/dilewati. */
    private function advanceOdQueue(): void
    {
        if ($this->odQueue === []) {
            return;
        }

        $next = array_shift($this->odQueue);
        $this->openOperatorFlow($next);
    }

    /** Tombol "ubah" di baris keranjang: ulangi alur Operator -> Dokter -> Ref dari awal. */
    public function editOperatorDokter(int $i): void
    {
        if (! isset($this->cart[$i])) {
            return;
        }
        $this->openOperatorFlow($i);
    }

    public function updatedOdQ(): void
    {
        $this->odHighlight = 0;
    }

    /** @return \Illuminate\Support\Collection */
    private function operatorSearchResults()
    {
        $q = trim($this->odQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $query = DB::table('bkontak as k')
            ->where('k.KAKTIF', '<>', 0)
            ->where('k.KTIPE', 4)
            ->where('k.KTAMPILDIPERAWAT', 1);
        \App\Models\Contact::applySearch($query, $q, 'k');

        return $query->limit(15)->get(['k.KID as id', 'k.KKODE as kode', 'k.KNAMA as nama']);
    }

    /** @return \Illuminate\Support\Collection */
    private function dokterSearchResults()
    {
        $q = trim($this->odQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $query = DB::table('bkontak as k')
            ->where('k.KAKTIF', '<>', 0)
            ->where('k.KTIPE', 4)
            ->where('k.KTAMPILDIDOKTER', 1);
        \App\Models\Contact::applySearch($query, $q, 'k');

        return $query->limit(15)->get(['k.KID as id', 'k.KKODE as kode', 'k.KNAMA as nama']);
    }

    public function moveOdHighlight(int $delta): void
    {
        $count = $this->showDokterModal ? $this->dokterSearchResults()->count() : $this->operatorSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->odHighlight = max(0, min($count - 1, $this->odHighlight + $delta));
    }

    public function pickOdHighlighted(): void
    {
        if ($this->showDokterModal) {
            $row = $this->dokterSearchResults()->values()->get($this->odHighlight);
            if ($row) {
                $this->pickDokter((int) $row->id, $row->nama);
            }

            return;
        }

        $row = $this->operatorSearchResults()->values()->get($this->odHighlight);
        if ($row) {
            $this->pickOperator((int) $row->id, $row->nama);
        }
    }

    /** "Lewati" - baris ini tetap ditambahkan tanpa operator, tapi kalau ada baris LAIN di
     *  antrean (mis. dari applyKombinasi()) tetap lanjut direview, tidak ikut dibatalkan. */
    public function closeOperatorModal(): void
    {
        $this->showOperatorModal = false;
        $this->odLine = null;
        $this->odQ = '';
        $this->advanceOdQueue();
    }

    public function pickOperator(int $id, ?string $nama = null): void
    {
        if ($this->odLine === null || ! isset($this->cart[$this->odLine])) {
            $this->showOperatorModal = false;

            return;
        }

        $this->cart[$this->odLine]['sdkaryawan'] = $id;
        $this->cart[$this->odLine]['sdkaryawan_label'] = $nama ?? (string) DB::table('bkontak')->where('KID', $id)->value('KNAMA');

        $this->showOperatorModal = false;
        $this->odQ = '';
        $this->odHighlight = 0;
        $this->showDokterModal = true;
    }

    /** "Lewati" - sama spt closeOperatorModal(), lanjutkan antrean kalau ada baris lain. */
    public function closeDokterModal(): void
    {
        $this->showDokterModal = false;
        $this->odLine = null;
        $this->odQ = '';
        $this->advanceOdQueue();
    }

    public function pickDokter(int $id, ?string $nama = null): void
    {
        if ($this->odLine === null || ! isset($this->cart[$this->odLine])) {
            $this->showDokterModal = false;

            return;
        }

        $this->cart[$this->odLine]['sddokter'] = $id;
        $this->cart[$this->odLine]['sddokter_label'] = $nama ?? (string) DB::table('bkontak')->where('KID', $id)->value('KNAMA');

        $this->showDokterModal = false;
        $this->odQ = '';

        $this->openRefFlow($this->odLine);
    }

    private function openRefFlow(int $lineIndex): void
    {
        $this->odLine = $lineIndex;
        $this->refNo = $this->cart[$lineIndex]['sdnoref'] ?? '';
        $this->refIc = $this->cart[$lineIndex]['sdlantai2'] ?? '';
        $this->showRefModal = true;
    }

    /** No Ref/No IC wajib diisi - tidak ada jalan pintas "lewati" (tanpa tombol tutup/skip di UI). */
    public function saveRef(): void
    {
        $this->resetErrorBag(['refNo', 'refIc']);

        $refNo = trim($this->refNo);
        $refIc = trim($this->refIc);

        if ($refNo === '') {
            $this->addError('refNo', 'No Ref wajib diisi.');
        }
        if ($refIc === '') {
            $this->addError('refIc', 'No IC wajib diisi.');
        }
        if ($refNo === '' || $refIc === '') {
            return;
        }

        if ($this->odLine !== null && isset($this->cart[$this->odLine])) {
            $this->cart[$this->odLine]['sdnoref'] = $refNo;
            $this->cart[$this->odLine]['sdlantai2'] = $refIc;
        }

        $this->showRefModal = false;
        $this->odLine = null;
        $this->refNo = '';
        $this->refIc = '';

        // Baris ini selesai lengkap (No Ref/IC wajib, tidak bisa dilewati) - lanjut ke baris
        // berikutnya di antrean kalau ada (mis. dari applyKombinasi()).
        $this->advanceOdQueue();
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->resetPayments();
        $this->editingFromId = null;
        $this->editingFromNomor = null;
        $this->odQueue = [];
        $this->unlockedLines = [];
        $this->resetPaketState();
    }

    /* ---------------- Pelanggan ---------------- */

    public function pickCustomer(int $id): void
    {
        $row = DB::table('bkontak as k')
            ->leftJoin('bkontaktipe as t', 't.KTID', '=', 'k.KTIPE')
            ->where('k.KID', $id)
            ->first(['k.KID', 'k.KKODE', 'k.KNAMA', 'k.KTIPE', 't.KTNAMA as tipe_nama']);

        if (! $row) {
            return;
        }

        $this->custId = (int) $row->KID;
        $this->custLabel = trim($row->KKODE . ' — ' . $row->KNAMA);
        $this->custQ = '';

        // Member aktif = KTIPE 12 DAN f_tanggal_akhir + 1 tahun >= hari ini.
        $m = DB::selectOne(
            'SELECT (k.KTIPE = 12 AND x.ta IS NOT NULL AND DATE_ADD(x.ta, INTERVAL 1 YEAR) >= CURDATE()) AS ok, x.ta
               FROM bkontak k JOIN (SELECT f_tanggal_akhir(?) AS ta) x WHERE k.KID = ?',
            [$id, $id]
        );
        $this->memberActive = (bool) ($m->ok ?? false);
        $this->memberInfo = $this->memberActive
            ? 'Member aktif s/d ' . date('d/m/Y', strtotime(($m->ta) . ' +1 year'))
            : ($row->KTIPE == 12 ? 'Member (tidak aktif)' : $row->tipe_nama);

        $this->applyMemberDiscount();
        $this->clearVoucher(); // voucher/DP lama (kalau ada) milik pelanggan sebelumnya - tidak valid lagi
        $this->clearDp();
        $this->autoPullDp(); // langsung tarik & terapkan DP pelanggan ini kalau ada (tanpa perlu F6 manual)
        $this->showCustModal = false;
    }

    public function clearCustomer(): void
    {
        $this->custId = null;
        $this->custLabel = null;
        $this->memberActive = false;
        $this->memberInfo = null;
        $this->applyMemberDiscount();
        $this->clearVoucher();
        $this->clearDp();
    }

    private function applyMemberDiscount(): void
    {
        if ($this->cart === []) {
            return;
        }
        $ids = array_column($this->cart, 'item');
        $disc = DB::table('bitem')->whereIn('IID', $ids)->pluck('IDISKON', 'IID');

        foreach ($this->cart as $i => $line) {
            $this->cart[$i]['dis1'] = $this->memberActive ? (float) ($disc[$line['item']] ?? 0) : 0.0;
        }
    }

    /* ---------------- Pembayaran ---------------- */

    private function resetPayments(): void
    {
        $this->pay = [
            'tunai'    => 0,
            'debit'    => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null, 'jenis' => null, 'bank_lain' => ''],
            'kredit'   => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null, 'jenis' => null, 'bank_lain' => ''],
            'transfer' => ['jumlah' => 0, 'no' => '', 'nama' => '', 'bank' => null],
            'merchant' => ['jumlah' => 0, 'no' => '', 'jenis' => null],
            'voucher'  => ['jumlah' => 0, 'no' => '', 'vid' => null, 'program' => null, 'nama' => null],
            'dp'       => ['jumlah' => 0, 'sdid' => null, 'no' => null, 'nama_item' => null, 'jenis' => null],
        ];
    }

    /* ---------------- Modal Pembayaran ---------------- */

    /**
     * Salinan isian bayar saat dialog DIBUKA - dipakai tombol Batal untuk mengembalikan
     * keadaan semula. Tanpa ini Batal & OK sama saja (dua-duanya cuma menutup dialog).
     *
     * @var array<string,mixed>
     */
    public array $paySebelumnya = [];

    /**
     * Buka dialog bayar. Syarat yg BISA dicek murah dicek di sini supaya kasir tidak membuka
     * dialog lalu langsung ditolak; sisanya tetap divalidasi `checkout()` (satu-satunya
     * gerbang sebelum simpan).
     */
    public function openPayModal(): void
    {
        $this->resetErrorBag();

        if ($this->cart === []) {
            $this->addError('cart', 'Keranjang kosong.');

            return;
        }
        if (! $this->custId) {
            $this->addError('cust', 'Pelanggan/pasien wajib dipilih.');
            $this->openCustModal();

            return;
        }

        $this->paySebelumnya = $this->pay;
        $this->showPayModal = true;
    }

    /**
     * Tombol OK - HANYA menyimpan isian pembayaran ke layar, **TIDAK menyimpan transaksi**
     * (permintaan user 2026-09-28). Transaksi baru tersimpan lewat tombol Simpan di layar
     * utama / `checkout()`.
     *
     * Isiannya divalidasi lebih dulu supaya salahnya ketahuan selagi dialog masih terbuka;
     * kalau salah, dialog TIDAK ditutup.
     */
    public function simpanPembayaran(): void
    {
        $this->resetErrorBag();

        if (! $this->validasiPembayaran()) {
            return;
        }

        $this->paySebelumnya = [];
        $this->showPayModal = false;
    }

    /** Batal - kembalikan isian bayar seperti saat dialog dibuka, lalu tutup. */
    public function closePayModal(): void
    {
        if ($this->paySebelumnya !== []) {
            $this->pay = $this->paySebelumnya;
        }

        $this->paySebelumnya = [];
        $this->resetErrorBag();
        $this->showPayModal = false;
    }

    /**
     * Pintasan bayar di dalam dialog, urut sesuai kolomnya (permintaan user 2026-09-28):
     *
     *   F1 Tunai · F2 Debit · F3 Kredit · F4 Transfer · F5 DP · F6 Merchant · F7 Voucher
     *
     * DUA PERILAKU BERBEDA, karena memang beda sifatnya:
     *
     *  - F1/F2/F3/F4/F6 -> `bayarPenuh()`: semua NILAI bayar dikosongkan, lalu metode itu
     *    diisi sebesar total transaksi.
     *  - F5 (DP) & F7 (Voucher) -> MEMBUKA PEMILIHNYA. Keduanya tidak bisa "diisi penuh":
     *    nilainya berasal dari saldo DP/voucher yg dipilih (`pickDp()` mengisi sebesar sisa
     *    saldo), dan sisa itu sering TIDAK menutup seluruh transaksi - jadi metode lain
     *    SENGAJA TIDAK dikosongkan, karena biasanya masih dibutuhkan untuk menutup sisanya.
     *
     * Yang dikosongkan `bayarPenuh()` hanya NILAI bayarnya. Voucher & DP yg terlanjur dipilih
     * TIDAK dilepas - nilainya jadi 0 sehingga tidak ikut terpakai (`checkout()` mengabaikan
     * keduanya saat jumlah 0), tapi kasir tidak perlu mencarinya ulang kalau berubah pikiran.
     */
    public const PINTASAN_BAYAR = [
        'F1' => 'tunai',
        'F2' => 'debit',
        'F3' => 'kredit',
        'F4' => 'transfer',
        'F6' => 'merchant',
    ];

    public function bayarPenuh(string $key): void
    {
        if (! in_array($key, self::PINTASAN_BAYAR, true)) {
            return;
        }

        $this->pay['tunai'] = 0;
        foreach (['debit', 'kredit', 'transfer', 'merchant', 'voucher', 'dp'] as $k) {
            $this->pay[$k]['jumlah'] = 0;
        }

        $total = $this->grandTotal();
        if ($key === 'tunai') {
            $this->pay['tunai'] = $total;
        } else {
            $this->pay[$key]['jumlah'] = $total;
        }

        // Kursor pindah ke isian yg masih perlu dilengkapi (no. kartu / no. merchant).
        $this->dispatch('fokus-bayar', key: $key);
    }

    /* Pengalih tombol pintas. F1-F7 SEMUANYA punya arti ganda: di luar dialog = pintasan lama,
     | di dalam dialog = pintasan bayar. Satu pengikat per tombol dgn percabangan DI SINI -
     | kalau dipasang dua `wire:keydown.fN.window` (satu di root, satu di dalam dialog),
     | KEDUANYA ikut jalan (mis. nilai bayar terisi TAPI modal cari item ikut terbuka). */

    public function hotkeyF1(): void
    {
        $this->showPayModal ? $this->bayarPenuh('tunai') : $this->openCustModal();
    }

    public function hotkeyF2(): void
    {
        $this->showPayModal ? $this->bayarPenuh('debit') : $this->openItemModal();
    }

    /**
     * Di LUAR dialog sengaja TIDAK melakukan apa pun - "Harga Khusus" (dulu Cari Promo)
     * pindah ke F10 (permintaan user 2026-09-30).
     */
    public function hotkeyF3(): void
    {
        if ($this->showPayModal) {
            $this->bayarPenuh('kredit');
        }
    }

    /** Idem F3 - "Daftar Paket" (dulu Cari Paket) pindah ke F11 (2026-09-30). */
    public function hotkeyF4(): void
    {
        if ($this->showPayModal) {
            $this->bayarPenuh('transfer');
        }
    }

    /** DP: di KEDUA keadaan membuka pemilih DP - di dalam dialog inilah "rincian DP"-nya. */
    public function hotkeyF5(): void
    {
        $this->showPayModal ? $this->openDpModal() : $this->openVoucherModal();
    }

    public function hotkeyF6(): void
    {
        $this->showPayModal ? $this->bayarPenuh('merchant') : $this->openDpModal();
    }

    /**
     * Voucher: di dalam dialog membuka pemilih voucher (nilainya dari saldo voucher).
     * Di LUAR dialog sengaja tidak melakukan apa pun - promo pindah ke F3 (2026-09-29).
     */
    public function hotkeyF7(): void
    {
        if ($this->showPayModal) {
            $this->openVoucherModal();
        }
    }

    /**
     * F8 membuka dialog Pembayaran (permintaan user 2026-09-30, sebelumnya F9). Dijaga supaya
     * tidak jalan saat dialognya SUDAH terbuka - `openPayModal()` menyalin ulang nilai bayar
     * ke `$paySebelumnya`, jadi memanggilnya lagi akan menghapus titik pulih untuk Batal.
     */
    public function hotkeyF8(): void
    {
        if (! $this->showPayModal) {
            $this->openPayModal();
        }
    }

    /**
     * F10 "Harga Khusus" & F11 "Daftar Paket" (permintaan user 2026-09-30; dulu F3 & F4, dan
     * captionnya dulu "Cari Promo"/"Cari Paket"). **Hanya di LUAR dialog bayar** - memilih
     * promo/paket di tengah dialog akan mengubah total sementara isian bayar sudah terkunci
     * di layar.
     *
     * CATATAN BROWSER: F11 = layar penuh dan F10 = bilah menu (Firefox). Keduanya MASIH bisa
     * dicegah `preventDefault()` - beda dari **F12** yg TIDAK bisa (DevTools dipegang browser,
     * itu sebabnya simpan transaksi pindah ke Ctrl+Enter). Kalau ternyata di peramban tertentu
     * F11 tetap memicu layar penuh, tombol di layar tetap ada sbg jalan keluarnya.
     */
    public function hotkeyF10(): void
    {
        if (! $this->showPayModal) {
            $this->openPromoModal();
        }
    }

    public function hotkeyF11(): void
    {
        if (! $this->showPayModal) {
            $this->openPaketModal();
        }
    }

    /**
     * Ctrl+Enter = "setujui yang sedang di layar":
     *  - dialog bayar terbuka -> OK (simpan ISIAN BAYAR saja, dialog ditutup);
     *  - dialog tertutup      -> SIMPAN TRANSAKSI.
     *
     * Ini jalur andal untuk keduanya. `F12` (mengikuti tombol OK di VB6) tetap dipasang di
     * dalam dialog, tapi Chrome/Edge MEMBUKA DEVTOOLS pada F12 dan `preventDefault()` tidak
     * bisa mencegahnya - jadi F12 tidak bisa diandalkan di semua browser.
     */
    public function hotkeyCtrlEnter(): void
    {
        if ($this->showPayModal) {
            $this->simpanPembayaran();

            return;
        }

        $this->checkout(app(PosSaleWriter::class));
    }

    /* `payExact()`, `payExactMethod()` & `totalBayarExcept()` DIHAPUS 2026-09-29 - tautan
     | "uang pas" di dialog bayar dihapus atas permintaan user karena sudah ada pintasan F1-F7.
     |
     | BEDANYA, kalau nanti dibutuhkan lagi: "uang pas" mengisi SISA yg belum terbayar,
     | sedangkan `bayarPenuh()` mengosongkan semua lalu mengisi TOTAL PENUH. Jadi sekarang
     | TIDAK ADA lagi cara satu-klik mengisi sisa pada PEMBAYARAN TERBAGI (mis. debit 20rb
     | dulu, lalu tunai sisanya) - kasir mengetik sendiri sisanya. Rumus lamanya:
     |     $sisa = grandTotal() - (totalBayar() - nilai metode yg sedang diisi); */

    /* ---------------- Hitung ---------------- */

    private function lineNet(array $l): float
    {
        $harga = max(0.0, (float) $l['harga']);
        $d1 = min(100.0, max(0.0, (float) $l['dis1']));
        $d2 = min(100.0, max(0.0, (float) $l['dis2']));
        $net = $harga * (1 - $d1 / 100) * (1 - $d2 / 100);

        return max(0.0, (float) $l['qty']) * $net;
    }

    public function grandTotal(): float
    {
        return round(array_sum(array_map(fn ($l) => $this->lineNet($l), $this->cart)), 2);
    }

    private function totalBayar(): float
    {
        return round(
            (float) $this->pay['tunai']
            + (float) $this->pay['debit']['jumlah']
            + (float) $this->pay['kredit']['jumlah']
            + (float) $this->pay['transfer']['jumlah']
            + (float) $this->pay['merchant']['jumlah']
            + (float) $this->pay['voucher']['jumlah']
            + (float) $this->pay['dp']['jumlah'],
            2
        );
    }

    /* ---------------- Voucher (dicari dari pelanggan terpilih) ---------------- */

    public function openVoucherModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pelanggan dulu sebelum cari voucher.');

            return;
        }
        $this->showVoucherModal = true;
        $this->voucherHighlight = 0;
    }

    public function closeVoucherModal(): void
    {
        $this->showVoucherModal = false;
    }

    /** Voucher milik pelanggan terpilih yg masih ada sisa saldo (vnilai - vnilaipakai > 0). */
    private function voucherSearchResults()
    {
        if (! $this->custId) {
            return collect();
        }

        return DB::table('bvoucher as v')
            ->leftJoin('blain as j', 'j.LID', '=', 'v.VJENIS')
            ->where('v.VKONTAK', $this->custId)
            ->whereRaw('(v.VNILAI - v.VNILAIPAKAI) > 0')
            ->orderBy('v.VNOMOR')
            ->get([
                'v.VID as id', 'v.VNOMOR as nomor',
                DB::raw('(v.VNILAI - v.VNILAIPAKAI) as sisa'),
                'j.LNAMA as program',
            ]);
    }

    public function moveVoucherHighlight(int $delta): void
    {
        $count = $this->voucherSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->voucherHighlight = max(0, min($count - 1, $this->voucherHighlight + $delta));
    }

    public function pickVoucherHighlighted(): void
    {
        $row = $this->voucherSearchResults()->values()->get($this->voucherHighlight);
        if ($row) {
            $this->pickVoucher((int) $row->id);
        }
    }

    public function pickVoucher(int $vid): void
    {
        $v = DB::table('bvoucher as v')
            ->leftJoin('blain as j', 'j.LID', '=', 'v.VJENIS')
            ->where('v.VID', $vid)
            ->first(['v.VID', 'v.VNOMOR', 'v.VKONTAK', DB::raw('(v.VNILAI - v.VNILAIPAKAI) as sisa'), 'j.LNAMA as program']);

        if (! $v) {
            return;
        }

        $namaPemilik = (string) DB::table('bkontak')->where('KID', $v->VKONTAK)->value('KNAMA');

        $this->pay['voucher'] = [
            'jumlah'  => max(0.0, (float) $v->sisa),
            'no'      => $v->VNOMOR,
            'vid'     => (int) $v->VID,
            'program' => $v->program,
            'nama'    => $namaPemilik,
        ];

        $this->showVoucherModal = false;
    }

    public function clearVoucher(): void
    {
        $this->pay['voucher'] = ['jumlah' => 0, 'no' => '', 'vid' => null, 'program' => null, 'nama' => null];
    }

    /* ---------------- DP (tukar sisa saldo baris item DP milik pelanggan terpilih) ---------------- */

    public function openDpModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pelanggan dulu sebelum cari DP.');

            return;
        }
        $this->showDpModal = true;
        $this->dpHighlight = 0;
    }

    public function closeDpModal(): void
    {
        $this->showDpModal = false;
    }

    /**
     * Baris item DP (bitem.IJENISITEM=14, hanya 5 item resmi) milik pelanggan terpilih, diinput
     * HARI INI (SUTANGGAL), transaksi aktif (SUSTATUS<>9), yg masih ada sisa saldo
     * ((SDHARGA-SDDISKON)*SDKELUAR - SDBAYARDP > 0).
     */
    private function dpSearchResults()
    {
        if (! $this->custId) {
            return collect();
        }

        return DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->where('u.SUKONTAK', $this->custId)
            ->whereDate('u.SUTANGGAL', now()->toDateString())
            ->whereIn('d.SDITEM', self::ITEM_DP)
            ->whereRaw('((d.SDHARGA - d.SDDISKON) * d.SDKELUAR - d.SDBAYARDP) > 0')
            ->orderBy('d.SDID')
            ->get([
                'd.SDID as id', 'u.SUNOTRANSAKSI as nomor', 'i.INAMA as nama_item',
                DB::raw('((d.SDHARGA - d.SDDISKON) * d.SDKELUAR - d.SDBAYARDP) as sisa'),
            ]);
    }

    public function moveDpHighlight(int $delta): void
    {
        $count = $this->dpSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->dpHighlight = max(0, min($count - 1, $this->dpHighlight + $delta));
    }

    public function pickDpHighlighted(): void
    {
        $row = $this->dpSearchResults()->values()->get($this->dpHighlight);
        if ($row) {
            $this->pickDp((int) $row->id);
        }
    }

    public function pickDp(int $sdid): void
    {
        $d = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('d.SDID', $sdid)
            ->first([
                'd.SDID', 'u.SUNOTRANSAKSI', 'u.SUKONTAK', 'i.INAMA as nama_item',
                DB::raw('((d.SDHARGA - d.SDDISKON) * d.SDKELUAR - d.SDBAYARDP) as sisa'),
            ]);

        if (! $d) {
            return;
        }

        $this->pay['dp'] = [
            'jumlah'    => max(0.0, (float) $d->sisa),
            'sdid'      => (int) $d->SDID,
            'no'        => $d->SUNOTRANSAKSI,
            'nama_item' => $d->nama_item,
            'jenis'     => $this->pay['dp']['jenis'] ?? null,
        ];

        $this->showDpModal = false;
    }

    public function clearDp(): void
    {
        $this->pay['dp'] = ['jumlah' => 0, 'sdid' => null, 'no' => null, 'nama_item' => null, 'jenis' => null];
    }

    /**
     * Dipanggil otomatis setiap pelanggan dipilih - kalau ada DP yg bisa ditarik (hari ini,
     * sisa saldo > 0), langsung terapkan tanpa perlu kasir buka modal F6 manual. Ambil yg
     * paling lama (SDID terkecil) kalau lebih dari satu, sama seperti getdata_dp() legacy
     * (order by SDID, limit 1) - kalau kasir mau pilih DP lain, tetap bisa lewat F6/"Ganti".
     */
    private function autoPullDp(): void
    {
        $row = $this->dpSearchResults()->first();
        if ($row) {
            $this->pickDp((int) $row->id);
        }
    }

    /* ---------------- Promo (cari & tandai, bukan hitung diskon otomatis) ---------------- */

    /** Sama spt Voucher/DP - promo skrg tersaring by cabang kasir & tipe pelanggan, jadi
     *  pelanggan wajib dipilih dulu (supaya tipe-nya diketahui) sebelum bisa cari promo. */
    public function openPromoModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pelanggan dulu sebelum cari promo.');

            return;
        }
        $this->showPromoModal = true;
        $this->promoHighlight = 0;
    }

    public function closePromoModal(): void
    {
        $this->showPromoModal = false;
        $this->promoQ = '';
    }

    public function updatedPromoQ(): void
    {
        $this->promoHighlight = 0;
    }

    /**
     * Promo aktif & berlaku hari ini (emasterpromou), dicari by kode/nama, DISARING lagi by:
     * - Cabang (`emasterpromoc`): **kosong (promo tidak punya baris cabang sama sekali) =
     *   TIDAK AKTIF DI CABANG MANAPUN** (per permintaan user 2026-09-15 - beda dari Tipe
     *   Pelanggan di bawah, sengaja tidak simetris) - cabang kasir SEKARANG ($this->branchId)
     *   WAJIB match salah satu baris `emasterpromoc` promo itu, tidak ada kondisi lolos lain.
     * - Tipe Pelanggan (`emasterpromokontaktipe`): kosong = berlaku SEMUA tipe; kalau ada
     *   baris, tipe pelanggan TERPILIH (`bkontak.KTIPE`) harus match salah satu baris itu.
     *   openPromoModal() sudah menjamin $this->custId selalu terisi saat method ini dipanggil.
     */
    private function promoSearchResults()
    {
        $q = trim($this->promoQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $custTipe = $this->custId ? (int) DB::table('bkontak')->where('KID', $this->custId)->value('KTIPE') : null;

        return DB::table('emasterpromou as u')
            ->where('u.MPUAKTIF', 1)
            ->whereDate('u.MPUTANGGAL1', '<=', now()->toDateString())
            ->whereDate('u.MPUTANGGAL2', '>=', now()->toDateString())
            ->where(fn ($b) => $b->where('u.MPUKODE', 'like', "%{$q}%")->orWhere('u.MPUNAMA', 'like', "%{$q}%"))
            ->whereExists(fn ($q2) => $q2->selectRaw(1)->from('emasterpromoc as c')->whereColumn('c.MPCIDU', 'u.MPUID')->where('c.MPCCABANG', $this->branchId))
            ->where(function ($b) use ($custTipe) {
                $b->whereNotExists(fn ($q2) => $q2->selectRaw(1)->from('emasterpromokontaktipe as t')->whereColumn('t.MPKTIDU', 'u.MPUID'));
                if ($custTipe !== null) {
                    $b->orWhereExists(fn ($q2) => $q2->selectRaw(1)->from('emasterpromokontaktipe as t')->whereColumn('t.MPKTIDU', 'u.MPUID')->where('t.MPKTTIPE', $custTipe));
                }
            })
            ->orderBy('u.MPUNAMA')
            ->limit(20)
            ->get(['u.MPUID as id', 'u.MPUKODE as kode', 'u.MPUNAMA as nama', 'u.MPUTANGGAL1 as tgl1', 'u.MPUTANGGAL2 as tgl2']);
    }

    public function movePromoHighlight(int $delta): void
    {
        $count = $this->promoSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->promoHighlight = max(0, min($count - 1, $this->promoHighlight + $delta));
    }

    public function pickPromoHighlighted(): void
    {
        $row = $this->promoSearchResults()->values()->get($this->promoHighlight);
        if ($row) {
            $this->pickPromo((int) $row->id);
        }
    }

    /**
     * Pilih 1 promo - BISA dipanggil berkali-kali utk transaksi yg sama (1 transaksi boleh
     * pakai lebih dari 1 promo). Kode promo dikumpulkan ke $promos (dedup, list tampilan +
     * SUPROMO gabungan); kalau promo ini Kombinasi 1 ATAU Biasa & punya baris `emasterpromod`,
     * lanjut alur "Terapkan Kombinasi"/"Terapkan Promo" (lihat komentar
     * $showKombinasiApplyModal/$showBiasaApplyModal) - promo tipe lain/tanpa baris cuma nambah
     * ke daftar label, tidak ada langkah lanjutan.
     */
    public function pickPromo(int $mpuid): void
    {
        $row = DB::table('emasterpromou')->where('MPUID', $mpuid)->first(['MPUID', 'MPUKODE', 'MPUNAMA', 'MPUJENISPROMO']);
        if (! $row) {
            return;
        }

        if (! in_array($row->MPUKODE, $this->promos, true)) {
            $this->promos[] = $row->MPUKODE;
        }
        $this->showPromoModal = false;
        $this->promoQ = '';

        $jenis = (int) $row->MPUJENISPROMO;

        if ($jenis === 1) {
            $this->activeKombinasiPromoId = (int) $row->MPUID;
            $this->activeKombinasiKode = $row->MPUKODE;

            $rows = $this->kombinasiRows();
            if ($rows->count() === 1) {
                $this->openKombinasiApply((int) $rows->first()->MPDID);
            } elseif ($rows->count() > 1) {
                $this->kombinasiHighlight = 0;
                $this->showKombinasiModal = true;
            }
        } elseif ($jenis === 0) {
            $this->activeBiasaPromoId = (int) $row->MPUID;
            $this->activeBiasaKode = $row->MPUKODE;

            $rows = $this->biasaRows();
            if ($rows->count() === 1) {
                $this->openBiasaApply((int) $rows->first()->MPDID);
            } elseif ($rows->count() > 1) {
                $this->biasaHighlight = 0;
                $this->showBiasaModal = true;
            }
        }
    }

    /** Hapus 1 kode dari daftar $promos (chip "x") - TIDAK menghapus baris keranjang yg sudah
     *  memakai promo itu (sdidpromo per baris tetap apa adanya, cuma label header yg berubah). */
    public function removePromo(int $i): void
    {
        unset($this->promos[$i]);
        $this->promos = array_values($this->promos);
    }

    /** Reset total state promo (dipanggil setelah checkout sukses / keranjang dikosongkan). */
    private function resetPromoState(): void
    {
        $this->promos = [];
        $this->activeKombinasiPromoId = null;
        $this->activeKombinasiKode = null;
        $this->showKombinasiModal = false;
        $this->showKombinasiApplyModal = false;
        $this->kombApply = [];
        $this->activeBiasaPromoId = null;
        $this->activeBiasaKode = null;
        $this->showBiasaModal = false;
        $this->showBiasaApplyModal = false;
        $this->biasaApply = [];
    }

    /* ---------------- Kombinasi 1 (emasterpromod) - terapkan item+diskon ke keranjang ---------------- */

    /** Baris `emasterpromod` milik promo yg sedang diproses ($this->activeKombinasiPromoId), urut MPDURUTAN. */
    private function kombinasiRows()
    {
        if (! $this->activeKombinasiPromoId) {
            return collect();
        }

        return DB::table('emasterpromod')->where('MPDIDU', $this->activeKombinasiPromoId)->orderBy('MPDURUTAN')->get();
    }

    private function splitPipeList(?string $v): array
    {
        $v = trim((string) $v);

        return $v === '' ? [] : array_values(array_filter(explode('|', $v)));
    }

    public function closeKombinasiModal(): void
    {
        $this->showKombinasiModal = false;
    }

    public function moveKombinasiHighlight(int $delta): void
    {
        $count = $this->kombinasiRows()->count();
        if ($count === 0) {
            return;
        }
        $this->kombinasiHighlight = max(0, min($count - 1, $this->kombinasiHighlight + $delta));
    }

    public function pickKombinasiHighlighted(): void
    {
        $row = $this->kombinasiRows()->values()->get($this->kombinasiHighlight);
        if ($row) {
            $this->openKombinasiApply((int) $row->MPDID);
        }
    }

    public function pickKombinasiBaris(int $mpdid): void
    {
        $this->openKombinasiApply($mpdid);
    }

    /**
     * Buka modal "Terapkan Kombinasi" utk 1 baris `emasterpromod` terpilih. Utk tiap slot
     * item 1-4 yg ADA datanya (MPDKELITEMn tidak kosong): default item = MPDKELITEMn, tapi
     * kasir bisa tukar ke alternatif dari MPDPILIHANn (pipe-delimited) kalau ada; qty default
     * = minimal qty kolom itu (MPDTOTALINVOICE1/2 / MPDMINIMALQTY3/4); diskon1/diskon2 tetap
     * dari baris (MPDDISKON.../MPDDISKON...KE3/KE4 - lihat pemetaan yg sudah dikoreksi di
     * PromoKombinasiForm, dipakai ulang di sini apa adanya). Slot kosong tidak ditampilkan.
     */
    private function openKombinasiApply(int $mpdid): void
    {
        $row = DB::table('emasterpromod')->where('MPDID', $mpdid)->first();
        if (! $row) {
            return;
        }

        $defs = [
            1 => ['kelitem' => $row->MPDKELITEM1, 'pilihan' => $row->MPDPILIHAN1, 'qty' => $row->MPDTOTALINVOICE1, 'd1' => $row->MPDDISKON, 'd2' => $row->MPDDISKON2],
            2 => ['kelitem' => $row->MPDKELITEM2, 'pilihan' => $row->MPDPILIHAN2, 'qty' => $row->MPDTOTALINVOICE2, 'd1' => $row->MPDDISKONITEM2, 'd2' => $row->MPDDISKONITEM22],
            3 => ['kelitem' => $row->MPDKELITEM3, 'pilihan' => $row->MPDPILIHAN3, 'qty' => $row->MPDMINIMALQTY3, 'd1' => $row->MPDDISKON1KE3, 'd2' => $row->MPDDISKON2KE3],
            4 => ['kelitem' => $row->MPDKELITEM4, 'pilihan' => $row->MPDPILIHAN4, 'qty' => $row->MPDMINIMALQTY4, 'd1' => $row->MPDDISKON1KE4, 'd2' => $row->MPDDISKON2KE4],
        ];

        // Kumpulkan semua kode item (kelitem + semua pilihan semua slot) - 1 query saja utk
        // nama & harga jual, dibatasi item AKTIF (ISTATUS=0).
        $allCodes = [];
        foreach ($defs as $d) {
            if ($d['kelitem']) {
                $allCodes[] = $d['kelitem'];
            }
            foreach ($this->splitPipeList($d['pilihan']) as $c) {
                $allCodes[] = $c;
            }
        }
        $allCodes = array_values(array_unique($allCodes));
        $items = $allCodes !== []
            ? DB::table('bitem')->whereIn('IKODE', $allCodes)->where('ISTATUS', 0)->get(['IKODE', 'INAMA', 'IHARGAJUAL1'])->keyBy('IKODE')
            : collect();

        $slots = [];
        foreach ($defs as $n => $d) {
            if (! $d['kelitem']) {
                continue; // slot kosong - tidak ditampilkan sama sekali
            }

            $options = array_values(array_unique(array_merge([$d['kelitem']], $this->splitPipeList($d['pilihan']))));
            $options = array_values(array_filter($options, fn ($c) => $items->has($c))); // hanya yg aktif

            if ($options === []) {
                continue; // item default & semua alternatif sudah tidak aktif - skip slot ini
            }

            $slots[$n] = [
                'chosen'  => in_array($d['kelitem'], $options, true) ? $d['kelitem'] : $options[0],
                'options' => array_map(fn ($c) => ['kode' => $c, 'nama' => $items[$c]->INAMA, 'harga' => (float) $items[$c]->IHARGAJUAL1], $options),
                'qty'     => (float) ($d['qty'] ?? 0) ?: 1.0,
                'd1'      => min(100.0, max(0.0, (float) ($d['d1'] ?? 0))),
                'd2'      => min(100.0, max(0.0, (float) ($d['d2'] ?? 0))),
            ];
        }

        if ($slots === []) {
            $this->addError('cart', 'Baris kombinasi promo ini tidak punya item aktif yang bisa ditambahkan.');

            return;
        }

        $this->kombApply = ['mpdid' => (int) $row->MPDID, 'slots' => $slots];
        $this->showKombinasiModal = false;
        $this->showKombinasiApplyModal = true;
    }

    public function closeKombinasiApplyModal(): void
    {
        $this->showKombinasiApplyModal = false;
        $this->kombApply = [];
    }

    /**
     * Tambahkan semua slot baris kombinasi terpilih ke keranjang SEKALIGUS, sbg baris baru
     * masing2 (bukan digabung ke baris item yg sama kalau sudah ada spt addItem() biasa - baris
     * kombinasi punya diskon & sdidpromo sendiri yg beda maknanya dari baris manual biasa).
     * Item yg gagal lolos ICABANG cabang ini/sudah tidak aktif di-skip dgn pesan, sisanya tetap
     * ditambahkan.
     */
    public function applyKombinasi(): void
    {
        $slots = $this->kombApply['slots'] ?? [];
        if ($slots === []) {
            $this->closeKombinasiApplyModal();

            return;
        }

        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);
        $mpdid = (int) $this->kombApply['mpdid'];
        $skipped = [];
        $addedIndexes = [];

        foreach ($slots as $slot) {
            $kode = $slot['chosen'] ?? null;
            if (! $kode) {
                continue;
            }

            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IKODE', $kode)->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $skipped[] = $kode;

                continue;
            }

            $this->cart[] = [
                'item'             => (int) $it->IID,
                'kode'             => $it->IKODE,
                'nama'             => $it->INAMA,
                'qty'              => max(0.01, (float) $slot['qty']),
                'harga'            => (float) $it->IHARGAJUAL1,
                'dis1'             => (float) $slot['d1'],
                'dis2'             => (float) $slot['d2'],
                'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                'tipe'             => (int) $it->ITIPEITEM,
                'stok'             => (float) $it->stok,
                'jenisitem'        => (int) $it->IJENISITEM,
                'sdkaryawan'       => null,
                'sdkaryawan_label' => null,
                'sddokter'         => null,
                'sddokter_label'   => null,
                'sdnoref'          => null,
                'sdlantai2'        => null,
                'sdidpromo'        => $mpdid,
                'promo_label'      => $this->activeKombinasiKode,
                'is_bonus'         => false,
                'sddaripaket'      => 0,
                'sdidpotongstok'   => null,
                'sdcatatankoli'    => null,
                'sdkedatangan'     => null,
                'sdsodurutan'      => null,
                'paket_label'      => null,
                'sdpaket_pasien'   => null,
            ];
            $addedIndexes[] = count($this->cart) - 1;
        }

        if ($skipped !== []) {
            $this->addError('cart', 'Item kombinasi tidak bisa ditambahkan (tidak aktif/bukan utk cabang ini): ' . implode(', ', $skipped));
        }

        $this->closeKombinasiApplyModal();

        // Langsung cek Operator/Dokter/No Ref-IC utk tiap baris yg jenisitem-nya wajib (sama
        // spt kalau item itu ditambah manual satu2 via addItem()) - direview berantai kalau
        // lebih dari satu baris kombinasi butuh ini.
        $this->startOdFlowForLines($addedIndexes);
    }

    /* ---------------- Biasa (emasterpromod) - terapkan diskon item + bonus ke keranjang ---------------- */

    /** Baris `emasterpromod` milik promo Biasa yg sedang diproses ($this->activeBiasaPromoId), urut MPDURUTAN. */
    private function biasaRows()
    {
        if (! $this->activeBiasaPromoId) {
            return collect();
        }

        return DB::table('emasterpromod')->where('MPDIDU', $this->activeBiasaPromoId)->orderBy('MPDURUTAN')->get();
    }

    public function closeBiasaModal(): void
    {
        $this->showBiasaModal = false;
    }

    public function moveBiasaHighlight(int $delta): void
    {
        $count = $this->biasaRows()->count();
        if ($count === 0) {
            return;
        }
        $this->biasaHighlight = max(0, min($count - 1, $this->biasaHighlight + $delta));
    }

    public function pickBiasaHighlighted(): void
    {
        $row = $this->biasaRows()->values()->get($this->biasaHighlight);
        if ($row) {
            $this->openBiasaApply((int) $row->MPDID);
        }
    }

    public function pickBiasaBaris(int $mpdid): void
    {
        $this->openBiasaApply($mpdid);
    }

    /**
     * Buka modal "Terapkan Promo" utk 1 baris `emasterpromod` (Biasa) terpilih. "Jenis Item"
     * (MPDKELITEM1, kalau ada) resolve jadi 1 slot item spt Kombinasi (default = MPDKELITEM1,
     * bisa ditukar ke alternatif dari MPDPILIHAN1), qty default = Min Qty (MPDMINIMALQTY),
     * diskon1/diskon2 dari baris (MPDDISKON/MPDDISKON2). "Item Bonus" 1-5 (MPDITEM1-5, FK
     * bitem.IID) resolve jadi daftar centang - SEMUA field syarat (minimal belanja/qty/tanggal/
     * jam/max pasien/seluruh invoice) disimpan apa adanya sbg INFO SAJA, tidak dievaluasi.
     */
    private function openBiasaApply(int $mpdid): void
    {
        $row = DB::table('emasterpromod')->where('MPDID', $mpdid)->first();
        if (! $row) {
            return;
        }

        $kelitem = $row->MPDKELITEM1;
        $pilihan = $this->splitPipeList($row->MPDPILIHAN1);
        $allCodes = array_values(array_unique(array_filter(array_merge($kelitem ? [$kelitem] : [], $pilihan))));
        $items = $allCodes !== []
            ? DB::table('bitem')->whereIn('IKODE', $allCodes)->where('ISTATUS', 0)->get(['IKODE', 'INAMA', 'IHARGAJUAL1'])->keyBy('IKODE')
            : collect();

        $itemSlot = null;
        if ($kelitem) {
            $options = array_values(array_unique(array_merge([$kelitem], $pilihan)));
            $options = array_values(array_filter($options, fn ($c) => $items->has($c)));

            if ($options !== []) {
                $itemSlot = [
                    'chosen'  => in_array($kelitem, $options, true) ? $kelitem : $options[0],
                    'options' => array_map(fn ($c) => ['kode' => $c, 'nama' => $items[$c]->INAMA, 'harga' => (float) $items[$c]->IHARGAJUAL1], $options),
                    'qty'     => (float) ($row->MPDMINIMALQTY ?: 1),
                    'd1'      => min(100.0, max(0.0, (float) $row->MPDDISKON)),
                    'd2'      => min(100.0, max(0.0, (float) $row->MPDDISKON2)),
                ];
            }
        }

        // Item Bonus 1-5 (bitem.IID, FK integer - beda dari Jenis Item yg simpan IKODE teks).
        $bonusDefs = [1 => $row->MPDITEM1, 2 => $row->MPDITEM2, 3 => $row->MPDITEM3, 4 => $row->MPDITEM4, 5 => $row->MPDITEM5];
        $bonusIds = array_values(array_filter($bonusDefs));
        $bonusItems = $bonusIds !== []
            ? DB::table('bitem')->whereIn('IID', $bonusIds)->where('ISTATUS', 0)->get(['IID', 'IKODE', 'INAMA'])->keyBy('IID')
            : collect();

        $bonus = [];
        foreach ($bonusDefs as $n => $iid) {
            if (! $iid || ! $bonusItems->has($iid)) {
                continue;
            }
            $it = $bonusItems[$iid];
            $bonus[$n] = ['iid' => (int) $iid, 'kode' => $it->IKODE, 'nama' => $it->INAMA, 'include' => true, 'qty' => 1.0];
        }

        if ($itemSlot === null && $bonus === []) {
            $this->addError('cart', 'Baris promo ini tidak punya item aktif yang bisa ditambahkan.');

            return;
        }

        $this->biasaApply = [
            'mpdid'  => (int) $row->MPDID,
            'item'   => $itemSlot,
            'bonus'  => $bonus,
            'syarat' => [
                'minbelanja'     => $row->MPDTOTALINVOICE1,
                'seluruhinvoice' => (int) $row->MPDTOTALINVOICE2 !== 0,
                'minqty'         => $row->MPDMINIMALQTY,
                'tanggal1'       => $this->cleanDateForDisplay($row->MPDTANGGAL),
                'tanggal2'       => $this->cleanDateForDisplay($row->MPDTANGGAL2),
                'jam1'           => $row->MPDJAM1 ? substr($row->MPDJAM1, 0, 5) : null,
                'jam2'           => $row->MPDJAM2 ? substr($row->MPDJAM2, 0, 5) : null,
                'pakaitanggal'   => (int) $row->MPDPAKAITANGGAL === 1,
                'maxpasien'      => $row->MPDMAXPASIEN,
            ],
        ];
        $this->showBiasaModal = false;
        $this->showBiasaApplyModal = true;
    }

    private function cleanDateForDisplay($v): ?string
    {
        $v = substr((string) $v, 0, 10);

        return ($v === '' || $v === '0000-00-00') ? null : $v;
    }

    public function closeBiasaApplyModal(): void
    {
        $this->showBiasaApplyModal = false;
        $this->biasaApply = [];
    }

    /**
     * Tambahkan hasil "Terapkan Promo" ke keranjang: baris "Jenis Item" (kalau ada, dgn
     * diskon1/diskon2 baris ini) + tiap "Item Bonus" yg dicentang "sertakan" (ditambahkan
     * GRATIS - harga 0, kasir bisa ubah manual di baris keranjang kalau ternyata tidak
     * seharusnya gratis penuh). Item yg gagal lolos ICABANG/tidak aktif di-skip dgn pesan.
     */
    public function applyBiasa(): void
    {
        if (($this->biasaApply['mpdid'] ?? null) === null) {
            $this->closeBiasaApplyModal();

            return;
        }

        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);
        $mpdid = (int) $this->biasaApply['mpdid'];
        $skipped = [];
        $addedIndexes = [];

        $itemSlot = $this->biasaApply['item'] ?? null;
        $kode = $itemSlot['chosen'] ?? null;
        if ($kode) {
            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IKODE', $kode)->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $skipped[] = $kode;
            } else {
                $this->cart[] = [
                    'item'             => (int) $it->IID,
                    'kode'             => $it->IKODE,
                    'nama'             => $it->INAMA,
                    'qty'              => max(0.01, (float) $itemSlot['qty']),
                    'harga'            => (float) $it->IHARGAJUAL1,
                    'dis1'             => (float) $itemSlot['d1'],
                    'dis2'             => (float) $itemSlot['d2'],
                    'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                    'tipe'             => (int) $it->ITIPEITEM,
                    'stok'             => (float) $it->stok,
                    'jenisitem'        => (int) $it->IJENISITEM,
                    'sdkaryawan'       => null,
                    'sdkaryawan_label' => null,
                    'sddokter'         => null,
                    'sddokter_label'   => null,
                    'sdnoref'          => null,
                    'sdlantai2'        => null,
                    'sdidpromo'        => $mpdid,
                    'promo_label'      => $this->activeBiasaKode,
                    'is_bonus'         => false,
                    'sddaripaket'      => 0,
                    'sdidpotongstok'   => null,
                    'sdcatatankoli'    => null,
                    'sdkedatangan'     => null,
                    'sdsodurutan'      => null,
                    'paket_label'      => null,
                    'sdpaket_pasien'   => null,
                ];
                $addedIndexes[] = count($this->cart) - 1;
            }
        }

        foreach ($this->biasaApply['bonus'] ?? [] as $slot) {
            if (empty($slot['include'])) {
                continue;
            }

            $iid = (int) $slot['iid'];
            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IID', $iid)->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $skipped[] = $slot['kode'] ?? ('IID ' . $iid);

                continue;
            }

            $this->cart[] = [
                'item'             => (int) $it->IID,
                'kode'             => $it->IKODE,
                'nama'             => $it->INAMA,
                'qty'              => max(0.01, (float) ($slot['qty'] ?? 1)),
                'harga'            => 0.0, // Item Bonus = gratis, kasir bisa ubah manual kalau perlu
                'dis1'             => 0.0,
                'dis2'             => 0.0,
                'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                'tipe'             => (int) $it->ITIPEITEM,
                'stok'             => (float) $it->stok,
                'jenisitem'        => (int) $it->IJENISITEM,
                'sdkaryawan'       => null,
                'sdkaryawan_label' => null,
                'sddokter'         => null,
                'sddokter_label'   => null,
                'sdnoref'          => null,
                'sdlantai2'        => null,
                'sdidpromo'        => $mpdid,
                'promo_label'      => $this->activeBiasaKode,
                'is_bonus'         => true,
                'sddaripaket'      => 0,
                'sdidpotongstok'   => null,
                'sdcatatankoli'    => null,
                'sdkedatangan'     => null,
                'sdsodurutan'      => null,
                'paket_label'      => null,
                'sdpaket_pasien'   => null,
            ];
            $addedIndexes[] = count($this->cart) - 1;
        }

        if ($skipped !== []) {
            $this->addError('cart', 'Item promo tidak bisa ditambahkan (tidak aktif/bukan utk cabang ini): ' . implode(', ', $skipped));
        }

        $this->closeBiasaApplyModal();
        $this->startOdFlowForLines($addedIndexes);
    }

    /* ------------- Daftar Paket (F11) - cari/tarik paket, epaketu/epaketd/epaketc ------------- */

    public function openPaketModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pelanggan dulu sebelum cari paket.');

            return;
        }
        $this->showPaketModal = true;
        $this->paketHighlight = 0;
    }

    public function closePaketModal(): void
    {
        $this->showPaketModal = false;
        $this->paketQ = '';
    }

    public function updatedPaketQ(): void
    {
        $this->paketHighlight = 0;
    }

    /**
     * Paket aktif, berlaku tanggal hari ini, & boleh dijual/ditarik di cabang kasir sekarang.
     * Konvensi cabang PERSIS sesuai docblock App\Livewire\Master\PaketManager::$PUSEMUACABANG:
     * PUSEMUACABANG=1 -> semua cabang (epaketc diabaikan); =0 -> hanya cabang di epaketc, KOSONG
     * = tidak aktif di cabang manapun. Paket tanpa PUTANGGAL1/2 (NULL) otomatis tersaring keluar -
     * konsisten dgn konvensi PaketManager sendiri ("kosong = tidak jelas kapan berlaku").
     */
    private function paketSearchResults()
    {
        $q = trim($this->paketQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        return DB::table('epaketu as u')
            ->where('u.PUAKTIF', 1)
            ->whereDate('u.PUTANGGAL1', '<=', now()->toDateString())
            ->whereDate('u.PUTANGGAL2', '>=', now()->toDateString())
            ->where(fn ($b) => $b->where('u.PUKODE', 'like', "%{$q}%")->orWhere('u.PUNAMA', 'like', "%{$q}%"))
            ->where(fn ($b) => $b->where('u.PUSEMUACABANG', 1)
                ->orWhereExists(fn ($q2) => $q2->selectRaw(1)->from('epaketc as c')
                    ->whereColumn('c.PCIDU', 'u.PUID')->where('c.PCCABANG', $this->branchId)))
            ->orderBy('u.PUNAMA')
            ->limit(20)
            ->get(['u.PUID as id', 'u.PUKODE as kode', 'u.PUNAMA as nama', 'u.PUJUMLAH as jumlah']);
    }

    public function movePaketHighlight(int $delta): void
    {
        $count = $this->paketSearchResults()->count();
        if ($count === 0) {
            return;
        }
        $this->paketHighlight = max(0, min($count - 1, $this->paketHighlight + $delta));
    }

    public function pickPaketHighlighted(): void
    {
        $row = $this->paketSearchResults()->values()->get($this->paketHighlight);
        if ($row) {
            $this->pickPaket((int) $row->id);
        }
    }

    public function pickPaket(int $puid): void
    {
        $p = DB::table('epaketu')->where('PUID', $puid)->first(['PUID', 'PUKODE', 'PUNAMA', 'PUJUMLAH']);
        if (! $p) {
            return;
        }

        $this->showPaketModal = false;
        $this->paketQ = '';
        $this->paketPuid = (int) $p->PUID;
        $this->paketKode = $p->PUKODE;
        $this->paketNama = $p->PUNAMA;

        // Paket sekali pakai (PUJUMLAH<=1) - dikonfirmasi user: PDQTY selalu = PDQTYTINDAKAN utk
        // kasus ini, tidak butuh Nomer Paket/pelacakan kuota sama sekali.
        if ((float) $p->PUJUMLAH <= 1) {
            $this->addPaketSimple($puid);

            return;
        }

        $this->paketNomor = '';
        $this->resetErrorBag('paket');
        $this->showPaketNomorModal = true;
    }

    public function closePaketNomorModal(): void
    {
        $this->showPaketNomorModal = false;
        $this->resetPaketState();
    }

    private function resetPaketState(): void
    {
        $this->paketPuid = null;
        $this->paketKode = null;
        $this->paketNama = null;
        $this->paketNomor = '';
        $this->paketRows = [];
        $this->paketChecked = [];
    }

    /**
     * Paket PUJUMLAH<=1: tambah SEMUA baris epaketd langsung, qty=PDQTY (=PDQTYTINDAKAN utk
     * kasus ini per konvensi input Master Paket), TANPA Nomer Paket/pelacakan kuota
     * (sddaripaket=0) - paket ini tidak butuh ditarik lagi nanti. sdidpotongstok/sdsodurutan
     * tetap diisi (utk telusur laporan), sdcatatankoli/sdkedatangan tetap null.
     */
    private function addPaketSimple(int $puid): void
    {
        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);
        $rows = DB::table('epaketd')->where('PDIDU', $puid)->orderBy('PDURUTAN')->get();
        $skipped = [];
        $addedIndexes = [];
        $kode = $this->paketKode;

        // Nama item utk pesan "dilewati" - dicari TERPISAH dari lookup ISTATUS/ICABANG di
        // bawah (yg boleh gagal), supaya pesan error selalu tampilkan nama, bukan cuma IID.
        $itemIds = $rows->pluck('PDITEM')->unique()->values()->all();
        $itemNames = $itemIds !== [] ? DB::table('bitem')->whereIn('IID', $itemIds)->pluck('INAMA', 'IID') : collect();

        foreach ($rows as $r) {
            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IID', (int) $r->PDITEM)->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $skipped[] = $itemNames[(int) $r->PDITEM] ?? ('IID ' . $r->PDITEM);

                continue;
            }

            $this->cart[] = [
                'item'             => (int) $it->IID,
                'kode'             => $it->IKODE,
                'nama'             => $it->INAMA,
                'qty'              => max(0.01, (float) $r->PDQTY),
                'harga'            => (float) $it->IHARGAJUAL1,
                // Diskon paket (Master Paket, epaketd.PDDISKONPERSEN1/2) - sebelumnya
                // ke-skip/hardcode 0, bug ditemukan user 2026-09-16.
                'dis1'             => (float) $r->PDDISKONPERSEN1,
                'dis2'             => (float) $r->PDDISKONPERSEN2,
                'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                'tipe'             => (int) $it->ITIPEITEM,
                'stok'             => (float) $it->stok,
                'jenisitem'        => (int) $it->IJENISITEM,
                'sdkaryawan'       => null,
                'sdkaryawan_label' => null,
                'sddokter'         => null,
                'sddokter_label'   => null,
                'sdnoref'          => null,
                'sdlantai2'        => null,
                'sdidpromo'        => null,
                'promo_label'      => null,
                'is_bonus'         => false,
                'sddaripaket'      => 0,
                'sdidpotongstok'   => $puid,
                'sdcatatankoli'    => null,
                'sdkedatangan'     => null,
                'sdsodurutan'      => (int) $r->PDID,
                'paket_label'      => $kode,
                'sdpaket_pasien'   => null,
            ];
            $addedIndexes[] = count($this->cart) - 1;
        }

        if ($skipped !== []) {
            $this->addError('cart', 'Sebagian item paket tidak bisa ditambahkan (tidak aktif/bukan utk cabang ini): ' . implode(', ', $skipped));
        }

        $this->resetPaketState();
        $this->startOdFlowForLines($addedIndexes);
    }

    /**
     * Jumlah baris di KERANJANG SAAT INI (belum checkout, jadi belum ada di DB) yg sudah
     * bertanda paket utk kombinasi (Nomer+baris `epaketd`) ini - dipakai lookupPaketInstance()
     * spy "sudah dipakai berapa kali" tidak cuma lihat DB (lihat catatan di lookupPaketInstance()
     * soal skenario Beli+Tarik dlm 1 transaksi yg sama).
     */
    private function pendingPaketCartCount(string $nomor, int $pdid): int
    {
        $n = 0;
        foreach ($this->cart as $l) {
            if (! empty($l['sddaripaket']) && ($l['sdcatatankoli'] ?? null) === $nomor && (int) ($l['sdsodurutan'] ?? 0) === $pdid) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Cek Nomer Paket: (a) tabrakan pasien lain SCOPED ke kombinasi (Nomer+PUID) yg SAMA - BUKAN
     * Nomer sendirian, krn Nomer Paket cuma nomor tiket kertas kunjungan, lazim dipakai ulang
     * lintas cabang/pasien/paket lain (lihat plan "Tarik Paket" utk penjelasan lengkap kenapa
     * pengecekan sempit ini penting, cegah false-positive); (b) tentukan BELI vs TARIK - DUA
     * ALUR BERBEDA (dikoreksi user 2026-09-16, BUKAN "satu mekanisme tanpa checklist" spt
     * anggapan awal): kombinasi (Nomer+PUID+pasien) BELUM ADA riwayat SAMA SEKALI (semua baris
     * `epaketd` kedatangan=0) -> **BELI PAKET**, SEMUA baris otomatis masuk keranjang TANPA
     * checklist, qty `PDQTY` SERAGAM. Kombinasi SUDAH ADA riwayat (kejadian pertama sdh
     * tercatat) -> **TARIK PAKET**, tampilkan checklist item yg msh ada sisa kuota, qty
     * `PDQTYTINDAKAN` SERAGAM utk SEMUA baris yg ditampilkan (bukan per-item lagi - PDQTY
     * HANYA dipakai di event "Beli", PDQTYTINDAKAN dipakai di SEMUA event "Tarik" apapun).
     */
    public function lookupPaketInstance(): void
    {
        $this->resetErrorBag('paket');
        $nomor = trim($this->paketNomor);
        if ($nomor === '') {
            $this->addError('paket', 'Nomer Paket wajib diisi.');

            return;
        }
        if (! $this->paketPuid || ! $this->custId) {
            return;
        }

        $owner = DB::table('fstokd as d')
            ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
            ->join('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('d.SDDARIPAKET', 1)
            ->where('d.SDIDPOTONGSTOK', $this->paketPuid)
            ->where('d.SDCATATANKOLI', $nomor)
            ->where('u.SUSTATUS', '<>', 9)
            ->where('u.SUKONTAK', '<>', $this->custId)
            ->when($this->editingFromId, fn ($q) => $q->where('u.SUID', '<>', $this->editingFromId))
            ->first(['u.SUKONTAK', 'k.KNAMA']);

        if ($owner) {
            $this->addError('paket', 'Nomer Paket ini milik pasien "' . $owner->KNAMA . '" utk paket "' . $this->paketKode . '" - cek lagi tiket kunjungannya.');

            return;
        }

        $rows = DB::table('epaketd')->where('PDIDU', $this->paketPuid)->orderBy('PDURUTAN')->get();
        $itemIds = $rows->pluck('PDITEM')->unique()->values()->all();
        $itemMeta = $itemIds !== [] ? DB::table('bitem')->whereIn('IID', $itemIds)->get(['IID', 'IKODE', 'INAMA'])->keyBy('IID') : collect();
        $puJumlah = (float) DB::table('epaketu')->where('PUID', $this->paketPuid)->value('PUJUMLAH');

        // Hitung "sudah dipakai berapa kali" utk SETIAP baris DULU (sblm menentukan alur) - kalau
        // SEMUA baris masih 0 (belum ada riwayat sama sekali), ini "Beli Paket" pertama kali.
        // WAJIB hitung baris di KERANJANG SAAT INI jg (belum tersimpan ke DB sampai checkout()) -
        // supaya "Beli lalu langsung Tarik kedatangan-1 dlm 1 transaksi yg sama" terdeteksi benar
        // (kasir buka Cari Paket 2x sblm checkout, dgn Nomer Paket yg sama) - kalau cuma
        // mengandalkan DB, baris Beli yg msh di keranjang tidak akan terhitung & panggilan ke-2
        // akan salah dikira "Beli" lagi (bukan "Tarik"), per temuan user 2026-09-16.
        $sudahDipakaiPerBaris = [];
        foreach ($rows as $r) {
            $pdid = (int) $r->PDID;
            $dariDb = DB::table('fstokd as d')
                ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
                ->where('d.SDSODURUTAN', $pdid)
                ->where('d.SDIDPOTONGSTOK', $this->paketPuid)
                ->where('d.SDCATATANKOLI', $nomor)
                ->where('u.SUKONTAK', $this->custId)
                ->where('u.SUSTATUS', '<>', 9)
                ->when($this->editingFromId, fn ($q) => $q->where('u.SUID', '<>', $this->editingFromId))
                ->count();

            $sudahDipakaiPerBaris[$pdid] = $dariDb + $this->pendingPaketCartCount($nomor, $pdid);
        }

        $isBeli = array_sum($sudahDipakaiPerBaris) === 0;

        if ($isBeli) {
            $this->addPaketBeliRows($rows, $itemMeta, $nomor);

            return;
        }

        $built = [];
        foreach ($rows as $r) {
            $pdid = (int) $r->PDID;
            $sudahDipakai = $sudahDipakaiPerBaris[$pdid];
            $sisa = ($puJumlah + 1) - $sudahDipakai;
            if ($sisa <= 0) {
                continue;
            }

            $item = $itemMeta[(int) $r->PDITEM] ?? null;
            if (! $item) {
                continue;
            }

            $built[] = [
                'pdid'       => $pdid,
                'item'       => (int) $r->PDITEM,
                'kode'       => $item->IKODE,
                'nama'       => $item->INAMA,
                'sisa'       => (int) $sisa,
                'qty'        => (float) $r->PDQTYTINDAKAN, // TARIK = PDQTYTINDAKAN seragam, bukan per-item
                'kedatangan' => $sudahDipakai,
            ];
        }

        $this->paketRows = $built;
        $this->paketChecked = [];

        if ($built === []) {
            $this->addError('paket', 'Paket sudah selesai - semua item sudah mencapai batas kedatangan.');

            return;
        }

        $this->showPaketNomorModal = false;
        $this->showPaketTarikModal = true;
    }

    /**
     * BELI PAKET (Nomer Paket baru, blm ada riwayat sama sekali) - SEMUA baris `epaketd`
     * otomatis masuk keranjang, TANPA checklist, qty `PDQTY` SERAGAM, kedatangan=0 utk semua.
     * Bentuk baris cart LENGKAP via lookup bitem yg SAMA spt addItem()/addPaketLines() (re-cek
     * ISTATUS/ICABANG krn checkout() sendiri tidak re-cek ICABANG).
     */
    /**
     * @param \Illuminate\Support\Collection $rows baris epaketd (PDID/PDITEM/PDQTY/dst)
     * @param \Illuminate\Support\Collection $itemMeta bitem terkait, keyed by IID (IKODE/INAMA)
     */
    private function addPaketBeliRows($rows, $itemMeta, string $nomor): void
    {
        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);
        $puid = $this->paketPuid;
        $kode = $this->paketKode;
        $custId = $this->custId;
        $skipped = [];
        $addedIndexes = [];

        foreach ($rows as $r) {
            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IID', (int) $r->PDITEM)->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $meta = $itemMeta[(int) $r->PDITEM] ?? null;
                $skipped[] = $meta->INAMA ?? ('IID ' . $r->PDITEM);

                continue;
            }

            $this->cart[] = [
                'item'             => (int) $it->IID,
                'kode'             => $it->IKODE,
                'nama'             => $it->INAMA,
                'qty'              => max(0.01, (float) $r->PDQTY),
                'harga'            => (float) $it->IHARGAJUAL1,
                // Diskon paket (Master Paket, epaketd.PDDISKONPERSEN1/2) - sebelumnya
                // ke-skip/hardcode 0, bug ditemukan user 2026-09-16.
                'dis1'             => (float) $r->PDDISKONPERSEN1,
                'dis2'             => (float) $r->PDDISKONPERSEN2,
                'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                'tipe'             => (int) $it->ITIPEITEM,
                'stok'             => (float) $it->stok,
                'jenisitem'        => (int) $it->IJENISITEM,
                'sdkaryawan'       => null,
                'sdkaryawan_label' => null,
                'sddokter'         => null,
                'sddokter_label'   => null,
                'sdnoref'          => null,
                'sdlantai2'        => null,
                'sdidpromo'        => null,
                'promo_label'      => null,
                'is_bonus'         => false,
                'sddaripaket'      => 1,
                'sdidpotongstok'   => $puid,
                'sdcatatankoli'    => $nomor,
                'sdkedatangan'     => 0,
                'sdsodurutan'      => (int) $r->PDID,
                'paket_label'      => $kode,
                'sdpaket_pasien'   => $custId,
            ];
            $addedIndexes[] = count($this->cart) - 1;
        }

        if ($skipped !== []) {
            $this->addError('cart', 'Sebagian item paket tidak bisa ditambahkan (tidak aktif/bukan utk cabang ini): ' . implode(', ', $skipped));
        }

        $this->showPaketNomorModal = false;
        $this->resetPaketState();
        $this->startOdFlowForLines($addedIndexes);
    }

    public function closePaketTarikModal(): void
    {
        $this->showPaketTarikModal = false;
        $this->resetPaketState();
    }

    /**
     * Tambahkan baris yg dicentang ke keranjang - bentuk baris LENGKAP via lookup bitem yg SAMA
     * spt addItem() (checkout() sendiri TIDAK re-cek ICABANG, cuma stok, jadi validasi ISTATUS/
     * ICABANG WAJIB di titik tambah-ke-cart ini). sdpaket_pasien dicatat spy checkout() bisa
     * menolak kalau kasir ganti pelanggan di tengah transaksi sblm checkout (pola sama guard
     * voucher/DP: VKONTAK/SUKONTAK !== custId sekarang -> tolak).
     */
    public function addPaketLines(): void
    {
        $branch = $this->branch();
        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }

        $checked = array_keys(array_filter($this->paketChecked));
        if ($checked === []) {
            $this->closePaketTarikModal();

            return;
        }

        $stokCol = $this->stokColumn((int) $branch->GID);
        $puid = $this->paketPuid;
        $nomor = trim($this->paketNomor);
        $kode = $this->paketKode;
        $custId = $this->custId;
        $skipped = [];
        $addedIndexes = [];

        foreach ($this->paketRows as $row) {
            if (! in_array($row['pdid'], $checked, true)) {
                continue;
            }

            $it = $this->applyIcabangFilter(
                DB::table('bitem')->where('IID', $row['item'])->where('ISTATUS', 0),
                (int) $branch->GID
            )->first(['IID', 'IKODE', 'INAMA', 'IHARGAJUAL1', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

            if (! $it) {
                $skipped[] = $row['kode'];

                continue;
            }

            $this->cart[] = [
                'item'             => (int) $it->IID,
                'kode'             => $it->IKODE,
                'nama'             => $it->INAMA,
                'qty'              => max(0.01, (float) $row['qty']),
                // Tarik Paket = gratis (harga 0) - sudah dibayar penuh saat Beli Paket. dis1/dis2
                // TIDAK perlu ditarik lagi (selalu 0) - per permintaan user 2026-09-16, tidak
                // relevan lagi krn harga sudah 0.
                'harga'            => 0.0,
                'dis1'             => 0.0,
                'dis2'             => 0.0,
                'satuan'           => $it->ISATUAN ? (int) $it->ISATUAN : null,
                'tipe'             => (int) $it->ITIPEITEM,
                'stok'             => (float) $it->stok,
                'jenisitem'        => (int) $it->IJENISITEM,
                'sdkaryawan'       => null,
                'sdkaryawan_label' => null,
                'sddokter'         => null,
                'sddokter_label'   => null,
                'sdnoref'          => null,
                'sdlantai2'        => null,
                'sdidpromo'        => null,
                'promo_label'      => null,
                'is_bonus'         => false,
                'sddaripaket'      => 1,
                'sdidpotongstok'   => $puid,
                'sdcatatankoli'    => $nomor,
                'sdkedatangan'     => $row['kedatangan'],
                'sdsodurutan'      => $row['pdid'],
                'paket_label'      => $kode,
                'sdpaket_pasien'   => $custId,
            ];
            $addedIndexes[] = count($this->cart) - 1;
        }

        if ($skipped !== []) {
            $this->addError('cart', 'Sebagian item paket tidak bisa ditambahkan (tidak aktif/bukan utk cabang ini): ' . implode(', ', $skipped));
        }

        $this->closePaketTarikModal();
        $this->startOdFlowForLines($addedIndexes);
    }

    /* ---------------- Checkout ---------------- */

    /**
     * Validasi ISIAN PEMBAYARAN saja (kartu, voucher, DP). Dipakai DUA kali:
     *  - tombol OK di dialog bayar (`simpanPembayaran()`) supaya salahnya ketahuan selagi
     *    dialognya masih terbuka & isiannya kelihatan;
     *  - `checkout()` sebelum menyimpan - TETAP diulang di sana, karena isian bisa berubah
     *    lagi setelah dialog ditutup, dan saldo voucher/DP bisa berubah di sela itu
     *    (pemeriksaannya menembak DB, bukan cuma bentuk isian).
     *
     * @return bool false kalau ada yang salah; pesannya sudah masuk error bag.
     */
    private function validasiPembayaran(): bool
    {
        // Kartu debit/kredit/transfer diisi jumlah -> no. kartu/ref & bank wajib diisi juga.
        $payLabels = ['debit' => 'Kartu Debit', 'kredit' => 'Kartu Kredit', 'transfer' => 'Transfer'];
        $payInvalid = false;
        foreach ($payLabels as $key => $label) {
            $p = $this->pay[$key];
            if ((float) $p['jumlah'] <= 0) {
                continue;
            }
            if (trim((string) $p['no']) === '') {
                $this->addError("pay.{$key}.no", "No. kartu/ref {$label} wajib diisi.");
                $payInvalid = true;
            }
            if (empty($p['bank'])) {
                $this->addError("pay.{$key}.bank", "Bank {$label} wajib dipilih.");
                $payInvalid = true;
            }
        }
        if ($payInvalid) {
            return false;
        }

        // Voucher diisi jumlah -> harus dari voucher yg benar2 dipilih (bukan diketik bebas),
        // masih milik pelanggan yg sekarang, dan tidak melebihi sisa saldo saat ini (anti race).
        $vc = $this->pay['voucher'];
        if ((float) $vc['jumlah'] > 0) {
            $vRow = $vc['vid'] ? DB::table('bvoucher')->where('VID', $vc['vid'])
                ->first(['VKONTAK', DB::raw('(VNILAI - VNILAIPAKAI) as sisa')]) : null;

            if (! $vRow) {
                $this->addError('pay.voucher.jumlah', 'Voucher belum dipilih.');

                return false;
            }
            if ((int) $vRow->VKONTAK !== (int) $this->custId) {
                $this->addError('pay.voucher.jumlah', 'Voucher bukan milik pelanggan ini.');
                $this->clearVoucher();

                return false;
            }

            // Sedang edit transaksi lama yg belum dibatalkan -> kalau voucher yg SAMA sudah
            // dipakai di sana, pemakaiannya belum "dikembalikan" ke sisa saldo live (baru
            // dibalik saat replace() disimpan). Kompensasi supaya tidak salah ditolak.
            $sisaTersedia = (float) $vRow->sisa;
            if ($this->editingFromId) {
                $vcLama = (float) DB::table('fstoku')->where('SUID', $this->editingFromId)
                    ->where('SUSTATUSKIRIM', $vc['vid'])->value('SUTOTALVOUCHER');
                $sisaTersedia += $vcLama;
            }

            if ((float) $vc['jumlah'] > $sisaTersedia + 0.001) {
                $this->addError('pay.voucher.jumlah', 'Sisa saldo voucher tinggal ' . number_format($sisaTersedia, 0, ',', '.') . '.');

                return false;
            }
        }

        // DP diisi jumlah -> harus dari baris DP yg benar2 dipilih, item-nya benar2 salah satu
        // dari 5 item DP resmi (whitelist yg sama dipakai trigger DB), masih milik pelanggan yg
        // sekarang, dan tidak melebihi sisa saldo saat ini.
        $dp = $this->pay['dp'];
        if ((float) $dp['jumlah'] > 0) {
            $dRow = $dp['sdid'] ? DB::table('fstokd as d')
                ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
                ->where('d.SDID', $dp['sdid'])
                ->first(['d.SDITEM', 'u.SUKONTAK', DB::raw('((d.SDHARGA - d.SDDISKON) * d.SDKELUAR - d.SDBAYARDP) as sisa')]) : null;

            if (! $dRow || ! in_array((int) $dRow->SDITEM, self::ITEM_DP, true)) {
                $this->addError('pay.dp.jumlah', 'DP belum dipilih.');
                $this->clearDp();

                return false;
            }
            if ((int) $dRow->SUKONTAK !== (int) $this->custId) {
                $this->addError('pay.dp.jumlah', 'DP bukan milik pelanggan ini.');
                $this->clearDp();

                return false;
            }

            // Sedang edit transaksi lama yg belum dibatalkan -> kompensasi spt voucher/stok.
            $sisaDpTersedia = (float) $dRow->sisa;
            if ($this->editingFromId) {
                $dpLama = (float) DB::table('fstoku')->where('SUID', $this->editingFromId)
                    ->where('SUDPID', $dp['sdid'])->value('SUTOTALDP');
                $sisaDpTersedia += $dpLama;
            }

            if ((float) $dp['jumlah'] > $sisaDpTersedia + 0.001) {
                $this->addError('pay.dp.jumlah', 'Sisa saldo DP tinggal ' . number_format($sisaDpTersedia, 0, ',', '.') . '.');

                return false;
            }
        }

        return true;
    }


    public function checkout(PosSaleWriter $writer): void
    {
        $this->resetErrorBag();
        $branch = $this->branch();

        if ($branch === null) {
            $this->addError('branch', 'Cabang akun Anda tidak valid/tidak aktif - hubungi admin.');

            return;
        }
        if ($this->cart === []) {
            $this->addError('cart', 'Keranjang kosong.');

            return;
        }
        if (! $this->custId) {
            $this->addError('cust', 'Pelanggan/pasien wajib dipilih.');
            $this->openCustModal();

            return;
        }

        // Catatan Rekam Medis wajib (permintaan user 2026-09-29). Diperiksa SEBELUM pembayaran
        // supaya kasir tidak sempat mengisi dialog bayar lalu ditolak karena field di layar
        // utama. Dipotong 255 krn `SUREKAMMEDIS` varchar(255) - `strict => false` di config DB
        // akan MEMOTONG DIAM-DIAM kalau lebih, jadi dibatasi di sini supaya ketahuan kasir.
        $rm = trim((string) $this->rekamMedis);
        if ($rm === '') {
            $this->addError('rekamMedis', 'Catatan Rekam Medis wajib diisi.');

            return;
        }
        if (mb_strlen($rm) > 255) {
            $this->addError('rekamMedis', 'Catatan Rekam Medis maksimal 255 huruf (sekarang ' . mb_strlen($rm) . ').');

            return;
        }

        if (! $this->validasiPembayaran()) {
            return;
        }

        // Baris asal-paket (sddaripaket=1): (a) tolak kalau pelanggan sudah diganti sejak baris
        // itu ditambahkan (pola sama guard voucher/DP di atas: VKONTAK/SUKONTAK !== custId
        // sekarang); (b) hitung ulang kuota KUMULATIF per kombinasi (Nomer+PUID+PDID) - bukan
        // cuma per-baris independen ke DB, krn kasir bisa buka modal tarik-paket 2x utk item yg
        // sama sblm checkout (2 baris cart yg masing2 "aman" sendiri2 tp gabungannya over-draw).
        // sdkedatangan DIHITUNG ULANG persis sblm insert (bukan dipercaya dari kapan ditambahkan
        // ke cart) - sekaligus menangani penomoran urut kalau ada >1 baris kombinasi yg sama.
        $paketGroups = [];
        foreach ($this->cart as $i => $l) {
            if (empty($l['sddaripaket'])) {
                continue;
            }
            if ((int) ($l['sdpaket_pasien'] ?? -1) !== (int) $this->custId) {
                $this->addError('cart', 'Baris "' . $l['nama'] . '" ditandai utk pelanggan lain - pelanggan sudah diganti sejak baris ini ditambahkan.');

                return;
            }
            $key = $l['sdcatatankoli'] . '|' . $l['sdidpotongstok'] . '|' . $l['sdsodurutan'];
            $paketGroups[$key][] = $i;
        }

        foreach ($paketGroups as $key => $idxs) {
            [$nomor, $puid, $pdid] = explode('|', $key, 3);
            $puJumlah = (float) DB::table('epaketu')->where('PUID', $puid)->value('PUJUMLAH');
            $sudahDipakai = DB::table('fstokd as d')
                ->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
                ->where('d.SDSODURUTAN', $pdid)
                ->where('d.SDIDPOTONGSTOK', $puid)
                ->where('d.SDCATATANKOLI', $nomor)
                ->where('u.SUKONTAK', $this->custId)
                ->where('u.SUSTATUS', '<>', 9)
                ->when($this->editingFromId, fn ($q) => $q->where('u.SUID', '<>', $this->editingFromId))
                ->count();

            if ($sudahDipakai + count($idxs) > $puJumlah + 1) {
                $this->addError('cart', 'Kuota paket utk item "' . ($this->cart[$idxs[0]]['nama'] ?? '') . '" sudah terlampaui - cari ulang Nomer Paket.');

                return;
            }

            foreach (array_values($idxs) as $seq => $i) {
                $this->cart[$i]['sdkedatangan'] = $sudahDipakai + $seq;
            }
        }

        foreach ($this->cart as $i => $l) {
            if (! in_array((int) ($l['jenisitem'] ?? -1), self::JENIS_PERLU_OPERATOR, true)) {
                continue;
            }
            if (empty($l['sdkaryawan']) || empty($l['sddokter'])) {
                $this->addError('cart', 'Baris "' . $l['nama'] . '" belum diisi Operator/Dokter.');
                $this->editOperatorDokter($i);

                return;
            }
            if (empty($l['sdnoref']) || empty($l['sdlantai2'])) {
                $this->addError('cart', 'Baris "' . $l['nama'] . '" belum diisi No Ref/No IC.');
                $this->openRefFlow($i);

                return;
            }
        }

        $tgl = now()->toDateString();
        $stokCol = $this->stokColumn((int) $branch->GID);
        $ids = array_column($this->cart, 'item');
        $meta = DB::table('bitem')->whereIn('IID', $ids)
            ->get(['IID', 'INAMA', 'ITIPEITEM', 'IDISKON', DB::raw("{$stokCol} AS stok")])->keyBy('IID');

        // Sedang edit transaksi lama (belum dibatalkan) -> qty lama per item dianggap "akan
        // dikembalikan" saat checkout ini benar2 disimpan, jadi ditambahkan dulu ke stok yg
        // dicek supaya tidak salah ditolak "stok kurang" padahal cuma menyimpan ulang qty yg sama.
        $qtyLama = [];
        if ($this->editingFromId) {
            foreach (DB::table('fstokd')->where('SDIDSU', $this->editingFromId)->get(['SDITEM', 'SDKELUAR']) as $ol) {
                $qtyLama[(int) $ol->SDITEM] = ($qtyLama[(int) $ol->SDITEM] ?? 0) + (float) $ol->SDKELUAR;
            }
        }

        $subtotal = 0.0;
        $lines = [];
        foreach ($this->cart as $l) {
            $itemId = (int) $l['item'];
            $qty = max(0.0, (float) $l['qty']);
            $harga = max(0.0, (float) $l['harga']);
            if ($qty <= 0 || $itemId <= 0) {
                continue;
            }
            $m = $meta[$itemId] ?? null;
            $stokTersedia = $m ? (float) $m->stok + ($qtyLama[$itemId] ?? 0) : 0.0;
            if ($m && (int) $m->ITIPEITEM === 0 && $stokTersedia < $qty) {
                $this->addError('cart', 'Stok "' . $m->INAMA . '" tinggal ' . rtrim(rtrim(number_format($stokTersedia, 2), '0'), '.') . '.');

                return;
            }

            $d1 = min(100.0, max(0.0, (float) $l['dis1']));
            if ($this->memberActive && $m) {
                $d1 = max($d1, (float) $m->IDISKON);
            }
            $d2 = min(100.0, max(0.0, (float) $l['dis2']));

            $net = $harga * (1 - $d1 / 100) * (1 - $d2 / 100);
            $diskonUnit = round($harga - $net, 2);
            $subtotal += $qty * ($harga - $diskonUnit);

            $lines[] = [
                'SDITEM'          => $itemId,
                'SDKELUAR'        => $qty,
                'SDKELUARD'       => $qty,
                'SDHARGA'         => $harga,
                'SDDISKONPERSEN'  => $d1,
                'SDDISKONPERSEN2' => $d2,
                'SDDISKON'        => $diskonUnit,
                'SDSATUAN'        => $l['satuan'] ?: null,
                'SDSATUAND'       => $l['satuan'] ?: null,
                'SDGUDANG'        => (int) $branch->GID,
                'SDCETAK'         => 1,
                'SDKARYAWAN'      => $l['sdkaryawan'] ?? null,
                'SDDOKTER'        => $l['sddokter'] ?? null,
                'SDNOREF'         => $l['sdnoref'] ?? null,
                'SDLANTAI2'       => $l['sdlantai2'] ?? null,
                'SDIDPROMO'       => $l['sdidpromo'] ?? null,
                'SDDARIPAKET'     => $l['sddaripaket'] ?? null,
                'SDIDPOTONGSTOK'  => $l['sdidpotongstok'] ?? null,
                'SDCATATANKOLI'   => $l['sdcatatankoli'] ?? null,
                'SDKEDATANGAN'    => $l['sdkedatangan'] ?? null,
                'SDSODURUTAN'     => $l['sdsodurutan'] ?? null,
            ];
        }

        if ($lines === []) {
            $this->addError('cart', 'Tidak ada baris valid.');

            return;
        }

        $subtotal = round($subtotal, 2);
        $bayar = $this->totalBayar();
        if ($bayar + 0.001 < $subtotal) {
            $this->addError('pay', 'Pembayaran kurang Rp ' . number_format($subtotal - $bayar, 0, ',', '.'));

            return;
        }

        $d = $this->pay['debit'];
        $k = $this->pay['kredit'];
        $tf = $this->pay['transfer'];
        $mc = $this->pay['merchant'];
        $vc = $this->pay['voucher'];
        $dp = $this->pay['dp'];

        $header = [
            'SUTANGGAL'          => $tgl,
            'SUKONTAK'           => $this->custId,
            'SUKARYAWAN'         => $this->kasirId,
            'SUCATATAN'          => trim((string) $this->catatan) ?: null,
            'SUREKAMMEDIS'       => trim((string) $this->rekamMedis),
            'SUCABANG'           => (int) $branch->GID,
            'SUTOTALTRANSAKSI'   => $subtotal,
            'SUTOTALBAYAR'       => $bayar,
            'SUTOTALSISA'        => max(0, round($subtotal - $bayar, 2)),
            'SUTOTALTADA'        => $subtotal,
            'SUTOTALKAS'         => (float) $this->pay['tunai'],
            'SUTOTALKARTUDEBIT'  => (float) $d['jumlah'],
            'SUNOKARTUDEBIT'     => $d['no'] ?: null,
            'SUNAMADEBIT'        => $d['nama'] ?: null,
            'SUBANKDEBIT'        => $d['bank'] ?: null,
            'SUDEBITJENIS'       => $d['jenis'] ?: null,
            'SUATTENTION'        => $d['bank_lain'] ?: null, // "Bank Lain" debit - kolom legacy dipakai ulang persis spt CI3
            'SUTOTALKARTUKREDIT' => (float) $k['jumlah'],
            'SUNOKARTUKREDIT'    => $k['no'] ?: null,
            'SUNAMAKREDIT'       => $k['nama'] ?: null,
            'SUBANKKREDIT'       => $k['bank'] ?: null,
            'SUKREDITJENIS'      => $k['jenis'] ?: null,
            'SUNOFAKTURPAJAK'    => $k['bank_lain'] ?: null, // "Bank Lain" kredit - kolom legacy dipakai ulang persis spt CI3
            'SUTOTALTRANSFER'    => (float) $tf['jumlah'],
            'SUNOTRANSFER'       => $tf['no'] ?: null,
            'SUNAMATRANSFER'     => $tf['nama'] ?: null,
            'SUBANKTRANSFER'     => $tf['bank'] ?: null,
            'SUMERCHANTJUMLAH'   => (float) $mc['jumlah'],
            'SUMERCHANTNO'       => $mc['no'] ?: null,
            'SUMERCHANTJENIS'    => $mc['jenis'] ?: null,
            'SUTOTALVOUCHER'     => (float) $vc['jumlah'],
            'SUNOVOUCHER'        => $vc['no'] ?: null,
            'SUSTATUSKIRIM'      => $vc['vid'] ?: null,
            'SUPROGRAMVOUCHER'   => $vc['program'] ?: null,
            'SUNAMAVOUCHER'      => $vc['nama'] ?: null,
            'SUTOTALDP'          => (float) $dp['jumlah'],
            'SUDP1'              => (float) $dp['jumlah'],
            'SUDPID'             => $dp['sdid'] ?: null,
            'SUJENISDP'          => $dp['jenis'] ?: null,
            'SUPROMO'            => $this->promos !== [] ? implode(', ', $this->promos) : null,
            // "Data Lainnya" - lihat komentar $showOtherDataModal. SUIDMEDLIB/SULMCID (ID
            // Medlib/ID PRO) SENGAJA TIDAK ditulis dari sini - readonly, rujukan sistem lain.
            'SUREKHUTANG'        => $this->suTraining ?: null,
            'SUFARMASI'          => $this->suFarmasi ?: null,
            'SUFARMASIASISTEN'   => $this->suFarmasiAsisten ?: null,
            'SUSALESMARKETING'   => $this->suSalesMarketing ?: null,
            'SUKLINIKLAIN'       => $this->suKlinikLain ?: null,
            'SUTEMAN'            => $this->suTeman ?: null,
            'SUKODETELE'         => $this->suKodeTele ?: null,
            'SUREVIEWNILAI'      => $this->suReviewNilai ?: 0,
            'SUREVIEWCATATAN'    => $this->suReviewCatatan ?: null,
            'SUKONUL'            => $this->suKonsulSaja ? 1 : 0,
            'SUKONSULKEMARIN'    => $this->suKonsulKemarin ? 1 : 0,
            'SUCREATEU'          => auth()->id(),
        ];

        $meta2 = [
            'kodecabang' => (string) ($branch->GALAMAT1 ?: $branch->GKODE),
            'tgl'        => $tgl,
        ];

        // Edit transaksi lama: batalkan + simpan baru dalam SATU transaksi DB atomik -
        // transaksi lama baru benar2 hilang begitu ini sukses, TIDAK di titik "Edit" diklik.
        $res = $this->editingFromId
            ? $writer->replace($this->editingFromId, $header, $lines, $meta2)
            : $writer->save($header, $lines, $meta2);

        if (! $res['ok']) {
            $this->addError('cart', $res['error'] ?? 'Gagal menyimpan.');

            return;
        }

        $wasEditingFrom = $this->editingFromNomor;

        $writer->maybeAddPoints((int) $res['id'], $this->custId, $subtotal);
        activity_log(
            'create',
            'sales/pos',
            $res['nomor'],
            $wasEditingFrom
                ? 'Penjualan POS ' . $res['nomor'] . ' Rp ' . number_format($subtotal, 0, ',', '.') . ' (pengganti transaksi ' . $wasEditingFrom . ', dibatalkan)'
                : 'Penjualan POS ' . $res['nomor'] . ' Rp ' . number_format($subtotal, 0, ',', '.')
        );

        $this->lastReceipt = [
            'id'        => $res['id'],
            'nomor'     => $res['nomor'],
            'total'     => $subtotal,
            'bayar'     => $bayar,
            'kembalian' => max(0, round($bayar - $subtotal, 2)),
            'items'     => count($lines),
        ];

        $this->cart = [];
        $this->clearCustomer();
        $this->resetPayments();
        $this->catatan = null;
        $this->rekamMedis = null;
        $this->resetPromoState();
        $this->editingFromId = null;
        $this->editingFromNomor = null;
        $this->odQueue = [];
        $this->unlockedLines = [];
        $this->resetOtherData();
        $this->showPaketModal = false;
        $this->showPaketNomorModal = false;
        $this->showPaketTarikModal = false;
        $this->resetPaketState();
        // Dialog bayar ditutup HANYA di sini - kalau checkout() berhenti karena validasi, dialog
        // sengaja dibiarkan terbuka supaya pesan kesalahannya terbaca di tempat isiannya.
        $this->showPayModal = false;
        // kasirId/kasirLabel TIDAK direset - melekat ke user login, bukan per transaksi.
    }

    /** Reset semua field "Data Lainnya" (dipakai setelah checkout sukses & di awal editTransaction()). */
    private function resetOtherData(): void
    {
        $this->showOtherDataModal = false;
        $this->suTraining = null;
        $this->suTrainingLabel = null;
        $this->suFarmasi = null;
        $this->suFarmasiLabel = null;
        $this->suFarmasiAsisten = null;
        $this->suFarmasiAsistenLabel = null;
        $this->suSalesMarketing = null;
        $this->suSalesMarketingLabel = null;
        $this->suKlinikLain = null;
        $this->suKlinikLainLabel = null;
        $this->suTeman = null;
        $this->suTemanLabel = null;
        $this->suKodeTele = null;
        $this->suReviewNilai = null;
        $this->suReviewCatatan = null;
        $this->suKonsulSaja = false;
        $this->suKonsulKemarin = false;
        $this->suIdMedlib = null;
        $this->suLmcId = null;
    }

    public function openOtherDataModal(): void
    {
        $this->showOtherDataModal = true;
    }

    public function closeOtherDataModal(): void
    {
        $this->showOtherDataModal = false;
    }

    /**
     * Buka "Data Transaksi" (list SEMUA transaksi POS lintas kasir/cabang, `Sales\PosDataList`)
     * sbg tab baru - SENGAJA BUKAN menu sidebar sendiri (per permintaan user 2026-09-16, "jadi
     * tidak dimenu sendiri") - jadi tombol di form POS ini, riding along dgn akses `sales/pos`
     * yg sudah dipunya siapapun yg bisa buka layar ini (tidak ada lapisan hak akses tambahan).
     * Konvensi tombol serupa ini akan diulang di form2 lain nanti (per permintaan user).
     */
    public function newTransaction(): void
    {
        $this->lastReceipt = [];
    }

    /* ---------------- Riwayat hari ini (cetak ulang / edit) ---------------- */

    public function openHistoryModal(): void
    {
        $this->showHistoryModal = true;
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
    }

    /** Transaksi POS hari ini yg diinput kasir yg login (SUSUMBER='IP') - utk cetak ulang / edit. */
    private function todayHistory()
    {
        return DB::table('fstoku as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9) // 9 = Cancel/sudah diganti - konvensi legacy CI3
            ->where('u.SUCREATEU', auth()->id())
            ->whereDate('u.SUCREATED', now()->toDateString())
            ->orderByDesc('u.SUID')
            ->get(['u.SUID', 'u.SUNOTRANSAKSI', 'u.SUCREATED', 'u.SUTOTALTRANSAKSI', 'k.KNAMA as pelanggan']);
    }

    /**
     * Muat transaksi lama ke keranjang utk diedit. Transaksi lama TIDAK dibatalkan di sini -
     * tetap aktif apa adanya sampai kasir benar-benar menekan Bayar & Simpan (baru dibatalkan
     * bersamaan dgn penyimpanan baru, satu transaksi DB atomik lewat PosSaleWriter::replace() -
     * SUSTATUS diset 9, baris TIDAK dihapus, persis konvensi CI3). Kalau kasir batal/menutup
     * tanpa menyimpan, transaksi lama tidak pernah tersentuh.
     */
    public function editTransaction(int $id): void
    {
        $header = DB::table('fstoku')->where('SUID', $id)->first();
        if (! $header
            || $header->SUSUMBER !== 'IP'
            || (int) $header->SUSTATUS === 9 // sudah batal/diganti sebelumnya - tidak bisa diedit lagi
            || (int) $header->SUCREATEU !== (int) auth()->id()) {
            return; // bukan transaksi POS / bukan input kasir yg login
        }

        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('d.SDIDSU', $id)
            ->orderBy('d.SDURUTAN')
            ->get([
                'd.SDITEM', 'd.SDKELUAR', 'd.SDHARGA', 'd.SDDISKONPERSEN', 'd.SDDISKONPERSEN2',
                'd.SDSATUAN', 'd.SDKARYAWAN', 'd.SDDOKTER', 'd.SDNOREF', 'd.SDLANTAI2', 'd.SDIDPROMO',
                'd.SDDARIPAKET', 'd.SDIDPOTONGSTOK', 'd.SDCATATANKOLI', 'd.SDKEDATANGAN', 'd.SDSODURUTAN',
                'i.IKODE', 'i.INAMA', 'i.ITIPEITEM', 'i.IJENISITEM',
            ]);

        if ($lines->isEmpty()) {
            return;
        }

        $branch = $this->branch();
        $stokCol = $branch ? $this->stokColumn((int) $branch->GID) : 'ISTOKPG';

        $opIds = $lines->pluck('SDKARYAWAN')->merge($lines->pluck('SDDOKTER'))->filter()->unique()->values()->all();
        $namaMap = [];
        if ($opIds !== []) {
            foreach (DB::table('bkontak')->whereIn('KID', $opIds)->get(['KID', 'KNAMA']) as $row) {
                $namaMap[(int) $row->KID] = $row->KNAMA;
            }
        }

        // Label promo per baris kombinasi (MPDID -> MPUKODE) - info tampilan saja, MPDID
        // sendiri (SDIDPROMO) yg jadi sumber kebenaran & ditulis ulang persis saat disimpan.
        $mpdIds = $lines->pluck('SDIDPROMO')->filter()->unique()->values()->all();
        $promoLabelMap = [];
        if ($mpdIds !== []) {
            foreach (DB::table('emasterpromod as d')->join('emasterpromou as u', 'u.MPUID', '=', 'd.MPDIDU')
                ->whereIn('d.MPDID', $mpdIds)->get(['d.MPDID', 'u.MPUKODE']) as $row) {
                $promoLabelMap[(int) $row->MPDID] = $row->MPUKODE;
            }
        }

        // Label paket per baris asal-paket (PUID -> PUKODE) - info tampilan saja, sama pola spt
        // $promoLabelMap di atas (kolom asli SDIDPOTONGSTOK/dst yg jadi sumber kebenaran).
        $puIds = $lines->pluck('SDIDPOTONGSTOK')->filter()->unique()->values()->all();
        $paketLabelMap = $puIds !== []
            ? DB::table('epaketu')->whereIn('PUID', $puIds)->pluck('PUKODE', 'PUID')
            : collect();

        // Pelanggan diset DULU selagi cart masih kosong - pickCustomer()/clearCustomer() memanggil
        // applyMemberDiscount() yg no-op kalau cart kosong, supaya dis1/dis2 asli transaksi lama
        // (diisi setelah ini) TIDAK ketiban perhitungan member yg berlaku "hari ini".
        $this->cart = [];
        $this->odQueue = [];
        $this->unlockedLines = [];
        $this->showPaketModal = false;
        $this->showPaketNomorModal = false;
        $this->showPaketTarikModal = false;
        $this->resetPaketState();
        if ($header->SUKONTAK) {
            $this->pickCustomer((int) $header->SUKONTAK);
        } else {
            $this->clearCustomer();
        }

        foreach ($lines as $l) {
            // Stok saat ini - BELUM termasuk pengembalian qty transaksi lama (baru dikembalikan
            // saat checkout benar2 disimpan). Nilai ini murni informatif di tampilan keranjang;
            // validasi stok yg sesungguhnya di checkout() sudah dikompensasi dgn qty lama.
            $stokSekarang = $branch ? (float) (DB::table('bitem')->where('IID', $l->SDITEM)->value($stokCol) ?? 0) : 0.0;

            $this->cart[] = [
                'item'             => (int) $l->SDITEM,
                'kode'             => $l->IKODE,
                'nama'             => $l->INAMA,
                'qty'              => (float) $l->SDKELUAR,
                'harga'            => (float) $l->SDHARGA,
                'dis1'             => (float) $l->SDDISKONPERSEN,
                'dis2'             => (float) $l->SDDISKONPERSEN2,
                'satuan'           => $l->SDSATUAN ? (int) $l->SDSATUAN : null,
                'tipe'             => (int) $l->ITIPEITEM,
                'stok'             => $stokSekarang,
                'jenisitem'        => (int) $l->IJENISITEM,
                'sdkaryawan'       => $l->SDKARYAWAN ? (int) $l->SDKARYAWAN : null,
                'sdkaryawan_label' => $l->SDKARYAWAN ? ($namaMap[(int) $l->SDKARYAWAN] ?? null) : null,
                'sddokter'         => $l->SDDOKTER ? (int) $l->SDDOKTER : null,
                'sddokter_label'   => $l->SDDOKTER ? ($namaMap[(int) $l->SDDOKTER] ?? null) : null,
                'sdnoref'          => $l->SDNOREF,
                'sdlantai2'        => $l->SDLANTAI2,
                'sdidpromo'        => $l->SDIDPROMO ? (int) $l->SDIDPROMO : null,
                'promo_label'      => $l->SDIDPROMO ? ($promoLabelMap[(int) $l->SDIDPROMO] ?? null) : null,
                // Heuristik tampilan saja (tidak ada kolom "is_bonus" di fstokd): baris hasil
                // "Item Bonus" promo Biasa SELALU disimpan SDHARGA=0 - kalau ada sdidpromo DAN
                // harga 0, anggap itu baris bonus. Jarang salah (harga 0 tanpa promo = bukan
                // bonus krn sdidpromo null; harga 0 DENGAN promo hampir selalu memang bonus).
                'is_bonus'         => $l->SDIDPROMO && (float) $l->SDHARGA === 0.0,
                'sddaripaket'      => (int) $l->SDDARIPAKET,
                'sdidpotongstok'   => $l->SDIDPOTONGSTOK ? (int) $l->SDIDPOTONGSTOK : null,
                'sdcatatankoli'    => $l->SDCATATANKOLI,
                'sdkedatangan'     => $l->SDKEDATANGAN !== null ? (int) $l->SDKEDATANGAN : null,
                'sdsodurutan'      => $l->SDSODURUTAN ? (int) $l->SDSODURUTAN : null,
                'paket_label'      => $l->SDIDPOTONGSTOK ? ($paketLabelMap[(int) $l->SDIDPOTONGSTOK] ?? null) : null,
                // Pasien SAAT INI (bukan disimpan di fstokd) - $header->SUKONTAK dipakai krn
                // pickCustomer() sudah dipanggil DULU dgn nilai yg sama persis sblm loop ini
                // (lihat komentar di atas), jadi custId skrg = pasien asli transaksi ini.
                'sdpaket_pasien'   => (int) $l->SDDARIPAKET === 1 ? (int) $header->SUKONTAK : null,
            ];
        }

        $this->catatan = $header->SUCATATAN;
        $this->rekamMedis = $header->SUREKAMMEDIS;

        // "Data Lainnya" - muat ulang 6 field FK (bkontak) + sisanya, lihat komentar
        // $showOtherDataModal. Label resolve 1x query utk keenamnya.
        $this->resetOtherData();
        $odIds = array_values(array_filter([
            $header->SUREKHUTANG, $header->SUFARMASI, $header->SUFARMASIASISTEN,
            $header->SUSALESMARKETING, $header->SUKLINIKLAIN, $header->SUTEMAN,
        ]));
        $odNamaMap = $odIds !== [] ? DB::table('bkontak')->whereIn('KID', $odIds)->pluck('KNAMA', 'KID') : collect();
        $this->suTraining = $header->SUREKHUTANG ? (int) $header->SUREKHUTANG : null;
        $this->suTrainingLabel = $this->suTraining ? ($odNamaMap[$this->suTraining] ?? null) : null;
        $this->suFarmasi = $header->SUFARMASI ? (int) $header->SUFARMASI : null;
        $this->suFarmasiLabel = $this->suFarmasi ? ($odNamaMap[$this->suFarmasi] ?? null) : null;
        $this->suFarmasiAsisten = $header->SUFARMASIASISTEN ? (int) $header->SUFARMASIASISTEN : null;
        $this->suFarmasiAsistenLabel = $this->suFarmasiAsisten ? ($odNamaMap[$this->suFarmasiAsisten] ?? null) : null;
        $this->suSalesMarketing = $header->SUSALESMARKETING ? (int) $header->SUSALESMARKETING : null;
        $this->suSalesMarketingLabel = $this->suSalesMarketing ? ($odNamaMap[$this->suSalesMarketing] ?? null) : null;
        $this->suKlinikLain = $header->SUKLINIKLAIN ? (int) $header->SUKLINIKLAIN : null;
        $this->suKlinikLainLabel = $this->suKlinikLain ? ($odNamaMap[$this->suKlinikLain] ?? null) : null;
        $this->suTeman = $header->SUTEMAN ? (int) $header->SUTEMAN : null;
        $this->suTemanLabel = $this->suTeman ? ($odNamaMap[$this->suTeman] ?? null) : null;
        $this->suKodeTele = $header->SUKODETELE;
        $this->suReviewNilai = $header->SUREVIEWNILAI !== null ? (float) $header->SUREVIEWNILAI : null;
        $this->suReviewCatatan = $header->SUREVIEWCATATAN;
        $this->suKonsulSaja = (int) $header->SUKONUL === 1;
        $this->suKonsulKemarin = (int) $header->SUKONSULKEMARIN === 1;
        $this->suIdMedlib = $header->SUIDMEDLIB ? (int) $header->SUIDMEDLIB : null;
        $this->suLmcId = $header->SULMCID ? (int) $header->SULMCID : null;

        // SUPROMO = gabungan kode promo dipisah ", " (bisa lebih dari 1, lihat $promos) - MPUID
        // asli tidak disimpan di fstoku, cuma teks kodenya, jadi dipecah balik dari string.
        $this->promos = $header->SUPROMO ? array_values(array_filter(array_map('trim', explode(',', $header->SUPROMO)))) : [];
        $this->activeKombinasiPromoId = null;
        $this->activeKombinasiKode = null;

        $this->pay = [
            'tunai'    => (float) $header->SUTOTALKAS,
            'debit'    => ['jumlah' => (float) $header->SUTOTALKARTUDEBIT, 'no' => $header->SUNOKARTUDEBIT ?? '', 'nama' => $header->SUNAMADEBIT ?? '', 'bank' => $header->SUBANKDEBIT, 'jenis' => $header->SUDEBITJENIS, 'bank_lain' => $header->SUATTENTION ?? ''],
            'kredit'   => ['jumlah' => (float) $header->SUTOTALKARTUKREDIT, 'no' => $header->SUNOKARTUKREDIT ?? '', 'nama' => $header->SUNAMAKREDIT ?? '', 'bank' => $header->SUBANKKREDIT, 'jenis' => $header->SUKREDITJENIS, 'bank_lain' => $header->SUNOFAKTURPAJAK ?? ''],
            'transfer' => ['jumlah' => (float) $header->SUTOTALTRANSFER, 'no' => $header->SUNOTRANSFER ?? '', 'nama' => $header->SUNAMATRANSFER ?? '', 'bank' => $header->SUBANKTRANSFER],
            'merchant' => ['jumlah' => (float) $header->SUMERCHANTJUMLAH, 'no' => $header->SUMERCHANTNO ?? '', 'jenis' => $header->SUMERCHANTJENIS],
            'voucher'  => [
                'jumlah'  => (float) $header->SUTOTALVOUCHER,
                'no'      => $header->SUNOVOUCHER ?? '',
                'vid'     => $header->SUSTATUSKIRIM ? (int) $header->SUSTATUSKIRIM : null,
                'program' => $header->SUPROGRAMVOUCHER,
                'nama'    => $header->SUNAMAVOUCHER,
            ],
            'dp' => [
                'jumlah'    => (float) $header->SUTOTALDP,
                'sdid'      => $header->SUDPID ? (int) $header->SUDPID : null,
                'no'        => $header->SUDPID ? (string) DB::table('fstokd as d')->join('fstoku as u', 'u.SUID', '=', 'd.SDIDSU')
                    ->where('d.SDID', $header->SUDPID)->value('u.SUNOTRANSAKSI') : null,
                'nama_item' => $header->SUDPID ? (string) DB::table('fstokd as d')->join('bitem as i', 'i.IID', '=', 'd.SDITEM')
                    ->where('d.SDID', $header->SUDPID)->value('i.INAMA') : null,
                'jenis'     => $header->SUJENISDP,
            ],
        ];

        $this->lastReceipt = [];
        $this->showHistoryModal = false;
        $this->editingFromId = $id;
        $this->editingFromNomor = $header->SUNOTRANSAKSI;

        activity_log('edit_start', 'sales/pos', $id, 'Transaksi ' . $header->SUNOTRANSAKSI . ' dimuat ulang ke keranjang utk diedit (belum dibatalkan - menunggu disimpan).');
    }

    public function render()
    {
        $branch = $this->branch();

        $itemResults = $this->itemSearchResults();
        $custResults = $this->custSearchResults();
        $operatorResults = $this->showOperatorModal ? $this->operatorSearchResults() : collect();
        $dokterResults = $this->showDokterModal ? $this->dokterSearchResults() : collect();
        $historyResults = $this->showHistoryModal ? $this->todayHistory() : collect();
        $voucherResults = $this->showVoucherModal ? $this->voucherSearchResults() : collect();
        $dpResults = $this->showDpModal ? $this->dpSearchResults() : collect();
        $promoResults = $this->showPromoModal ? $this->promoSearchResults() : collect();
        $paketResults = $this->showPaketModal ? $this->paketSearchResults() : collect();
        $kombinasiRows = $this->showKombinasiModal ? $this->kombinasiRows() : collect();

        // Baris "Terapkan Kombinasi": hitung harga & subtotal per slot (murni tampilan,
        // dihitung ulang tiap render supaya reaktif thd ganti item/qty - lihat komentar
        // serupa di PromoKombinasiForm::render()).
        $kombApplyRows = [];
        $kombApplyTotal = 0.0;
        if ($this->showKombinasiApplyModal) {
            foreach ($this->kombApply['slots'] ?? [] as $n => $slot) {
                $opt = collect($slot['options'])->firstWhere('kode', $slot['chosen'] ?? null);
                $harga = (float) ($opt['harga'] ?? 0);
                $qty = (float) ($slot['qty'] ?? 0);
                $d1 = (float) ($slot['d1'] ?? 0);
                $d2 = (float) ($slot['d2'] ?? 0);
                $subtotal = $qty * $harga * (1 - $d1 / 100) * (1 - $d2 / 100);
                $kombApplyTotal += $subtotal;
                $kombApplyRows[$n] = ['harga' => $harga, 'subtotal' => $subtotal];
            }
        }

        $biasaRows = $this->showBiasaModal ? $this->biasaRows() : collect();

        // Baris "Terapkan Promo" (Biasa): harga & subtotal preview utk slot "Jenis Item" (kalau
        // ada) - sama pola spt $kombApplyRows di atas, murni tampilan.
        $biasaApplyItem = null;
        if ($this->showBiasaApplyModal && ($this->biasaApply['item'] ?? null)) {
            $slot = $this->biasaApply['item'];
            $opt = collect($slot['options'])->firstWhere('kode', $slot['chosen'] ?? null);
            $harga = (float) ($opt['harga'] ?? 0);
            $qty = (float) ($slot['qty'] ?? 0);
            $d1 = (float) ($slot['d1'] ?? 0);
            $d2 = (float) ($slot['d2'] ?? 0);
            $biasaApplyItem = ['harga' => $harga, 'subtotal' => $qty * $harga * (1 - $d1 / 100) * (1 - $d2 / 100)];
        }

        $lineTotals = array_map(fn ($l) => $this->lineNet($l), $this->cart);
        $grand = round(array_sum($lineTotals), 2);
        $bayar = $this->totalBayar();

        return view('livewire.sales.pos-terminal', [
            'branch'      => $branch,
            'itemResults' => $itemResults,
            'custResults' => $custResults,
            'operatorResults' => $operatorResults,
            'dokterResults'   => $dokterResults,
            'historyResults'  => $historyResults,
            'voucherResults'  => $voucherResults,
            'dpResults'       => $dpResults,
            'promoResults'    => $promoResults,
            'paketResults'    => $paketResults,
            'kombinasiRows'   => $kombinasiRows,
            'kombApplyRows'   => $kombApplyRows,
            'kombApplyTotal'  => $kombApplyTotal,
            'biasaRows'       => $biasaRows,
            'biasaApplyItem'  => $biasaApplyItem,
            'lineTotals'  => $lineTotals,
            'grand'       => $grand,
            'bayar'       => $bayar,
            'kurang'      => round($grand - $bayar, 2),
            'banks'       => DB::table('bbank')->orderBy('BNAMA')->get(['BID', 'BNAMA']),
            'merchants'   => DB::table('bmerchant')->orderBy('MCNAMA')->get(['MCID', 'MCKODE', 'MCNAMA']),
            'jenisKartuDebit'  => self::JENIS_KARTU_DEBIT,
            'jenisKartuKredit' => self::JENIS_KARTU_KREDIT,
        ]);
    }
}
