<?php

namespace App\Livewire\Sales;

use App\Models\Branch;
use App\Models\User;
use App\Services\BankNama;
use App\Services\EmailStruk;
use App\Services\LegacyAuth;
use App\Services\PosSaleWriter;
use App\Services\Surgery;
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

    /** Kode item yg tidak dihitung poin - VB6 `xHitungTotalTanpaDP` (selain item jenis 14 = DP). */
    private const KODE_BUKAN_POIN = ['DP', 'DENDA MEMBER', 'SURCHARGE', 'CASH BACK', 'DP KONSUL'];

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
    /**
     * Filter cabang pencarian Operator & Dokter (permintaan user 2026-10-16): default cabang
     * aktif, dikembalikan ke cabang aktif TIAP kali dialog dibuka; '' = semua cabang. Kasir
     * boleh membuka cabang lain mis. utk dokter/perawat tamu.
     */
    public string $odCabang = '';
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
    /** 'aktif' | 'tidak_aktif' | null (bukan member) - utk warna label member di panel pasien. */
    public ?string $memberStatus = null;

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
     * `SUIDMEDLIB`/`SULMCID` ("ID Medlib"/"ID PRO") readonly DI MODAL INI - rujukan sistem
     * lain (`official_nmw.ops_medical.medId` dst). SULMCID diisi otomatis oleh Tarik PRO
     * (`pilihPro()`), bukan diketik.
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
        // Piutang (sisa yg belum dibayar) -> fstoku.SUNILAIPIUTANG. HANYA utk transaksi SURGERY
        // (lihat `$surgery`) - dicek `validasiPembayaran()`.
        'piutang' => 0,
    ];

    /*
     * ---------------- SURGERY (cicilan) ----------------
     * Alur VB6 `eFrmPOS2` (permintaan user 2026-10-16, aturan lengkap di `App\Services\Surgery`):
     *   - tiap nota surgery dicentang "Surgery"            -> SUSURGERYDP
     *   - nota pertama: tindakan lengkap, sisa = Piutang    -> SUNILAIPIUTANG
     *   - nota berikutnya: tombol "No IP Surgery" menarik nota surgery TERAKHIR pasien itu,
     *     menyalin itemnya, dan "sudah dibayar" sebelumnya ikut dihitung sbg pembayaran
     *       SUSURGERYDPIDU        = SUID nota yg ditarik
     *       SUSURGERYDPPEMBAYARAN = total - piutang nota yg ditarik (kumulatif)
     *       SUSURGERYDPTOTAL      = total nota yg ditarik
     *       SUSURGERYDPPIUTANG    = piutang nota yg ditarik
     *   - nota lunas (piutang 0) = hari operasi.
     */
    public bool $surgery = false;
    public ?int $surgeryIdu = null;
    public ?string $surgeryNo = null;
    public float $surgeryTotal = 0;
    public float $surgeryPembayaran = 0;
    public float $surgeryPiutang = 0;
    public bool $showSurgeryModal = false;

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

    /**
     * Kolom HARGA JUAL utk cabang aktif - SATU-SATUNYA sumber harga item di POS.
     *
     * Cabang Marketplace (`config('pos.cabang_marketplace')`, GID 18) memakai harga khusus
     * marketplace `bitem2.I2HARGAJUALMP` (diisi lewat menu CI3 "Update Harga Marketplace"),
     * cabang lain `bitem.IHARGAJUAL1`. Aturan disalin dari CI3 `PJ_POS_HP` (get_item, paket,
     * detail promo) - permintaan user 2026-10-15.
     *
     * Item TANPA harga marketplace -> 0, SAMA dgn CI3 (pilihan user, bukan jatuh ke
     * IHARGAJUAL1): kasir buka kunci harga & isi manual.
     *
     * Alias default `IHARGAJUAL1` SENGAJA dipertahankan supaya pemakai lama (`$it->IHARGAJUAL1`)
     * tidak perlu diubah satu per satu - isinya sudah harga yg berlaku utk cabang ini.
     * `MAX()` krn `bitem2` punya segelintir baris ganda per item (lihat `DashboardPenjualan::
     * jumlahBaris()`). Butuh tabel `bitem` TANPA alias di kueri pemanggil.
     */
    private function kolomHarga(string $alias = 'IHARGAJUAL1')
    {
        if ((int) $this->branchId !== (int) config('pos.cabang_marketplace')) {
            return DB::raw("bitem.IHARGAJUAL1 AS {$alias}");
        }

        return DB::raw('COALESCE((SELECT MAX(b2.I2HARGAJUALMP) FROM bitem2 b2 WHERE b2.I2IDITEM = bitem.IID), 0)'
            . " AS {$alias}");
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
            ->get(['IID as id', 'IKODE as kode', 'INAMA as nama', $this->kolomHarga('harga'), 'ITIPEITEM as tipe', DB::raw("{$stokCol} AS stok")]);
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
            ->get(['k.KID as id', 'k.KKODE as kode', 'k.KIDPASIEN as idpasien', 'k.KNAMA as nama', 'k.K1TELP1 as telp', 't.KTNAMA as tipe', 'g.GKODE as cabang']);
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
        )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', 'IDISKON', DB::raw("{$stokCol} AS stok")]);

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
        $this->odCabang = (string) ($this->branchId ?? '');
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

    public function updatedOdCabang(): void
    {
        $this->odHighlight = 0;
        $this->dispatch('refocus-od');
    }

    /**
     * Pencarian Operator & Dokter DISARING per cabang (`bkontak.KCABANG`) - permintaan user
     * 2026-09-30, dilonggarkan 2026-10-16: default tetap cabang aktif kasir, tapi kasir bisa
     * memilih cabang lain / "Semua cabang" lewat `$odCabang` (mis. dokter tamu).
     *
     * **BEDA DISENGAJA dari VB6**: di sana (`eFrmPOS.frm` / `eFrmPOS2Edit.frm`,
     * `bFrmCariKontak.lblFilterTambahan`) filternya HANYA
     * `coalesce(KTAMPILDIDOKTER,0)=1` / `...DIPERAWAT...` - **tanpa** batasan cabang, jadi
     * kasir Bali bisa memilih perawat Petogogan. Ini penambahan, bukan port.
     *
     * Aman diterapkan ketat: diperiksa di data nyata, **SELURUH** operator (223) & dokter (59)
     * yg aktif punya `KCABANG` terisi dan menunjuk `bgudang.GID` yg ADA - tidak ada satu pun
     * yg akan hilang gara-gara filter ini. Kalau suatu saat ada yg `KCABANG`-nya kosong, dia
     * TIDAK akan muncul di cabang mana pun - perbaikannya di master kontak, bukan dgn
     * melonggarkan filter di sini.
     *
     * `$this->branchId` bisa `null` (cabang user tidak aktif di `bgudang`) - saat itu filter
     * DILEWATI, bukan mencocokkan `KCABANG = NULL` yg pasti nihil. Transaksinya toh tetap
     * tidak bisa disimpan tanpa cabang (lihat `checkout()`), jadi tidak ada risiko data salah.
     *
     * @return \Illuminate\Support\Collection
     */
    private function operatorSearchResults()
    {
        return $this->cariPetugas('KTAMPILDIPERAWAT');
    }

    /** @return \Illuminate\Support\Collection */
    private function dokterSearchResults()
    {
        return $this->cariPetugas('KTAMPILDIDOKTER');
    }

    /**
     * Badan bersama Operator & Dokter - keduanya HANYA beda kolom flag. Disatukan supaya
     * filter cabangnya mustahil terpasang di salah satu saja.
     *
     * @return \Illuminate\Support\Collection
     */
    private function cariPetugas(string $kolomFlag)
    {
        $q = trim($this->odQ);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        /* JANGAN pakai `Contact::applySearch()` di sini. Ia memotong kandidat di 300 nama dari
         | SELURUH bkontak (321rb, mayoritas pasien) SEBELUM saringan petugas diterapkan -
         | mencari "siska" cocok 558 kontak, dan "dr. Siska Rahmawati" tidak masuk 300 yg
         | terambil, jadi tidak pernah muncul walau datanya benar (dilaporkan 2026-10-17).
         |
         | `LIKE '%kata%'` AMAN di sini - pengecualian sadar dari aturan "jangan leading
         | wildcard di bkontak": query digerakkan index `I_bkontak_u` (KTIPE), dan KTIPE=4
         | cuma ~1.300 baris (EXPLAIN: type=ref, rows 1328), bukan 321rb. Sekaligus menangani
         | "dr" (2 huruf, diabaikan FULLTEXT) & potongan di tengah nama ("rahma"). */
        $kata = array_values(array_filter(preg_split('/[\s.,]+/u', $q), fn ($w) => $w !== ''));

        return DB::table('bkontak as k')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'k.KCABANG')
            ->where('k.KTIPE', 4)
            ->where('k.KAKTIF', '<>', 0)
            ->where('k.' . $kolomFlag, 1)
            ->when((int) $this->odCabang, fn ($b) => $b->where('k.KCABANG', (int) $this->odCabang))
            ->where(function ($w) use ($kata, $q) {
                $w->where(function ($n) use ($kata) {
                    foreach ($kata as $k) {
                        $n->where('k.KNAMA', 'like', '%' . addcslashes($k, '%_\\') . '%');
                    }
                })->orWhere('k.KKODE', 'like', addcslashes($q, '%_\\') . '%');
            })
            ->orderBy('k.KNAMA')
            ->limit(15)
            ->get(['k.KID as id', 'k.KKODE as kode', 'k.KNAMA as nama', 'g.GNAMA as cabang']);
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
        $this->odCabang = (string) ($this->branchId ?? '');
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
        $this->resetSurgery();
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

        // Nota surgery yg ditarik milik pasien lama - tidak berlaku utk pasien lain.
        if ($this->surgeryIdu && (int) $row->KID !== (int) $this->custId) {
            $this->resetSurgeryLink();
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
        $this->memberStatus = $this->memberActive ? 'aktif' : ($row->KTIPE == 12 ? 'tidak_aktif' : null);

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
        $this->memberStatus = null;
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
            'piutang'  => 0,
        ];
    }

    /* ---------------- Surgery ---------------- */

    /** Lepas tautan ke nota surgery sebelumnya (centang Surgery-nya sendiri dibiarkan). */
    private function resetSurgeryLink(): void
    {
        $this->surgeryIdu = null;
        $this->surgeryNo = null;
        $this->surgeryTotal = 0;
        $this->surgeryPembayaran = 0;
        $this->surgeryPiutang = 0;
    }

    private function resetSurgery(): void
    {
        $this->resetSurgeryLink();
        $this->surgery = false;
        $this->showSurgeryModal = false;
    }

    /** Centang Surgery dilepas -> tautan & piutang ikut hilang (keduanya khusus surgery). */
    public function updatedSurgery(bool $nilai): void
    {
        if (! $nilai) {
            $this->resetSurgeryLink();
            $this->pay['piutang'] = 0;
        }
    }

    /** Tombol "Lepas" - batalkan tarikan No IP Surgery (isi keranjang dibiarkan). */
    public function lepasSurgery(): void
    {
        $this->resetSurgeryLink();
    }

    /** Tombol "Sisa → Piutang" di dialog bayar - sisa tagihan saat ini dijadikan piutang. */
    public function isiPiutangSisa(): void
    {
        if (! $this->surgery) {
            return;
        }
        $lain = $this->totalBayar() - (float) $this->pay['piutang'];
        $this->pay['piutang'] = max(0, round($this->grandTotal() - $lain, 2));
    }

    /* ---------------- Farmasi default per cabang (lv_cabang_pref) ----------------
     * Permintaan user 2026-10-16: Farmasi & Farmasi Asisten selalu diisi utk penjualan
     * produk -> kartu "Farmasi" di panel kanan (dipindah dari Data Lainnya) + tombol
     * "Isi default cabang". Default diatur pemegang hak Approve Kasir/POS.
     */

    /** @return object{farmasi:?int,farmasiLabel:?string,asisten:?int,asistenLabel:?string}|null */
    private function farmasiDefault(): ?object
    {
        if (! $this->branchId) {
            return null;
        }
        $p = DB::table('lv_cabang_pref')->where('gid', $this->branchId)->first(['farmasi_kid', 'farmasi_asisten_kid']);
        if (! $p || (! $p->farmasi_kid && ! $p->farmasi_asisten_kid)) {
            return null;
        }

        return (object) [
            'farmasi'      => $p->farmasi_kid ? (int) $p->farmasi_kid : null,
            'farmasiLabel' => $this->labelKaryawan($p->farmasi_kid),
            'asisten'      => $p->farmasi_asisten_kid ? (int) $p->farmasi_asisten_kid : null,
            'asistenLabel' => $this->labelKaryawan($p->farmasi_asisten_kid),
        ];
    }

    /** Label spt hasil lookup karyawan: "Nama — Kode". */
    private function labelKaryawan($kid): ?string
    {
        if (! $kid) {
            return null;
        }
        $k = DB::table('bkontak')->where('KID', $kid)->first(['KNAMA', 'KKODE']);

        return $k ? trim($k->KNAMA . ($k->KKODE ? ' — ' . $k->KKODE : '')) : null;
    }

    /** Tombol "Isi default cabang" - Farmasi & Asisten diisi default cabang aktif. */
    public function isiFarmasiDefault(): void
    {
        $d = $this->farmasiDefault();
        if (! $d) {
            $this->dispatch('pos-toast', msg: 'Default farmasi cabang ini belum diatur.', type: 'warning');

            return;
        }
        $this->suFarmasi = $d->farmasi;
        $this->suFarmasiLabel = $d->farmasiLabel;
        $this->suFarmasiAsisten = $d->asisten;
        $this->suFarmasiAsistenLabel = $d->asistenLabel;
    }

    /** "Jadikan default cabang" - isian sekarang disimpan sbg default cabang aktif. */
    public function simpanFarmasiDefault(): void
    {
        abort_unless(can_do('sales/pos', 'approve'), 403);
        if (! $this->branchId) {
            return;
        }
        if (! $this->suFarmasi && ! $this->suFarmasiAsisten) {
            $this->dispatch('pos-toast', msg: 'Isi Farmasi / Farmasi Asisten dulu.', type: 'warning');

            return;
        }
        DB::table('lv_cabang_pref')->updateOrInsert(['gid' => $this->branchId], [
            'farmasi_kid'         => $this->suFarmasi ?: null,
            'farmasi_asisten_kid' => $this->suFarmasiAsisten ?: null,
            'updated_by'          => auth()->id(),
            'updated_at'          => now(),
        ]);
        activity_log('update', 'sales/pos', (string) $this->branchId, 'Default farmasi cabang: '
            . ($this->labelKaryawan($this->suFarmasi) ?? '-') . ' / asisten ' . ($this->labelKaryawan($this->suFarmasiAsisten) ?? '-'));
        $this->dispatch('pos-toast', msg: 'Default farmasi cabang disimpan.', type: 'success');
    }

    public function openSurgeryModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pasien dulu sebelum menarik No IP Surgery.');
            $this->openCustModal();

            return;
        }
        $this->showSurgeryModal = true;
    }

    public function closeSurgeryModal(): void
    {
        $this->showSurgeryModal = false;
    }

    /**
     * Nota surgery pasien ini yg BISA dilanjutkan: dicentang Surgery, tidak batal, masih ada
     * piutang, dan belum dilanjutkan nota lain (= ujung rantai). Semua cabang, sama dgn VB6
     * (`chkSemuaCabang`).
     *
     * LEBIH KETAT dari VB6, yg menampilkan SEMUA nota surgery pasien termasuk yg sudah
     * dilanjutkan/lunas - di data nyata itu membuat nota yg sama bisa ditarik dua kali.
     * Nota yg sedang di-Edit tidak dihitung sbg "lanjutan" - menyimpan ulang nota B tetap
     * harus bisa menarik nota A yg dulu ditariknya.
     */
    private function surgeryResults()
    {
        if (! $this->custId) {
            return collect();
        }

        return DB::table('fstoku as u')
            ->leftJoin('bgudang as g', 'g.GID', '=', 'u.SUCABANG')
            ->where('u.SUSUMBER', 'IP')
            ->where('u.SUSTATUS', '<>', 9)
            ->where('u.SUKONTAK', $this->custId)
            ->whereRaw(Surgery::kondisiCicilan('u'))
            ->when($this->editingFromId, fn ($b) => $b->where('u.SUID', '<>', $this->editingFromId))
            ->whereNotExists(fn ($q) => $q->from('fstoku as n')
                ->whereColumn('n.SUSURGERYDPIDU', 'u.SUID')
                ->where('n.SUSTATUS', '<>', 9)
                ->when($this->editingFromId, fn ($w) => $w->where('n.SUID', '<>', $this->editingFromId)))
            ->orderByDesc('u.SUTANGGAL')
            ->orderByDesc('u.SUID')
            ->get([
                'u.SUID as id', 'u.SUNOTRANSAKSI as nomor', 'u.SUTANGGAL as tanggal', 'g.GNAMA as cabang',
                'u.SUTOTALTRANSAKSI as total', 'u.SUNILAIPIUTANG as piutang',
                DB::raw('(u.SUTOTALTRANSAKSI - u.SUNILAIPIUTANG) as dibayar'),
            ]);
    }

    /**
     * Tarik nota surgery: keranjang DIGANTI item nota itu (VB6 juga mengosongkan grid), lalu
     * "sudah dibayar" sebelumnya dihitung sbg pembayaran. Pasien sudah terpilih, jadi
     * diskon asli baris tidak ketiban diskon member (lihat komentar di `editTransaction()`).
     */
    public function pilihSurgery(int $suid): void
    {
        $nota = $this->surgeryResults()->firstWhere('id', $suid);
        if (! $nota) {
            $this->addError('surgery', 'Nota surgery tidak bisa ditarik (sudah dilanjutkan, lunas, atau bukan milik pasien ini).');

            return;
        }

        // ID asal Tarik Medlib TIDAK ikut tersalin: invoice/resep itu sudah tercatat di nota
        // sebelumnya - kalau dibawa, nota ini ditolak "invoice sudah masuk DIAS".
        $this->cart = array_map(fn ($l) => array_merge($l,
            ['sdsodid' => null, 'sdprdid' => null, 'sdmedidu' => null, 'sdmedidd' => null, 'medlib' => false,
                'sdpro' => null, 'sdprorecom' => null, 'sdproidu' => null, 'sdproidd' => null, 'pro' => false]),
            $this->barisDariTransaksi($suid, (int) $this->custId));
        $this->odQueue = [];
        $this->unlockedLines = [];

        $this->surgery = true;
        $this->surgeryIdu = (int) $nota->id;
        $this->surgeryNo = $nota->nomor;
        $this->surgeryTotal = (float) $nota->total;
        $this->surgeryPiutang = (float) $nota->piutang;
        $this->surgeryPembayaran = (float) $nota->dibayar;
        $this->pay['piutang'] = 0;
        $this->showSurgeryModal = false;
    }

    /* ---------------- Tarik Medlib (official_nmw) ----------------
     * Port VB6 `eFrmPOS2.cmdTarikMedlib_Click` -> `bFrmCariMedlib` (permintaan user 2026-10-16).
     * Dua sumber dari DB `official_nmw` (aplikasi Medlib/online):
     *   'invoice' = invoice yg SUDAH DIBAYAR online (Jokul) & belum masuk DIAS
     *               (`ivhStatusDIAS = 0`), pasien & cabang aktif.
     *   'resep'   = resep rekam medis yg BELUM dibayar, maks 1 bulan terakhir.
     * Item yg ditarik membawa ID asalnya (lihat `pilihMedlib()`), dan trigger `fstokd_add`
     * menandai invoice-nya `ivhStatusDIAS = 1` lewat `SDPRDID` - jadi invoice yg sudah
     * disimpan tidak muncul lagi.
     */
    public bool $showMedlibModal = false;
    public string $medlibTab = 'invoice';
    /** Baris yg dicentang - kunci `ivdId` (tab invoice) / `mdcId` (tab resep). @var list<string> */
    public array $medlibPilih = [];

    /** Tipe reservasi yg membuat baris resep = "RESEP SKIN CARE DKK" (VB6 TampilkanDetail_Medlib). */
    private const MEDLIB_TIPE_DKK = [1, 2, 3, 5, 6];

    public function openMedlibModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pasien dulu sebelum Tarik Medlib.');
            $this->openCustModal();

            return;
        }
        $this->medlibPilih = [];
        $this->medlibTab = 'invoice';
        $this->showMedlibModal = true;
    }

    public function closeMedlibModal(): void
    {
        $this->showMedlibModal = false;
        $this->medlibPilih = [];
    }

    public function updatedMedlibTab(): void
    {
        $this->medlibPilih = [];
    }

    /** ID asal yg SUDAH ada di keranjang - supaya baris yg sama tidak ditarik dua kali. */
    private function medlibDiKeranjang(string $kolom): array
    {
        return array_values(array_filter(array_map(fn ($l) => $l[$kolom] ?? null, $this->cart)));
    }

    /**
     * Invoice SUDAH DIBAYAR & belum masuk DIAS - kueri dari VB6 `bFrmCariMedlib.IsiData`
     * (grid IG_Produk).
     *
     * HARGA = HARGA INVOICE (`ivdPrice`), BUKAN harga master spt VB6 (keputusan user
     * 2026-10-16). VB6 memakai IHARGAJUAL1/IHARGAWEB + diskon %, padahal di SEMUA invoice
     * lunas sejak Sep 2026 (108 baris) harga invoice beda dgn master - 19 di antaranya
     * master-nya 0 (mis. KONSUL BEDAH: invoice 350.000, master 0). Akibatnya total keranjang
     * tidak sama dgn yg dibayar online & muncul "kembalian" palsu. Grand total invoice =
     * harga item + ongkir (108/108), jadi dgn harga invoice totalnya pas.
     */
    private function medlibInvoiceRows()
    {
        if (! $this->custId || ! $this->branchId) {
            return collect();
        }
        $sudah = $this->medlibDiKeranjang('sdsodid');

        return DB::table('official_nmw.ops_invoice_detail as d')
            ->join('official_nmw.ops_invoice_header as h', 'h.ivhId', '=', 'd.ivdIvhId')
            ->join('bitem as i', 'i.IID', '=', 'd.ivdIID')
            ->join('official_nmw.ops_payment as p', 'p.payIvhCode', '=', 'h.ivhCode')
            ->join('official_nmw.ops_medical as m', 'm.medId', '=', 'h.ivhMedId')
            ->join('official_nmw.data_users as us', 'us.usrId', '=', 'm.medCreateId')
            ->join('bkontak as dr', 'dr.KID', '=', 'us.usrKID')
            ->leftJoin('official_nmw.ops_reservation as r', 'r.rsvMedId', '=', 'm.medId')
            ->leftJoin('official_nmw.ops_reservation_type as rt', 'rt.rtpId', '=', 'r.rsvRtpId')
            ->where('h.ivhGID', $this->branchId)
            ->where('p.payStatus', 1)
            ->where('h.ivhStatusDIAS', 0)
            ->where('h.ivhKID', $this->custId)
            ->when($sudah !== [], fn ($b) => $b->whereNotIn('d.ivdId', $sudah))
            ->orderBy('h.ivhDate')
            ->orderBy('d.ivdId')
            ->get([
                'd.ivdId as kunci', 'h.ivhId', 'm.medCode as noResep', 'i.IID as item', 'i.IKODE as kode', 'i.INAMA as nama',
                'd.ivdQty as qty', 'h.ivhDate as tanggal', 'rt.rtpCode as tipeKonsul', 'r.rsvRtpId as tipeId',
                'dr.KID as dokterId', 'dr.KNAMA as dokter', 'h.ivhCode as noInvoice', 'h.ivhReff as reff',
                'd.ivdPrice as harga',
                DB::raw('0 as diskonPersen'),
                DB::raw("CASE WHEN h.ivhShippingPrice = '-' THEN 0 ELSE COALESCE(h.ivhShippingPrice, 0) END as ongkir"),
                DB::raw('COALESCE(h.ivhServiceFee, 0) as biayaLayanan'),
                'h.ivhGrandTotal as total',
            ]);
    }

    /**
     * Resep rekam medis BELUM dibayar (maks 1 bulan) - kueri disalin dari VB6
     * `bFrmCariMedlib.IsiData` (grid IG_M). Diskon member hanya kalau pasien member aktif.
     */
    private function medlibResepRows()
    {
        if (! $this->custId) {
            return collect();
        }
        $sudah = $this->medlibDiKeranjang('sdmedidd');

        return DB::table('official_nmw.ops_medical as m')
            ->leftJoin('official_nmw.ops_medical_medicine as mm', 'mm.mdcMedId', '=', 'm.medId')
            ->leftJoin('bitem as i', 'i.IID', '=', 'mm.mdcIID')
            ->leftJoin('official_nmw.data_users as us', 'us.usrId', '=', 'm.medCreateId')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'us.usrKID')
            ->leftJoin('official_nmw.ops_invoice_header as h', 'h.ivhMedId', '=', 'm.medId')
            ->leftJoin('official_nmw.ops_reservation as r', 'r.rsvMedId', '=', 'm.medId')
            ->leftJoin('official_nmw.ops_reservation_type as rt', 'rt.rtpId', '=', 'r.rsvRtpId')
            ->leftJoin('official_nmw.ops_payment as p', 'p.payIvhCode', '=', 'h.ivhCode')
            ->leftJoin('official_nmw.ops_reservation_date as rd', 'rd.rsdId', '=', 'r.rsvRsdId')
            ->leftJoin('official_nmw.ops_invoice_detail as d', 'd.ivdIvhId', '=', 'h.ivhId')
            ->whereRaw("COALESCE(i.IKODE, '') <> ''")
            ->whereRaw('(COALESCE(p.payStatus, 0) = 0 OR (COALESCE(p.payStatus, 0) = 1 AND COALESCE(d.ivdIID, 0) = 2560))')
            ->where('m.medKID', $this->custId)
            ->whereRaw('TIMESTAMPDIFF(MONTH, rd.rsdDate, NOW()) <= 1')
            ->when($sudah !== [], fn ($b) => $b->whereNotIn('mm.mdcId', $sudah))
            ->orderBy('rd.rsdDate')
            ->orderBy('mm.mdcId')
            ->get([
                'mm.mdcId as kunci', 'm.medId', 'm.medCode as noResep', 'i.IID as item', 'i.IKODE as kode', 'i.INAMA as nama',
                'mm.mdcQty as qty', 'rd.rsdDate as tanggal', 'rt.rtpCode as tipeKonsul', DB::raw('COALESCE(rt.rtpDKK, 0) as dkk'),
                'dr.KID as dokterId', 'dr.KNAMA as dokter', 'h.ivhCode as noInvoice', 'i.IHARGAJUAL1 as harga',
                DB::raw('COALESCE(i.IDISKON, 0) as diskonMember'),
            ])
            // ops_invoice_detail ikut di-join utk syarat item 2560 - satu resep bisa
            // berlipat per baris invoice. VB6 menampilkan apa adanya; di sini dibuat unik.
            ->unique('kunci')->values();
    }

    public function pilihSemuaMedlib(): void
    {
        $rows = $this->medlibTab === 'invoice' ? $this->medlibInvoiceRows() : $this->medlibResepRows();
        $semua = $rows->pluck('kunci')->map(fn ($k) => (string) $k)->all();
        $this->medlibPilih = count($this->medlibPilih) === count($semua) ? [] : $semua;
    }

    /**
     * Masukkan baris terpilih ke keranjang - port VB6 `TampilkanDetail_Medlib` (invoice) &
     * `TampilkanDetail_Medlib_BelumInvoice` (resep):
     *   - tiap produk -> baris item dgn harga/diskon dari Medlib + ID asalnya:
     *       invoice: SDSODID = ivdId, SDPRDID = ivhId (trigger menandai invoice masuk DIAS)
     *       resep  : SDMEDIDU = medId, SDMEDIDD = mdcId
     *   - 1 baris "RESEP SKIN CARE" (atau "… DKK"), qty = jumlah qty produk, dokter/operator
     *     = dokter pembuat resep, No Ref = no resep, No IC = "000"
     *   - ONGKIR kalau ada, + "BIAYA LAYANAN" (tab invoice saja, sama dgn VB6)
     *   - invoice sudah dibayar -> Merchant DOKU = grand total, no. merchant = no. invoice
     *   - insider (`SUSALESMARKETING`) dari kode reff invoice (`bkontak.KKODEINSIDER`)
     * Produk juga diberi dokter/No Ref yg sama supaya lolos validasi operator/dokter POS.
     */
    public function pilihMedlib(): void
    {
        $tab = $this->medlibTab;
        $rows = ($tab === 'invoice' ? $this->medlibInvoiceRows() : $this->medlibResepRows())
            ->filter(fn ($r) => in_array((string) $r->kunci, $this->medlibPilih, true))->values();

        if ($rows->isEmpty()) {
            $this->addError('medlib', 'Centang minimal satu baris.');

            return;
        }

        $branch = $this->branch();
        $stokCol = $branch ? $this->stokColumn((int) $branch->GID) : 'ISTOKPG';
        $meta = DB::table('bitem')->whereIn('IID', $rows->pluck('item')->unique()->all())
            ->get(['IID', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")])->keyBy('IID');

        $akhir = $rows->last();
        $dokterId = $akhir->dokterId ? (int) $akhir->dokterId : null;
        $dokterNama = $akhir->dokter;
        $noResep = (string) $akhir->noResep;

        foreach ($rows as $r) {
            $m = $meta[(int) $r->item] ?? null;
            $dis1 = $tab === 'invoice'
                ? (float) $r->diskonPersen
                : ($this->memberActive ? (float) $r->diskonMember : 0.0);

            $this->cart[] = $this->barisMedlib((int) $r->item, (string) $r->kode, (string) $r->nama, (float) $r->qty,
                (float) $r->harga, $dis1, $m, $dokterId, $dokterNama, $noResep) + ($tab === 'invoice'
                    ? ['sdsodid' => (int) $r->kunci, 'sdprdid' => (int) $r->ivhId]
                    : ['sdmedidd' => (int) $r->kunci, 'sdmedidu' => (int) $r->medId]);
        }

        // Baris "RESEP SKIN CARE" - tipe DKK menurut reservasi baris TERAKHIR (spt VB6).
        $dkk = $tab === 'invoice'
            ? in_array((int) $akhir->tipeId, self::MEDLIB_TIPE_DKK, true)
            : (int) $akhir->dkk === 1;
        $this->tambahItemPendukung($dkk ? 'RESEP SKIN CARE DKK' : 'RESEP SKIN CARE', (float) $rows->sum('qty'),
            null, $dokterId, $dokterNama, $noResep);

        $ongkir = $tab === 'invoice' ? (float) $akhir->ongkir : 0.0;
        if ($ongkir > 0) {
            $this->tambahItemPendukung('ONGKIR', 1, $ongkir, $dokterId, $dokterNama, $noResep);
        }
        if ($tab === 'invoice') {
            // BIAYA LAYANAN hanya kalau invoice memang mengenakannya, sebesar nilai invoice -
            // VB6 selalu menambah harga master (2.000) sehingga tagihan melebihi yg dibayar
            // online. Per Okt 2026 `ivhServiceFee` selalu 0.
            if ((float) $akhir->biayaLayanan > 0) {
                $this->tambahItemPendukung('BIAYA LAYANAN', 1, (float) $akhir->biayaLayanan, $dokterId, $dokterNama, $noResep);
            }

            // Sudah dibayar online -> Merchant DOKU sebesar grand total invoice.
            $this->pay['merchant'] = ['jumlah' => (float) $akhir->total, 'no' => (string) $akhir->noInvoice, 'jenis' => 'DOKU'];

            if ($akhir->reff) {
                $insider = DB::table('bkontak')->where('KKODEINSIDER', $akhir->reff)->first(['KID', 'KNAMA']);
                if ($insider) {
                    $this->suSalesMarketing = (int) $insider->KID;
                    $this->suSalesMarketingLabel = $insider->KNAMA;
                } else {
                    $this->dispatch('pos-toast', msg: 'Insider "' . $akhir->reff . '" tidak terdaftar.', type: 'warning');
                }
            }
        }

        $this->closeMedlibModal();
        $this->dispatch('pos-toast', msg: $rows->count() . ' item Medlib ditambahkan ke keranjang.', type: 'success');
    }

    /** Satu baris keranjang dari Medlib - kerangka kunci sama dgn `addItem()`. */
    private function barisMedlib(int $item, string $kode, string $nama, float $qty, float $harga, float $dis1, ?object $m,
        ?int $dokterId, ?string $dokterNama, string $noResep): array
    {
        return [
            'item' => $item, 'kode' => $kode, 'nama' => $nama, 'qty' => $qty, 'harga' => $harga,
            'dis1' => $dis1, 'dis2' => 0.0,
            'satuan' => $m && $m->ISATUAN ? (int) $m->ISATUAN : null,
            'tipe' => $m ? (int) $m->ITIPEITEM : 1, 'stok' => $m ? (float) $m->stok : 0.0,
            'jenisitem' => $m ? (int) $m->IJENISITEM : 0,
            'sdkaryawan' => $dokterId, 'sdkaryawan_label' => $dokterNama,
            'sddokter' => $dokterId, 'sddokter_label' => $dokterNama,
            'sdnoref' => $noResep, 'sdlantai2' => '000',
            'sdidpromo' => null, 'promo_label' => null, 'is_bonus' => false,
            'sddaripaket' => 0, 'sdidpotongstok' => null, 'sdcatatankoli' => null, 'sdkedatangan' => null,
            'sdsodurutan' => null, 'paket_label' => null, 'sdpaket_pasien' => null,
            'medlib' => true,
        ];
    }

    /** RESEP SKIN CARE / ONGKIR / BIAYA LAYANAN - harga master kecuali `$harga` diisi. */
    private function tambahItemPendukung(string $kode, float $qty, ?float $harga, ?int $dokterId, ?string $dokterNama, string $noResep): void
    {
        $branch = $this->branch();
        $stokCol = $branch ? $this->stokColumn((int) $branch->GID) : 'ISTOKPG';
        $it = DB::table('bitem')->where('IKODE', $kode)
            ->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);
        if (! $it) {
            $this->dispatch('pos-toast', msg: 'Item "' . $kode . '" tidak ada di master item.', type: 'warning');

            return;
        }
        $this->cart[] = $this->barisMedlib((int) $it->IID, $it->IKODE, $it->INAMA, $qty,
            $harga ?? (float) $it->IHARGAJUAL1, 0.0, $it, $dokterId, $dokterNama, $noResep);
    }

    /* ---------------- Tarik PRO (keranjang rekomendasi PRO, official_nmw) ----------------
     * Port VB6 `eFrmPOS2.cmdPRO_Click` -> `bFrmCariPRO` (permintaan user 2026-10-16). Sumber:
     * `official_nmw.log_medlib_cart` (keranjang) + `log_medlib_detail` (baris), milik pasien,
     * belum masuk DIAS (`lmcDiasIP` kosong), dipilih (`lmdSelected`), tidak ditolak dokter.
     *   'bayar' = keranjang SUDAH dibayar online  'belum' = belum dibayar
     * Penanda "sudah masuk DIAS": header `SULMCID` = lmcId -> trigger `fstoku_add` mengisi
     * `lmcDiasIP`. Trigger hanya menandai SATU keranjang per nota, jadi satu transaksi
     * hanya boleh menarik satu keranjang PRO.
     *
     * Arti kolom harga (terukur 1.249/1.249 baris sejak Sep 2026): `lmdDiscount` = harga
     * per unit SETELAH diskon (namanya menyesatkan), `lmdGrandPrice` = qty x lmdDiscount.
     * Harga dasar = `lmdPrice` (BUKAN harga master spt VB6 - keputusan user utk Medlib,
     * berlaku sama di sini), Disc % = (lmdPrice - harga setelah diskon) / lmdPrice.
     */
    public bool $showProModal = false;
    public string $proTab = 'belum';
    /** @var list<string> lmdId yg dicentang */
    public array $proPilih = [];

    public function openProModal(): void
    {
        if (! $this->custId) {
            $this->addError('cust', 'Pilih pasien dulu sebelum Tarik PRO.');
            $this->openCustModal();

            return;
        }
        $this->proPilih = [];
        $this->showProModal = true;
    }

    public function closeProModal(): void
    {
        $this->showProModal = false;
        $this->proPilih = [];
    }

    public function updatedProTab(): void
    {
        $this->proPilih = [];
    }

    private function proRows()
    {
        if (! $this->custId) {
            return collect();
        }
        $sudah = $this->medlibDiKeranjang('sdproidd');

        return DB::table('official_nmw.log_medlib_cart as b')
            ->join('official_nmw.log_medlib_detail as d', 'd.lmdCartId', '=', 'b.lmcId')
            ->join('bitem as e', 'e.IID', '=', 'd.lmdItemId')
            ->leftJoin('official_nmw.ops_medical as a', 'a.medId', '=', 'b.lmcMedicalId')
            ->leftJoin('official_nmw.data_users as udok', 'udok.usrId', '=', 'a.medCreateId')
            ->leftJoin('bkontak as dr', 'dr.KID', '=', 'udok.usrKID')
            ->leftJoin('emasterpromod as pmd', fn ($j) => $j->on('pmd.MPDID', '=', 'd.lmdItemTypeId')->where('d.lmdItemType', 'promo'))
            ->leftJoin('emasterpromou as pmu', 'pmu.MPUID', '=', 'pmd.MPDIDU')
            ->leftJoin('epaketd as pd', fn ($j) => $j->on('pd.PDID', '=', 'd.lmdItemTypeId')->where('d.lmdItemType', 'package'))
            ->leftJoin('epaketu as pu', 'pu.PUID', '=', 'pd.PDIDU')
            ->leftJoin('official_nmw.data_users as upro', 'upro.usrId', '=', 'b.lmcCreateId')
            ->leftJoin('bkontak as pro', 'pro.KID', '=', 'upro.usrKID')
            ->whereRaw("COALESCE(b.lmcDiasIP, '') = ''")
            ->where('b.lmcPaymentStatus', $this->proTab === 'bayar' ? 1 : 0)
            ->where('d.lmdSelected', 1)
            ->whereRaw('COALESCE(d.lmdApprovalDokter, 1) = 1')
            ->where('b.lmcPatientKID', $this->custId)
            ->when($sudah !== [], fn ($q) => $q->whereNotIn('d.lmdId', $sudah))
            ->orderByDesc('b.lmcCreateTime')
            ->orderBy('d.lmdId')
            ->get([
                'd.lmdId as kunci', 'b.lmcId as keranjang', 'b.lmcCreateTime as tanggal', 'b.lmcTotalPrice as totalKeranjang',
                'a.medCode as noResep', 'e.IID as item', 'e.IKODE as kode', 'e.INAMA as nama', 'e.IJENISITEM as jenis',
                'd.lmdQuantity as qty', 'd.lmdPrice as harga', 'd.lmdGrandPrice as subtotal', 'd.lmdType as tipe',
                DB::raw('CASE WHEN d.lmdPrice > 0 THEN (d.lmdPrice - COALESCE(d.lmdDiscount, d.lmdPrice)) / d.lmdPrice * 100 ELSE 0 END as diskonPersen'),
                'dr.KID as dokterId', 'dr.KNAMA as dokter', 'pro.KID as proId', 'pro.KNAMA as proNama',
                'pmd.MPDID as promoId', 'pmu.MPUKODE as promoKode',
                'pu.PUID as paketU', 'pd.PDID as paketD', 'pu.PUKODE as paketKode', 'pu.PUJUMLAH as paketJumlah',
            ]);
    }

    /** Centang/lepas semua baris SATU keranjang (keranjang pertama yg tampil kalau belum ada yg dicentang). */
    public function pilihKeranjangPro(int $lmcId): void
    {
        $ids = $this->proRows()->where('keranjang', $lmcId)->pluck('kunci')->map(fn ($k) => (string) $k)->all();
        $this->proPilih = array_values(array_diff($ids, $this->proPilih)) === [] ? [] : $ids;
    }

    /**
     * Masukkan baris terpilih - port VB6 `TampilkanDetail_PRO` / `_Belum_Bayar`:
     *   - tiap baris: harga & diskon dari keranjang PRO, promo/paket ikut, ID asal
     *     SDPROIDU (keranjang) & SDPROIDD (baris)
     *   - PRO: lmdType 'add_on' -> SDPRO (operator 2), selain itu -> SDPRORECOM (perekomendasi)
     *   - dokter hanya utk tindakan (jenis 1/4/6/10); operator & No Ref diisi kasir (dialog
     *     Operator/Dokter dibuka otomatis, spt menambah tindakan biasa)
     *   - ada PRODUK (jenis 0) -> + "RESEP SKIN CARE" (qty = jumlah qty, No IC "000")
     *   - keranjang sudah dibayar -> Merchant DOKU = total keranjang (`lmcTotalPrice`)
     */
    public function pilihPro(): void
    {
        $rows = $this->proRows()->filter(fn ($r) => in_array((string) $r->kunci, $this->proPilih, true))->values();
        if ($rows->isEmpty()) {
            $this->addError('pro', 'Centang minimal satu baris.');

            return;
        }
        $keranjang = $rows->pluck('keranjang')->unique();
        $lainDiKeranjang = collect($this->cart)->pluck('sdproidu')->filter()->unique()->reject(fn ($v) => (int) $v === (int) $keranjang->first());
        if ($keranjang->count() > 1 || $lainDiKeranjang->isNotEmpty()) {
            $this->addError('pro', 'Satu transaksi hanya untuk SATU keranjang PRO - pilih baris dari keranjang yang sama.');

            return;
        }

        $branch = $this->branch();
        $stokCol = $branch ? $this->stokColumn((int) $branch->GID) : 'ISTOKPG';
        $meta = DB::table('bitem')->whereIn('IID', $rows->pluck('item')->unique()->all())
            ->get(['IID', 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")])->keyBy('IID');
        $akhir = $rows->last();
        $baruMulai = count($this->cart);

        foreach ($rows as $r) {
            $tindakan = in_array((int) $r->jenis, self::JENIS_PERLU_OPERATOR, true);
            $l = $this->barisMedlib((int) $r->item, (string) $r->kode, (string) $r->nama, (float) $r->qty, (float) $r->harga,
                round((float) $r->diskonPersen, 6), $meta[(int) $r->item] ?? null,
                $tindakan && $r->dokterId ? (int) $r->dokterId : null, $tindakan ? $r->dokter : null, '');
            $paketBanyak = (float) $r->paketJumlah > 1;

            $this->cart[] = array_merge($l, [
                // Operator & No Ref/IC diisi kasir - bukan dari keranjang PRO.
                'sdkaryawan' => null, 'sdkaryawan_label' => null, 'sdnoref' => null, 'sdlantai2' => null,
                'sdidpromo' => $r->promoId ? (int) $r->promoId : null, 'promo_label' => $r->promoKode,
                'sddaripaket' => $paketBanyak ? 1 : 0,
                'sdidpotongstok' => $r->paketU ? (int) $r->paketU : null, 'sdsodurutan' => $r->paketD ? (int) $r->paketD : null,
                'paket_label' => $r->paketKode, 'sdpaket_pasien' => $paketBanyak ? (int) $this->custId : null,
                'sdpro' => $r->tipe === 'add_on' && $r->proId ? (int) $r->proId : null,
                'sdprorecom' => $r->tipe !== 'add_on' && $r->proId ? (int) $r->proId : null,
                'sdproidu' => (int) $r->keranjang, 'sdproidd' => (int) $r->kunci,
                'medlib' => false, 'pro' => true,
            ]);
        }

        if ($rows->contains(fn ($r) => (int) $r->jenis === 0)) {
            $this->tambahItemPendukung('RESEP SKIN CARE', (float) $rows->sum('qty'), null,
                $akhir->dokterId ? (int) $akhir->dokterId : null, $akhir->dokter, (string) $akhir->noResep);
        }
        if ($this->proTab === 'bayar') {
            $this->pay['merchant'] = ['jumlah' => (float) $akhir->totalKeranjang, 'no' => (string) $akhir->noResep, 'jenis' => 'DOKU'];
        }
        $this->suLmcId = (int) $akhir->keranjang;

        $this->closeProModal();
        $this->dispatch('pos-toast', msg: $rows->count() . ' item PRO ditambahkan ke keranjang.', type: 'success');

        // Tindakan dari PRO belum ber-operator/No Ref -> buka dialognya spt menambah tindakan biasa.
        $perlu = [];
        foreach ($this->cart as $i => $c) {
            if ($i >= $baruMulai && ! empty($c['pro']) && in_array((int) ($c['jenisitem'] ?? -1), self::JENIS_PERLU_OPERATOR, true)) {
                $perlu[] = $i;
            }
        }
        if ($perlu !== []) {
            $this->startOdFlowForLines($perlu);
        }
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
        $this->merchantDipakai = [];
        $this->merchantDiketahui = null;
        $this->showPayModal = true;
    }

    /**
     * Transaksi LAIN yg sudah memakai no. merchant ini - diisi saat OK ditekan, ditampilkan
     * sbg info di dialog bayar. @var list<array{nomor:string,tanggal:string}>
     */
    public array $merchantDipakai = [];

    /**
     * Kunci `pelanggan|jenis|no` yg infonya SUDAH ditampilkan. OK kedua dgn kunci yg sama =
     * kasir sudah membaca info & tetap melanjutkan; kalau nomor/jenisnya diganti, dicek ulang.
     */
    public ?string $merchantDiketahui = null;

    private function kunciMerchant(): string
    {
        $mc = $this->pay['merchant'];

        return $this->custId . '|' . trim((string) $mc['jenis']) . '|' . trim((string) $mc['no']);
    }

    /**
     * Cek no. merchant yg SUDAH PERNAH dipakai (permintaan user 2026-10-15): pelanggan SAMA,
     * jenis merchant SAMA (`SUMERCHANTJENIS`), no. merchant SAMA (`SUMERCHANTNO`), di
     * transaksi mana pun yg tidak batal. Transaksi asal yg sedang di-Edit dikecualikan -
     * nomornya memang miliknya sendiri.
     *
     * Contoh nyata: pelanggan 314460, CARENOW, satu no. merchant dipakai di BL-IP26060137 &
     * BL-IP26060141 (07/06/2026).
     *
     * @return list<array{nomor:string,tanggal:string}>
     */
    private function cekMerchantDipakai(): array
    {
        $mc = $this->pay['merchant'];
        $no = trim((string) $mc['no']);
        $jenis = trim((string) $mc['jenis']);

        if ((float) $mc['jumlah'] <= 0 || $no === '' || $jenis === '' || ! $this->custId) {
            return [];
        }

        return DB::table('fstoku')
            ->where('SUKONTAK', $this->custId)
            ->where('SUMERCHANTJENIS', $jenis)
            ->whereRaw('TRIM(SUMERCHANTNO) = ?', [$no])
            ->where('SUSTATUS', '<>', 9)
            ->when($this->editingFromId, fn ($b) => $b->where('SUID', '<>', $this->editingFromId))
            ->orderBy('SUTANGGAL')
            ->limit(10)
            ->get(['SUNOTRANSAKSI', 'SUTANGGAL'])
            ->map(fn ($r) => [
                'nomor'   => (string) $r->SUNOTRANSAKSI,
                'tanggal' => $r->SUTANGGAL ? \Carbon\Carbon::parse($r->SUTANGGAL)->format('d/m/Y') : '-',
            ])->all();
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

        // No. merchant sudah pernah dipakai -> INFO, bukan larangan: dialog tetap terbuka
        // sekali, OK berikutnya (nomor & jenis tidak diganti) melanjutkan.
        $dipakai = $this->cekMerchantDipakai();
        if ($dipakai !== [] && $this->merchantDiketahui !== $this->kunciMerchant()) {
            $this->merchantDipakai = $dipakai;
            $this->merchantDiketahui = $this->kunciMerchant();

            return;
        }

        $this->merchantDipakai = [];
        $this->merchantDiketahui = null;
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
        $this->merchantDipakai = [];
        $this->merchantDiketahui = null;
        $this->resetErrorBag();
        $this->showPayModal = false;
    }

    /**
     * Pintasan bayar di dalam dialog (permintaan user 2026-09-30, penomoran KETIGA):
     *
     *   F4 Tunai · F5 Debit · F8 Kredit · F9 Transfer · F2 Merchant · F10 Voucher · F6 DP
     *
     * **DP tidak disebut user** - F5 yg dulu memegangnya kini jadi Debit, jadi DP ditaruh di
     * F6: satu-satunya tombol tersisa yg tidak bentrok, DAN artinya jadi konsisten karena di
     * LUAR dialog F6 memang sudah "Cari DP". (F6 juga pemegang DP pada penomoran PERTAMA,
     * permintaan user 2026-09-28 "F6 tampil rincian DP".)
     *
     * DUA PERILAKU BERBEDA, karena memang beda sifatnya:
     *
     *  - F4/F5/F8/F9/F2 -> `bayarPenuh()`: semua NILAI bayar dikosongkan, lalu metode itu
     *    diisi sebesar total transaksi.
     *  - F6 (DP) & F10 (Voucher) -> MEMBUKA PEMILIHNYA. Keduanya tidak bisa "diisi penuh":
     *    nilainya berasal dari saldo DP/voucher yg dipilih (`pickDp()` mengisi sebesar sisa
     *    saldo), dan sisa itu sering TIDAK menutup seluruh transaksi - jadi metode lain
     *    SENGAJA TIDAK dikosongkan, karena biasanya masih dibutuhkan untuk menutup sisanya.
     *
     * Yang dikosongkan `bayarPenuh()` hanya NILAI bayarnya. Voucher & DP yg terlanjur dipilih
     * TIDAK dilepas - nilainya jadi 0 sehingga tidak ikut terpakai (`checkout()` mengabaikan
     * keduanya saat jumlah 0), tapi kasir tidak perlu mencarinya ulang kalau berubah pikiran.
     */
    public const PINTASAN_BAYAR = [
        'F4' => 'tunai',
        'F5' => 'debit',
        'F8' => 'kredit',
        'F9' => 'transfer',
        'F2' => 'merchant',
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

    /* Pengalih tombol pintas. Tiap Fn punya arti GANDA: di luar dialog bayar = pintasan layar
     | utama, di dalam dialog = pintasan bayar. Satu pengikat per tombol dgn percabangan DI SINI -
     | kalau dipasang dua `wire:keydown.fN.window` (satu di root, satu di dalam dialog),
     | KEDUANYA ikut jalan (mis. nilai bayar terisi TAPI modal cari item ikut terbuka).
     |
     | TABEL LENGKAPNYA ada di CLAUDE.md - rujuk ke sana, jangan menyimpulkan dari satu method. */

    /** Di DALAM dialog TIDAK berarti apa-apa lagi - Tunai pindah ke F4 (2026-09-30). */
    public function hotkeyF1(): void
    {
        if (! $this->showPayModal) {
            $this->openCustModal();
        }
    }

    public function hotkeyF2(): void
    {
        $this->showPayModal ? $this->bayarPenuh('merchant') : $this->openItemModal();
    }

    /** Kosong di KEDUA keadaan sejak 2026-09-30 - satu-satunya Fn yg benar-benar menganggur. */
    public function hotkeyF3(): void
    {
        // sengaja kosong
    }

    /** Di LUAR dialog kosong - "Daftar Paket" (dulu Cari Paket) pindah ke F11 (2026-09-30). */
    public function hotkeyF4(): void
    {
        if ($this->showPayModal) {
            $this->bayarPenuh('tunai');
        }
    }

    public function hotkeyF5(): void
    {
        $this->showPayModal ? $this->bayarPenuh('debit') : $this->openVoucherModal();
    }

    /** DP: di KEDUA keadaan membuka pemilih DP - di dalam dialog inilah "rincian DP"-nya. */
    public function hotkeyF6(): void
    {
        $this->openDpModal();
    }

    /** Kosong di KEDUA keadaan sejak 2026-09-30 - Voucher pindah ke F10. */
    public function hotkeyF7(): void
    {
        // sengaja kosong
    }

    /**
     * Di LUAR dialog: membuka dialog Pembayaran (permintaan user 2026-09-30, sebelumnya F9).
     * Dijaga supaya tidak jalan saat dialognya SUDAH terbuka - `openPayModal()` menyalin ulang
     * nilai bayar ke `$paySebelumnya`, jadi memanggilnya lagi akan menghapus titik pulih Batal.
     * Di DALAM dialog: Kartu Kredit.
     */
    public function hotkeyF8(): void
    {
        $this->showPayModal ? $this->bayarPenuh('kredit') : $this->openPayModal();
    }

    /** Di LUAR dialog kosong - F9 dulu pembuka dialog bayar, sekarang F8 (2026-09-30). */
    public function hotkeyF9(): void
    {
        if ($this->showPayModal) {
            $this->bayarPenuh('transfer');
        }
    }

    /**
     * F10: di LUAR dialog "Harga Khusus" (dulu Cari Promo), di DALAM dialog **Voucher**
     * (pindah dari F7, permintaan user 2026-09-30). F11 "Daftar Paket" (dulu Cari Paket)
     * **hanya di LUAR dialog** - memilih paket di tengah dialog akan mengubah total sementara
     * isian bayar sudah terkunci di layar.
     *
     * CATATAN BROWSER: F11 = layar penuh dan F10 = bilah menu (Firefox). Keduanya MASIH bisa
     * dicegah `preventDefault()` - beda dari **F12** yg TIDAK bisa (DevTools dipegang browser,
     * itu sebabnya simpan transaksi pindah ke Ctrl+Enter). Kalau ternyata di peramban tertentu
     * F11 tetap memicu layar penuh, tombol di layar tetap ada sbg jalan keluarnya.
     */
    public function hotkeyF10(): void
    {
        $this->showPayModal ? $this->openVoucherModal() : $this->openPromoModal();
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
     | "uang pas" di dialog bayar dihapus atas permintaan user karena sudah ada pintasan bayar.
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
            + (float) $this->pay['dp']['jumlah']
            // Surgery - sama dgn VB6 `JumlahSubTOtal`: piutang & "sudah dibayar" nota
            // sebelumnya ikut menutup tagihan (bukan uang yg diterima hari ini).
            + (float) $this->pay['piutang']
            + ($this->surgeryIdu ? $this->surgeryPembayaran : 0),
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
            ->leftJoinSub($this->minimalQtyPerPromo(), 'mq', 'mq.MPDIDU', '=', 'u.MPUID')
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
            ->get([
                'u.MPUID as id', 'u.MPUKODE as kode', 'u.MPUNAMA as nama',
                'u.MPUTANGGAL1 as tgl1', 'u.MPUTANGGAL2 as tgl2',
                'mq.qty1', 'mq.qty2', 'mq.qty3',
            ]);
    }

    /**
     * Minimal Qty per promo, utk ditampilkan di pencarian "Harga Khusus" (permintaan user
     * 2026-09-30). Qty tersimpan di `emasterpromod` (BARIS DETAIL), bukan di master - jadi
     * harus diringkas per `MPDIDU`.
     *
     * ## Pemetaan kolom - PERLU DIKONFIRMASI USER
     * `emasterpromod` punya TIGA kolom qty (tanpa `...QTY2`, memang begitu di skema legacy).
     * Label VB6-nya (`eFrmMasterPromoDiskon2.frm` baris 1509 & `eFrmMasterPromoData.frm` 562):
     *
     *   Qty 1 = `MPDMINIMALQTY`  -> VB6 "Minima Item 1" DAN "Minimal Item 2" (slot item 1 & 2
     *                               BERBAGI kolom yg sama - lihat query grid master promo)
     *   Qty 2 = `MPDMINIMALQTY3` -> VB6 "Min Qty 3"  (slot item 3)
     *   Qty 3 = `MPDMINIMALQTY4` -> VB6 "Min Qty 4"  (slot item 4)
     *
     * Jadi urutan "Qty 1/2/3" yg diminta user memetakan ke slot item 1&2 / 3 / 4.
     *
     * ## Kenapa GROUP_CONCAT, bukan satu angka
     * Satu promo rata-rata punya **7,5 baris** `emasterpromod` (maksimum 189 di data nyata),
     * dan **111 promo qty-nya BERBEDA antar baris**. Menampilkan satu angka (mis. `MIN`) akan
     * MENYESATKAN kasir untuk promo-promo itu. Karena itu ditampilkan daftar nilai BERBEDA-nya,
     * mis. `1/2`. `NULLIF(...,0)` membuang nol - nol di sini berarti "tidak dipakai", bukan
     * "minimal 0 buah" (11.251 baris detail bernilai 0 di ketiga kolom); kalau SEMUA nol,
     * hasilnya NULL dan di layar jadi "—".
     */
    private function minimalQtyPerPromo()
    {
        return DB::table('emasterpromod')
            ->selectRaw('MPDIDU')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(MPDMINIMALQTY,0)  ORDER BY NULLIF(MPDMINIMALQTY,0)  SEPARATOR '/') as qty1")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(MPDMINIMALQTY3,0) ORDER BY NULLIF(MPDMINIMALQTY3,0) SEPARATOR '/') as qty2")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(MPDMINIMALQTY4,0) ORDER BY NULLIF(MPDMINIMALQTY4,0) SEPARATOR '/') as qty3")
            ->groupBy('MPDIDU');
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
            ? DB::table('bitem')->whereIn('IKODE', $allCodes)->where('ISTATUS', 0)->get(['IKODE', 'INAMA', $this->kolomHarga()])->keyBy('IKODE')
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
            )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

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
            ? DB::table('bitem')->whereIn('IKODE', $allCodes)->where('ISTATUS', 0)->get(['IKODE', 'INAMA', $this->kolomHarga()])->keyBy('IKODE')
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
            )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

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
     *
     * **Kolom Total** (permintaan user 2026-09-30) = `epaketu.PUTOTALHARGA`, kolom yg SAMA
     * dipakai VB6 di layar yg sama (`bFrmCariPaket.frm` baris 331:
     * `select PUID,PUKODE,PUNAMA,PUTOTALHARGA from epaketu ...`). **Bukan** `SUM(epaketd.PDSUBTOTAL)`
     * - keduanya TIDAK selalu sama: dari 1.783 paket aktif, 5 terisi keduanya tapi berbeda,
     * 6 header-nya 0 padahal detailnya berisi, 24 sebaliknya, dan 58 nol di kedua sisi.
     * Dipakai `PUTOTALHARGA` supaya angkanya SAMA dgn yg selama ini dilihat user di VB6;
     * paket ber-Total 0 itu masalah DATA MASTER (64 paket), bukan bug di sini - jangan
     * "diperbaiki" dgn diam-diam menghitung dari detail, karena hasilnya akan beda dari
     * aplikasi lama tanpa user tahu.
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
            ->get(['u.PUID as id', 'u.PUKODE as kode', 'u.PUNAMA as nama', 'u.PUJUMLAH as jumlah',
                'u.PUTOTALHARGA as total']);
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
            )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

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
            )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

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
            )->first(['IID', 'IKODE', 'INAMA', $this->kolomHarga(), 'ISATUAN', 'ITIPEITEM', 'IJENISITEM', DB::raw("{$stokCol} AS stok")]);

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

        return $this->validasiSurgery();
    }

    /**
     * Aturan pembayaran SURGERY (lihat `App\Services\Surgery`):
     *  - Piutang HANYA utk transaksi yg dicentang Surgery - di POS biasa tidak ada piutang.
     *  - Nota surgery yg ditarik dicek ULANG dari DB (bukan dipercaya dari properti Livewire
     *    yg bisa diubah lewat request): masih milik pasien ini, belum dilanjutkan nota lain,
     *    belum lunas. "Sudah dibayar"-nya juga dihitung ulang dari DB.
     *  - Total tidak boleh di bawah yg sudah dibayar, dan piutang tidak boleh membuat
     *    pembayaran melebihi tagihan (sisa = piutang, bukan kembalian).
     */
    private function validasiSurgery(): bool
    {
        $piutang = (float) $this->pay['piutang'];

        if ($piutang < 0) {
            $this->addError('pay.piutang', 'Piutang tidak boleh minus.');

            return false;
        }
        if (! $this->surgery) {
            if ($piutang > 0) {
                $this->addError('pay.piutang', 'Piutang hanya untuk transaksi Surgery - centang "Surgery" dulu.');

                return false;
            }
            $this->resetSurgeryLink();

            return true;
        }

        if ($this->surgeryIdu) {
            $nota = $this->surgeryResults()->firstWhere('id', $this->surgeryIdu);
            if (! $nota) {
                $this->addError('surgery', 'Nota surgery ' . $this->surgeryNo . ' tidak bisa dilanjutkan lagi (sudah dilanjutkan nota lain, lunas, atau bukan milik pasien ini). Tarik ulang No IP Surgery.');
                $this->resetSurgeryLink();

                return false;
            }
            $this->surgeryTotal = (float) $nota->total;
            $this->surgeryPiutang = (float) $nota->piutang;
            $this->surgeryPembayaran = (float) $nota->dibayar;

            if ($this->grandTotal() + 0.001 < $this->surgeryPembayaran) {
                $this->addError('surgery', 'Total transaksi (' . number_format($this->grandTotal(), 0, ',', '.')
                    . ') lebih kecil dari yang sudah dibayar (' . number_format($this->surgeryPembayaran, 0, ',', '.') . ').');

                return false;
            }
        }

        if ($piutang > 0 && $this->totalBayar() > $this->grandTotal() + 0.001) {
            $this->addError('pay.piutang', 'Piutang melebihi sisa tagihan - maksimal '
                . number_format(max(0, $this->grandTotal() - ($this->totalBayar() - $piutang)), 0, ',', '.') . '.');

            return false;
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

        // Keranjang PRO: masih milik pasien ini & belum masuk DIAS. Transaksi yg sedang di-Edit
        // boleh - `lmcDiasIP` keranjangnya memang berisi nomor transaksi lama itu.
        $keranjangPro = collect($this->cart)->pluck('sdproidu')->filter()->unique()->values();
        if ($keranjangPro->count() > 1) {
            $this->addError('cart', 'Satu transaksi hanya untuk satu keranjang PRO.');

            return;
        }
        if ($keranjangPro->isNotEmpty()) {
            $k = DB::table('official_nmw.log_medlib_cart')->where('lmcId', $keranjangPro->first())->first(['lmcPatientKID', 'lmcDiasIP']);
            $dias = trim((string) ($k->lmcDiasIP ?? ''));
            if (! $k || (int) $k->lmcPatientKID !== (int) $this->custId || ($dias !== '' && $dias !== (string) $this->editingFromNomor)) {
                $this->addError('cart', 'Keranjang PRO sudah masuk DIAS (' . ($dias ?: '-') . ') atau bukan milik pasien ini - hapus barisnya lalu Tarik PRO ulang.');

                return;
            }
        }

        // Invoice Medlib: masih milik pasien ini & belum masuk DIAS (kasir lain bisa saja sudah
        // menyimpannya sejak ditarik). Invoice dari transaksi yg sedang di-Edit dikecualikan -
        // ivhStatusDIAS-nya memang sudah 1 oleh transaksi lama itu.
        $invoiceIds = array_values(array_unique(array_filter(array_column($this->cart, 'sdprdid'))));
        if ($invoiceIds !== []) {
            $milikEdit = $this->editingFromId
                ? DB::table('fstokd')->where('SDIDSU', $this->editingFromId)->whereNotNull('SDPRDID')->pluck('SDPRDID')->map(fn ($v) => (int) $v)->all()
                : [];
            $bentrok = DB::table('official_nmw.ops_invoice_header')->whereIn('ivhId', $invoiceIds)
                ->where(fn ($w) => $w->where('ivhKID', '<>', $this->custId)
                    ->orWhere(fn ($x) => $x->where('ivhStatusDIAS', '<>', 0)->when($milikEdit !== [], fn ($y) => $y->whereNotIn('ivhId', $milikEdit))))
                ->pluck('ivhCode')->all();
            if ($bentrok !== []) {
                $this->addError('cart', 'Invoice Medlib ' . implode(', ', $bentrok) . ' sudah masuk DIAS atau bukan milik pasien ini - hapus barisnya lalu Tarik Medlib ulang.');

                return;
            }
        }

        $tgl = now()->toDateString();
        $stokCol = $this->stokColumn((int) $branch->GID);
        $ids = array_column($this->cart, 'item');
        $meta = DB::table('bitem')->whereIn('IID', $ids)
            ->get(['IID', 'IKODE', 'INAMA', 'ITIPEITEM', 'IJENISITEM', 'IDISKON', 'ICOGS', DB::raw("{$stokCol} AS stok")])->keyBy('IID');

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
        $totalPoin = 0.0;
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
            if ($m && (int) $m->IJENISITEM !== 14 && ! in_array(strtoupper((string) $m->IKODE), self::KODE_BUKAN_POIN, true)) {
                $totalPoin += $qty * ($harga - $diskonUnit);
            }

            $lines[] = [
                'SDITEM'          => $itemId,
                'SDKELUAR'        => $qty,
                'SDKELUARD'       => $qty,
                // Harga pokok baris = bitem.ICOGS x qty (permintaan user 2026-10-16). Dulu POS
                // Laravel tidak mengisi SDBELI sama sekali (0) - dan stored procedure HPP
                // (SP_HITUNG_HPP_ADD) yg dipakai CI3 tidak pernah dipanggil - sehingga COGS
                // transaksi POS Laravel di Dashboard Penjualan selalu 0.
                'SDBELI'          => round(($m ? (float) $m->ICOGS : 0.0) * $qty, 2),
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
                // Tarik Medlib - ID asal di official_nmw (lihat `pilihMedlib()`). SDPRDID
                // dipakai trigger `fstokd_add` utk menandai invoice `ivhStatusDIAS = 1`.
                'SDSODID'         => $l['sdsodid'] ?? null,
                'SDPRDID'         => $l['sdprdid'] ?? null,
                'SDMEDIDU'        => $l['sdmedidu'] ?? null,
                'SDMEDIDD'        => $l['sdmedidd'] ?? null,
                // Tarik PRO - lihat `pilihPro()`.
                'SDPRO'           => $l['sdpro'] ?? null,
                'SDPRORECOM'      => $l['sdprorecom'] ?? null,
                'SDPROIDU'        => $l['sdproidu'] ?? null,
                'SDPROIDD'        => $l['sdproidd'] ?? null,
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
            // "Data Lainnya" - lihat komentar $showOtherDataModal. SUIDMEDLIB (ID Medlib) TIDAK
            // ditulis dari sini - rujukan sistem lain. SULMCID ditulis di bawah (Tarik PRO).
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
            // Tarik PRO - keranjang yg ditarik (`pilihPro()`); trigger `fstoku_add` mengisi
            // `log_medlib_cart.lmcDiasIP`. Diambil dari baris keranjang, bukan properti, supaya
            // keranjang yg barisnya sudah dihapus kasir tidak ikut ditandai. Edit transaksi lama
            // tanpa baris PRO tetap membawa SULMCID aslinya.
            'SULMCID'            => collect($this->cart)->pluck('sdproidu')->filter()->first() ?: ($this->editingFromId ? $this->suLmcId : null),
            // Surgery - kolom & isi SAMA dgn VB6 `eFrmPOS2` (lihat komentar `$surgery`).
            // Nilai nota sebelumnya sudah dihitung ulang dari DB di `validasiSurgery()`.
            'SUNILAIPIUTANG'        => $this->surgery ? (float) $this->pay['piutang'] : 0,
            'SUSURGERYDP'           => $this->surgery ? 1 : 0,
            'SUSURGERYDPIDU'        => $this->surgery ? $this->surgeryIdu : null,
            'SUSURGERYDPPEMBAYARAN' => $this->surgery && $this->surgeryIdu ? $this->surgeryPembayaran : 0,
            'SUSURGERYDPTOTAL'      => $this->surgery && $this->surgeryIdu ? $this->surgeryTotal : 0,
            'SUSURGERYDPPIUTANG'    => $this->surgery && $this->surgeryIdu ? $this->surgeryPiutang : 0,
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

        // Dasar poin = total TANPA DP dkk (VB6 xHitungTotalTanpaDP) - lihat `PosSaleWriter::maybeAddPoints()`.
        $writer->maybeAddPoints((int) $res['id'], $this->custId, round($totalPoin, 2), (int) $branch->GID, $this->suTeman ?: null);
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
            // Kirim struk ke email (VB6 xCabangKirimEmail) - hanya cabang GKIRIMEMAIL = 1.
            'email'     => app(EmailStruk::class)->cabangKirim((int) $branch->GID),
            'emailHasil' => null,
        ];

        // Transaksi BARU -> langsung dikirim. Hasil EDIT -> tidak otomatis: VB6 bertanya dulu
        // "kirim ulang struk ke pasien?", di sini jadi tombol di panel "Transaksi tersimpan".
        if ($this->lastReceipt['email'] && ! $wasEditingFrom) {
            $this->kirimEmailStruk();
        }

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
        $this->resetSurgery();
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
    /**
     * Kirim struk transaksi terakhir ke email pasien - otomatis utk transaksi baru, lewat
     * tombol utk hasil Edit / kirim ulang. Kegagalan API TIDAK membatalkan transaksi (sudah
     * tersimpan); kasir cukup diberi tahu, spt pesan VB6.
     */
    public function kirimEmailStruk(): void
    {
        if (! ($this->lastReceipt['id'] ?? null) || ! ($this->lastReceipt['email'] ?? false)) {
            return;
        }
        $h = app(EmailStruk::class)->kirim((int) $this->lastReceipt['id'], (string) $this->lastReceipt['nomor']);
        $this->lastReceipt['emailHasil'] = $h;
        $this->dispatch('pos-toast', msg: $h['pesan'], type: $h['ok'] ? 'success' : 'error');
    }

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

        // Nota surgery yg SUDAH dilanjutkan nota berikutnya tidak boleh di-Edit: Edit
        // membatalkan nota ini & membuat nomor baru, sehingga nota lanjutannya menunjuk nota
        // batal dan rantai cicilannya putus.
        if (DB::table('fstoku')->where('SUSURGERYDPIDU', $id)->where('SUSTATUS', '<>', 9)->exists()) {
            $this->dispatch('pos-toast', msg: 'Nota ' . $header->SUNOTRANSAKSI . ' sudah dilanjutkan cicilan surgery berikutnya - tidak bisa diedit.', type: 'warning');

            return;
        }

        if (! DB::table('fstokd')->where('SDIDSU', $id)->exists()) {
            return;
        }

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

        $this->cart = $this->barisDariTransaksi($id, (int) $header->SUKONTAK);
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
            'debit'    => ['jumlah' => (float) $header->SUTOTALKARTUDEBIT, 'no' => $header->SUNOKARTUDEBIT ?? '', 'nama' => $header->SUNAMADEBIT ?? '', 'bank' => app(BankNama::class)->nama($header->SUBANKDEBIT) ?: null, 'jenis' => $header->SUDEBITJENIS, 'bank_lain' => $header->SUATTENTION ?? ''],
            'kredit'   => ['jumlah' => (float) $header->SUTOTALKARTUKREDIT, 'no' => $header->SUNOKARTUKREDIT ?? '', 'nama' => $header->SUNAMAKREDIT ?? '', 'bank' => app(BankNama::class)->nama($header->SUBANKKREDIT) ?: null, 'jenis' => $header->SUKREDITJENIS, 'bank_lain' => $header->SUNOFAKTURPAJAK ?? ''],
            'transfer' => ['jumlah' => (float) $header->SUTOTALTRANSFER, 'no' => $header->SUNOTRANSFER ?? '', 'nama' => $header->SUNAMATRANSFER ?? '', 'bank' => app(BankNama::class)->nama($header->SUBANKTRANSFER) ?: null],
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
            'piutang' => (float) $header->SUNILAIPIUTANG,
        ];

        // Surgery - dimuat apa adanya; dicek ulang terhadap DB saat disimpan (`validasiSurgery()`).
        $this->surgery = (int) $header->SUSURGERYDP !== 0;
        $this->surgeryIdu = $header->SUSURGERYDPIDU ? (int) $header->SUSURGERYDPIDU : null;
        $this->surgeryNo = $this->surgeryIdu
            ? (string) DB::table('fstoku')->where('SUID', $this->surgeryIdu)->value('SUNOTRANSAKSI') : null;
        $this->surgeryTotal = (float) $header->SUSURGERYDPTOTAL;
        $this->surgeryPembayaran = (float) $header->SUSURGERYDPPEMBAYARAN;
        $this->surgeryPiutang = (float) $header->SUSURGERYDPPIUTANG;
        $this->showSurgeryModal = false;

        $this->lastReceipt = [];
        $this->showHistoryModal = false;
        $this->editingFromId = $id;
        $this->editingFromNomor = $header->SUNOTRANSAKSI;

        activity_log('edit_start', 'sales/pos', $id, 'Transaksi ' . $header->SUNOTRANSAKSI . ' dimuat ulang ke keranjang utk diedit (belum dibatalkan - menunggu disimpan).');
    }


    /**
     * Baris `fstokd` satu transaksi -> baris keranjang POS. Dipakai `editTransaction()` DAN
     * tarik "No IP Surgery" (`pilihSurgery()`) supaya keduanya memetakan kolom persis sama.
     *
     * Pemanggil WAJIB sudah memilih pasien (`pickCustomer()`) SEBELUM memasang hasilnya ke
     * `$this->cart` - lihat komentar "Pelanggan diset DULU" di `editTransaction()`.
     *
     * @return list<array<string,mixed>>
     */
    private function barisDariTransaksi(int $suid, int $pasienId): array
    {
        $lines = DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->where('d.SDIDSU', $suid)
            ->orderBy('d.SDURUTAN')
            ->get([
                'd.SDITEM', 'd.SDKELUAR', 'd.SDHARGA', 'd.SDDISKONPERSEN', 'd.SDDISKONPERSEN2',
                'd.SDSATUAN', 'd.SDKARYAWAN', 'd.SDDOKTER', 'd.SDNOREF', 'd.SDLANTAI2', 'd.SDIDPROMO',
                'd.SDDARIPAKET', 'd.SDIDPOTONGSTOK', 'd.SDCATATANKOLI', 'd.SDKEDATANGAN', 'd.SDSODURUTAN',
                'd.SDSODID', 'd.SDPRDID', 'd.SDMEDIDU', 'd.SDMEDIDD',
                'd.SDPRO', 'd.SDPRORECOM', 'd.SDPROIDU', 'd.SDPROIDD',
                'i.IKODE', 'i.INAMA', 'i.ITIPEITEM', 'i.IJENISITEM',
            ]);

        if ($lines->isEmpty()) {
            return [];
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

        $hasil = [];
        foreach ($lines as $l) {
            // Stok saat ini - BELUM termasuk pengembalian qty transaksi lama (baru dikembalikan
            // saat checkout benar2 disimpan). Nilai ini murni informatif di tampilan keranjang;
            // validasi stok yg sesungguhnya di checkout() sudah dikompensasi dgn qty lama.
            $stokSekarang = $branch ? (float) (DB::table('bitem')->where('IID', $l->SDITEM)->value($stokCol) ?? 0) : 0.0;

            $hasil[] = [
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
                // Pasien SAAT INI (bukan disimpan di fstokd) - `$pasienId` = pasien yg sudah dipilih krn
                // pickCustomer() sudah dipanggil DULU dgn nilai yg sama persis sblm loop ini
                // (lihat komentar di atas), jadi custId skrg = pasien asli transaksi ini.
                'sdpaket_pasien'   => (int) $l->SDDARIPAKET === 1 ? $pasienId : null,
                // ID asal Tarik Medlib - dibawa ulang supaya Edit tetap menandai invoice yg sama.
                'sdsodid'          => $l->SDSODID ? (int) $l->SDSODID : null,
                'sdprdid'          => $l->SDPRDID ? (int) $l->SDPRDID : null,
                'sdmedidu'         => $l->SDMEDIDU ? (int) $l->SDMEDIDU : null,
                'sdmedidd'         => $l->SDMEDIDD ? (int) $l->SDMEDIDD : null,
                'medlib'           => $l->SDPRDID || $l->SDMEDIDD,
                'sdpro'            => $l->SDPRO ? (int) $l->SDPRO : null,
                'sdprorecom'       => $l->SDPRORECOM ? (int) $l->SDPRORECOM : null,
                'sdproidu'         => $l->SDPROIDU ? (int) $l->SDPROIDU : null,
                'sdproidd'         => $l->SDPROIDD ? (int) $l->SDPROIDD : null,
                'pro'              => (bool) $l->SDPROIDD,
            ];
        }

        return $hasil;
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
        $surgeryResults = $this->showSurgeryModal ? $this->surgeryResults() : collect();
        $proRows = $this->showProModal ? $this->proRows() : collect();
        $medlibRows = ! $this->showMedlibModal ? collect()
            : ($this->medlibTab === 'invoice' ? $this->medlibInvoiceRows() : $this->medlibResepRows());
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
            'surgeryResults'  => $surgeryResults,
            // Daftar cabang filter Pilih Operator/Dokter - hanya saat dialognya terbuka.
            'odCabangList'    => ($this->showOperatorModal || $this->showDokterModal) ? Branch::options() : collect(),
            'medlibRows'      => $medlibRows,
            'farmasiDefault'  => $this->farmasiDefault(),
            'proRows'         => $proRows,
            'kombinasiRows'   => $kombinasiRows,
            'kombApplyRows'   => $kombApplyRows,
            'kombApplyTotal'  => $kombApplyTotal,
            'biasaRows'       => $biasaRows,
            'biasaApplyItem'  => $biasaApplyItem,
            'lineTotals'  => $lineTotals,
            'grand'       => $grand,
            'bayar'       => $bayar,
            'kurang'      => round($grand - $bayar, 2),
            // Nilai pilihan = NAMA bank (disimpan di SUBANK*) spt CI3/VB6 - lihat BankNama.
            'banks'       => DB::table('bbank')->whereNotIn('BNAMA', ['None', 'undefined'])->orderBy('BNAMA')->get(['BID', 'BNAMA']),
            'merchants'   => DB::table('bmerchant')->orderBy('MCNAMA')->get(['MCID', 'MCKODE', 'MCNAMA']),
            'jenisKartuDebit'  => self::JENIS_KARTU_DEBIT,
            'jenisKartuKredit' => self::JENIS_KARTU_KREDIT,
        ]);
    }
}
