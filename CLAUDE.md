# DIAS ERP (Laravel) — panduan proyek

Rebuild ERP klinik kecantikan NMW dengan **Laravel 12 + Livewire (class-based) + Blade + AdminLTE 4**.
Jalan **langsung di DB produksi `data_pos_nmw_2023`** (MariaDB). CI3 (`C:\xampp\htdocs\dias-online-app`)
dan CI4 (`C:\xampp\htdocs\dias-online-nmw`) jalan paralel di DB yang sama.

### LINGKUP DATABASE — hanya `data_pos_nmw_2023` (ditegaskan user 2026-09-28)

**Database lain di server ini milik PROYEK LAIN, bukan urusan proyek ini.** Termasuk
`data_pos_nmw_2027`, `db_mp_pabrik_2026`, `official_nmw`. Konsekuensi konkret:
- **Jangan** mengusulkan/mengerjakan perbaikan di sana, meskipun cacatnya sama (mis. trigger
  `fstokd` di `db_mp_pabrik_2026` & `data_pos_nmw_2027` punya bug kembar yg persis sama -
  tetap BUKAN urusan kita), dan jangan menaruhnya di daftar "masih terbuka".
- **Jangan** menghitungnya sbg bagian dari cutover 1 Oktober.
- **Tapi WASPADA saat menguji**: trigger `fstokd` di DB kita MENULIS ke
  `official_nmw.ops_invoice_header` (dirujuk absolut, lewat `SDPRDID`). Data uji WAJIB pakai
  `SDPRDID=-1` supaya UPDATE itu tidak mengenai baris apa pun di database proyek lain.
- **Selalu filter `TRIGGER_SCHEMA`/`ROUTINE_SCHEMA`/`TABLE_SCHEMA`** saat query
  `information_schema` - tanpa itu hasilnya bercampur DB proyek lain & bikin salah kesimpulan.

## Menjalankan

- PHP: **`C:\php82\php.exe`** (PATH `php` = 7.4, rusak untuk composer/artisan).
- Composer: `C:\php82\php.exe C:\ProgramData\ComposerSetup\bin\composer.phar`
- Dev server: `C:\php82\php.exe artisan serve --port=8000` → http://127.0.0.1:8000
- Login super admin: **`admin4` / `admin2026`**
- MySQL CLI: `C:\xampp\mysql\bin\mysql.exe -uroot data_pos_nmw_2023`

## Aturan anti-tabrakan (3 framework, 1 DB) — WAJIB

- **JANGAN sentuh** tabel CI3 (`amenu`, `ausermenu`, `arole`, `migrations`, …) & CI4 (`a4menu`, `a4_migrations`, `a4user_auth`, …).
- Semua tabel baru Laravel diberi prefix **`lv_`**. Tabel migrasi = `lv_migrations` (di `config/database.php`).
- `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` — jangan bikin tabel di DB bersama.

## Bekerja dengan tabel legacy

- **Kapitalisasi kolom**: banyak tabel legacy pakai kolom HURUF BESAR (`bitem` → `IID`, `IKODE`;
  `bkontak` → `KID`, `KKODE`; `bgudang` → `GID`). SQL tidak peka kapitalisasi, **tapi Eloquent `SELECT *`
  mengembalikan key sesuai kapitalisasi asli**. Selalu cocokkan case dari `SHOW COLUMNS` saat baca via Eloquent,
  atau gejalanya: create/list jalan, Edit muat kosong.
- `config/database.php` **`strict => false`** — tabel legacy penuh default `0000-00-00` & kolom tanpa default.
- **`config/app.php` `timezone` = `Asia/Jakarta` — JANGAN dikembalikan ke `UTC` bawaan Laravel.**
  MySQL-nya `time_zone=SYSTEM` (= WIB) dan kolom seperti `ctransaksiu.CUCREATED`/`fstoku.SUCREATED`
  diisi DEFAULT `current_timestamp()` **MySQL**, bukan PHP. Dengan `UTC`, Laravel menulis jam yang
  selisih **7 jam** dari yang ditulis MySQL **di dokumen yang sama** (terbukti 2026-09-30:
  `CUCREATED`=10:21:29 vs `lv_activity_log.created_at`=03:21:29 untuk satu transaksi). Yang lebih
  berbahaya daripada jam cetakan: `now()->toDateString()` (tanggal bawaan form Kas/Bank, POS, dll)
  memberi tanggal **KEMARIN** sepanjang 00:00–07:00 WIB, dan `startOfMonth()/endOfMonth()` untuk
  filter laporan meleset di pergantian bulan. **Jangan pernah mengakali ini dengan `addHours(7)`
  atau `setTimezone()` di kode** — sudah dicek, tidak ada satu pun kompensasi semacam itu di
  `app/`, `config/`, `resources/views/`, `routes/`; menambahkannya sekarang = dobel geser.
- Charset koneksi `utf8mb4` (MariaDB transcode tabel latin1 saat baca).
- Tabel transaksi POS (`fstoku`/`fstokd`) punya **trigger** yang mengurus stok + jurnal — cukup insert baris yang benar (`App\Services\PosSaleWriter`). Nomor: `{bgudang.GALAMAT1}-IP{ym}{NNNN}`, urut dari `MAX(RIGHT(SUNOTRANSAKSI,4))` atas prefix (unik global, bukan per cabang).
- **Konvensi pembatalan transaksi `fstoku`/`fstokd` (dari kode CI3 asli, `M_PJ_POS_HP::tambahTransaksi()`) — JANGAN DELETE, UPDATE status**: `fstoku.SUSTATUS = 9` artinya "Cancel" (selain itu = "Aktif"; konvensi ini dipakai di SEMUA jenis transaksi `fstoku`, bukan cuma POS). Batalkan transaksi = `UPDATE fstokd SET SDCANCEL=1 WHERE SDIDSU=id` (BUKAN delete baris) + `UPDATE fstoku SET SUSTATUS=9, SUDP1=0, SUTOTALDP=0, SUIPBARU=<id pengganti> WHERE SUID=id` + `DELETE FROM bpoint WHERE PIDTRANSAKSIMASUK=id` (poin TETAP dihapus). Trigger `fstokd_edit` (AFTER UPDATE) yang membalik stok: kontribusi `OLD.*` selalu dikurangi, kontribusi `NEW.*` ditambah lagi HANYA kalau `NEW.SDCANCEL=0` — begitu di-set 1, penambahan itu di-skip, net effect = stok kembali seperti dihapus TANPA benar-benar dihapus. Transaksi pengganti diberi `SUNOIPLAMA = <no. transaksi lama>` (link baru→lama; `SUIPBARU` = link lama→baru). Query "transaksi aktif" WAJIB filter `WHERE SUSTATUS<>9`. Ada JUGA hard-delete sungguhan terpisah di CI3 (`hapusTransaksi()`, dipetakan ke `PosSaleWriter::cancel()`) — beda tujuan dari cancel/replace biasa, jangan tertukar. Implementasi Laravel: `PosSaleWriter::replace()`.
- Fungsi legacy dipakai: `F_KOLOMGUDANG(GID)` → nama kolom stok cabang di `bitem` (whitelist `/^ISTOK[A-Z0-9]+$/`); `f_tanggal_akhir(KID)` → tanggal akhir member (mahal, panggil via subquery).
- **`bkontak` (315rb+ baris) — JANGAN PERNAH `WHERE KNAMA LIKE '%q%'`** (leading wildcard = full table scan, pernah bikin request timeout 30 detik nyata di POS). Selalu pakai `App\Models\Contact::applySearch($query, $q, $alias, $extraColumns = [])` — FULLTEXT `MATCH(KNAMA) AGAINST('+w1* +w2*' IN BOOLEAN MODE)` (index `idx_ft_knama`, sudah ada di DB) + prefix-LIKE (`'q%'`, bukan `'%q%'`) untuk KKODE/KIDPASIEN/KNOMEMBER/K1TELP1/kolom tambahan. Jangan `ORDER BY` kolom lain saat `$q` ada (FULLTEXT + sort = filesort, lambat) — pakai `->when($q === '', fn($b)=>$b->orderBy(...))`.
- **`bitem.ICABANG`** = daftar GID cabang yg boleh menjual item itu, format pipe-delimited `|1|2|3|...|48|`. **JANGAN filter pakai `LIKE '%|{gid}|%'` polos** — 1.638/5.580 baris (29%!) datanya RUSAK: kata literal "ICABANG" + CRLF nyasar menggantikan pipe pembatas PERTAMA (`|ICABANG\r\n1|2|...`), bikin GID=1 (Petogogan, cabang aktif) salah ditolak walau daftarnya jelas mencantumkan 1 (untungnya SEMUA baris rusak itu kebetulan `ISTATUS<>0`/tidak aktif saat ini — tapi bisa jadi nyata kalau diaktifkan lagi). Pakai helper `PosTerminal::applyIcabangFilter($query, $gid)` — `whereRaw('ICABANG REGEXP ?', ['[|\r\n]'.$gid.'\|'])`, delimiter kiri toleran `|`/CR/LF, delimiter kanan wajib `|` (cegah gid pendek nyangkut di gid panjang, mis. 1 vs "11"). Kalau bikin filter serupa di modul lain (Pembelian/Inventory dsb yg jg baca `bitem`), pakai pola REGEXP ini, jangan LIKE naif.

## Konvensi: default filter/field Cabang = cabang login user

**SEMUA komponen (list filter MAUPUN form field) yang punya pilihan Cabang WAJIB default ke
`auser.UCABANG` milik user login** (2026-09-23, permintaan eksplisit user - "di semua data,
jika ada pilihan cabang, maka diisi sesuai cabang pilihan di user"), BUKAN dibiarkan kosong
("Semua Cabang"). Pola standar (dipakai konsisten di hampir semua List/Form component sejak
awal, mis. `ProduksiList`/`PbForm`/`PoForm`/`KasBankFormBase` dst - HANYA 3 file Master yg
ketinggalan, sudah diperbaiki):

```php
public function mount(): void
{
    $this->fCabang = $this->defaultCabang(); // atau langsung inline kalau cuma 1 pemakaian
}

private function defaultCabang(): string
{
    $ucabang = (int) (auth()->user()->UCABANG ?? 0);
    return Branch::active()->where('GID', $ucabang)->exists() ? (string) $ucabang : '';
}
```

- Filter LIST: property string (`$fCabang`), `''` = fallback "Semua Cabang" HANYA kalau
  `UCABANG` user kosong/bukan cabang aktif (belum tentu terjadi tapi dijaga).
- Field FORM (transaksi/master BARU): property int (`$cabang`/`$gudang`), default lewat
  `resetForm()`/`mount()` - `edit()` SELALU override dari kolom asli record (`KCABANG` dst),
  JANGAN sampai default ini menimpa data existing saat edit.
- **Perbaikan 2026-09-23**: `Master\KaryawanManager`/`Master\PasienManager` (kedua fCabang
  filter DAN cabang field form baru sebelumnya SELALU kosong/`0`, tidak py `mount()` sama
  sekali) + `Master\PromoManager` (`mount()` sudah ada tp cuma isi tanggal, `fCabang` belum
  disentuh) - ketiganya ditambah `defaultCabang()` + dipanggil di `mount()`/`resetForm()`.
  Verified `Livewire::test()`: `fCabang` & `cabang` terisi UCABANG admin4 (=1) saat
  mount()/create(), TAPI `edit()` record dgn `KCABANG` BEDA (10, 5) tetap load nilai ASLI
  record (bukan ketiban default) - regresi dicek eksplisit, bukan cuma asumsi.
- **DIKECUALIKAN dari konvensi ini (dicek eksplisit, BUKAN gap)**: properti `cabang`/GID yg
  maknanya BUKAN "cabang tampilan/kerja user" - `Admin\UserManager::$UCABANG` (assignment
  cabang user LAIN yg sedang diedit admin, defaultnya harus kosong biar admin sadar pilih),
  `Master\ItemManager::$cabang` & `Master\PaketManager::$cabangPilih` & `Master\PromoManager
  ::$cabangPilih` (daftar cabang MANA SAJA yg boleh pakai item/paket/promo itu - multi-select
  eligibility, BUKAN filter tampilan, defaultnya harus kosong/eksplisit dipilih admin).

## Autentikasi & hak akses

- Login verifikasi ke `auser` (`UKODE` unik; `UPASSWORD` = MD5 mentah, dipakai CI3). Sukses → tulis hash
  bcrypt ke `lv_user_auth` ("upgrade saat login"). `auser.UPASSWORD` **tidak pernah** diubah.
- `App\Support\Acl` (singleton `acl`): super admin dari `config/acl.php`, pohon menu sidebar, `canRoute()`.
- Middleware `perm:<ability>` (view/add/edit/delete/print/approve). Blade: `@can_do('path','ability')` / helper `can_do()`.
- Menu & hak per user di `lv_menu` + `lv_user_menu`. Seeder menu: `MenuSeeder` (idempoten by `segment_key`).

## Konvensi UI

- **Satu shell `App\Livewire\Workspace`** di route `/` (name `workspace`). Semua modul dibuka sebagai **tab**, bukan halaman.
  Strip tab di area bawah navbar. Tab non-aktif tetap ter-mount (`d-none`) → isian form tidak hilang saat pindah tab.
  - Sidebar & navbar ada di dalam view Workspace (`wire:ignore`). Layout `layouts/app.blade.php` = kerangka `<html>` + `{{ $slot }}` saja.
  - Daftar modul yang bisa dibuka: `Workspace::listRegistry()` (segment_key → [nama-komponen, label, ikon]). Tambahkan modul baru di sini.
  - `/?open=<segment_key>` membuka tab tertentu saat load.
- **Komponen Livewire class-based**: `livewire:make X --class --emoji=false`. Komponen anak (tab) tanpa `#[Layout]`;
  wajib punya `public ?string $tabKey = null;` (Workspace mengoper key tab-nya).
- **Kontrak event anak → Workspace:**
  - `$this->dispatch('open-tab', cmp:'master.item-form', args:['itemId'=>7], label:'…', icon:'…')` — buka/fokus tab
  - `$this->dispatch('close-tab', key: $this->tabKey)` — tutup tab sendiri (dipakai tombol Simpan/Tutup di form)
  - `$this->dispatch('tab-label', key: $this->tabKey, label:'…')` — ganti judul tab sendiri
  - list mendengar event save form via `#[On('item-saved')]` (method kosong = auto re-render)
  - **GOTCHA**: `dispatch()` mem-*reserve* nama argumen `component`, `to`, `self` (untuk merutekan event ke komponen bernama). JANGAN pakai itu sebagai data event — makanya `cmp`/`args`, bukan `component`/`params`.
- **GOTCHA Blade**: jangan tulis token `@push`/`@if`/`@foreach` dll di dalam KOMENTAR JS/`<style>` di file .blade.php — Blade tetap mem-parse-nya → "unexpected end of file". Helper JS global taruh di `public/js/dias-helpers.js` (`<script src>` di layout), BUKAN `@push('scripts')` dari komponen (tidak sampai ke `@stack` saat komponen dimuat via Livewire).
- **Bentuk konten tab (patokan STRUKTUR, bukan jumlah field):**
  - **ada tabel rincian (baris item/detail) → tab penuh sendiri** (nanti PO, Faktur, POS): `Master\XxxList` (tab daftar) membuka `Master\XxxForm` sebagai tab terpisah.
  - **Selebihnya → satu komponen list+modal** dalam satu tab. Modal via `<x-lw-modal>` (`size="xl"` untuk form sedang; default untuk master ringkas). Termasuk **Data Item POS** (`Master\ItemManager`, modal xl, 6 tab di dalam modal) setelah form-nya diringkas.
- Modal = `<x-lw-modal :show="$showModal" title="…" size="xl">` (Bootstrap modal via CSS class, tanpa JS).
- **Tab di dalam modal/form: pakai Alpine, JANGAN Bootstrap `data-bs-toggle="tab"`**. Setiap `wire:click` memicu morph Livewire yang me-reset kelas `.active` Bootstrap (HTML server selalu aktif di tab pertama) → tab melompat balik. Pola: `<div x-data="{ tab: 'detail' }" x-cloak>` , tombol `<button :class="{ active: tab === 'x' }" @click="tab = 'x'">`, panel `<div x-show="tab === 'x'">` (TANPA kelas `.tab-pane` — CSS Bootstrap `.tab-pane{display:none}` menutupi `x-show`). State Alpine dipertahankan Livewire lintas morph.
- Dropdown besar / remote (bwilayah, karyawan, dll) → `<x-search-select model="…" :value="…" :endpoint="route('lookup.…')">` (Alpine + `LookupController`, route `lookup/*` di `web.php`).
- **Draft form**: form penuh (mis. `ItemForm`) membungkus dengan Alpine `formDraft(key)` → autosave field ke `localStorage` tiap ketik (debounce), banner "Pulihkan/Buang" saat ada draft, dihapus otomatis setelah `item-saved`. Key: `dias.draft.<modul>.<id|new>`.
- **Pencarian keyboard-first (POS, dan layar transaksi lain nanti)**: pencarian item/pelanggan = **modal**, bukan dropdown inline. Autofocus via Alpine `x-init="$nextTick(()=>$refs.X.focus())"` di wrapper `.modal-body`. Navigasi dalam modal 100% lewat `wire:keydown` (server-side, index disimpan di properti komponen, di-clamp oleh method `move*Highlight(±1)`): `.arrow-down`/`.arrow-up` pindah baris, `.escape` tutup. Shortcut global di root komponen: `wire:keydown.f2.window.prevent`, `.f4`, `.f1`, `.f9`/`.enter.ctrl`. Baris tabel keranjang: `onkeydown="posFieldKey(event)"` (di `public/js/dias-helpers.js`, murni JS tanpa round-trip) — Enter pindah field/baris berikut, Ctrl+Delete klik tombol `[data-remove-line]`.
  - **GOTCHA (2026-09-13): sempat diduga `wire:model.live` di kotak cari modal tidak reaktif di browser** (server-side sudah dites benar via simulasi request Livewire mentah). Sempat diganti ke pola "deferred + Enter dua-langkah" sebagai workaround, TAPI user minta kembali ke live-search satu-langkah (persis CI4: ketik 2 huruf langsung tampil, panah pilih baris, Enter langsung memilih). **Keadaan final:** `wire:model.live.debounce.200-300ms` dipakai lagi apa adanya, `wire:keydown.enter.prevent="pick*Highlighted"` langsung memilih (bukan 2-step). Ditambah `wire:key` stabil di wrapper `x-data` (`.modal-body`) DAN di `<input>` pencarian itu sendiri, dengan dugaan akar masalah = Alpine `x-init` ikut ter-reinit saat Livewire morph subtree tanpa key stabil (destroy+recreate elemen tiap keystroke). **Kalau live-search masih tidak reaktif di browser setelah ini, langkah debug lanjutan: coba hapus sementara `x-init`/`@refocus-*` untuk isolasi apakah Alpine yang jadi biang keladinya.**
- Pencatatan aktivitas: `activity_log($action, $module, $entityId, $desc)` (tidak pernah melempar exception).
- **GOTCHA `bootstrap/app.php` `withExceptions()`**: `Handler::render()` memanggil `prepareException()` (mapping tipe) **SEBELUM** callback custom dicek — beberapa exception (`TokenMismatchException`, `ModelNotFoundException`, `AuthorizationException`, dll, lihat `match()` di `Handler::prepareException()`) sudah berubah jadi `Symfony\...\HttpException` duluan. Type-hint callback `$exceptions->render(fn (X $e, ...) => ...)` harus ke kelas HASIL mapping (`HttpException` + cek `$e->getStatusCode()`), BUKAN ke exception aslinya — kalau salah, callback diam-diam tidak pernah kepanggil. Contoh nyata: fix halaman "419 Page Expired" mentah di login → harus `HttpException` + `getStatusCode()===419`, bukan `TokenMismatchException`.

## Modul Finance: Kas & Bank Masuk/Keluar

- Tabel legacy `ctransaksiu`/`ctransaksid` (jurnal double-entry sederhana) - **BEDA dari
  `fstoku`/`fstokd`** yg dipakai modul stok - JANGAN campur aturan soft-cancel `fstoku` ke
  sini. Tidak ada trigger di `ctransaksiu`/`ctransaksid` (dicek `SHOW TRIGGERS`).
- `App\Services\KasBankWriter` - SATU writer diparametrisasi `$sumber` (KM/KK/BM/BK, const
  kelas) melayani ke-4 form, krn strukturnya identik (cuma beda arah debit/kredit +
  filter COA + field khusus Bank). `App\Livewire\Fina\KasBankListBase`/`KasBankFormBase`
  = base abstrak, 4 subclass tipis (`KasMasukList`/`Form`, `KasKeluarList`/`Form`,
  `BankMasukList`/`Form`, `BankKeluarList`/`Form`) cuma override `sumber()`/`judul()`/dst.
  2 blade view dipakai bersama (`kas-bank-list.blade.php`/`kas-bank-form.blade.php`).
- **Double-entry**: baris 1 = rekening (`CUREKKAS`). MASUK → rekening DEBIT, baris biaya
  KREDIT. KELUAR → rekening KREDIT, baris biaya DEBIT. Total = SUM baris biaya (dihitung
  server-side dari `$lines`, tidak ada input total terpisah).
- **COA "rekening" (`CUREKKAS`)**: filter `bcoa.CTIPE=0` (grup KAS) utk form Kas,
  `CTIPE=1` (grup BANK) utk form Bank (`LookupController::coaRekening(?tipe=kas|bank)`) -
  BEDA/PERBAIKAN dari CI3 yg query `view_coa_kas()` cuma `ctipe=0` (makanya CI3 butuh form
  Bank terpisah dgn dropdown lain).
- **COA "biaya" (baris detail, `CDNOCOA`)**: whitelist `bcoa.CKASMASUK=1`/`CKASKELUAR=1`
  (SUDAH dikurasi di data master nyata, pola SAMA CI3, dipakai APA ADANYA utk Kas MAUPUN
  Bank - `LookupController::coaBiaya(?arah=masuk|keluar)`).
- **Field khusus Bank** (`CUTIPE` 0=Tunai/1=Giro/2=Transfer, `CUBANK` dari `bbank`,
  `CUNOGIRO`/`CUTGLTEMPO`) HANYA form Bank, kondisional per `tipeBayar` (Transfer→tampil
  `bank`, Giro→tampil `noGiro`+`tglGiro`) - Kas TIDAK PERNAH mengisi kolom2 ini.
- **Hapus = HARD DELETE** (`DELETE FROM ctransaksiu/ctransaksid`, pola CI3
  `hapusTransaksi()`) - BUKAN soft-cancel `SUSTATUS=9` spt `fstoku` (tabel beda, konvensi
  beda). `nextNumber()` re-hitung `MAX()` dari isi tabel SEKARANG - slot nomor yg
  dihapus BISA kepakai lagi (bukan bug).
- **TIDAK ADA data histori nyata** di DB ini utk KM/KK/BM/BK (0 baris, beda dari modul2
  lain yg py data import) - verifikasi HANYA via tinker sintetis + baca kode CI3.
- Menu: `finance.kas-masuk`/`kas-keluar`/`bank-masuk`/`bank-keluar` (sort 61-64,
  menggantikan placeholder lama `finance.cashbank` yg DIHAPUS manual dari `lv_menu`
  sblm re-seed - pola sama pemindahan menu SJ/PR sesi sblmnya).

## Laporan pertama: Penjualan Per Barang

- Pola LENGKAP laporan PDF pertama yg dibangun pakai infrastruktur `PdfReport` -
  dipakai sbg TEMPLATE utk laporan berikutnya: (1) tab filter Livewire
  (`App\Livewire\Reports\PenjualanPerBarang`) dgn 2 tombol AKSI LANGSUNG - lihat
  "Tombol PDF/Excel langsung" di bawah (REVISI 2026-09-23, gantikan pola awal
  set-`$pdfUrl`-lalu-klik-link-terpisah), (2) route BIASA (`GET reports/<nama>`) →
  (3) method di `App\Http\Controllers\ReportController` yg query data + panggil
  `PdfReport::preview()`, (4) view `resources/views/reports/<nama>.blade.php`
  dibungkus `<x-reports.layout>`.
- Data dari `fstoku`/`fstokd` (`SUSUMBER='IP'`, POS - SAMA tabel yg ditulis
  `PosSaleWriter`), filter `SUSTATUS<>9` (exclude batal, konvensi universal). Qty=
  `SDKELUAR`, Harga=`SDHARGA`, Disc1/2=`SDDISKONPERSEN`/`SDDISKONPERSEN2` (PERSEN,
  bukan Rp), `SDDISKON`=Rp diskon/unit hasil kaskade disc1×disc2 (SUDAH final, dipakai
  hitung Jumlah). **TIDAK ADA kolom subtotal tersimpan di `fstokd`** - Jumlah dihitung
  `(SDHARGA - SDDISKON) * SDKELUAR` di controller, bukan query SQL murni.
  No.HP pasien = `bkontak.K1TELP1`.
- Item **OPSIONAL** (2026-09-23, revisi per permintaan user - awalnya wajib) - kosong =
  SEMUA item, kolom "Item" muncul kondisional (`$showItemColumn`) di kolom PERTAMA saat
  tanpa filter; kalau item DIPILIH, nama/kode pindah ke `subtitle` & kolom disembunyikan
  (hemat lebar, sesuai versi awal). Tanggal TETAP wajib (batasi lebar tabel `fstokd`,
  ~25rb baris IP aktif total - "semua item" 1-2 hari SAJA bisa ribuan baris).
  Orientasi Landscape (`orientasi=>'L'` di `PdfReport::preview()` opt array).
- **GOTCHA memory ditemukan sekaligus (2026-09-23)**: render tabel BESAR (ribuan baris,
  mis. "semua item" tanpa filter) via mpdf makan memori JAUH lbh besar drpd ukuran HTML
  mentahnya - 2.750 baris (2 hari data) SAJA sudah menghabiskan default PHP 128M. Fix:
  `PdfReport::make()` SEKARANG `ini_set('memory_limit', '512M')` scope-per-request
  (pola sama `pcre.backtrack_limit` yg sudah ada) - kalau laporan lain masih OOM,
  naikkan lagi DI SINI (satu tempat), bukan per-controller.
- Filter cabang OPSIONAL + auto-scope ke `auth()->user()->branchIds()` (defense-in-depth,
  pola sama list transaksi lain) kalau user py `UCABANGPILIH` terbatas.
- Menu group BARU `laporan` (sort 80, top-level, terpisah dari `finance`/`sales` krn
  laporan lintas-modul) - `laporan.penjualan-per-barang` (sort 81).
- **Default rentang tanggal = HARI INI s/d HARI INI (2026-09-23, permintaan user, REVISI
  dari default awal-bulan)**: krn data transaksi (`fstoku`/`fstokd` dll) besar, laporan yg
  filter tanggalnya default rentang lebar (mis. awal bulan) berisiko user TIDAK SADAR generate
  ribuan baris/OOM (lihat gotcha memory di bawah) hanya krn lupa persempit tanggal. **Konvensi
  SEMUA laporan ke depan**: `mount()` defaultkan `$from = $to = now()->toDateString()` (BUKAN
  `startOfMonth()`), user PERLU sengaja perlebar rentang kalau mau. Verified `Livewire::test()`.
- **Rename tampilan (2026-09-23, permintaan user, TIDAK ubah struktur kode)**: "Laporan
  Penjualan Per Barang" → **"Laporan IP Per Barang"**, sub-grup sidebar `laporan.penjualan`
  → **"POS"** (bukan "Penjualan" lagi). Cuma STRING label yg berubah (`MenuSeeder` title,
  `Workspace::listRegistry()` label tab, `ReportController` `title` di array data
  PDF/Excel) - `segment_key`, nama route, nama method controller (`penjualanPerBarang`),
  nama file/class (`PenjualanPerBarang`) SENGAJA TETAP tidak diubah (rename struktur kode
  bukan yg diminta, & segment_key harus tetap sama biar `MenuSeeder` idempotent tidak bikin
  baris `lv_menu` duplikat). Verified render Workspace penuh: sidebar `Laporan → POS → IP
  Per Barang` bersarang benar, judul card filter "Laporan IP Per Barang", nama file Excel
  hasil download "Laporan IP Per Barang.xls".
- **Verifikasi laporan WAJIB generate PDF sungguhan dari DATA NYATA** (bukan cuma cek
  status HTTP) - render via `Livewire::test()` (tangkap error blade) + panggil
  controller langsung dgn `Request::create()` + cek magic bytes `%PDF` + BACA VISUAL
  PDF-nya (Read tool) utk pastikan kolom/format/total benar, BUKAN cuma "200 OK".
- **Export Excel (2026-09-23, "opsi 1" - dipilih user)**: pola SAMA PERSIS CI3
  (`Laporan.php` mode=3) - view HTML `<table>` polos (BUKAN `<x-reports.layout>`, itu
  py tag khusus mpdf yg tidak dikenal Excel) diserve dgn header
  `Content-Type: application/vnd.ms-excel; charset=utf-8` + `Content-Disposition:
  attachment; filename=....xls` - Excel buka file itu apa adanya. **TIDAK BUTUH
  library baru** (PhpSpreadsheet tidak ada di environment ini). Query data DI-SHARE
  via method private (`ReportController::dataPenjualanPerBarang()`) dipakai PDF & Excel
  sekaligus, cuma view & header response yg beda.
  Nilai numerik di cell `.xls` HARUS tetap ANGKA MENTAH (bukan string ber-format
  `number_format`) supaya Excel treat sbg angka betulan (bisa dihitung ulang) -
  formatting tampilan (ribuan/persen) diserahkan ke CSS `mso-number-format` per kelas
  (`.num`, `.pct`). **GOTCHA**: `sum()` float dari kolom sepert `SDKELUAR` bisa hasil
  presisi ngaco (`3989.0333333333`) krn floating-point - WAJIB `round()` (bukan
  `number_format`) sebelum ditaruh di cell `.xls`, baik per-baris maupun baris Total.
- **Tombol PDF/Excel langsung, TANPA link perantara (2026-09-23, revisi UX per
  permintaan user - screenshot form filter)**: awalnya tombol "Tampilkan PDF" cuma
  SET properti (`$pdfUrl`/`$excelUrl`) lalu user harus klik LINK terpisah yg muncul
  kondisional ("Buka Laporan"/"Export Excel") - user minta simplifikasi: **2 tombol
  aksi langsung** ("Tampilkan PDF" & "Excel") yg begitu diklik LANGSUNG preview/
  download, tanpa langkah antara. Livewire component TIDAK BISA `window.open()`/
  trigger download langsung dari method server (`redirect()` Livewire akan
  menavigasi SELURUH SPA Workspace pergi, tidak cocok - tab lain akan hilang) - pola
  yg dipakai: method component dispatch **event browser generik** SETELAH validasi +
  bangun URL (`$this->dispatch('report-pdf-ready', url: ...)` /
  `'report-excel-download'`), listener GLOBAL di `public/js/dias-helpers.js`
  (`Livewire.on('report-pdf-ready', e => window.open(e.url,'_blank'))` /
  `Livewire.on('report-excel-download', e => window.location = e.url)`) yg
  mengeksekusi navigasi browser. PDF = `window.open` tab baru (preview, tab
  Workspace lama tetap utuh). Excel = `window.location` TANPA pindah halaman krn
  response py `Content-Disposition: attachment` (browser download di tempat, bukan
  navigasi sungguhan). **2 nama event ini GENERIK** (bukan spesifik Penjualan Per
  Barang) - laporan berikutnya cukup dispatch nama event yg SAMA, listener JS-nya
  sudah reusable, TIDAK perlu tambah listener baru per laporan.
  Verified via `Livewire::test()`: tombol render dgn `wire:click` yg benar (tombol
  lama `tampilkan()` sudah tidak ada), `assertDispatched('report-pdf-ready', ...)` /
  `'report-excel-download'` dgn payload URL yg dicek isinya benar² mengarah ke route
  PDF vs Excel yg tepat (bukan cuma "event terkirim").
- **Menu Laporan dikelompokkan per modul transaksi (2026-09-23, per permintaan user)**:
  krn laporan akan banyak, sidebar `laporan` (top-level, sort 80) SEKARANG punya sub-grup
  per modul (`laporan.penjualan`, dst ke depan `laporan.pembelian`/`inventory`/`finance`/
  `pabrik`/`admin`/`master` mengikuti 7 grup modul yg sudah ada) - laporan individual jadi
  CUCU (3 level: `laporan` → `laporan.penjualan` → `laporan.penjualan-per-barang`), BUKAN
  anak langsung `laporan`. Sidebar (`ws-sidebar-node.blade.php`) SUDAH rekursif by design
  (include dirinya sendiri per `nav-treeview`) - nesting 3 level jalan TANPA ubah komponen,
  cukup tambah baris `group` baru di `MenuSeeder` dgn `parent_segment` = `laporan`, lalu
  arahkan `parent_segment` laporan individual ke sub-grup itu (BUKAN ke `laporan` langsung).
  **Konvensi ke depan**: sub-grup kategori BARU (mis. `laporan.finance`) baru dibuat SAAT
  laporan pertama kategori itu dibuat, JANGAN bikin sub-grup kosong di muka (sidebar
  `buildTree()` toh otomatis skip grup tanpa anak yg visible, tapi lebih rapi kalau
  `MenuSeeder` sendiri tidak punya baris nganggur). Verified via `Livewire::test()` render
  workspace penuh - urutan HTML `Laporan` → `Penjualan` (sub-grup) → `Penjualan Per Barang`
  (link, `wire:click="openFromSidebar('laporan.penjualan-per-barang')"`) benar bersarang.

## GOTCHA verifikasi: `artisan view:cache` TIDAK menangkap mismatch `@if`/`@endif`

`php artisan view:cache` bisa sukses ("Blade templates cached successfully") WALAUPUN ada
`@if`/`@endif` yg tidak seimbang di view Livewire (ketahuan 2026-09-23 saat nambah field
Cabang di `pengajuan-dana-form.blade.php` - view:cache lolos, tapi render sungguhan lempar
`syntax error, unexpected token "endif"`). Livewire membungkus tiap `@if`/`@endif` dgn
directive tambahannya sendiri (`ExtendBlade::isRenderingLivewireComponent()`) saat
kompilasi-per-request, beda dari `view:cache` yg kompilasi statis - directive mismatch baru
kelihatan pas WAKTU RENDER, bukan waktu compile-cache. **Rule**: sehabis edit blade view
komponen Livewire, JANGAN cukup `view:cache` sbg bukti view valid - WAJIB render
sungguhan (`Livewire::test(Komponen::class)->html()` via tinker, atau buka tab di browser)
sebelum bilang selesai.

## Modul Finance: Pengajuan Dana (PDN)

- Tabel **TERPISAH** `ctransaksipu`/`ctransaksipd` (BUKAN `ctransaksiu`/`ctransaksid` yg
  dipakai KM/KK/BM/BK) - struktur kolom sama (CU*/CD*) tapi dokumen independen. "Pengajuan
  Dana" = konsolidasi baris biaya Kas Keluar (KK) yg BELUM ditarik ke pengajuan manapun.
- **Mekanisme "belum ditarik" via `ctransaksid.CDDIBUATPENGAJUAN`, DIJAGA TRIGGER DB**
  (`ctransaksipd_add`/`_dell`) - insert `ctransaksipd` dgn `CDBKKID`=id baris KK sumber,
  trigger OTOMATIS set flag 1; hapus baris (atau seluruh dokumen) OTOMATIS balikkan flag
  ke 0 (baris KK bisa ditarik lagi). **JANGAN update `CDDIBUATPENGAJUAN` manual** - trigger
  yg urus, kita cukup isi `CDBKKID` dgn benar.
- **BUG CI3 diperbaiki**: `ctransaksid.CDCABANG` TIDAK PERNAH diisi CI3 `M_Fina_Kas_Keluar`
  (cuma header `cucabang`) - padahal filter tarik-data Pengajuan Dana WAJIB `cdcabang`.
  `KasBankWriter::create()` SEKARANG mengisi `CDCABANG` tiap baris `ctransaksid` (fix
  dilakukan SEBELUM modul PDN dibangun, supaya data KK kita sendiri bisa ditarik).
  **Kalau modul lain ke depan insert ke `ctransaksid`/`ctransaksipd`, WAJIB isi `CDCABANG`.**
  `App\Services\PengajuanDanaWriter::pullableKk($rekening, $cabang)` filter:
  `CUSUMBER='KK' AND CUREKKAS=$rekening AND CDCABANG=$cabang AND CDDIBUATPENGAJUAN=0 AND
  CDDEBIT>0`.
  Rekening yg dipilih Pengajuan Dana HARUS SAMA dgn rekening KK yg mau ditarik.
- **Kontak** kategori "KEUANGAN" (`bkontaktipe.KTID=16`, BUKAN kontak umum,
  `LookupController::kontakKeuangan()`). **Rekening**: SEMUA akun `bcoa` leaf (`CGD='D'`,
  TANPA filter `CTIPE`, beda dari KM/KK/BM/BK) - `LookupController::coaRekening()` tanpa
  param `tipe`.
- **UX "tarik data"**: tarik SEMUA baris eligible sekaligus (SESUAI PERMINTAAN USER "tarik
  semua"), TIDAK pakai checkbox per-baris spt VB6 asli - user hapus baris yg tidak
  diinginkan (tombol remove, pola sama `PbForm`).
- **Hapus = HARD DELETE** (pola sama `ctransaksiu`/`ctransaksid`) - trigger `ctransaksipd_dell`
  otomatis lepas flag `CDDIBUATPENGAJUAN` baris KK terkait.
- Menu `finance.pengajuan-dana` (sort 65, setelah Bank Keluar).

## Laporan PDF (mpdf)

- `mpdf/mpdf` (v8.3.1) terinstall, pola port LANGSUNG dari CI3 (`dias-online-app`,
  `application/controllers/Laporan.php`) yg SUDAH jalan di produksi dgn `mpdf/mpdf ^8.0.10`.
- Service `App\Services\PdfReport`: `make()` (return objek `Mpdf`), `preview()` (inline di
  browser), `download()` (paksa unduh), `companyInfo()` (baca tabel legacy `ainfo`, SELALU
  1 baris, nama/alamat/telepon/email perusahaan utk header laporan).
- View laporan dibungkus `<x-reports.layout :title="..." :subtitle="..." :company="$company">`
  (`resources/views/components/reports/layout.blade.php`) - sudah include header
  perusahaan + footer nomor halaman (`{PAGENO}/{nbpg}`, via tag mpdf
  `<htmlpagefooter name="...">`/`<sethtmlpagefooter ... value="on" />` - JANGAN pakai
  `htmlpageheader` utk footer, nama tag beda meski isinya sama2 bisa taruh HTML apa saja).
- **Dipanggil dari ROUTE/CONTROLLER BIASA, BUKAN dari Livewire component** - render PDF
  besar tidak cocok dgn siklus hidup Livewire (re-render tiap interaksi), pola sama CI3.
- `ini_set('pcre.backtrack_limit', '5000000')` SELALU dipanggil di `PdfReport::make()` -
  tabel laporan legacy bisa sangat panjang, default PHP kurang utk regex internal mpdf saat
  parsing HTML besar (confirmed perlu di produksi CI3).
- `tempDir` mpdf = `storage/app/mpdf` (bukan default sys temp) - biar predictable & mudah
  di-clear.
- Belum ada laporan spesifik yg dibuat (infrastruktur doang, 2026-09-22) - verified via
  script sementara (generate PDF sungguhan dari view uji, cek magic bytes `%PDF` + baca
  visual via PDF reader) lalu dihapus, TIDAK ada file test permanen tersisa di repo.

## Modul Sales: Invoice Penjualan (IV)

- **Tabel TERPISAH dari `fstoku`/`fstokd`**: `einvoicepenjualanu`(`IPU*`)/`einvoicepenjualand`
  (`IPD*`) - invoice TIDAK memindah stok lagi (sudah selesai di level Surat Jalan/SJ), murni
  dokumen tagihan yg mereferensikan baris SJ yg sudah dikirim. VB6 asli:
  `eFrmInvoicePenjualan_SJ.frm`. CI3: `PJ_Faktur_Penjualan.php`. **`einvoicepenjualanu` di
  data produksi 100% KOSONG (0 baris, 2026-09-23)** - fitur belum pernah dipakai lewat
  CI3/VB6 di DB ini, jadi modul ini murni baru dari sisi data walau strukturnya legacy.
- **Sumber "Surat Jalan"**: `fstoku`/`fstokd` `SUSUMBER='SJ'` (dikonfirmasi via
  `aanomor WHERE NID=718` → `NKODE='SJ'`) - data yg SAMA ditulis `App\Livewire\Purchase\
  SjForm`/`SjList` WALAU namespace PHP-nya "Purchase" (organisasi kode saja, bukan indikasi
  modul lain) - dikonfirmasi lewat `Workspace::listRegistry()`: menu `sales.sj` MEMANG
  mengarah ke `purchase.sj-list`, satu2nya pemakai slot `SUSUMBER='SJ'` di app ini.
- **GOTCHA riset PENTING - eligibility "SJ blm ditagih" py 3 klaim BEDA, cuma 1 yg akurat**
  (VB6 & CI3 dibaca via subagent Explore dulu, KEDUANYA lalu dikonfirmasi ULANG langsung ke
  `SHOW CREATE TRIGGER`/`SHOW COLUMNS` - rule CLAUDE.md "cek trigger dulu" terbukti krusial
  di sini, bukan formalitas): VB6 pakai anti-join per (SJ, ITEM) tanpa qty parsial; CI3 KLAIM
  `fstokd.sdkeluar-sdfaktur>0` TAPI **kolom `sdfaktur` TERNYATA TIDAK ADA SAMA SEKALI** di
  skema (klaim basi/salah atribusi). **Yang BENAR2 ada & jalan**: trigger
  `einvoicepenjualand_ADD`/`_dell`/`_update` (AFTER INSERT/DELETE/UPDATE)
  `UPDATE fstoku SET SUTARIKIV=1/0 WHERE suid=NEW/OLD.ipdsuid` - flag **HEADER-level**
  (`fstoku.SUTARIKIV`), otomatis, gratis. Kolom `fstokd.SDDITARIKIV` (per-baris) JUGA ADA
  tapi TIDAK disentuh trigger manapun DAN TIDAK dipakai kode CI3 sama sekali (grep eksplisit)
  - kolom legacy yatim, diabaikan modul ini.
- **Keputusan eligibility v1 (LEBIH presisi drpd trigger header-level yg kasar)**: anti-join
  PER BARIS SJ via `einvoicepenjualand.IPDSJD = fstokd.SDID` (`InvoicePenjualanWriter::
  fromSj()`/`pullableSj()`) - begitu 1 baris SJ masuk invoice manapun, baris ITU SAJA hilang
  dari daftar tarik, baris lain di SJ yg sama tetap bisa ditagih terpisah. `fstoku.SUTARIKIV`
  TETAP kepakai otomatis (trigger DB) sbg indikator kasar "SJ pernah disentuh", TAPI TIDAK
  dipakai memutuskan eligibility - jadi keterbatasannya (reset ke 0 saat 1 dari beberapa
  invoice atas SJ yg sama dihapus, walau invoice lain msh mereferensikan SJ itu) TIDAK
  mempengaruhi kebenaran fungsional.
- **4 kolom FK SJ di `einvoicepenjualand` diisi SEMUA sekaligus** (BUKAN cross-module
  overloading spt `SDPRDID` dll - murni redundansi historis SATU tabel yg sama, aman diisi
  bareng): `IPDSUID`/`IPDSJU` = `fstoku.SUID` (SJ header - **`IPDSUID` WAJIB diisi**, itu yg
  dipakai trigger `SUTARIKIV`), `IPDSJD`/`IPDSDID` = `fstokd.SDID` (SJ baris - dipakai
  anti-join eligibility kita).
- **TEMUAN DATA (2026-09-23)**: SEMUA 5.435 baris `fstokd` `SUSUMBER='SJ'` di produksi py
  `SDHARGA=0` (dicek `SUM(CASE WHEN SDHARGA>0...)`=0, tanpa kecuali) - Surat Jalan di app ini
  MEMANG murni dokumen pengiriman TANPA harga, harga BARU diisi manual saat invoice (match
  persis kapabilitas re-pricing VB6 yg ditemukan riset). Form invoice SENGAJA buat field
  Harga/Diskon per baris EDITABLE (bukan read-only spt PB) - beda dari PB yg justru MENGUNCI
  harga dari PO krn PO memang sudah py harga final.
- **PPN disederhanakan jadi 2 mode** (`IPUJENISPAJAK`: 0=Tanpa Pajak, 1=PPN 11% exclusive) -
  VB6 py mode ke-3 "inclusive" pakai rumus `subtotal*10/111` yg tarifnya (10%) diragukan basi
  drpd PPN saat ini (11%) & sekaligus membingungkan - DIHILANGKAN, bukan direplikasi apa
  adanya. `IPUTOTALPAJAK`/`IPUSUBTOTAL`/`IPUTOTALTRANSAKSI` dihitung ULANG server-side di
  `InvoicePenjualanWriter::create()` (TIDAK percaya input klien), verified arithmetic exact
  match via tinker (subtotal per-baris `(harga-disc)*qty`, pajak `subtotal*11%` dibulatkan
  2 desimal, total = subtotal+pajak).
- **DEFER (tidak diminta scope-nya, pola sama modul2 lain sesi ini)**: DP/uang muka
  (`einvoicepenjualandp`/`ddp` - alur terpisah, blm ada modul DP Laravel sama sekali), diskon/
  ongkir/materai header (`IPUDISKON*`/`IPUBIAYAONGKIR`/`IPUBIAYAMATERAI`), faktur pajak formal
  (`IPUNOFAKTURPAJAK` dst), posting jurnal/COA otomatis (infrastruktur jurnal umum blm ada di
  Laravel app ini sama sekali - modul lain spt PB/PR/SJ/KMB/TMB/JOP jg tidak posting jurnal),
  multi-currency (selalu Rp, pola sama PDN).
- **Hapus = HARD DELETE** (dikonfirmasi VB6 `xHapusData` & CI3 `hapusTransaksi()` DUA2NYA,
  tidak ada status-void di modul ini) - trigger `einvoicepenjualand_dell` OTOMATIS balikkan
  `fstoku.SUTARIKIV=0` + baris SJ kembali eligible ditagih ulang (verified tinker: delete lalu
  `fromSj()` lagi -> baris yg tadi hilang muncul lagi).
- Menu `sales.invoice` (placeholder LAMA sudah ada, sort 34) → `Workspace::listRegistry()`
  ditambah `'sales.invoice' => ['sales.invoice-list', ...]`. Component: `App\Livewire\Sales\
  InvoiceList`/`InvoiceForm` (alias `sales.invoice-list`/`sales.invoice-form`).
- Verified LENGKAP via tinker (`DB::beginTransaction()`/`rollBack()`): pull dari SJ nyata
  (4 baris, `SDHARGA=0` asli) → set harga manual → simpan → cek baris `einvoicepenjualand`
  tertulis benar (4 kolom FK terisi) → cek trigger `SUTARIKIV` beneran nyala →
  `fromSj()` ulang confirm 0 baris eligible tersisa → `delete()` → trigger balik ke 0 →
  `fromSj()` ulang confirm 4 baris eligible lagi. Plus `Livewire::test()` List+Form render +
  buka tab dari sidebar (`openFromSidebar('sales.invoice')`) end-to-end.
- **Layout form direvisi ikut screenshot VB6 asli (2026-09-23)** - user pilih "layout saja
  dulu" (opsi lain: sekalian tambah field yg di-DEFER, DITOLAK utk sekarang). Field v1 yg
  SUDAH ada dirapikan POSISINYA persis VB6 (Pelanggan/Pajak/Tanggal/No Transaksi baris 1,
  Kontak Person(=`attention`)/Gudang/Sales/Termin baris 2, Alamat jadi `<textarea>`/Jatuh
  Tempo baris 3, grid kolom diurutkan Kode|Nama|Qty|Satuan|Harga|Diskon|Disc%|Sub Total|No SJ
  spt VB6, "SJ Terpilih" ditambah sbg badge list read-only [dari `noSj` unik tiap baris] +
  Catatan kiri-bawah, rekap Total Qty/Sub Total/Pajak/Total kanan-bawah dlm kotak border) -
  **TIDAK ada field/kolom BARU** (Jenis Invoice, Diskon header, Materai, Ongkir, Faktur Pajak,
  UP% masih DEFER, lihat poin di atas). Verified `Livewire::test()`: render + pull SJ nyata
  (badge SJ muncul, 4 baris tampil) + save masih berhasil (no regression dari reposisi).

## Modul Sales: Invoice Penjualan Mutasi (IVM)

- Sibling `InvoicePenjualanWriter` (IV) - SATU tabel yg SAMA `einvoicepenjualanu`/`d`,
  dibedakan `IPUSUMBER='IVM'` (bukan tabel terpisah, VB6 konfirmasi literal). Tagih cabang
  PENERIMA mutasi (`fstoku`/`fstokd` `SUSUMBER='TMB'`, ditulis `TmbWriter`), BUKAN
  pelanggan eksternal. VB6 asli: `eFrmInvoicePenjualan.frm` (BEDA file dari
  `eFrmInvoicePenjualan_SJ.frm` yg dipakai IV).
- **GOTCHA riset**: VB6 file ini SECARA LITERAL query `SUSUMBER='SJ'` (kemungkinan besar
  BUG COPY-PASTE dari form SJ - variabel msh bernama `IDSJ`/`TampilkanDataSJ`). **User
  EKSPLISIT instruksikan sumbernya TMB** - instruksi user menang atas literal SQL VB6 yg
  diragukan. Field `IPUGUDANG`+`IPUGUDANGTUJUAN` (asal+tujuan) & harga default `bitem.
  ICOGS` di form ini TIDAK relevan utk SJ pelanggan biasa (SJ tidak py "gudang tujuan") -
  jelas dirancang utk mutasi antar cabang, cuma klausa sumbernya kelewat diganti pas dev.
- **`IPUGUDANG` (asal) & `IPUGUDANGTUJUAN` (tujuan, YG DITAGIH) OTOMATIS terisi dari TMB
  yg ditarik** (BUKAN dropdown manual spt IV): `IPUGUDANGTUJUAN` = `fstoku.SUCABANG` TMB
  itu sendiri (cabang pembuat TMB = penerima, konvensi `TmbWriter`); `IPUGUDANG`
  ditelusuri TRANSITIF `TMB.SUPRUID` → `KMB.SUID` → `KMB.SUCABANG` (cabang pengirim,
  konvensi `KmbWriter`). **Sebagian TMB historis TIDAK py `SUPRUID` valid (asal NULL)** -
  form BLOK simpan kalau gudang asal tidak ketemu (validasi eksplisit), tidak fallback
  tebak-tebak.
- **Harga default = `bitem.ICOGS`** (bukan `SDHARGA`, SELALU 0 di `fstokd` TMB jg -
  dicek eksplisit sama spt SJ), EDITABLE.
- **Efek simpan TAMBAHAN (dari VB6, TIDAK ADA di IV biasa)**: `UPDATE bitem SET
  IHARGADEPO=<harga invoice> WHERE IID=<item>` per baris - sinkronkan "harga depo" item
  master ke harga mutasi TERBARU. **Satu arah** - hapus invoice TIDAK mengembalikan
  `IHARGADEPO` (sama spt VB6 aslinya, bukan disengaja dibatasi kita).
- **Eligibility - VB6 TERNYATA TIDAK PY anti-join server-side sama sekali** (cuma exclude
  no TMB yg sudah ada di grid SESI INI, client-side - gap nyata, TMB bisa ditagih dobel ke
  invoice berbeda di VB6 asli). **Modul ini SENGAJA perbaiki gap itu** - pola SAMA
  `InvoicePenjualanWriter`: anti-join PER BARIS via `IPDSJD=fstokd.SDID`. 4 kolom FK
  (`IPDSUID`/`IPDSJU`/`IPDSJD`/`IPDSDID`) diisi sama semua (nama "SJ"-sentris murni
  historis, generik utk fstoku/fstokd manapun).
- Kontak (pelanggan) **OPSIONAL** (bkontak tidak py kategori "Cabang"/internal, dicek
  `bkontaktipe`) - identitas "siapa ditagih" cukup lewat `IPUGUDANGTUJUAN`.
- PPN/hard-delete/DEFER: SAMA PERSIS `InvoicePenjualanWriter`, tidak diulang di sini.
- Picker "Tarik dari TMB" (`pullableTmb()`) scope cabang cek KEDUA sisi (asal ATAU
  tujuan match `branchIds()` user) - beda dari IV yg cuma 1 sisi (SJ tidak py 2 gudang).
- Menu `sales.invoice-mutasi` (BARU, sort 35, `sales.return` digeser ke 36).
  `App\Livewire\Sales\InvoiceMutasiList`/`InvoiceMutasiForm` (alias `sales.invoice-mutasi-
  list`/`-form`). Layout form ikut pola sama IV (VB6-inspired, lihat section "Modul Sales:
  Invoice Penjualan") tp Pelanggan diganti 2 field read-only Gudang Asal/Tujuan.
- Verified LENGKAP via tinker (`DB::beginTransaction()`/`rollBack()`) dgn data TMB NYATA
  (2 baris, harga ICOGS asli) - pull → override harga manual → simpan → cek header
  `IPUGUDANG`/`IPUGUDANGTUJUAN` benar → cek `bitem.IHARGADEPO` SEBELUM/SESUDAH beneran
  berubah → cek trigger `SUTARIKIV` nyala → `fromTmb()` ulang 0 eligible → `delete()` →
  trigger balik → `fromTmb()` ulang 2 eligible lagi. Plus `Livewire::test()` List+Form +
  buka tab dari sidebar end-to-end.

## Navbar: cabang aktif ditampilkan di sebelah nama user (2026-09-24)

`App\Models\User::activeBranchName()` (baca `UCABANG` → `bgudang.GNAMA`, fallback "Semua
Cabang" kalau `UCABANG` kosong/0) dipakai di `resources/views/livewire/workspace.blade.php`
(navbar `wire:ignore`, dropdown user) - tampil di label dropdown ("Administrator · Petogogan")
DAN di dalam panel dropdown. Verified `Livewire::test()` render nyata.

## Navbar: Ganti Cabang Aktif (untuk user multi-cabang) (2026-09-24)

- **Permintaan user**: user dgn `UCABANGPILIH` BERISI LEBIH DARI 1 GID (mis. admin4
  py 31 cabang, bukan "kosong=unrestricted" spt dugaan awal - `UCABANGPILIH` SELALU
  daftar EKSPLISIT, tidak ada makna "kosong = semua" di kolom ini sendiri, cuma
  `branchIds()` yg mengembalikan `[]` KALAU string-nya genuinely kosong) butuh cara
  GANTI cabang aktif tanpa logout/ganti akun.
- **Mekanisme**: session-only (`session(['active_cabang_{UID}' => $gid])`, KUNCI PER-USER
  biar aman multi-akun beda browser tab/device), `auser.UCABANG` di DB TIDAK PERNAH
  ditulis (bukan ganti default permanen, cuma "aktif SAAT INI").
- **Accessor Eloquent `User::getUCABANGAttribute()`** (nama method WAJIB persis
  `getUCABANGAttribute`, huruf besar semua - dicek eksplisit `Str::studly('UCABANG')` =
  "UCABANG" bukan "Ucabang", krn kolom aslinya SUDAH all-caps) - baca session override
  dulu (validasi HARUS ada di `branchIds()` user, cegah lompat ke cabang yg tidak
  diizinkan), fallback ke nilai asli DB. **Efeknya sangat luas SECARA OTOMATIS**: SEMUA
  kode existing yg baca `$user->UCABANG` langsung (puluhan `mount()` Livewire component
  lain - PrForm/PbForm/KasBankFormBase/dst - default cabang/gudang transaksi baru) IKUT
  cabang aktif hasil switch TANPA disentuh satu-per-satu - `activeBranchName()` (section
  di atas) jg otomatis ikut, murni konsekuensi baca `$this->UCABANG` yg sama.
- `User::switchActiveCabang(int $gid): bool` - tolak (return false, TIDAK ubah session)
  kalau `$gid` bukan bagian `branchIds()` user (cegah tebak-GID via request manual).
- `Workspace::switchCabang(int $gid)` - panggil `switchActiveCabang()`, kalau sukses
  `$this->redirect(route('workspace'))` (RELOAD PENUH, bukan cuma re-render) - tab yg
  lagi terbuka bisa py default cabang lama dari mount()-nya, reload bersih lebih aman
  drpd sinkron ulang tiap komponen anak satu-satu.
- UI: dropdown navbar (`workspace.blade.php`, di DALAM `wire:ignore` - `wire:click` TETAP
  jalan di situ, pola SAMA persis sidebar yg sudah lebih dulu terbukti jalan lewat
  `ws-sidebar-node.blade.php`) - section "Ganti Cabang Aktif" HANYA muncul kalau
  `Branch::active()->whereIn('GID', branchIds())->count() > 1` (bukan cuma `count
  (branchIds()) > 1` mentah - cabang NONAKTIF di `branchIds()` tidak dihitung, cegah
  switcher muncul percuma utk user yg techically py >1 GID di `UCABANGPILIH` tp cuma 1
  yg beneran aktif/bisa dipilih). Cabang aktif SAAT INI ditandai centang + tombolnya
  disabled.
- Verified LENGKAP via tinker: user genuinely multi-active-cabang (UID=7, 48 GID
  eksplisit) → switcher tampil, `switchCabang()` via komponen Livewire ubah `UCABANG`
  (dicek fresh `Auth::user()` stlh call, BUKAN cache lama) + `assertRedirect()` ke
  `workspace` + GID TIDAK VALID ditolak (UCABANG tidak berubah). Regresi: user 1
  cabang murni (branchIds=[1]) → switcher TIDAK muncul, benar.

## Modul Inventory: Permintaan Barang (PR) - kolom "Real Stok"

- Kolom "Real Stok" (stok sistem, `bitem.ISTOK{kode}`) di grid item `PrForm` DISEMBUNYIKAN
  DEFAULT (2026-09-24, permintaan user) - user isi Qty dari HASIL HITUNG MANUAL/buku
  catatan sendiri, BUKAN nyontek angka stok sistem (mencegah anchoring bias). Stok TETAP
  dihitung & disimpan spt biasa (`refreshStock()`/`addItem()`/`PBDSTOKREAL` tidak berubah
  sama sekali) - CUMA kolom TAMPILANNYA yg toggle (`$showStok`, method `toggleStok()`).
  Tombol "Tampilkan/Sembunyikan Stok Sistem" di atas grid.
  **Revisi (2026-09-24, sama hari)**: awalnya toggle ini terbuka utk SIAPA PUN yg buka
  form ("bukan role-gated permission" - keputusan awal, TERNYATA salah) - user tegaskan
  HARUS khusus verifikator, `can_do('inventory/pr','approve')` (SAMA PERSIS hak yg dipakai
  tombol Verifikasi & notif lonceng - bukan permission baru). Digate blade (`@if`) DAN
  server-side `toggleStok()` (defense-in-depth, pola sama mount()/save()). Verified
  `Livewire::test()`: user tanpa `approve` - tombol hilang DAN panggil `toggleStok()`
  langsung (bypass tombol) TETAP diblok (`showStok` tidak berubah); user DGN `approve` -
  tombol muncul & toggle berfungsi normal.
- Kolom "Real Stok" jg dipindah posisinya (permintaan user terpisah, sama hari) - skrg
  persis sebelah "Satuan" (bukan di ujung kanan dekat "Catatan").
- **Input "Stok" BARU ditambahkan (2026-09-24, sama hari, permintaan user terpisah) -
  simpan ke `PBDSTOK`**, SELALU tampil (BUKAN ditoggle spt "Real Stok") persis sebelah
  "Satuan". Ini kolom MANUAL (`lines[].stokManual`, default `0.0` saat `addItem()`) - user
  isi hasil hitung fisik/buku catatan SENDIRI, TERPISAH dari "Real Stok" sistem
  (`lines[].stok` -> `PBDSTOKREAL`, TIDAK berubah). `refreshStock()` (dipicu ganti Depo/
  Farmasi) HANYA update `stok` (sistem) - `stokManual` TIDAK ikut ke-reset, murni milik
  input user. Verified `Livewire::test()`: input tampil independen dari toggle Real Stok,
  `save()` tulis `PBDSTOK`≠`PBDSTOKREAL` dgn benar (2 nilai beda dicek eksplisit di DB),
  reload PR tersimpan muat ulang `stokManual` dari `PBDSTOK` dgn benar (bukan ketiban 0).

## Modul Inventory: Cetak "Surat Permintaan Pelanggan" (PR)

- Cetak PDF SATU dokumen PR (`App\Http\Controllers\PrPrintController::show()`, route
  `inventory/pr/{id}/print`), pola SAMA `PosReceiptController` (cetak 1 transaksi, BUKAN
  laporan tabel banyak baris) tapi A4 penuh via `PdfReport` (mpdf) krn layoutnya kompleks
  (blok info 2 kolom + tabel + blok tanda tangan) - bukan struk thermal spt POS.
- **Layout & mapping kolom DIREPLIKASI PERSIS dari contoh PDF cetakan sistem lama** yg
  diberikan user (dicocokkan ke `fpermintaanbarangu` PBUID=40875 by nomor transaksi,
  SEMUA field dikonfirmasi lewat query langsung, bukan tebakan) - termasuk label kolom yg
  TERKESAN SALAH tapi SENGAJA dipertahankan: "No PO" = `PBUNOTRANSAKSI` (nomor PR itu
  SENDIRI, bukan referensi PO), "Tipe" = `blain.LKODE` (kode pendek, BEDA dari `LNAMA` yg
  dipakai "Gudang / Supplier Tujuan" via `bgudang.GNAMA` dari `PBUGUDANGSUMBER`), "Dari
  Cabang" = `bgudang.GNAMA` dari `PBUGUDANG` (field yg di FORM Laravel dilabeli
  "Depo/Farmasi" - label BEDA form vs cetakan, kolom SAMA), kolom tabel "Nama Item" =
  literal `bitem.IKODE` (kode, BUKAN `INAMA`).
- **GOTCHA DATA ditemukan sekaligus**: 2 item di PDF contoh (IID 3812/3638) TERNYATA py
  `IKODE`/`INAMA` TERTUKAR di DB (kebalikan dari konvensi normal IKODE=kode pendek/INAMA=
  nama panjang yg dipakai KONSISTEN 97% item lain) - dicek: **87 dari 2.764 item aktif
  (~3%) py pola tertukar serupa**. Ini masalah KUALITAS DATA legacy (kemungkinan import
  lama), BUKAN bug kode - `PrPrintController` TETAP pilih `IKODE` apa adanya (benar utk
  mayoritas item), TIDAK ada heuristik kompensasi (tidak ada cara aman membedakan item
  yg genuinely tertukar vs tidak).
- "Real Stok" di cetakan = `PBDSTOKREAL` (stok SISTEM tersimpan saat PR dibuat, BUKAN
  `PBDSTOK` yg manual - lihat 2 kolom stok di section Permintaan Barang atas).
- Blok tanda tangan "Dibuat/Diketahui/Disetujui Oleh" HANYA label statis + garis kosong -
  TIDAK ada kolom penyimpan nama penandatangan di DB manapun (tetap kosong spt PDF asli).
- **Nama perusahaan direvisi (2026-09-24, sama hari, setelah user tunjukkan cetakan asli)**:
  BUKAN lagi `ainfo` global - `CONCAT(bnamapt.NPNAMACLINIC, ' ', bnamapt.NPNAMA2)` via
  `bgudang.GPT` dari cabang "Dari Cabang" (`PBUGUDANG`). `NPNAMACLINIC` SELALU "NMW CLINIC"
  (semua cabang), `NPNAMA2` = badan hukum BEDA per cabang/franchise (mis. Ciputat → "PT.
  Exclusive Igyolini Healthcare", Petogogan → "PT. Igyolini Indonesia", Yogyakarta → "PT.
  Royal NMW Jogja" - dicek 10 cabang aktif, semua benar & beda2). `bgudang.GNAMAPT` SUDAH
  py teks jadi yg sama (denormalisasi dari `NPNAMA2`) TAPI user eksplisit minta join
  `bnamapt` via `GPT`, dipakai persis itu (lebih akurat kalau `bnamapt` diedit tanpa sync
  ulang `GNAMAPT`).
- **Posisi "Total Qty" direvisi** - PINDAH ke `<tfoot>` tabel item itu sendiri (kolom
  No+Nama Item digabung `colspan`, angka totalnya PERSIS di bawah kolom Qty), BUKAN lagi
  tabel info terpisah di bawah tabel item.
- **Lebar kolom label info diperlebar** (130px → 165px) - "Gudang / Supplier Tujuan :"
  sebelumnya kepotong jadi 2 baris, sekarang 1 baris.
- **Titik dua (:) dirapikan jadi 1 kolom sejajar (2026-09-24, sama hari, REVISI 2x)** -
  sebelumnya colon nempel langsung di teks label (`"Nama Karyawan :"` sbg satu string dlm
  1 `<td>` lebar tetap) jadi POSISI colon ikut panjang-pendek teksnya (berantakan). Fix:
  label & colon jadi 2 `<td>` TERPISAH (`.label` teks TANPA colon, `.colon` kolom sendiri
  lebar 8px isi ":" doang) - krn colon py kolom SENDIRI, semua baris otomatis sejajar 1
  kolom vertikal TERLEPAS dari alignment label. **Revisi ke-2 (user tunjukkan hasil
  cetak)**: percobaan pertama label dibuat `text-align: right` (nempel ke colon) - user
  MINTA TETAP rata KIRI spt semula, cukup diubah balik `.label { text-align: left }` -
  colon TETAP sejajar (independen dari alignment label, krn kolomnya terpisah), TIDAK
  perlu ubah struktur HTML lagi, cuma 1 baris CSS. Verified baca visual PDF sungguhan.
- **Otomatis tampil setelah `PrForm::save()`** (create MAUPUN update) - dispatch event
  browser GENERIK `report-pdf-ready` (SAMA persis dipakai laporan lain, lihat docblock
  `Reports\PenjualanPerBarang` - listener global `dias-helpers.js` SUDAH ada, TIDAK perlu
  listener baru) dgn `url: route('inventory.pr.print', $prId)`. Tombol "Cetak" manual jg
  ditambah di header form (tampil begitu `$prId` ada, TIDAK terikat status `$locked` -
  bisa cetak ulang kapan saja).
- Verified: PDF sungguhan digenerate dari data NYATA (PBUID=40875, sama PR yg jadi contoh
  PDF user) + BACA VISUAL (Read tool) - SEMUA field cocok persis kecuali nama perusahaan
  (disederhanakan, disengaja) & isi kolom "Nama Item" (data source IKODE/INAMA tertukar
  utk 2 item contoh ini spesifik, BUKAN bug kode - lihat gotcha di atas). Plus
  `Livewire::test()`: `save()` men-dispatch event dgn URL yg benar (dicek isinya, bukan
  cuma "event terkirim"), tombol "Cetak" muncul stlh save.

## Modul Inventory: Hak Akses Permintaan Barang (PR) dikeraskan + tombol Cetak di list

- **GOTCHA KEAMANAN ditemukan & diperbaiki (2026-09-24, permintaan user "sesuaikan dengan
  hak akses")**: sebelum ini, `PrForm::mount()` dan `PrForm::save()` **TIDAK PUNYA SATU PUN
  `can_do()` check** - tombol "Permintaan Baru" cuma digate di BLADE (`PrList`), method
  `newPr()`/`editPr()` di baliknya TIDAK dicek server-side, dan `PrPrintController::show()`
  (route biasa) TIDAK dicek SAMA SEKALI. Artinya user tanpa hak apapun bisa: buka tab PR
  form via event `open-tab` langsung (bypass tombol UI), SIMPAN transaksi, dan akses PDF
  cetakan siapapun cuma dgn tebak URL `/inventory/pr/{id}/print` - SEMUA celah nyata,
  bukan teoretis (dikonfirmasi via tinker: user tanpa hak BERHASIL 403 sblm fix, lolos
  begitu saja sesudahnya sblm fix diterapkan).
- **Fix - defense-in-depth di SEMUA layer** (blade gate = UX saja, method/mount() gate =
  penjaga NYATA):
  - `PrList::newPr()`/`editPr()` (BARU): `can_do('inventory/pr','add'/'view')`.
  - `PrList::printPr()` (BARU, tombol Cetak di kolom Aksi list - permintaan user sesi ini):
    `can_do('inventory/pr','print')`, dispatch event generik `report-pdf-ready` (reuse,
    TANPA buka form dulu).
  - `PrForm::mount()` (BARU): `abort_unless(can_do('inventory/pr', $prId?'view':'add'), 403)`
    - berlaku baik buka data lama (perlu 'view') MAUPUN buat baru (perlu 'add').
  - `PrForm::save()` (BARU): cek `can_do('inventory/pr', $prId?'edit':'add')` sebelum proses
    - beda ability utk create vs update (konsisten sama 6 ability standar ACL app ini:
    view/add/edit/delete/print/approve).
  - `PrForm::printPr()` + auto-dispatch cetak stlh `save()` (BARU): `can_do(...,'print')` -
    user tanpa hak cetak TETAP bisa simpan, cuma PDF tidak otomatis kebuka.
  - `PrPrintController::show()` (BARU, PALING PENTING - satu2nya penjaga NYATA endpoint
    PDF krn route biasa bisa diakses langsung via URL): `abort_unless(can_do(...,'print'),403)`.
  - `cancel()`/`openVerify()`/`saveVerify()` SUDAH benar dari awal (server-side + blade),
    TIDAK diubah - dikonfirmasi ulang tetap konsisten.
- Blade `pr-list.blade.php`/`pr-form.blade.php`: tombol Buka/Simpan/Cetak SEKARANG semua
  `@if (can_do(...))` (sebelumnya tombol "Buka" & "Simpan" TIDAK digate blade sama sekali).
- Verified LENGKAP via tinker (`DB::beginTransaction()`/`rollBack()`, user NON-super nyata
  UID=6 dgn `lv_user_menu` diubah-ubah per skenario + `app('acl')->refresh()`): 5 skenario
  (kosong/view-saja/print-saja/add-saja/edit+view) x cek tombol blade TERSEMBUNYI/TAMPIL +
  method server-side DIBLOK/DIIZINKAN + `PrForm::mount()` 403 yg benar + `PrPrintController`
  403/200 yg benar - SEMUA lolos. **Gotcha verifikasi sendiri**: 2 kesalahan deteksi di
  skrip tes (bukan bug app) - (1) `Livewire::test()` yg mount()-nya `abort_unless()` TIDAK
  melempar exception ke kode pemanggil, harus dicek via `assertStatus(403)`, bukan try/catch
  langsung; (2) `str_contains($html,'>Simpan<')` false-negative krn ada whitespace/newline
  antara `>` dan teks tombol di blade asli - ganti pakai marker unik `wire:target="save"`.

## Modul Inventory: Urutan field form PR (2026-09-25)

Urutan baris `PrForm` diubah per permintaan user (sesuai screenshot): **Nama Karyawan →
Depo / Farmasi → Tujuan → Gudang Tujuan (PO Ke) → Tipe Permintaan → Keterangan**
(sebelumnya Karyawan → Tujuan → Tipe → Depo → Gudang Tujuan → Keterangan). MURNI urutan
tampilan di blade - binding/logika tiap field TIDAK disentuh. Verified `Livewire::test()`:
urutan render benar + reaktivitas `updatedTujuan()` (pilih Tujuan berkategori gudang tetap
→ `gudangSumber` & `jenis` ikut berubah, badge Tipe update) tetap jalan + PR tersimpan
tetap load benar.

## Modul Inventory: Filter "Cabang Tujuan" di daftar PR (2026-09-25)

Filter baru `PrList::$fGudangTujuan` → `PBUGUDANGSUMBER` (gudang/cabang yg DIMINTA
MENGIRIM). **Jangan tertukar dgn 2 hal lain yg mirip namanya**: `$fCabang` = `PBUGUDANG`
(cabang PEMINTA), dan kolom "Tujuan" di tabel = KATEGORI dari `blain` via
`PBUTIPEPERMINTAAN` (Cabang/Depo/Farmasi/Pabrik - bukan cabang spesifik). Kolom yg sama
(`PBUGUDANGSUMBER`) memang dilabeli BEDA-BEDA sejak awal: "Gudang Tujuan (PO Ke)" di form,
"Gudang / Supplier Tujuan" di cetakan, "Gudang Asal" di kolom list. Dropdown-nya pakai
`Branch::options()` (SEMUA cabang aktif), BUKAN `branchOptions()` yg dibatasi
`branchIds()` - cabang tujuan tidak ada hubungannya dgn hak akses cabang si peminta.
Verified data nyata: tujuan Depo (GID 20) = 364 baris, Depo Farmasi (46) = 171, cocok
hitungan SQL langsung; gabungan dgn filter cabang peminta & pencarian teks jg benar.

## Modul Inventory: Filter Status PR hanya berlaku Permintaan Pembelian (jenis=1)

**Bug ditemukan & diperbaiki (2026-09-24, dikonfirmasi dari screenshot user, 2 PUTARAN)**:
filter dropdown Status (Belum Verifikasi/Pending/Disetujui/dst, `PrList::$fStatus`)
sebelumnya CUMA `WHERE PBUSTATUS = :fStatus` - krn PR jenis=0 (Permintaan Barang, alur
RS→KMB→TMB TANPA verifikasi) `PBUSTATUS`-nya TIDAK PERNAH berubah dari 0 (progres dilacak
via `PBUSTATUSKM`+status KMB, badge terpisah "Belum Ditarik"/"Dikirim (KMB)"/"Diterima
(TMB)" - lihat `$kmTmbBadge` di blade), filter "Belum Verifikasi" (value 0) ikut menjaring
SEMUA baris jenis=0 apapun progresnya - jelas bukan yg dimaksud user.
- **Fix putaran 1**: tambah `->where('PBUJENIS', 1)` setiap kali filter status aktif.
- **Bug KEDUA ditemukan user TEPAT SETELAH fix putaran 1**: status=9 "Batal" JUSTRU
  hilang total utk PR jenis=0 yg BENERAN dibatalkan - krn `PurchaseRequestWriter::cancel()`
  set `PBUSTATUS=9` TANPA syarat jenis (jenis=0 JUGA bisa dibatalkan via tombol Batalkan di
  list), fix putaran 1 yg nge-blok SEMUA jenis=0 kalau filter status aktif jadi KEBABLASAN
  ikut nyembunyiin baris Batal jenis=0 yg valid.
- **Fix final**: batasan `PBUJENIS=1` HANYA berlaku utk status 0-7 (progresi verifikasi,
  genuinely konsep jenis=1-only), BUKAN utk status 9 "Batal" (berlaku KEDUA jenis) -
  `->when((int)$fStatus !== PurchaseRequestWriter::STATUS_BATAL, fn($b)=>$b->where('PBUJENIS',1))`
  di DALAM `->when($fStatus!=='', ...)`.
- Verified `Livewire::test()` dgn data NYATA (PR jenis=0 `PG-RS26090044` yg user beneran
  batalkan di sesi ini - row yg SAMA persis muncul di screenshot sebelumnya dgn badge
  "Belum Ditarik"): filter "Batal" → muncul benar; filter "Belum Verifikasi" → jenis=0
  (badge Belum Ditarik/Dikirim KMB/Diterima TMB) tetap hilang, regresi tidak terjadi.
- **Bug KETIGA ditemukan user TEPAT SETELAH fix ke-2** (row `PG-RS26090044` yg SAMA lagi):
  filter "Batal" SUDAH benar menampilkan baris itu, TAPI badge Status-nya masih nampilin
  "Belum Ditarik" alih2 "Batal" - krn `$kmTmbBadge` (badge KMB/TMB khusus jenis=0)
  **TIDAK PERNAH mengecek `PBUSTATUS` sama sekali**, cuma liat `PBUSTATUSKM`/status KMB
  terkait - PR jenis=0 yg dibatalkan (`PBUSTATUS=9`) tapi belum sempat ditarik ke KMB
  (`PBUSTATUSKM=0`) ttp kena cabang "Belum Ditarik" dari `$kmTmbBadge`, BUKAN "Batal".
  **Fix**: badge selection di blade SEKARANG match `(int)$r->status===9 => Batal` DULU
  (menang atas SEMUA jenis), baru fallback ke `$kmTmbBadge`(jenis=0)/`$statusBadge`(jenis=1)
  kalau bukan batal. Verified `Livewire::test()` dgn `PG-RS26090044` yg SAMA: badge
  sekarang benar "Batal" (bukan "Belum Ditarik" lagi); regresi dicek - jenis=0 non-batal
  & jenis=1 non-batal tetap tampil badge masing2 spt semula.

## Modul Pembelian: Picker "Tarik dari PR" di PKB SENGAJA lintas-cabang

**Dikonfirmasi user (2026-09-24)**: `PurchaseRequestWriter::pullableForPkb()` (picker
"Tarik dari PR (Permintaan Barang yang sudah Disetujui)" di `PkbList`) SUDAH & SENGAJA
TIDAK dibatasi cabang (beda dari picker "tarik" modul lain spt PB/PDN/dll yg scope ke
`branchIds()` user) - PKB dibuat TERPUSAT oleh SATU user yg urus SEMUA cabang, jadi
picker ini MEMANG harus nampilin PR Disetujui dari SELURUH cabang sekaligus, BUKAN cuma
cabang user login. **Perbaikan ditambahkan (bukan filter cabang, tapi VISIBILITAS)**:
picker sebelumnya cuma tampilkan Nomor/Tanggal/Karyawan tanpa info cabang SAMA SEKALI -
dgn banyak PR campuran dari berbagai cabang, user pusat tidak bisa bedakan/cari per
cabang. Ditambah: kolom `cabang` (`bgudang.GNAMA` via `PBUGUDANG`) di-JOIN & ditampilkan
per item picker + ikut kena filter pencarian teks (`pickerQ` sekarang jg cocok ke nama
cabang, bukan cuma no PR/karyawan). Verified `Livewire::test()` dgn data nyata: cabang
tampil benar di item picker, search by nama cabang narrow hasil dgn benar (cocok & tidak
cocok dua2nya dicek).

## Tema terang: background digelapkan sedikit (2026-09-24)

Bootstrap 5.3 default `--bs-tertiary-bg` (dipakai `<body class="bg-body-tertiary">` di
`layouts/app.blade.php` - warna background HALAMAN, bukan kartu) = `#f8f9fa`, terlalu
pucat/silau (permintaan user, mata cepat lelah). Override `:root[data-bs-theme="light"]
{ --bs-tertiary-bg: #e3e6ea; }` di `<style>` layout - HANYA scope tema terang (dark mode
tidak disentuh). `.ws-tabstrip` (strip tab Workspace) jg pakai variabel yg sama, otomatis
ikut lebih gelap tanpa perlu diubah terpisah. Kartu/`.card` tetap pakai `--bs-body-bg`
(putih) - kontras dgn background halaman yg skrg lebih gelap tetap terjaga. Verified via
PowerShell (login sungguhan + fetch halaman) - rule CSS baru terkonfirmasi ADA persis di
HTML yg dikirim browser, bukan cuma dicek di source file.

## Header navbar dikembalikan ke warna default AdminLTE (2026-09-24)

Override `.app-header.bg-brand { background-color: #3367d6 !important; }` (biru asli
CI3, ditambahkan sesi sebelumnya) **DIHAPUS** per permintaan user - header sekarang
pakai warna default AdminLTE 4 lagi (bukan biru custom). Sidebar TETAP hitam custom
(`.app-sidebar.bg-brand { background-color: #000 !important; }`, TIDAK ikut dihapus -
user cuma minta warna ATAS/header, sidebar tidak disinggung). Verified via PowerShell
(login sungguhan + fetch halaman) - rule biru header terkonfirmasi HILANG dari HTML yg
dikirim browser, rule hitam sidebar terkonfirmasi TETAP ADA.

**Bug lanjutan ditemukan user TEPAT SETELAH fix di atas**: teks "Administrator" + ikon di
header jadi TIDAK KELIHATAN (putih di atas background terang) - **`bg-brand` TERNYATA
BUKAN class asli AdminLTE sama sekali** (dicek `grep` langsung ke
`adminlte.min.css`/Bootstrap - nol hasil, class ini phantom/tidak pernah didefinisikan
di manapun) - background header SELAMA INI datang MURNI dari override custom `#3367d6`
yg baru dihapus, jadi begitu dihapus, background jadi transparan (nembus ke background
halaman terang di belakangnya) TAPI elemen `<nav>`-nya MASIH dipaksa
`data-bs-theme="dark"` (ditambahkan bareng override biru dulu, supaya teks putih
kontras di atas biru) - kombinasi "background transparan/terang" + "teks dipaksa putih"
= teks hilang. **Fix**: `data-bs-theme="dark"` DIHAPUS dari `<nav class="app-header">`
(`workspace.blade.php`) - tanpa itu, warna teks navbar Bootstrap (`--bs-navbar-color`,
turunan `--bs-emphasis-color`) otomatis ikut tema DOKUMEN sungguhan (`<html data-bs-
theme>`) spt seharusnya - gelap gelap di tema terang, terang di tema gelap. Verified
via PowerShell (fetch halaman nyata) - atribut `data-bs-theme="dark"` terkonfirmasi
HILANG dari tag `<nav>`.

## Navbar: pengingat "PR Belum Diverifikasi" utk Bagian Verifikasi (2026-09-24)

- Ikon lonceng di navbar (`workspace.blade.php`, di dalam `wire:ignore` yg sama spt
  switcher cabang - `wire:click` tetap jalan, pola sudah terbukti) - HANYA muncul kalau
  user py `can_do('inventory/pr','approve')` (= "User Bagian Verifikasi" yg dimaksud user,
  SAMA PERSIS ability yg sudah dipakai tombol Verifikasi `PrList` - bukan konsep hak akses
  baru). Badge merah = jumlah PR `PBUSTATUS=0` (Belum Verifikasi), dropdown list Nomor/
  Tanggal/Karyawan/Cabang, klik = LANGSUNG buka `PrForm` (skip `PrList`, `Workspace::
  openPrFromNotif()`).
- **Filter `PBUJENIS=1` WAJIB** (pola SAMA fix filter status PR sesi ini sebelumnya) -
  jenis=0 "Permintaan Barang" `PBUSTATUS` TIDAK PERNAH berarti "belum verifikasi" (selalu
  0, dilacak via KMB/TMB terpisah), tanpa filter ini pengingat akan salah include SEMUA
  PR jenis=0 yg belum ditarik KMB.
- Dibatasi `branchIds()` user (defense-in-depth konsisten modul lain, kosong = tidak
  dibatasi lihat semua cabang) - `Workspace::pendingVerifyPr()`.
- **Query ONE-SHOT per load halaman, BUKAN live/real-time** - navbar `wire:ignore`
  (dikonfirmasi sejak fitur switcher cabang: subtree ini TIDAK di-morph ulang Livewire
  antar render biasa) - cukup utk "pengingat" spt diminta, kalau butuh update tanpa reload
  nanti perlu pendekatan lain (polling/broadcast), DI LUAR scope permintaan ini.
- Verified via tinker dgn data NYATA (`BL-RS26090021`, benar2 `PBUSTATUS=0 JENIS=1`):
  lonceng+badge+PR muncul utk super admin; `openPrFromNotif()` benar buka `purchase.pr-
  form` dgn `prId` yg tepat (tab count & label dicek); user TANPA hak `approve` - lonceng
  hilang total; user DGN hak `approve` tp cabang direstriksi ke cabang LAIN - lonceng
  tetap muncul (py hak) tp PR itu TIDAK terdaftar; direstriksi ke cabang yg BENAR - PR
  muncul (verified via login ULANG stlh ubah `UCABANGPILIH`, krn `Auth::user()` di-cache
  per-request/session, TIDAK auto-refresh dari DB update langsung - gotcha skrip tes,
  bukan bug app, sama persis pola yg sudah ditemui sesi "Ganti Cabang Aktif").

## Modul Inventory: Batalkan Verifikasi PR (2026-09-24)

- **Kasus**: verifikator salah klik/mau tinjau ulang PR yg SUDAH "Disetujui" (status 2) -
  sebelum ini TIDAK ADA cara UI mengembalikannya ke "Belum Verifikasi" (form terkunci
  begitu status≠0, tombol Verifikasi hilang).
- `PurchaseRequestWriter::unverify(int $id)` - HANYA izinkan kalau (1) status PERSIS 2
  (bukan 1/Pending - itu verifikasi ulang biasa; bukan 3+ juga), DAN (2)
  `SUM(PBDQTYPAKAI)` semua baris = 0 (BELUM ADA PKB yg menarik qty - kalau sudah ada, PR
  "batal diverifikasi" bikin PKB nyantol ke PR yg tidak disetujui, TIDAK KONSISTEN; user
  harus batalkan PKB-nya dulu, `PkbWriter::cancel()` otomatis balikin `PBDQTYPAKAI` ke 0
  via `releaseOnCancel()`). Reset field konfirmasi (`PBUKONFIRMASICATATAN`/`PBUAPPROVEU`/
  `PBUKONFIRMASIU`/`PBUKONFIRMASITANGGAL`) ke NULL - PR ini efektif "belum pernah
  diverifikasi" lagi, bukan cuma ganti status.
- `PrList::unverifyPr()` - gate `can_do('inventory/pr','approve')` (SAMA persis hak
  Verifikasi biasa). Tombol "Batalkan Verifikasi" (ikon rotate-left, kuning) di kolom Aksi
  - render query nambah `qtyPakai` (subquery `SUM(PBDQTYPAKAI)`) biar tombol LANGSUNG
  disembunyikan kalau sudah ada PKB (bukan nunggu error server-side - UX lebih jelas drpd
  tombol yg pasti gagal).
- Verified LENGKAP via tinker dgn PR nyata (`PG-RS26090052`, status 2, `qtyPakai=0` saat
  itu - dikonfirmasi AMAN dibatalkan): tombol muncul → `unverifyPr()` → status DB balik 0
  + field konfirmasi NULL → list re-render: tombol Verifikasi balik muncul, tombol
  Batalkan Verifikasi hilang. Kasus BLOKIR disimulasikan (`PBDQTYPAKAI=5`): `unverify()`
  tolak dgn pesan yg benar, tombol TIDAK muncul di list. Permission: user tanpa `approve`
  - tombol hilang DAN panggil method langsung TETAP diblok (status tidak berubah).

## Modul Inventory: Histori penarikan di Permintaan Barang (2026-09-24)

- Tombol "Histori" (ikon timeline) di kolom Aksi `PrList` - tampil utk KEDUA jenis PR,
  alur yg dirender OTOMATIS mengikuti `PBUJENIS` (`PrList::openHistory()` set
  `$historyMode`, blade bercabang `@if ($historyMode === 'mutasi')`):
  - **jenis=1 "Permintaan Pembelian" → `history()`**: PR→PKB→SJ→PBC (3 tingkat bersarang).
  - **jenis=0 "Permintaan Barang"/mutasi → `historyMutasi()`**: PR→KMB→TMB (2 tingkat).
  Dua alur ini TERPISAH TOTAL (beda tabel perantara, beda kolom FK, beda konvensi status)
  - makanya method-nya SENGAJA dipisah, bukan satu method penuh `if`.
- Semua tingkat tampil per-baris ITEM PR dgn nomor/tanggal/qty + badge Batal; khusus KMB
  ada badge "Diterima" (KMB `SUSTATUS=3` = sudah ditarik TMB, `KmbWriter::STATUS_DITERIMA`).
- **Rantai FK alur mutasi** (dari `KmbWriter`/`TmbWriter` sesi sblmnya): PR→KMB via
  `fstoku.SUPBUID` = PBUID (HEADER) + baris `fstokd.SDPBDID` = `PBDID` utk qty per item
  (KMB MENGISI `SDPBDID`; TMB TIDAK pernah), qty di `SDKELUAR`. KMB→TMB HEADER-level saja
  via `fstoku.SUPRUID` = KMB.SUID, baris TMB utk 1 item dicocokkan `SDITEM` (aman krn
  "satu TMB = SATU KMB, FULL-RECEIPT" - pola PERSIS sama spt SJ→PBC), qty di `SDMASUK`.
- **GOTCHA DATA KEEMPAT (2026-09-24, BESAR - ditemukan saat user mengecek "PR mana yg
  sudah sampai TMB")**: BANYAK dokumen di DB ini adalah **header TANPA baris detail sama
  sekali** (lanjutan pola "162 fstoku PB kosong" yg dulu dikonfirmasi user "sedang
  diimport"): **TMB 206/315 (65%), PBC 849/1838 (46%), SJ 841/1878 (45%), KMB 175/447
  (39%)** - sebaliknya PKB cuma 5/1103 (0,5%, aman). Akibatnya versi PERTAMA histori
  (INNER JOIN ke baris detail) SALAH menampilkan "Belum diterima cabang (TMB)" utk
  dokumen yg SUNGGUHAN ADA - ketemu nyata di `CL-RS26080030`: `CL-TMB26080003` ada &
  terhubung ke KMB-nya, tapi `fstokd`-nya KOSONG (dari 24 PR yg sampai TMB, 8 TMB-nya
  header-saja).
  - **Fix**: penelusuran pakai relasi HEADER dulu (KMB via `SUPBUID`, TMB via `SUPRUID`,
    PBC via `SUNOSJAPOTIK`), baris detail di-LEFT JOIN utk qty. Dokumen header-saja TETAP
    TAMPIL dgn qty `null` → blade render "Qty —" (+ tooltip "Detail item dokumen ini belum
    ada di database"). Aturan skip-nya presisi: kalau baris utk item ini tidak ada TAPI
    dokumen itu PUNYA baris lain → memang tidak memuat item ini → DILEWATI; kalau dokumen
    TIDAK punya baris sama sekali → ditampilkan (qty tak diketahui). SJ TIDAK bisa
    di-fallback (link SJ→PKB cuma ada di level baris `SDSODID`, tidak ada relasi header).
- `PurchaseRequestWriter::history()` - traceability 3 level pertama SUDAH ada di skema
  (BUKAN perlu tabel/agregasi baru): `fperintahkirimbarangd.PKBDRSIDD` = FK LANGSUNG ke
  `PBDID` (baris PR), `fstokd.SDSODID` = FK LANGSUNG ke `PKBDID` (baris PKB) utk baris SJ
  (`SDSUMBER='SJ'`).
- **Level ke-4 (SJ→PBC) BEDA POLA**: `PbcWriter` TIDAK py FK per-baris ke SJ sumber
  (`SDPRDID` di-OVERLOAD trigger `official_nmw`, TIDAK BOLEH dipakai - lihat docblock
  `PbcWriter`) - traceability cuma HEADER-level via `fstoku.SUNOSJAPOTIK` = SJ.SUID. Baris
  PBC utk 1 item dicari via `SDITEM` DALAM PBC yg `SUNOSJAPOTIK`-nya SJ itu - AMAN krn
  konvensi "satu PBC = satu SJ, FULL-RECEIPT" (PBC selalu bawa SEMUA item SJ-nya).
- **GOTCHA DATA KRITIS ditemukan SEKALIGUS (2026-09-24, saat bangun fitur ini)**: kolom
  bookkeeping `fpermintaanbarangd.PBDQTYPAKAI` (dipakai LUAS di kode existing - "sisa yg
  boleh ditarik" di `PkbWriter::fromPr()`/`PurchaseRequestWriter::pullableForPkb()`, DAN
  dipakai fitur "Batalkan Verifikasi" sesi ini sendiri) **TERBUKTI TIDAK RELIABEL SECARA
  LUAS**: **4.439 dari 5.002 baris PR aktif (89%!) NILAINYA TIDAK COCOK** dgn
  `SUM(PKBDQTY)` aktual dari `fperintahkirimbarangd` (dicek query langsung ke DB). Pola
  gandanya BUKAN sekadar "trigger tidak jalan" - trigger `fperintahkirimbarangd_add`
  (INSERT) & `_UPDATE` DIKONFIRMASI SECARA KODE benar (bukan duplikat statement spt bug
  `ISTOKDE` sblmnya) - TAPI kasus nyata ditemukan (`PG-PKB26090033`) 1 baris PKB
  (`PKBDQTY`=50) yg `PBDQTYPAKAI` PR sumbernya PERSIS 2x lipat (100), DAN kasus lain
  (`DE-PKB26090008`) yg PBDQTYPAKAI-nya malah 0 padahal beneran ada tarikan (5, 20) -
  **root cause PASTINYA BELUM ketemu** (dicek: bukan CI3, bukan `PkbWriter` kita, tidak
  ada yg tulis manual kolom itu selain trigger).
  - **Keputusan (KONSISTEN pola sesi ini)**: TIDAK diperbaiki di trigger/kolom itu sendiri
    (produksi, berisiko, sama pola `ISTOKDE`) - TAPI method `history()` DAN
    `PurchaseRequestWriter::unverify()` (fitur "Batalkan Verifikasi" sesi ini) SENGAJA
    DIREVISI supaya TIDAK PERCAYA kolom itu - `history()` hitung `qtyDitarik` dari SUM
    `pkbList` yg BARU DITELUSURI (ground truth), `unverify()` cek `EXISTS` langsung ke
    `fperintahkirimbarangd` (keberadaan baris, bukan qty yg diakumulasi) utk memutuskan
    "sudah ditarik PKB apa belum". `PrList::render()` kolom `qtyPakai` (SUM, dipakai
    tombol Batalkan Verifikasi) diganti `hasPkb` (EXISTS boolean) - SAMA alasan.
  - **BELUM DITINDAKLANJUTI (di luar scope, PENTING diberitahu user)**: `PkbWriter::
    fromPr()`/`PurchaseRequestWriter::pullableForPkb()` MASIH pakai formula lama
    `PBDQTY - PBDQTYPAKAI` utk hitung "sisa yg boleh ditarik ke PKB baru" - KALAU
    `PBDQTYPAKAI` under-count (spt kasus `DE-PKB26090008`), "sisa" BISA OVER-REPORT,
    RISIKO PKB BARU MENARIK QTY YG SEBENARNYA SUDAH DIALOKASIKAN (over-allocation nyata,
    bukan cuma tampilan). Perlu keputusan/investigasi TERPISAH dari user - BUKAN
    diperbaiki diam2 sbg bagian fitur Histori ini.
- **GOTCHA DATA KETIGA (2026-09-24, JARANG - bukan sistemik)**: `fperintahkirimbarangd.
  PKBDRSIDD` (FK ke baris PR) & `PKBDITEM` (item aktual baris PKB itu) KADANG TIDAK
  SINKRON - PKB yg "mengaku" menarik dari baris PR item X ternyata isinya item Y.
  **Cuma 4 dari 4.804 baris (~0.08%)** - dicek eksplisit, JAUH beda skala dari bug
  `PBDQTYPAKAI` (89%). Ketemu kebetulan krn PR contoh pertama (`PG-RS26090052`) ternyata
  SALAH SATU dari 4 baris anomali itu. **Penjaga ditambahkan** (bukan "perbaiki" data):
  query `history()` filter `PKBDITEM = PBDITEM` (level PKB) & `SDITEM = PBDITEM` (level
  SJ) - baris inkonsisten TIDAK ditampilkan di bawah item yg salah (lebih aman sembunyi
  drpd salah asosiasi).
- Verified LENGKAP via tinker dgn data NYATA: rantai PENUH 4 level `CP-RS26090004` →
  `DF-PKB26090012` (qty 240) → `BZ-SJ26090010` (240) → `CP-PBC26090013` (240) semua
  tampil benar di modal; item lain di PR yg sama (PKB ada, SJ belum) tampil "Belum ada
  Surat Jalan"; PR lain (`PG-RS26080002`, SJ ada tp belum PBC) tampil "Belum diterima
  cabang (PBC)"; PR tanpa PKB sama sekali (`JG-RS26080016`) tampil "Belum ditarik ke PKB
  manapun". **Alur MUTASI diverifikasi terpisah dgn data nyata**: `KG-RS26080012` (2 item)
  → `PG-KMB26080020` → `KG-TMB26080001` tampil benar + badge "Diterima"; `CL-RS26080030`
  (KMB ada, TMB belum) → "Belum diterima cabang (TMB)"; `BL-RS26080001` (belum ada KMB) →
  "Belum ditarik ke KMB manapun"; regresi alur jenis=1 dicek ulang TIDAK terpengaruh
  (`historyMode` benar 'pembelian', PKB/SJ/PBC tetap tampil).
  PR `PG-RS26090052` (py PKB+SJ sungguhan,
  `DE-PKB26090008`→`DE-SJ26090040`) - modal tampilkan nomor PKB/SJ benar, `qtyDitarik`
  history SEKARANG benar (5, 20 - ground truth) vs SEBELUM fix (0, 0 - dari kolom buggy).
  `unverify()` di-uji ISOLASI (paksa status=2 sementara dlm transaksi) - BENAR diblok
  krn `hasPkb=true` (pesan spesifik "sudah ditarik ke PKB", bukan pesan status). List:
  tombol Batalkan Verifikasi BENAR tersembunyi utk PR ini (ground truth, bukan
  `PBDQTYPAKAI=0` yg SEBELUM fix salah bilang "aman"). Empty-state (PR py baris tp
  belum ditarik PKB apapun) diverifikasi terpisah - modal tampilkan "Belum ditarik ke
  PKB manapun" dgn benar.

## Modul Pembelian: No Batch / Serial di Penerimaan Barang (PB) (2026-09-25)

Item dgn `bitem.ISERIAL=1` WAJIB diisi No Batch saat terima barang. VB6 acuan:
`fFrmPenerimaanBarang.frm` (logika simpan di baris ~782-820; `fFrmPenerimaanBarangPBDepo.frm`
baris 864-874 SAMA persis - form yg jadi dasar modul PB kita, jadi fitur ini melengkapinya).

**Dua tabel, satu alur tulis** (`PbWriter::create()`):
- `fstokd.SDSERIAL` — string ringkas format `noBatch~dd/mm/yyyy~qty`, antar batch dipisah `|`,
  **desimal pakai KOMA** (locale VB6). Diverifikasi dari data nyata:
  `062615R~05/06/2029~35,780|062618R~18/06/2029~10,22|042621R~21/04/2029~4` = 50 = `SDMASUK`.
  Helper `packSerial()`/`unpackSerial()` (`unpackSerial()` public, dipakai `lines()` utk tampil ulang).
- `bitemserialhistori` — SATU baris per batch: `ISHIDSERIAL`, `ISHMK=1` (masuk),
  `ISHIDFSTOKD`=`fstokd.SDID`, `ISHURUTAN`=1..n, `ISHMASUK`=qty, `ISHKELUAR`=0.
- `bitemserial` (master batch) — dicari-atau-dibuat by (`ISITEM`, `ISNOSERIAL`) via
  `PbWriter::resolveSerialId()`, padanan helper VB6 `pIDSerial(item,noBatch,tglExpired)` yg
  dipakai 7 form legacy. `ISTGLEXPIRED` hanya ditulis saat baris baru / saat yg lama masih
  null (jangan timpa; di data nyata (item,noBatch) praktis unik: 1 duplikat dari 2.792 baris).

**`bitemserial.ISJUMLAH`/`ISAKTIF` TIDAK PERNAH ditulis manual** — trigger
`bitemserialhistori_add`/`_update`/`_del` yg mengurus (`ISJUMLAH += ISHMASUK - ISHKELUAR`,
`ISAKTIF = ISHMK`; delete membalik; update balik-lama-lalu-terapkan-baru). Tidak ada trigger
di `bitemserial` sendiri.

**`SDPAKAISERIAL` SENGAJA TIDAK diisi** — VB6 pun meng-comment kolom itu dari array
field-nya; sumber kebenaran "pakai batch" adalah `bitem.ISERIAL` (dibaca ULANG dari DB di
`create()` dan di `PbForm::lineIsSerial()`, tidak percaya flag yg dibawa state form).

**`cancel()` MENGHAPUS baris `bitemserialhistori` (hard delete)** — **TIDAK ADA trigger di
`fstokd` yg menyentuh `bitemserial`**, jadi kalau histori dibiarkan saat PB dibatalkan
(`SDCANCEL=1`, `SDMASUK=0`), `ISJUMLAH` tetap menghitung batch yg stoknya sudah dibalik =
stok batch jadi hantu. Ini **SENGAJA LEBIH BAIK dari VB6**, yg hanya
`delete from fstokd where SDIDSU=...` (baris 694) tanpa menghapus histori → `ISJUMLAH`
legacy bocor tiap PB berbatch dihapus. Dicatat, TIDAK diperbaiki mundur (aturan: jangan
betulkan bookkeeping legacy).

**Validasi**: total qty semua batch HARUS sama dgn qty diterima baris itu (tolerance 0.0001).
Dicek 2 lapis - di `PbForm::save()` (pesan menunjuk nama item, sebelum transaksi dibuka) dan
ULANG di `PbWriter::create()` (pagar terakhir). `cleanBatches()` membuang baris kosong/qty≤0
dan MENGGABUNG no batch sama (jumlahkan qty) supaya tidak pernah ada 2 baris histori
menunjuk `ISHIDSERIAL` sama utk satu `SDID`. Multi-batch per baris memang wajar di data nyata
(52.125 baris×1 batch, 2.702×2, 249×3, 26×4, 19×5).

**UI**: kolom "No Batch" di grid item (setelah Satuan) - tombol "Isi Batch" (kuning, kalau
kosong) / "<no batch>" atau "N batch" (hijau, kalau sudah) hanya utk baris `ISERIAL=1`,
sisanya "—". Modal meniru dialog VB6: input No Batch / Tanggal Expired (default hari ini) /
Jumlah + tombol `>` tambah, tabel daftar dgn tombol `X` hapus, lalu 3 kotak ringkasan
**Jumlah Product** (= qty baris) / **Total Serial** / **Selisih** (Total Serial merah kalau
beda, hijau kalau pas; tombol Simpan Batch disabled selama selisih ≠ 0). Enter di input
No Batch/Jumlah = tambah baris. Di PB tersimpan (locked) modal jadi READ-ONLY (tanpa input
& tanpa tombol tambah/simpan) - tetap bisa dibuka utk melihat batch.
**Qty baris diubah → batch baris itu otomatis dikosongkan** (`updatedLines()` hook, deteksi
key `*.qty`) supaya user sadar harus isi ulang, bukan baru ditolak saat simpan.

Verifikasi (3 skrip throwaway, semua transaksional + rollback, 22+23+13 asersi LULUS):
PO nyata `DT-PO26090052` item 3782 (MESSO J, `ISERIAL=1`) - `SDSERIAL` tersimpan persis
format legacy (termasuk kasus desimal koma `4,5` & expired kosong `VIEW-B~~1,5`), histori
2 baris dgn urutan/masuk benar, **trigger terbukti** naikkan `ISJUMLAH` 0→3 lalu balik ke 0
setelah `cancel()`, stok gudang kembali ke angka semula, `resolveSerialId()` tidak bikin
duplikat, batch tanpa isi & total tidak cocok DITOLAK, dan mode view menampilkan batch utuh
tanpa tombol edit. Dipakai `SUCABANG=46` (Depo Farmasi), bukan 20, utk menghindari bug
trigger `ISTOKDE` dobel.

## Batch/Serial dipakai bersama: `SerialBatch` + Pilih Batch di Surat Jalan (2026-09-25)

Logika batch dipindah dari `PbWriter` ke **`app/Services/SerialBatch.php`** (dipakai bersama
PB/SJ, nanti PBC/KMB/TMB/POS). `PbWriter` sekarang mendelegasi - perilakunya diuji regresi,
tidak berubah. API: `gudangPakaiSerial()`, `wajibBatch()`, `serialItems()`, `available()`,
`availableOf()`, `resolveId()`, `writeHistori()`, `forget()`, `clean()`, `pack()`, `unpack()`.

**WAJIB BATCH = 2 SYARAT BERTINGKAT** (aturan dari user 2026-09-25): **`bgudang.GPAKAISERIAL=1`
(GUDANG-nya) DAN `bitem.ISERIAL=1` (ITEM-nya)**. Ini persis pola VB6 di 7 form legacy:
`If xPakaiSerial = True Then` (setelan gudang) lalu `If iserial = 1 Then` (setelan item) -
`xPakaiSerial` yg selama ini tidak jelas asalnya ternyata = `GPAKAISERIAL` cabang aktif.
Konsekuensinya **item ber-ISERIAL=1 TIDAK diminta batch kalau transaksinya di gudang biasa**.
Selalu pakai `wajibBatch($itemIds, $gudang)`, JANGAN `serialItems()` saja.
Saat ini 5 dari 49 gudang: **10 Bizpark, 34 RII Bahan Baku, 35 RII Produksi, 36 RII Sample
Bahan Jadi, 37 RII Sample Bahan Baku**. Dikonfirmasi data nyata: **1.151 baris
`bitemserialhistori`, 100% milik `fstokd` di gudang ber-GPAKAISERIAL=1** - nol di gudang lain.
Catatan: dari 3 gudang tujuan PB (`PbWriter::GUDANG_DEPO` = 20, 34, 46) **cuma 34 yg berbatch**.
Gudang yg dipakai sbg acuan = `fstokd.SDGUDANG` (PB: gudang tujuan; SJ: gudang ASAL/`SUCABANG`).
Di `PbForm`, mengganti Gudang Tujuan setelah item ditarik akan **menghitung ulang flag tiap
baris dan membuang batch yg terlanjur diisi** (`updatedGudang()`), krn setelannya beda-beda.

**39 baris `fstokd` legacy** ber-item-serial di gudang berbatch TAPI tanpa `SDSERIAL` - semuanya
`SDSUMBER='PRO'` (Produksi, Jun 2026 & 1 baris Sep 2026), dibuat VB6 sblm modul kita. Modul
Produksi kita SEKARANG mengisi batch (lihat bagian di bawah); 39 baris lama dibiarkan apa adanya.

## Batch di Produksi - produk jadi (2026-09-25)

Permintaan user. Wajib kalau **Gudang Jadi** (`SUGUDANGTUJUAN`, = `SDGUDANG` baris produk
jadi) ber-`GPAKAISERIAL=1` DAN item ber-`ISERIAL=1`. Produk jadi = stok BARU, jadi user
**MENGETIK** no batch (pola `PbForm`), bukan memilih batch bersisa (pola `SjForm`). Arah
'masuk' (`ISHMASUK`).

**Baris BAHAN BAKU sengaja TIDAK berbatch** - VB6 pun tidak: 2 blok `If xPakaiSerial` di
`fFrmProduksi.frm` (baris 1064 & 1108) KEDUANYA di bagian produk jadi, tidak ada satu pun di
loop komposisi. **Konsekuensi yg perlu disadari & sudah disampaikan ke user**: bahan baku
ber-ISERIAL=1 yg dikeluarkan dari Gudang Produksi berbatch TIDAK mengurangi stok batch-nya
(stok `bitem` tetap benar krn diurus trigger `fstokd`). Warisan VB6, dibiarkan sampai user
memutuskan lain.

**BEDA dari VB6 (lanjutan Keputusan user 2026-09-23 soal alur produk jadi)**: VB6 menulis
**DUA** baris `fstokd` per produk jadi dgn string batch yg SAMA - masuk ke Gudang Produksi
(`SDCETAK=2`) lalu langsung keluar lagi (`SDBIAYAIDPENERIMAAN=1`, "akan ditarik oleh gudang
jadi"), sehingga efek netto ke `bitemserial.ISJUMLAH` = **NOL**. Modul kita cuma punya SATU
baris (masuk LANGSUNG ke Gudang Jadi), jadi batch benar2 bertambah di Gudang Jadi - justru
lebih tepat: stok batch mengikuti stok fisik.

`ProduksiForm`: kolom "No Batch" di grid produk jadi (setelah Satuan), modal sama persis PB
(input No Batch/Tgl Expired/Jumlah + `>`, tabel + `X`, ringkasan Qty Diproduksi / Total Batch /
Selisih, tombol mati selama selisih ≠ 0). **Ganti Gudang Jadi menghitung ulang flag tiap baris
dan membuang batch yg terlanjur diisi** (`syncFlagBatch()`, dipanggil dari `pickGudangJadi()`);
ubah qty baris juga mengosongkan batch (di dalam hook `updated()` yg sudah ada - JANGAN bikin
`updatedLines()` terpisah, komponen ini sudah pakai hook generic `updated()`).
`ProduksiWriter::cancel()` memanggil `SerialBatch::forget()`.

Verifikasi (skrip throwaway, 25 asersi LULUS, transaksional + rollback): Gudang Jadi biasa
tanpa batch BOLEH (SDSERIAL null, nol histori) sedangkan Gudang Jadi berbatch DITOLAK; total
batch beda DITOLAK; simpan benar -> `SDSERIAL` format legacy, histori `ISHMASUK=4/ISHKELUAR=0`,
stok batch Gudang Jadi 0->4 lalu KEMBALI 0 setelah `cancel()`; hanya 1 baris `fstokd` produk
jadi (bukan pola masuk+keluar VB6); komponen: tombol muncul utk item serial & tidak utk item
biasa, ubah qty mengosongkan batch, ganti Gudang Jadi 35->20 mematikan flag + membuang batch,
20->35 menyalakan lagi, simpan tanpa batch ditolak dgn pesan menyebut nama produknya.

**`ISHMK` BUKAN penanda arah masuk/keluar.** Default kolomnya 1, dan VB6 form keluar
(`fFrmSuratJalanDepo_CL.frm` baris 1051) TIDAK pernah mengisinya - array field-nya cuma
`ISHIDSERIAL, ISHIDFSTOKD, ISHURUTAN, ISHKELUAR`. Data nyata membenarkan: **920 baris histori
milik SJ semuanya `ISHMK=1` tapi `ISHMASUK=0, ISHKELUAR>0`**. Arah ditentukan MURNI oleh
`ISHMASUK` vs `ISHKELUAR`; kita ikut menulis `ISHMK=1` selalu.

**Stok batch bersifat PER GUDANG**, dihitung dari histori × `fstokd.SDGUDANG`, BUKAN dari
`bitemserial.ISJUMLAH` (yg global lintas gudang). Query `available()` = query VB6 `PilihSerial`
yg diberikan user persis, join `bitemserial` pakai `isid=ishidserial AND isitem=sditem`
(pengaman bawaan VB6 thd histori salah-tunjuk-item, dipertahankan), `HAVING SUM(...)>0`.
TAMBAHAN kita: abaikan baris `fstokd` yg `SDCANCEL=1` (saat ini 0 baris di data nyata, murni
pertahanan) dan urut **FEFO** (kedaluwarsa terdekat dulu, NULL terakhir) - relevan utk klinik.

**SJ = MEMILIH batch (beda dari PB yg MENGETIK batch baru).** Dialog "Pilih Serial" meniru
VB6: grid centang `X` / No Batch / Tanggal Expired / **Tersedia** / Qty, lalu **Jumlah Product**
(= qty baris) / **Jumlah Serial di Pilih** / **Selisih**, tombol **Isi Ulang Serial**
(kosongkan lalu isi otomatis FEFO sampai qty terpenuhi) dan **OK** (disabled selama selisih ≠ 0).
Mencentang sebuah batch otomatis mengisi qty sebanyak yg masih kurang, dibatasi stok batch itu.
Kolom "Tersedia" sudah DIKURANGI batch yg dipakai baris lain di SJ yg sama (satu item bisa
muncul di >1 baris PKB) - pencegahan di UI, validasi kerasnya tetap di writer.

**3 lapis validasi server-side di `SjWriter::create()`** (dokumen KELUAR jauh lebih berisiko
drpd masuk): (1) batch wajib ada utk item `ISERIAL=1`; (2) total batch == qty dikirim;
(3) tiap batch dicek ULANG stoknya di gudang asal SAAT ITU, termasuk akumulasi lintas baris
dlm satu SJ - cegah menarik batch yg sudah habis diambil dokumen lain (race). `ISERIAL`
selalu dibaca ulang dari `bitem`, tidak percaya flag dari form.

**`SjWriter::cancel()` menghapus histori batch** (`SerialBatch::forget()`) - WAJIB, sama
alasannya dgn PB: tidak ada trigger di `fstokd` yg menyentuh `bitemserial`, jadi tanpa ini
batch yg dikirim tercatat berkurang selamanya walau SJ-nya dibatalkan.

**Mode view SJ tersimpan**: dialog dibangun dari `SDSERIAL` yg tersimpan, **bukan** dari
`available()` - batch yg stoknya sudah habis terpakai tidak muncul di `available()` (HAVING>0),
jadi kalau dibaca dari sana batch yg dikirim malah hilang dari tampilan.

Verifikasi aturan per-gudang (skrip ke-4, 25 asersi LULUS): `gudangPakaiSerial()` benar utk
34/20/46/null; PB di gudang 20 tanpa batch BOLEH tersimpan (SDSERIAL null, nol baris histori)
sedangkan di gudang 34 DITOLAK; `PbForm` ganti gudang 34→20 mematikan flag, membuang batch yg
sudah diisi, dan menghilangkan tombolnya dari grid, lalu 20→34 mengaktifkannya lagi; SJ dari
gudang 20 tanpa batch BOLEH, dari gudang 34 DITOLAK.

Verifikasi (3 skrip throwaway transaksional, 24+20+9 asersi LULUS): regresi PB lewat service
baru (SDSERIAL, trigger ISJUMLAH 0→8→0) masih benar; `available()` memunculkan batch hanya di
gudang yg benar; SJ ditolak saat tanpa batch / total beda / batch tak berstok; SJ tersimpan
menulis `ISHKELUAR=3, ISHMASUK=0, ISHMK=1` dan stok batch turun 50→47 lalu **kembali ke 50
setelah `cancel()`**; komponen: urutan FEFO benar, "Isi Ulang Serial" membagi 15 jadi 10+5
lintas 2 batch, qty melebihi tersedia ditolak, ubah qty baris me-reset pilihan batch; E2E
simpan lewat komponen lalu buka mode view menampilkan batch utuh tanpa tombol edit.

## Master Data: Gudang / Cabang (`bgudang`) (2026-09-25)

`App\Livewire\Master\GudangManager` + `livewire/master/gudang-manager.blade.php`, menu
`master.gudang` (path ACL `master/gudang`, sort 20 = paling atas di grup Master Data).
Layout form MENGIKUTI VB6 `bFrmGudang.frm` persis (screenshot user).

**Pemetaan field VB6 -> kolom** (dari rutin `Edit` VB6 baris 489-515) - 2 yg mudah keliru:
**Inisial = `GALAMAT1`** (BUKAN alamat!) dan **Alamat = `GALAMAT2`**. Sisanya lurus:
Kode=`GKODE`, Default=`GDEFAULT`, Nama=`GNAMA`, Divisi=`GDIVISI`(->`bdivisi`),
Kontak=`GKONTAK`, Kota=`GKOTA`, Provinsi=`GPROPINSI`, Negara=`GNEGARA`, Telepon=`GTELP`,
Fax=`GFAX`, Kontak di SJ=`GKONTAKSJ`(->`bkontak`, pakai `<x-search-select>` + `lookup.kontak`).

**"Inisial" (`GALAMAT1`) READ-ONLY** - sama spt VB6 (kotaknya abu2) DAN krn kolom ini dipakai
SEMUA writer sbg **prefix nomor transaksi** (`nextNumber()`: `PG-`, `RB-`, `DE-`, `BL-`...).
Mengubahnya = mengubah penomoran dokumen cabang. Konsekuensi: **gudang BARU lahir tanpa
Inisial** - harus diisi manual lewat DB sblm dipakai transaksi (kalau null, writer jatuh ke
`GKODE` yg bisa panjang/berspasi). Kalau nanti perlu editable, itu keputusan sadar tersendiri.

**"Keterangan" ADA di layout VB6 tapi TIDAK TERIKAT KOLOM MANAPUN** (di VB6 barisnya
di-comment: `' txtKeterangan = Rs2("GKODE")`, dan tidak ada di array simpan; `bgudang` memang
tidak punya kolom keterangan). Ditampilkan biar layout sama, tapi DISABLED + diberi catatan
"tidak tersimpan" - jangan diam2 membuang input user.

**2 BEDA SENGAJA dari VB6, keduanya perbaikan**:
1. VB6 `Simpan` aktif cuma menulis 8 kolom (`GKODE, GNAMA, GALAMAT2, GTELP, GCREATEU,
   GMODIFU, GMODIFD, GKONTAKSJ`); daftar lengkapnya ADA tapi DI-COMMENT (baris 430-431), jadi
   **Divisi/Kontak/Kota/Provinsi/Negara/Fax/Default yg TAMPIL di form legacy tidak pernah
   tersimpan**. Jelas kondisi setengah-jadi, bukan maksud - di sini semua field yg tampil
   memang disimpan (sesuai daftar yg di-comment itu).
2. VB6 `chkDefault` menjalankan `update bgudang set GDEFAULT = 0` (mengosongkan SEMUA) tapi
   `GDEFAULT` tidak ikut ditulis -> **hasilnya tidak ada gudang default sama sekali**. Di sini:
   kosongkan yg lain LALU set gudang ini = 1, dalam satu transaksi (diuji: tepat 1 default).

**TAMBAHAN di luar layout VB6**: checkbox **"Pakai No Batch / Serial"** (`GPAKAISERIAL`) -
inilah alasan menu ini diminta (setelan batch per cabang, lihat bagian `SerialBatch`). Form
VB6 tidak punya kontrol ini; di sistem lama diubah langsung lewat DB.

**TIDAK ADA tombol Hapus** - `bgudang.GID` dirujuk hampir semua tabel transaksi tanpa FK
(`fstokd.SDGUDANG`, `fstoku.SUCABANG`, `auser.UCABANG`, dll), menghapus = data yatim. VB6 pun
tidak menghapus dari form ini. Kolom `GAKTIF` ditampilkan di list sbg badge (read-only) krn
`Branch::options()` menyaringnya - belum ada UI utk mengubahnya (tidak ada di VB6 juga).

Verifikasi (skrip throwaway, 36 asersi LULUS, semua tulisan DB di dalam transaksi + rollback):
list memuat 49 gudang & bisa dicari lewat kode/nama/Inisial; ke-14 field VB6 ter-render;
Inisial terbaca `RB` utk gudang 34 dan ter-render disabled; mematikan batch di gudang 34 lalu
`SerialBatch` (instance BARU) langsung membaca setelan baru = false; menyalakan di gudang 20;
Kota/Provinsi/Negara/Fax/Kontak/Divisi benar2 tersimpan sedangkan `GALAMAT1` tidak berubah;
Default berpindah & tersisa tepat satu; kode kosong/duplikat ditolak; gudang baru tersimpan
dgn `GCREATEU` terisi.

## Master Promo & Master Paket dipindah ke grup Penjualan (2026-09-25)

Permintaan user. Di `MenuSeeder` cukup ubah `parent_segment` -> `'sales'` + sort 37/38, TAPI
**barisnya HARUS dipindah FISIK ke blok Penjualan** dalam array `$items`: seeder mengisi
`parent_id` dari `$idBySegment[$parentSegment]` yg diisi berurutan saat loop, jadi kalau
barisnya tetap di blok Master (diproses sblm 'sales' ada) `parent_id` jadi NULL dan menunya
malah naik ke root.

**`segment_key` & `route` SENGAJA TETAP `master.promo`/`master.paket` + `master/promo`,
`master/paket`** - yg pindah cuma POSISI di sidebar. Alasannya: `route` = path ACL yg dipakai
~20 `can_do()`/`activity_log()` di 4 komponen + 4 blade, dan `segment_key` = kunci registry
`Workspace::listRegistry()`. Menggantinya cuma demi kerapian nama tidak sepadan dgn risiko.
Hak akses AMAN krn `lv_user_menu` terhubung lewat `menu_id` (FK), bukan path - `updateOrCreate`
by `segment_key` mempertahankan id lama (38 & 39, diverifikasi).

Diverifikasi via render Workspace nyata: urutan sidebar jadi Retur Penjualan -> Master Promo
-> Master Paket, keduanya tidak lagi muncul di grup Master Data, dan `openFromSidebar()` utk
kedua segment masih membuka tab dgn benar.

## Master Data: Chart of Account (`bcoa`) (2026-09-25)

`App\Livewire\Master\CoaManager` + `livewire/master/coa-manager.blade.php`. Menu `master.coa`
(path ACL `master/coa`) SUDAH ada di seeder sejak awal - yg kurang cuma komponen + entri
`Workspace::listRegistry()`. VB6 asli: `bFrmCOA.frm`. 423 COA aktif di data nyata.

**Field**: Nomor=`CNOCOA` (UNIQUE di DB), Nama=`CNAMA`, Tipe=`CTIPE`, Sub Dari=`CSUBDARI` +
`CPARENT`, Mata Uang=`CUANG`(->`buang`), Divisi=`CDIVISI`(->`bdivisi`), Bank=`CBANK`(->`bbank`),
Grup/Detail=`CGD`.

**`CTIPE` = INDEX combo VB6 (baris 725-741), JANGAN diacak urutannya**: 0 Kas, 1 Bank,
2 Piutang, 3 Persediaan, 4 Aktiva Lancar Lainnya, 5 Aktiva Tetap, 6 Akumulasi Penyusutan,
7 Hutang, 8 Hutang Lancar Lainnya, 9 Hutang Jangka Panjang, 10 Modal, 11 Pendapatan,
12 HPP, 13 Biaya, 14 Pendapatan Lain-Lain, 15 Biaya Lain-Lain, 16 Cash Back. Ada 2 baris
nyata ber-`CTIPE=17` yg TIDAK ada di daftar VB6 - list menampilkannya sbg "Tipe #17", bukan
kosong (jangan sampai data tak dikenal jadi tak terlihat).

**3 kolom TURUNAN, dihitung sistem** (persis VB6, bukan input user):
1. `CDC` dari `CTIPE`: tipe 0-6, 12, 13, 15 -> 'D', selain itu -> 'C' (VB6 baris 569-571).
2. `CLEVEL` = level induk + 1 (VB6 baris 590). Tanpa induk -> 1 (VB6 membiarkan 0, tapi semua
   root di data nyata ber-level 1 - ikut datanya).
3. **Induk OTOMATIS jadi grup**: `update bcoa set CGD='G'` (VB6 baris 587). Jadi `CGD` boleh
   dipilih user tapi bisa ditimpa jadi 'G' begitu COA lain menjadikannya induk.

**`CURUTAN` SELALU 0** - `pUrutan` di VB6 dideklarasikan tapi TIDAK PERNAH diisi (baris 550 &
576); benar, 423/423 baris nyata `CURUTAN=0`. Kolom mati.

**`CNAMA1`/`CNAMA2`/`CNAMA3`** ("Header 1/2/3" di VB6) ADA di `zField` VB6 tapi **TIDAK ADA di
`bcoa` database ini** - form VB6 versi itu pasti error / dipakai di DB lain. TIDAK direplikasi.

**TAMBAHAN di luar VB6** (VB6 punya `chkActive` tapi di-comment): checkbox **Aktif**
(`CACTIVE`) - kolom ini SUDAH dipakai menyaring semua lookup COA modul Finance
(`LookupController::coaRekening()`), jadi ini mekanisme "hapus" yg aman. Tidak ada tombol
Hapus (`bcoa.CID` dirujuk jurnal/transaksi). Juga ditambahkan **guard induk melingkar**
(induk tidak boleh diri sendiri / keturunan sendiri, telusur maks 20 level) - VB6 tidak
mengeceknya dan pohon melingkar akan menggantung query rekursif apa pun.

Lookup baru **`lookup.coa.semua`** (`LookupController::coaSemua()`) utk picker induk: SEMUA
COA aktif tanpa filter `CGD` + param `exclude` (COA yg sedang diedit). Beda dari
`lookup.coa.rekening` yg sengaja cuma baris detail (`CGD='D'`) - induk justru biasanya grup.

**DEFER**: tab **"Saldo Awal"** VB6 (grid saldo awal per kontak) - itu menulis `ctransaksiu`
(`CUSUMBER='SA'`) + `bcoaSA` + memanggil fungsi DB `PCHAPUSSALDOAWAL`, yaitu DOKUMEN
TRANSAKSI bukan master. Perlu riset sendiri.

Verifikasi (skrip throwaway, 38 asersi LULUS, tulisan DB di transaksi + rollback): list = 423
COA aktif, filter tipe & pencarian nomor jalan; `CDC` benar utk tipe debit (Bank->D) & kredit
(Pendapatan->C); `CLEVEL` 3->4 dari induk; `CURUTAN`=0; induk otomatis berubah Detail->Grup;
induk melingkar & induk=diri sendiri DITOLAK; nomor duplikat/field kosong/Sub Dari tanpa induk
DITOLAK; nonaktifkan COA lalu lookup induk tidak lagi memunculkannya; `exclude` jalan; tab
terbuka dari sidebar.

## Batch di TMB (Terima Mutasi Barang) (2026-09-25)

Permintaan user. Wajib kalau **gudang PENERIMA** (`SUCABANG`, = `SDGUDANG` baris TMB)
ber-`GPAKAISERIAL=1` DAN item ber-`ISERIAL=1`. Arah **'masuk'** (`ISHMASUK`) - sama spt VB6
`fFrmPenerimaanMutasi.frm` baris 758-786. Modal & pola form SAMA PERSIS `PbForm` (user
MENGETIK no batch dari fisik barang). `TmbWriter::cancel()` memanggil `SerialBatch::forget()`.

**Batch DIWARISI dari baris KMB**: `fromKmb()` membaca `fstokd.SDSERIAL` dokumen pengirim
lewat `SerialBatch::unpack()` - persis VB6 baris 539 yg ikut menarik `SDSERIAL` saat menarik
KMB. **TAPI `KmbWriter` kita BELUM menulis `SDSERIAL`**, jadi sekarang warisan itu selalu
kosong & user TMB mengetik manual. Sudah diuji dgn KMB buatan yg `SDSERIAL`-nya diisi: batch
+ qty-nya benar2 terbawa ke form, jadi **begitu sisi KMB dilengkapi, pewarisan jalan sendiri
tanpa ubah kode**.

**KESENJANGAN yg SUDAH DISAMPAIKAN ke user, belum diperbaiki**: **KMB (sisi kirim) belum punya
input batch**, padahal VB6 punya (`fFrmKirimMutasiBarang.frm` baris 927, arah `ISHKELUAR`).
Akibatnya stok batch di gudang ASAL TIDAK berkurang saat barang dikirim, sementara gudang
TUJUAN bertambah lewat TMB -> angka batch gudang asal menggelembung. Stok `bitem` sendiri
TETAP BENAR (diurus trigger `fstokd`); yg terdampak hanya buku batch.

Verifikasi (skrip throwaway, 26 asersi LULUS, transaksional + rollback): gudang penerima biasa
tanpa batch BOLEH (SDSERIAL null) sedangkan gudang berbatch DITOLAK; total beda DITOLAK;
simpan benar -> `SDSERIAL` format legacy, histori `ISHMASUK=5/ISHKELUAR=0`, stok batch gudang
penerima 0->5 lalu KEMBALI setelah `cancel()`, KMB sumber jadi "Diterima"; warisan `SDSERIAL`
dari KMB terbawa utuh; komponen: tombol muncul, ubah qty mengosongkan batch, simpan tanpa
batch ditolak, simpan lengkap sukses, mode view menampilkan batch. (Skrip sempat mengubah
`auser.UCABANG` di dalam transaksi utk mensimulasikan user cabang penerima - dicek kembali
ke nilai semula setelah rollback.)

## Inventory: Data Histori Serial (2026-09-25)

`App\Livewire\Inventory\SerialHistori` + `livewire/inventory/serial-histori.blade.php`, menu
`inventory.serial` (path ACL `inventory/serial`, sort 57). VB6 asli: `bFrmItemData_Serial.frm`
("Data Serial Item"). **READ-ONLY total** - tidak menulis apa pun.

Dua grid bertingkat, persis VB6:
1. `IsiData(Item)` (VB6 baris 414) - daftar BATCH milik satu item di satu gudang + sisanya.
2. `IsiDataHistori(IDSerial, Item)` (VB6 baris 432) - MUTASI batch terpilih, plus **saldo
   berjalan** yg di VB6 dihitung di grid (baris 440-447), BUKAN di SQL - kita hitung di PHP,
   akumulasi masuk-keluar mengikuti urutan `SDID`. Sama persis.

Stok batch dihitung PER GUDANG dari `bitemserialhistori` x `fstokd.SDGUDANG` (bukan
`bitemserial.ISJUMLAH` yg global), join `bitemserial` dgn `isid=ishidserial AND isitem=sditem`
- sama dgn `SerialBatch::available()`. **Diverifikasi angkanya identik** dgn yg dipakai picker
batch di SJ, jadi form ini bisa dipakai menjelaskan kenapa sebuah batch muncul/tidak di sana.

**3 BEDA SENGAJA dari VB6**:
1. VB6 query grid batch pakai **INNER JOIN bkontak** (`kid=sukontak`) - batch dari dokumen
   TANPA kontak HILANG dari daftar, padahal kontak tidak ada hubungannya dgn stok batch.
   Ironisnya query grid mutasi VB6 sendiri sudah LEFT JOIN. Di sini KEDUANYA LEFT JOIN.
   (0 dari 1.151 baris nyata terdampak sekarang - murni pertahanan; diuji eksplisit dgn
   dokumen ber-`SUKONTAK` null: barisnya TETAP muncul.)
2. VB6 pakai `xCabang` (gudang aktif global) tanpa bisa dipilih. Di sini ADA pilihan Gudang,
   default cabang aktif user (aturan app ini).
3. Pilih item pakai search-select **`lookup.item-serial`** (endpoint baru, HANYA item
   ber-`ISERIAL=1`) - bukan 5 kotak filter Kode/Nama/Kelompok/Kategori/Sub Kategori spt VB6.
   Item non-serial mustahil punya batch, jadi tidak ada gunanya bisa dipilih.

Tambahan kecil: kolom Masuk & Keluar dipisah (VB6 cuma total sisa), badge **Kedaluwarsa** utk
`ISTGLEXPIRED` yg sudah lewat, badge **Batal** utk mutasi dari dokumen `SUSTATUS=9`, filter
"hanya yang masih ada stok" (VB6 menampilkan semua - default kita jg semua), dan peringatan
kalau gudang terpilih ber-`GPAKAISERIAL=0`.

**DEFER**: tombol "Generate Barcode" VB6 (cetak barcode, tidak terkait penelusuran batch).

**GOTCHA `<x-search-select>` (ditemukan lewat laporan user 2026-09-25 "tidak ada nama
Produknya")**: komponen itu menyimpan label terpilih di state Alpine, TAPI tiap render
Livewire ia dibangun ULANG dari prop `:selected-text`. Kalau properti label-nya (di sini
`$itemLabel`) TIDAK ikut diisi saat id berubah, **kotak pencarian tampak KOSONG padahal
item sudah terpilih** dan hasil di bawahnya sudah tampil - membingungkan. Jadi: **setiap
`<x-search-select>` WAJIB punya properti label yg diisi di hook `updatedXxx()`** (lihat
`SerialHistori::syncItemLabel()`, plus jaring pengaman di `render()`). Nama produk & gudang
sekarang juga ditulis eksplisit di header kartu "Batch / No Serial" dan panel "Mutasi Batch",
bukan cuma mengandalkan kotak pencarian.

Verifikasi (skrip throwaway, 21 asersi LULUS, data NYATA item 6813 @ gudang 10 dgn 57 baris
histori + 1 skenario buatan yg di-rollback): angka Tersedia identik dgn `SerialBatch::available()`;
saldo berjalan konsisten baris-per-baris DAN saldo akhir = Tersedia (127); ganti gudang/item
mereset pilihan batch; skenario masuk 10 lalu keluar 4 -> tersedia 6, saldo 10 lalu 6, arah
keluar benar, batch TIDAK muncul di gudang lain, dan batch dari dokumen tanpa kontak tetap
muncul.

## Modul Penjualan: Input Alkes Depo (AL) (2026-09-26)

`App\Services\AlkesWriter` + `App\Livewire\Sales\{AlkesList,AlkesForm}` + 2 blade, menu
`sales.alkes` (path ACL `sales/alkes`, grup Penjualan sort 37). VB6 asli:
`eFrmPOS_DEPO_2.frm`. `fstoku`/`fstokd` `SUSUMBER='AL'` (`aanomor` NKODE='AL' = "Alkes DEPO").
7.408 dokumen AL nyata (Juni 2026), tapi **cuma 396 yg punya baris detail** - sisanya korban
import gagal yg sama spt modul lain.

Mencatat ALAT KESEHATAN/bahan habis pakai yg dipakai untuk SATU BARIS TINDAKAN di transaksi
POS/IP. **SATU DOKUMEN AL = SATU BARIS TINDAKAN**, bukan satu IP (dibuktikan dari data:
`CM-AL26060150` & `CM-AL26060151` `SUIDUALKES`-nya sama tapi `SDIDUALKES` beda).

**3 kolom penghubung yg namanya MIRIP & gampang tertukar**:
- `fstoku.SUIDUALKES` = `SUID` dokumen **IP** sumbernya.
- `fstokd.SDIDUALKES` = `SDID` **baris tindakan** di IP itu.
- `fstokd.SDIDALKESNYA` = ditulis BALIK ke baris tindakan IP, isinya `SUID` dokumen AL
  (VB6 baris 826) - inilah penanda "tindakan ini sudah diinput alkesnya" (769 baris nyata).

**Baris tindakan yg boleh diinput alkes**: `bitem.IJENISITEM IN (1,4,5)` DAN `IRESEP=0`
(VB6 baris 383). **`bitemalkes` = resep alkes per tindakan** (`IPIDB` tindakan -> `IPIDBB`
alkes, `IPIDQTY`, `IPIDSATUAN`): 3.155 baris untuk 328 tindakan, dipakai sbg daftar DEFAULT.

**`SDKELUAR` vs `SDQTYDASAR`** (penting, mudah terbalik): `SDQTYDASAR` = qty DEFAULT dari
resep, `SDKELUAR` = qty yg BENAR2 dipakai. Diverifikasi dari data nyata `CL-AL26060029`:
resep SERUM REJUVE 2 -> dipakai 1, `SDQTYDASAR` tetap 2. Alkes yg ditambah manual di luar
resep -> `SDQTYDASAR`=0 (VB6 `SetDataBarang` col 6 = 0). **HANYA baris yg DICENTANG yg
disimpan** (VB6 `If IG.TextMatrix(pI,5)="Y"`) - makanya di data nyata sering cuma 3 dari 13
baris resep yg tersimpan.

Stok: `fstokd` arah KELUAR di `SDGUDANG` - trigger generic `fstokd_add` yg mengurangi.
**`SDGUDANG` & `SUCABANG` KEDUANYA dari cabang IP** (VB6 agak tidak konsisten: header pakai
`xCabang` user, baris pakai `SUCABANG` IP) - picker IP sudah discope ke cabang aktif user,
jadi nilainya sama & tidak ada celah input alkes cabang lain.

**SUSTATUS = 0 saat aktif** (7.408/7.408 dokumen legacy = 0; VB6 tidak pernah mengisinya).
**Pembatalan TIDAK ADA di VB6, DITAMBAHKAN di sini** - tanpa itu salah input tidak bisa
dikoreksi & stok telanjur berkurang. `cancel()`: `SDCANCEL=1` + nolkan `SDKELUAR` (trigger
mengembalikan stok) + `SUSTATUS=9` + **kosongkan `SDIDALKESNYA`** supaya tindakannya bisa
diinput ulang. `fromIp()` menganggap tindakan "belum ada alkes" kalau dokumen AL-nya batal.

Form: grid ATAS = baris tindakan IP (tombol "Input Alkes" per baris, yg sudah ada alkesnya
tampil nomor AL-nya), grid BAWAH = alkes (checkbox Pakai + qty editable + kolom "Qty Resep"
sbg pembanding, tombol Centang semua/Kosongkan, dan pencarian item utk tambah di luar resep).
Kalau IP cuma py 1 tindakan yg belum diinput, langsung dipilihkan otomatis. AL tersimpan
read-only (koreksi lewat Batalkan di daftar).

**`SUURAIAN` DIBERI SPASI** (permintaan user 2026-09-26): VB6 menyambung `"Alkes No IP" &
nomor` TANPA spasi -> `"Alkes No IPPG-IP26090005 Tindakan DFT"` (7.408 baris legacy semuanya
begitu). Modul kita menulis `"Alkes No IP PG-IP26090005 Tindakan DFT"`. Baris legacy TIDAK
diubah mundur.

**DEFER**: harga/diskon alkes (VB6 py kolomnya di grid tapi TIDAK ikut disimpan - `zField`
simpan tidak memuat `SDHARGA`/`SDDISKON`), batch/serial (form VB6 ini tidak menyentuhnya).

Verifikasi (skrip throwaway, 35 asersi LULUS, tulisan DB di transaksi + rollback, IP nyata
`PG-IP26090005` tindakan DFT dgn 13 baris resep): picker discope cabang; `fromIp()` benar
3 baris tindakan; simpan -> `SUIDUALKES`/`SDIDUALKES`/`SUNOREF`/`SUURAIAN` format VB6 persis,
`SDKELUAR`=1 & `SDQTYDASAR`=qty resep, **trigger mengurangi stok 763->762**, `SDIDALKESNYA`
tertulis; input dobel DITOLAK; `cancel()` -> stok kembali 763, `SDIDALKESNYA` kosong,
`SUSTATUS`=9; komponen: grid tindakan & resep terisi, tanpa centang ditolak, simpan sukses,
mode view benar, daftar & tab sidebar jalan.

## Konfirmasi & Toast global (2026-09-26)

Permintaan user: kotak konfirmasi bawaan browser (dari `wire:confirm`) jelek. Dibuat sendiri
di ATAS Bootstrap yg SUDAH dimuat layout - **sengaja TIDAK menambah dependensi** (toastr/
SweetAlert) supaya tidak ada aset baru yg harus dirawat. Semua di `public/js/dias-helpers.js`
+ markup di `layouts/app.blade.php` (`#dias-toasts`, `#dias-confirm`).

**`wire:confirm` SUDAH DIGANTI `data-confirm` di SELURUH view** (28 file, 30 kemunculan, 0
sisa). **JANGAN pakai `wire:confirm` lagi** - kalau dipakai, yg muncul kotak browser lagi.
Atribut opsional: `data-confirm-title`, `data-confirm-ok`, `data-confirm-danger`.
Aksi yg pesannya mengandung hapus/batal/nonaktif/buang/reset OTOMATIS bergaya bahaya (tombol
merah + ikon segitiga), jadi tombol biasa tidak perlu atribut tambahan.

**Cara kerja interceptor (penting kalau nanti diutak-atik)**: listener klik dipasang di fase
**CAPTURE** + `stopImmediatePropagation()` - supaya listener `wire:click` (fase bubble di
elemen yg SAMA) tidak keburu jalan sebelum user menjawab. Setelah user menekan Ya, tombolnya
**di-klik ULANG** dgn penanda `data-confirm-ok="1"` supaya lolos dari interceptor. Kalau
Bootstrap/markup belum siap, `diasConfirm()` jatuh balik ke `window.confirm` - aksi tetap
jalan, cuma tampilannya lama.

**Toast**: `window.diasToast(pesan, tipe)` (success|error|warning|info) ATAU dari komponen
Livewire `$this->dispatch('toast', message: '...', type: 'success')`. Pesan dipasang lewat
`textContent`, BUKAN `innerHTML` (isinya sering nama/nomor dari DB - jangan jadi celah
injeksi). Toast error tampil 7 detik, sisanya 4 detik. `z-index: 1090` supaya tetap terlihat
di atas modal.

**Status pemakaian toast**: BARU modul Alkes (`AlkesList`/`AlkesForm`) yg memakai - alert bar
di dalam halamannya sudah dilepas. **16 blade lain MASIH pakai `@if (session('status'))` +
alert bar**; itu tetap jalan normal, tinggal dikonversi kalau diminta (komponennya harus
ganti `session()->flash()` -> `$this->dispatch('toast', ...)`, krn flash dari aksi Livewire
tidak memicu page-load sehingga tidak bisa ditangkap script layout).

Verifikasi (skrip throwaway, 14 asersi LULUS): markup & helper ada; 0 sisa `wire:confirm`;
**12 komponen yg tadinya pakainya masih render tanpa error** dan 9 di antaranya benar
mengeluarkan `data-confirm`; simpan & batalkan Alkes benar2 men-dispatch event `toast`
(`assertDispatched`), pembatalannya sungguhan jalan (SUSTATUS=9).

## PO (daftar): status DIHITUNG dari penerimaan, bukan dari `SOUSTATUS` (2026-09-26)

Permintaan user: "semua barang sudah ditarik = Selesai, sebagian = Ditarik Sebagian, belum
sama sekali = Aktif, batal = Batal". `PoList::statusExpr()` (CASE di SQL, dipakai SEKALIGUS
utk kolom `status` DAN filter status):
- `SOUSTATUS = 9` -> **Batal** (satu2nya status yg memang ditulis modul kita)
- `SUM(SODORDER) > 0` DAN `SUM(SODMASUK) >= SUM(SODORDER)` -> **Selesai** (3)
- `SUM(SODMASUK) > 0` -> **Ditarik Sebagian** (2)
- selain itu -> **Aktif** (0). PO tanpa baris detail jatuh ke sini (tidak ada yg bisa diterima).

**KENAPA tidak membaca `SOUSTATUS`**: kolom itu TERBUKTI tidak pernah diperbarui - dari
**224 PO berstatus tersimpan 0 ("Aktif"), 168 sebenarnya sudah diterima PENUH** dan 10
sebagian; cuma 46 yg benar2 belum ditarik. Tidak ada trigger yg memutakhirkannya & mekanisme
aslinya tidak diketahui (lihat docblock `PurchaseOrderWriter`/`PbWriter`). Sesuai aturan
proyek: kode BARU baca ground truth, kolom turunan buggy TIDAK dipercaya & TIDAK ditulis ulang.

**Filter status WAJIB pakai ekspresi yg sama** - kalau tidak, memilih "Selesai" cuma dapat 9
baris (kolom tersimpan) padahal nyatanya 177.

**BUG LAMA ikut diperbaiki**: dropdown filter status dulu cuma punya `Aktif (value=1)` &
`Batal (9)` - **nilai 1 TIDAK ADA di sistem**, jadi filter "Aktif" selalu nihil. Sekarang 4
opsi dgn nilai benar (0/2/3/9).

**Tombol aksi ikut status hitung**: PO yg sudah ada penerimaannya -> ikon "mata" (bukan
pensil) & tombol Batalkan DISEMBUNYIKAN. Ditambah **guard server-side di `PoList::cancel()`**
(`SUM(SODMASUK) > 0` -> tolak): `wire:click` bisa dipanggil paksa dari klien, dan tanpa guard
PO yg barangnya SUDAH diterima bisa ditandai batal padahal penerimaannya tidak ikut
dibatalkan - dokumen & stok jadi bertentangan.

Verifikasi (19 asersi LULUS): sebaran status hitung cocok query manual (Aktif 46 / Sebagian 13
/ Selesai 177 / Batal 0) utk KEEMPAT filter; status tiap baris halaman 1 cocok hitungan manual;
badge "Selesai" hijau; PO Selesai tidak menampilkan tombol Batalkan & ikonnya mata; paksa
`cancel()` PO yg sudah diterima -> status TIDAK berubah, sedangkan PO yg belum diterima TETAP
bisa dibatalkan (diuji di transaksi + rollback). Catatan: teks pesan penolakannya tidak bisa
diverifikasi di harness tinker (flash session tidak terbawa), perilaku penolakannya yg diuji.

## PO (daftar): tombol "Histori Penerimaan" (2026-09-26)

Permintaan user. `PurchaseOrderWriter::historiPenerimaan()` + modal di `PoList`
(`openHistory()`/`closeHistory()`, tombol jam-mundur di kolom Aksi). Pola sama modal Histori
di `PrList`.

Rantai: `esalesorderd.SODID` <- `fstokd.SDSODID` (baris PB) -> `fstoku` (`SUSUMBER='PB'`).
Modal menampilkan PER BARIS ITEM: Qty Order (`SODORDER`), Diterima (`SODMASUK`), Sisa, lalu
daftar PB yg menerimanya (nomor, tanggal, No Invoice, qty).

**Qty yg ditampilkan = `SDMASUKPB`, BUKAN `SDMASUK`** - `SDMASUKPB` adalah qty dlm SATUAN PO
dan justru kolom INILAH yg dipakai trigger `fstokd_add` menaikkan `esalesorderd.SODMASUK`.
Kalau dipakai `SDMASUK` (satuan dasar), angkanya tidak akan cocok dgn Qty Order/Diterima
begitu `IQTYPERBOX` != 1. Diuji eksplisit: **jumlah qty penerimaan == `SODMASUK` di SEMUA
baris**.

Baris PB yg DIBATALKAN tetap ditampilkan (badge "Batal") supaya jejaknya terlihat - qty-nya
memang sudah 0 krn `PbWriter::cancel()` menolkan `SDMASUKPB`.

Verifikasi (13 asersi LULUS, PO nyata `DF-PO26090029` dgn 8 baris): jumlah qty penerimaan
cocok `SODMASUK` tiap baris; Sisa = Order - Diterima; PO yg belum pernah diterima aman
(daftar kosong, tanpa error); tombol & modal ter-render, nomor PB tampil, `closeHistory`
membersihkan state.

## Modul Inventory: Penyesuaian Barang (PY) (2026-09-26)

`App\Services\PenyesuaianWriter` + `App\Livewire\Inventory\{PenyesuaianList,PenyesuaianForm}`
+ 2 blade. Menu `inventory.adjust` ("Penyesuaian Stok", path ACL `inventory/adjust`) SUDAH ada
di seeder - yg kurang cuma komponen + entri `Workspace::listRegistry()`.
`fstoku`/`fstokd` `SUSUMBER='PY'` (`aanomor` NKODE='PY' = "Penyesuaian Barang"), 600 dokumen
nyata (28 punya detail - sisanya korban import gagal).

**TIDAK ADA file VB6 acuan** utk form ini di `CODE_VB6` - struktur direkonstruksi dari DATA
NYATA + spesifikasi field dari user.

**Field**: header Kontak (`SUKONTAK`) / Gudang (`SUCABANG`) / Jenis Penyesuaian
(`SUJENISPENYESUAIAN` -> `bjenispenyesuaian.JID`) / Uraian (`SUURAIAN`) / Tanggal / No
Transaksi; detail Kode-Nama-**Masuk**(`SDMASUK`)-**Keluar**(`SDKELUAR`)-Satuan-Catatan;
ringkasan **Jumlah Masuk & Jumlah Keluar**.

**SATU BARIS BOLEH PUNYA MASUK *DAN* KELUAR SEKALIGUS** - bukan salah satu. Terbukti di data
(`KM-PY26060001` baris "SARUNG TANGAN S": `SDMASUK=200` DAN `SDKELUAR=100`). Trigger generic
menghitung stok `+SDMASUK - SDKELUAR`, jadi dampaknya netto. Diuji: masuk 10 + keluar 4 ->
stok naik 6, lalu kembali persis setelah dibatalkan.

**`SUSTATUS` = 0 saat aktif** (600/600 dokumen legacy = 0) - pola SAMA modul AL, BEDA dari
SJ/PBC/KMB/TMB yg pakai 1. Batal = 9 (konvensi kita).

**Jenis Penyesuaian WAJIB diisi** (11 pilihan di `bjenispenyesuaian`: Racikan dipakai 361x,
Penurunan Barang 194x, Pembelian Langsung 25x, dst). Baris dgn Masuk=0 DAN Keluar=0 dibuang
otomatis sebelum simpan - kalau semua baris begitu, simpan ditolak.

**Gudang = cabang user login, read-only** (aturan app), dan nilai itu juga dipakai `SDGUDANG`
tiap baris. Daftar discope `branchIds()` + filter Gudang/Jenis/Status/tanggal/pencarian.
PY tersimpan read-only; koreksi lewat Batalkan lalu input ulang.

**CATATAN ke user**: spesifikasi menyebut "jenis" dan "Penyesuaian (bjenispenyesuaian)"
terpisah - ditafsirkan sebagai SATU field "Jenis Penyesuaian" dari `bjenispenyesuaian` (tidak
ada kolom `SUJENIS` di `fstoku`). Perlu dikonfirmasi kalau ternyata maksudnya 2 field.

**DEFER**: `bjenispenyesuaian.JAKUNBIAYA` (akun biaya per jenis, utk posting jurnal) - modul
ini spt semua modul lain TIDAK posting jurnal.

Verifikasi (40 asersi LULUS, simpan di transaksi + rollback): ke-14 field spesifikasi
ter-render; Gudang = cabang user; Jenis wajib; baris 0/0 ditolak; simpan -> `SUSUMBER='PY'`,
`SUSTATUS=0`, nomor `PG-PY26090001`, `SUJENISPENYESUAIAN` & `SDGUDANG` & catatan benar,
satu baris Masuk 10 + Keluar 4; **trigger menaikkan stok netto +6 (134->140) lalu kembali
134 setelah dibatalkan**; mode view read-only + badge Batal; filter Jenis jalan; tab terbuka
dari sidebar.

## POS: Pembayaran jadi dialog (modal) (2026-09-28)

Permintaan user + screenshot dialog Pembayaran VB6 sbg **gambaran tata letak saja** -
**BUKAN menambah jenis bayar** (VB6 punya Medika Apps, Piutang Surgery, Cicilan; **ketiganya
SENGAJA tidak dibuat**). Isinya persis **7 jenis yg sudah ada**: tunai, debit, kredit,
transfer, merchant, voucher, DP - `wire:model` & validasinya tidak disentuh, cuma pindah
tempat, jadi perilaku simpan tidak berubah.

**Panel bayar lama DIHAPUS** dari kolom kanan (dulu memanjang dgn `<details>` bertumpuk).
Diganti: **ringkasan** (rincian jenis bayar yg terisi + Dibayar + Kurang/Kembalian, atau
"Belum ada pembayaran") + tombol. Dialog `size="lg"` dua kolom spt VB6 - kiri tunai + dua
kartu, kanan transfer/DP/merchant/voucher; ringkasan bawah memakai istilah VB6
**Sub Total / Total Bayar / Sisa**.

### Kas/Bank: Uraian bawaan dari `aanomor.NKETERANGAN` (2026-09-30)

Dokumen **BARU** mengisi Uraian otomatis dari `aanomor.NKETERANGAN` - "Bukti Kas Masuk",
"Bukti Kas Keluar", "Bukti Bank Masuk", "Bukti Bank Keluar". Pola SAMA CI3
`Fina_Kas_Keluar::getketerangan()` yg mengambil kolom yg sama lalu mengisi field Uraian.

Dicocokkan `NKODE` = `sumber()` ('KM'/'KK'/'BM'/'BK') **DAN** `NTABEL='ctransaksiu'`. `NTABEL`
ikut dipakai walau keempat kode itu terbukti unik di data sekarang - skemanya tidak menjamin
`NKODE` unik lintas tabel. Dokumen **LAMA tidak disentuh**: `load()` tetap memakai `CUURAIAN`
yg tersimpan.

**`ctransaksiu` SUDAH TIDAK KOSONG lagi sejak 2026-09-30** - user mulai memakai form ini
sungguhan (`PG-KK26090001`). Uji yang mengasumsikan tabel ini kosong akan gagal & bisa
menyesatkan: sempat terbaca spt "rollback gagal" & "uraian ditimpa writer", padahal yg terbaca
dokumen ASLI user. **Kenali baris uji lewat penanda unik dan bandingkan jumlah baris sbg
SELISIH**, jangan angka mutlak.

## `public/js/dias-helpers.js` WAJIB ber-`?v=` (2026-09-30)

`<script src="{{ asset('js/dias-helpers.js') }}">` tanpa penanda versi -> **peramban
menyajikan versi CACHE**, jadi perbaikan JS apa pun tidak pernah sampai ke user.

**Sudah memakan dua perbaikan berturut-turut dalam satu hari**: panel `<x-search-select>`
(`position: fixed`) dan komponen `uangInput` yg sama sekali baru. Keduanya dilaporkan user
"masih tertutup" / "belum lancar" - padahal kodenya benar, berkas JS-nya yg lama yang dimuat.
`uangInput` bahkan `undefined` di peramban, jadi isian uangnya tidak berfungsi sama sekali.

Sekarang: `?v={{ @filemtime(public_path('js/dias-helpers.js')) ?: 1 }}` - berubah otomatis tiap
berkasnya disimpan, tidak perlu diingat. **Kalau nanti menambah berkas JS/CSS lokal sendiri,
beri penanda versi yang sama.** Berkas vendor (AdminLTE) tidak perlu - isinya tidak pernah
berubah.

**PELAJARAN UMUM**: kalau perbaikan JS dilaporkan "tidak ada efeknya", **curigai cache dulu**
sebelum mengubah kodenya lagi. Dua kali saya langsung menduga logikanya yang salah dan
mengubah kode yang sebenarnya sudah benar.

## `<x-uang-input>`: isian rupiah berformat ribuan (2026-09-30)

Permintaan user di form Kas Keluar: jumlah yang diketik jadi berformat ribuan setelah selesai.

**`<input type="number">` TIDAK BISA menampilkan pemisah ribuan** - peramban menolak karakter
non-angka di dalamnya. Jadi dibuat komponen `<x-uang-input>` (`type="text"` +
`inputmode="decimal"` supaya ponsel tetap memunculkan papan ketik angka) dgn Alpine
`uangInput()` di `dias-helpers.js`:

| Saat | Perilaku |
|---|---|
| render / kehilangan fokus | diformat `1.234.567,89` (gaya Indonesia) |
| mendapat fokus | dikembalikan ke angka mentah + `select()` - gampang ditimpa; nilai 0 jadi KOSONG |
| sedang diketik | dibiarkan apa adanya, supaya kursor tidak melompat |

Yang dikirim ke Livewire **SELALU angka** lewat `x-modelable="value"` (pola sama
`<x-search-select>`), jadi hitungan di server tidak perlu mengurai teks apa pun. Penjaga
`mengetik` mencegah `$watch('value')` menimpa isian saat user sedang mengetik - tanpa itu
tiap round-trip Livewire akan mengacak isian yg belum selesai.

### JANGAN pakai `.live` di sini - versi pertama tersendat

Versi pertama memakai `wire:model.live` + `@input` yg mengurai tiap ketikan. Akibatnya
**setiap huruf memicu round-trip Livewire**, dan tiap balasan me-morph ulang barisnya -
user melaporkan **"belum lancar"**. Prop `live` sekarang **sengaja DIHAPUS** dari komponen
supaya tidak bisa dipakai lagi, dan `@input` dibuang.

Aturannya: **selama mengetik TIDAK ada yg dikirim maupun diformat ulang** (memformat per
ketikan juga membuat kursor meloncat ke ujung tiap kali panjang teks berubah). Semua terjadi
di `keluar()`: urai -> format -> `this.value = baru` -> `$wire.$commit()` **sekali**, dan
hanya kalau nilainya benar-benar berubah. Jadi Total di server tetap ikut terbarui dgn tepat
satu permintaan per isian.

Dipakai di baris detail Kas/Bank. **Belum diterapkan ke isian uang lain** (~100 input
`type="number"` di modul lain) - tinggal ganti tag-nya kalau diminta.

Diuji di **Node** (menjalankan `dias-helpers.js` apa adanya dgn `window`/`document` palsu &
`$wire.$commit` palsu utk MENGHITUNG berapa kali kirim): **0 kirim selama mengetik**, tepat
**1 kirim** saat ditinggalkan, **0 kirim** kalau nilainya tidak berubah, isian dikosongkan ->
0 & dikirim, user mengetik sendiri `1.250.000,75`, isian ngawur (`''`, `abc`, `Rp 5.000`,
negatif) tidak jadi NaN, dan nilai dari server **tidak menimpa** isian yg sedang diketik tapi
**memang** memperbarui tampilan saat tidak difokus. Sisi Livewire diuji terpisah: `initial`
per baris benar, Total berformat, validasi baris kosong tetap jalan.

**Jebakan uji yg kena 2x**: (1) `preg_match` mengambil kemunculan PERTAMA - baris indeks 0
memang bernilai 0, jadi terlihat spt `initial` tidak diteruskan; pakai `preg_match_all`.
(2) `save()` berhenti di validasi **kontak & rekening** sebelum sampai ke pemeriksaan baris -
isi keduanya dulu kalau mau menguji pesan "Minimal 1 baris".

## Pencarian kontak: 70 detik -> 0,1 detik + hasilnya diperbaiki (2026-09-30)

Dilaporkan user: cari kontak di Kas Keluar **"diam saja"**. Bukan bug UI - `lookup.kontak`
memang butuh **69.723 ms**. Diukur langsung ke endpoint:

| q | hasil | SEBELUM | SESUDAH |
|---|---|---|---|
| `''` | 30 | 25 ms | 26 ms |
| `'nmw'` | 30 | 7 ms | 51 ms |
| `'Effendi'` | 30 | **16.543 ms** | 31 ms |
| `'petogogan'` | 4 | **69.723 ms** | **6 ms** |
| `'nmw petogogan'` | 1 | **67.774 ms** | **4 ms** |

### Sebab 1 - `MATCH() OR LIKE` mematikan index FULLTEXT

`Contact::applySearch()` dulu menyusun satu `WHERE MATCH(KNAMA) AGAINST(...) OR KKODE LIKE
'q%' OR ...`. `EXPLAIN` membuktikan MySQL memakai `key: idx_bkontak_knama` `type: index` -
memindai index KNAMA berurutan demi `ORDER BY KNAMA LIMIT 30` sambil **menghitung MATCH per
baris**. **Makin SEDIKIT hasilnya makin LAMBAT**, karena LIMIT tidak pernah terpenuhi - itu
sebabnya 'nmw' (banyak hasil di awal abjad) cepat tapi 'petogogan' (4 hasil) 70 detik.

Diganti **DUA TAHAP**: tahap 1 `Contact::kandidatId()` mengambil KID lewat query terpisah per
sumber (masing-masing pakai index-nya sendiri) digabung `UNION`, diurut KNAMA, dipotong 300;
tahap 2 `whereIn(KID)` di query pemanggil (primary key). Tiap cabang UNION diberi LIMIT juga -
tanpa itu satu cabang bisa menyumbang ratusan ribu baris ke penggabungan.

### Sebab 2 - kolom kode cocok ke HAMPIR SELURUH TABEL

Ini memperbaiki **HASIL**, bukan cuma kecepatan. Di data nyata `KKODE LIKE 'nmw%'` cocok ke
**204.777** baris dan `KIDPASIEN LIKE 'nmw%'` ke **315.168** dari 321.768 baris - jadi mencari
"nmw" dulu mengembalikan 30 kontak **ACAK**, bukan hasil pencarian. Sekarang kolom kode hanya
dicari kalau kata kuncinya **mengandung angka** (kode/no member/telepon praktis selalu
berangka); kata huruf-semua = pencarian NAMA saja lewat FULLTEXT.

### Sebab 3 - `addcslashes()` TIDAK menetralkan operator boolean

MySQL tidak mengenal backslash sbg escape di BOOLEAN MODE. Mencari `NMW-NH07941` terbaca
`+NMW` DAN **`-NH07941` (minus = KECUALIKAN)**, sehingga semua nama ber-"NMW" terbawa dan
baris yg dicari tenggelam - ketahuan dari uji. Sekarang karakter operator `+-<>()~*"@`
**DIBUANG jadi spasi**, bukan di-escape.

Berdampak ke **6 pemanggil** `applySearch()`, termasuk **pencarian pelanggan POS** - ikut
terbukti cepat (`'petogogan'` 20 ms). Kontrak `applySearch($query,$q,$alias,$extraColumns)`
TIDAK berubah, jadi semua pemanggil aman; diuji utk alias `'k'` (PosTerminal) & `'A'`+KNOKTP
(PasienManager), dan `q` kosong tetap tidak menyaring apa pun (321.768 baris).

**Jebakan uji**: membandingkan urutan hasil dgn `sort()` PHP SALAH - collation MySQL
(`utf8mb4_general_ci`) beda perlakuannya pada huruf besar/kecil & spasi awal (ada nama diawali
TAB di data nyata). Bandingkan ke `ORDER BY KNAMA` dari DB itu sendiri.

## `<x-search-select>`: panel hasil pakai `position: fixed` (2026-09-30)

Dilaporkan user: di form **Kas Keluar**, daftar hasil "cari akun biaya" **terpotong footer**.
Sebabnya bukan z-index - baris detail dibungkus **`.table-responsive`** (Bootstrap
`overflow-x: auto`), dan itu membuat **konteks pemotongan**: anak ber-`position: absolute`
tidak bisa keluar dari kotak induknya, ke samping MAUPUN ke bawah.

Diperbaiki di **komponennya**, bukan di satu form - `grep` menemukan **16 dari 20** layar
pemakai `<x-search-select>` juga punya `.table-responsive`, jadi bug yang sama laten di
semuanya.

Panel sekarang `position: fixed` dgn koordinat dihitung `_ukur()` dari
`getBoundingClientRect()` pemicunya (`x-ref="pemicu"`). `fixed` lepas dari pemotongan induk
mana pun. Konsekuensi yg WAJIB diurus & sudah:
- posisi dihitung ulang saat **scroll & resize**; listener scroll dipasang dgn
  **`capture = true`** - event `scroll` TIDAK menggelembung, jadi tanpa itu scroll DI DALAM
  `.table-responsive`/`.modal-body` tidak tertangkap dan panel "melayang" di tempat lama;
- panel **dibalik ke atas** kalau ruang di bawah kurang, dan tinggi maksimumnya menyesuaikan
  ruang tersisa (CSS var `--ss-maks`);
- listener DILEPAS saat panel ditutup (`$watch('open')` + `destroy()`);
- `z-index: 1080` - di atas modal Bootstrap (1055).

**Jaring pengaman**: nilai awal `gaya` = persis posisi absolute versi lama. Kalau `_ukur()`
gagal, perilakunya kembali seperti sebelumnya (terpotong) - bukan jadi lebih rusak.

**Di dalam modal**: aman karena `.modal.show .modal-dialog { transform: none }` (Bootstrap
5.3) - `transform` pada leluhur akan membuat containing block dan merusak `fixed`. **Kalau
suatu saat modal dikasih animasi/transform sendiri, panel ini ikut rusak.**

**BELUM DIUJI DI BROWSER** - perubahan murni tata letak, tidak bisa dibuktikan dari
`Livewire::test()`/PHP. Yang sudah dicek cuma sintaks JS (`node --check`) & blade ter-compile.
12 layar memakainya di dalam modal, 16 di dalam `.table-responsive` - keduanya perlu dicoba.

## Master COA: ceklist "Dipakai di Kas Masuk / Kas Keluar" (2026-09-30)

`bcoa.CKASMASUK` / `CKASKELUAR` (smallint 0/1) kini bisa disunting dari form Master COA -
sebelumnya hanya bisa diubah langsung di DB.

**YANG SERING SALAH DIPAHAMI - ini BUKAN penanda akun kas/bank.** Dua ceklist ini menyaring
**AKUN LAWAN** (baris detail) di form Kas/Bank Masuk & Keluar. Akun kas/bank-nya sendiri
ditentukan **`CTIPE`** (0=Kas, 1=Bank). Dibuktikan dari CI3 & data nyata:

| | Sumber | Filter |
|---|---|---|
| Rekening kas/bank (header) | `LookupController::coaRekening()` · CI3 `view_coa_kas`/`view_coa_bank` | `CTIPE` 0/1 |
| **Akun lawan (baris)** | `LookupController::coaBiaya()` · CI3 `view_coa_kasmasuk`/`view_coa_kaskeluar` | **`CKASMASUK`/`CKASKELUAR`** |

Bukti di data: `CKASMASUK=1` cuma **2 akun BANK**, `CKASKELUAR=1` **24 akun BIAYA** - persis
pola "lawan jurnal", bukan daftar kas/bank. Di CI3, `.coakredit` kas-masuk.js pakai
`view_coa_kasmasuk` dan `.coadebet` kas-keluar.js pakai `view_coa_kaskeluar`.

**Filternya SUDAH ada & benar sejak awal** di `coaBiaya()` - yang kurang cuma ceklist di master.
Form **Bank** Masuk/Keluar di dias-laravel ikut memakai filter yang sama; CI3 justru TIDAK
memfilter versi Bank (`view_coa` polos) - perbedaan yang disengaja & sudah didokumentasikan di
docblock `coaBiaya()`. Baris **Pengajuan Dana** read-only (ditarik dari dokumen Kas Keluar),
jadi COA-nya otomatis sudah tersaring - tidak ada yang perlu diubah.

### Detail Biaya Kas KELUAR dibatasi ke akun berjenis biaya (2026-09-30)

`coaBiaya()` dgn `arah=keluar` kini juga mewajibkan `CTIPE IN (12,13,15)` - Harga Pokok
Penjualan, Biaya, Biaya Lain-Lain (`CoaManager::TIPE_BIAYA`).

**Ini WAJIB, bukan kosmetik.** Sejak `CKASKELUAR` dipakai menyaring dropdown REKENING juga
(lihat bagian di bawah), begitu user mencentang akun **Kas** supaya muncul sbg rekening,
akun itu **IKUT BOCOR ke dropdown Detail Biaya** - dilaporkan user beberapa jam setelah
fitur ceklist rekening dipasang, setelah mereka mencentang "Kas Kecil". Satu ceklist untuk
dua keperluan, **dipisahkan lewat TIPE akun**.

Arah **MASUK sengaja TIDAK dibatasi tipe**: lawan Kas Masuk bukan biaya, dan di data nyata
`CKASMASUK=1` justru 2 akun **BANK** (pemindahan antar rekening). Membatasi ke tipe pendapatan
akan mengosongkan dropdown-nya.

Diuji: Detail Biaya keluar = 24 baris, tipe yg muncul persis {12,13,15}; **Kas Kecil tidak
muncul lagi padahal `CKASKELUAR=1`**, TAPI tetap muncul sbg Rekening Kas Keluar; arah masuk
tetap 2 akun bank.

### Ceklist juga menyaring REKENING kas/bank (2026-09-30, permintaan lanjutan user)

`coaRekening()` menerima **`?arah=masuk|keluar`** -> mewajibkan `CKASMASUK`/`CKASKELUAR`.
**Tanpa `arah`, penyaringan ceklist TIDAK dipakai** - itulah yang menjaga **Pengajuan Dana**
(memanggil tanpa `arah`) tetap seperti sebelumnya.

Kenapa perlu: **`CTIPE` saja tidak bisa dipercaya**. Di data nyata CTIPE=0 (Kas) berisi
**Persediaan Bahan Baku**, **Persediaan Bahan Jadi**, dan satu baris bernama **"none"** -
salah tipe tapi ikut muncul di dropdown. Ceklist memberi daftar putih yang dikurasi manusia.

**CI3 tidak punya padanannya**: di sana field COA Kas form Kas Keluar malah **DIKUNCI** ke
`<option value="5">Kas Kecil</option>` + `disabled` - tidak ada pilihan sama sekali. Jadi ini
perbaikan atas CI3, bukan penyalinan.

**AKIBAT YANG HARUS DISADARI**: belum ada satu pun akun **Kas** yang diceklist, jadi dropdown
Rekening di **Kas Masuk, Kas Keluar, dan Bank Keluar KOSONG** sampai diceklist di Master COA.
Bank Masuk sudah ada 2 (Bank BCA 2500, BANK MANDIRI 101 - `CKASMASUK=1` dari data lama).
Aman dilakukan sekarang krn **`ctransaksiu` masih 0 baris** - belum ada dokumen Kas/Bank sama
sekali, jadi tidak ada data lama yang terdampak.

Diuji 20 asersi (dibungkus transaksi & di-rollback): form memuat/menyimpan kedua ceklist,
mencentang langsung menambah pilihan di dropdown arah yang benar dan **tidak** bocor ke arah
lain, melepas ceklist menghilangkannya lagi, dan **jumlah rekening Kas (5) / Bank (11) tidak
berubah sama sekali** - membuktikan ceklist tidak menyentuh daftar kas/bank.

## Struk POS: DUA ukuran + preferensi per user (2026-09-30)

Permintaan user: struk termal **58 mm** dan **setengah A4 / LX300** (yg setengah A4 dibuat
"seperti CI3"), plus setelan default per user. Keadaan berjalan: **hanya user cabang
Marketplace yg mencetak 58 mm**, sisanya setengah A4 - karena itu default aplikasi `a5`.

| Berkas | Isi | Keluaran |
|---|---|---|
| `resources/views/pos-receipt-58.blade.php` | termal 58mm (`@page size:58mm auto`, badan 48mm) | **HTML auto-print** |
| `resources/views/pos-receipt-a5.blade.php` | setengah A4, layout CI3 | **PDF** (mpdf, A5-L, inline) |
| `config/pos.php` | `struk_default` + `struk_pilihan` | |
| `lv_user_pref` (tabel BARU) | `user_id` + `struk_pos` | |

**A5 = PDF, 58mm = HTML** (keputusan user 2026-09-30: *"dikasir sudah jarang print ke fisik,
lebih banyak screenshot kirim WA dan email"*). A5 lewat `PdfReport::preview()` -> inline di tab
browser, nama berkas `Struk <no>.pdf`. 58mm TETAP halaman HTML auto-print: printer termal
dicetak langsung & PDF selebar 58mm merepotkan.

**Blade A5 harus ramah mpdf**: ukuran/orientasi dari opsi `['size'=>'A5','orientasi'=>'L']` -
**`@page` DIABAIKAN mpdf**; hindari flexbox/grid & `rem`, pakai tabel + `pt`. Tidak ada
`window.print()` di versi PDF.

`pos-receipt.blade.php` yg lama **DIHAPUS** (tidak dirujuk lagi).

**Urutan penentuan**: `?struk=58|a5` (menimpa SEKALI JALAN, tidak mengubah setelan - utk cetak
ulang saat kertas termal habis) -> `lv_user_pref.struk_pos` -> `config('pos.struk_default')`.
Nilai tak dikenal DIABAIKAN, bukan error: struk gagal tampil di kasir jauh lebih mahal.
Di POS ada tombol utama (ikut preferensi) + dua tombol kecil "58 mm" / "½ A4".

**Preferensi disimpan di `lv_user_pref`, BUKAN kolom baru di `auser`** - `auser` dipakai
bersama CI3/CI4 dan `2026-09-28_01_struktur.sql` berjanji tidak mengubah tabel legacy.
Preferensi berikutnya (mis. kepadatan tampilan) ditambah sbg KOLOM di tabel yg sama.
Disetel di **Administrasi User**; dikosongkan = **barisnya DIHAPUS**, bukan disimpan string
kosong, supaya "belum disetel" dan "sengaja default" tidak jadi dua keadaan berbeda.

**Isi struk setengah A4 disalin dari CI3** `formulir-penjualan-tunai.php`: nama PT per cabang
(26/28/48 = DAPS, 32 = NATIONAL HOSPITAL - DAPS, sisanya NMW), IG+WA, kolom
`Ref-IC-Dokt-Perawat` (`CONCAT_WS` SDNOREF-SDLANTAI2-dokter-perawat), blok bayar 3x2, no+jenis
kartu kalau nilainya ≠ 0, dan **Jumlah Point** (hanya kalau `SUSTATUSTADA=1` DAN
`SUTOTALTADA>=100.000`, 1 poin per 10.000). **Penggabungan baris paket**: baris dari paket
ber-`PUCETAKHEADERSAJA=1` tidak dicetak satu per satu - subtotalnya dijumlah jadi satu baris
"Paket <kode>", dikelompokkan per **kodePaket + kedatangan** (bukan kode paket saja), supaya
paket sama pada kedatangan berbeda tidak tergabung.

### JEBAKAN: `array_keys()` mengubah kunci '58' jadi INTEGER 58

Kunci array numeric-string SELALU di-cast PHP jadi integer. Akibatnya
`in_array('58', array_keys(config('pos.struk_pilihan')), true)` bernilai **FALSE** dan
preferensi 58mm diam-diam jatuh ke default - **sudah kena, ditangkap uji**. Dua tempat yg
terdampak & sudah diperbaiki: `User::strukPos()` (pakai `array_map('strval', ...)`) dan label
tombol di blade POS (pakai `(string) $kode === '58'`). `array_key_exists()` dan `Rule::in()`
TIDAK terdampak (keduanya menormalkan sendiri). **Tiap menambah kunci config yang berupa angka,
ingat ini.**

### Jebakan uji

`auth()->user()` mengembalikan **objek yang sama** selama satu proses, jadi relasi `pref` yg
sudah ter-cache membuat uji salah baca (terlihat spt preferensi tidak berfungsi padahal
`strukPos()` benar). Di produksi tidak jadi masalah - tiap request HTTP memuat user dari awal.
Di uji: `auth()->setUser(User::find($id))` sebelum tiap request.

### Jebakan uji PDF: JANGAN cari teks di dalam byte PDF

mpdf memakai **font SUBSET** - teks tersimpan sebagai indeks glyph, bukan ASCII, jadi
`str_contains($pdf, 'Invoice Penjualan')` SELALU false walau isinya benar (sudah kena).
Cara yang benar: isi diperiksa dari **HTML hasil `view(...)->render()`**, sedangkan PDF-nya
diperiksa secara berkas saja (`%PDF`, ukuran wajar, `Content-Type`, `Content-Disposition`
inline). Pola yang sama dipakai laporan lain (isi diverifikasi lewat keluaran Excel-nya).

Diuji 30 asersi (perubahan `lv_user_pref` dibungkus transaksi & di-rollback): default -> a5 &
isinya benar; preferensi 58 -> struk termal; `?struk=` menimpa tanpa mengubah setelan; nilai
rusak di DB -> jatuh ke default & halaman tetap 200; admin menyetel/mengosongkan lewat
Administrasi User; nilai tak sah ditolak validasi. Setelah A5 jadi PDF, diuji ulang: A5
`application/pdf` + inline + `%PDF`, 58mm tetap HTML ber-`window.print()`, `?struk=a5` dari
user 58mm tetap dapat PDF tanpa mengubah setelan. `2026-09-28_01_struktur.sql` sudah memuat
`lv_user_pref` + catatan migrasinya, diuji idempoten 2x jalan (6 tabel lv_, 10 migrasi).

## Laporan POS-IP "Daftar Penjualan Tunai" (2026-09-29)

Port dari CI3 `views/modul/laporan/xlap-daftar-penjualan-tunai.php` (dicari lewat
**riwayat git** CI3 - judulnya tidak ada di working tree, cuma di pesan commit). Satu baris per
transaksi POS (`fstoku` `SUSUMBER='IP'`, `SUSTATUS<>9`), 18 kolom, 2 baris total. PDF landscape
+ Excel, menu **Laporan > POS > Daftar Penjualan Tunai** (`laporan.penjualan-tunai`).

**Rumus kolom** (semua sudah dicocokkan ke PDF contoh user, 01-06-2026 cabang PG):
```
Kas Nett    = SUTOTALKAS - SUTOTALSISA          Cash Back = SUTOTALSISA
Total Real  = KasNett + Debit + Kredit + Transfer + Merchant
Total Semua = TotalReal + DP + Voucher + Piutang + DPSurgery + Surgery - TarikDP
- Tarik DP  = F_DP_PERHARI(SUID), DISIMPAN positif tapi DITAMPILKAN negatif
```
Baris total kedua ("Total TANPA Piutang Surgery") hanya menjumlah baris dgn
`SUNILAIPIUTANGBAYAR = 0`, dan dua kolom terakhirnya sengaja kosong (sama spt CI3 & PDF).

**Dua kejanggalan CI3 yg ditemukan waktu port:**
1. Alias `dpjumlah` ditulis DUA KALI di SELECT (`sutotaldp` lalu `sudp1`) sehingga yg menang
   `sudp1`. **Tidak berdampak** - diperiksa ke DB: kedua kolom identik di SELURUH 25.220 baris
   IP. Di sini dipakai `SUTOTALDP` (nama yg lebih jelas).
2. **BUG**: `Total Semua` pada baris total KEDUA tidak menambahkan Piutang (`$webpiutang` lupa
   dimasukkan ke `$totalweb`), padahal baris per-transaksi & baris total pertama
   menambahkannya. **Di sini SENGAJA DIPERBAIKI** (Piutang ikut dijumlah). Terdampak **9
   transaksi** di produksi - semua baris ber-Piutang kebetulan `SUNILAIPIUTANGBAYAR=0`, jadi
   angka baris kedua bisa beda dari CI3 pada rentang yg memuatnya, selisihnya persis
   sebesar Piutang-nya. **Sudah diberitahukan ke user.**

Filter: Tanggal s/d Tanggal, Cabang (default cabang aktif, disaring `cabangLaporan()`),
Jenis Merchant (`SUMERCHANTJENIS` = MCKODE teks, bukan MCID). **Filter Kontak yg ada di CI3
belum dibuat** - butuh pencarian `bkontak` (315rb+ baris) lewat `<x-search-select>`.

Diuji: 16 angka dicocokkan satu per satu ke PDF contoh (baris & KEDUA baris total) - semuanya
**persis sama**; PDF benar-benar terbentuk (`%PDF`, >20KB); Excel memuat judul, kedua baris
total, angka 40.009.848 dan Tarik DP negatif; cabang di luar hak akses -> 0 baris.

## POS: tombol "Ganti" langsung membuka pencarian (2026-09-29)

Tombol **Ganti** di kartu Pelanggan dulu `clearCustomer()` - menghapus dulu, baru kasir menekan
"Cari pelanggan": dua langkah. Sekarang langsung `openCustModal()`.

Efek samping yg justru **lebih baik**: batal mencari (Esc/tutup) berarti **pelanggan lama tetap
terpilih** - sebelumnya sudah terlanjur terhapus & harus dicari ulang. Aman karena
`pickCustomer()` sudah membersihkan voucher/DP milik pelanggan lama begitu ada pelanggan baru
(`clearVoucher()` + `clearDp()` di dalamnya) - jadi tidak ada voucher/DP nyangkut lintas
pelanggan. `clearCustomer()` TETAP ADA & dipakai setelah checkout sukses dan di
`editTransaction()`.

Pola yg sama sudah dipakai tombol "Ganti" DP di dialog bayar. **Kalau ada tombol "Ganti" lain
yg masih memanggil `clear*()`, itu kandidat perbaikan yg sama.**

### POS: Catatan Rekam Medis WAJIB (2026-09-29)

Input baru di kartu Pelanggan, **di bawah Cari Pelanggan** -> `fstoku.SUREKAMMEDIS`
(varchar 255), properti `$rekamMedis`. **Bukan kolom baru & bukan aturan baru**: kolomnya sudah
terisi di **26.314 dari 26.322** transaksi POS produksi (99,97%), jadi mewajibkannya mengikuti
kebiasaan yg sudah berjalan. BEDA dari `$catatan` (`SUCATATAN`, catatan bebas di "Data Lainnya").

Diperiksa di `checkout()` **SEBELUM validasi pembayaran**, supaya kasir tidak sempat mengisi
dialog bayar lalu ditolak gara-gara field di layar utama. Dua pemeriksaan: kosong/spasi saja
ditolak, dan **>255 huruf ditolak** - `strict => false` di `config/database.php` akan MEMOTONG
DIAM-DIAM kalau tidak dijaga. Nilainya `trim()` saat disimpan, dikosongkan setelah checkout,
dan ikut dimuat lagi oleh `editTransaction()`.

**DUA jebakan `<textarea>` + Livewire yg sudah kena di sini:**
- **Livewire TIDAK mengisi sendiri isi `<textarea>` saat render pertama** - isi awal HARUS
  dicetak di antara tag (`>{{ $rekamMedis }}</textarea>`), kalau tidak catatan lama tampak
  KOSONG waktu transaksi dibuka lewat Edit (padahal propertinya terisi).
- Dipakai `.live.debounce.400ms`, **bukan `.blur`** - ini field WAJIB, dan satu ketikan yg
  belum tersinkron saat tombol Simpan diklik akan jadi penolakan palsu.

### MENGISI pembayaran ≠ MENYIMPAN transaksi (2026-09-28)

Permintaan user: **"ketika save pembayaran jangan save transaksi, itu hanya mengisi
pembayaran"**. Jadi dialog bayar **tidak punya jalur simpan sama sekali**:

| Tindakan | Akibat |
|---|---|
| Tombol **OK** di dialog (`simpanPembayaran()`) | validasi isian -> tutup dialog. **Transaksi TIDAK disimpan.** |
| Tombol **Batal** (`closePayModal()`) | **kembalikan** isian spt saat dialog dibuka, lalu tutup |
| Tombol **Simpan Transaksi** di layar utama (`checkout()`) | satu-satunya yg menyimpan |

Layar utama sekarang punya **dua tombol**: "Isi/Ubah Pembayaran" (buka dialog, `F9`) dan
"Simpan Transaksi" (`checkout`, `Ctrl+Enter`). `Ctrl+Enter` = "setujui yg di layar": dialog
terbuka -> OK, tertutup -> simpan.

**Batal benar-benar membatalkan**: `openPayModal()` menyimpan salinan `$pay` ke
`$paySebelumnya`, `closePayModal()` memulihkannya. Tanpa itu Batal & OK sama saja (dua-duanya
cuma menutup dialog) - dan itu keadaan sebelum perubahan ini.

**Validasi pembayaran diangkat jadi `validasiPembayaran(): bool`** (kartu no/bank, kepemilikan
& sisa saldo voucher/DP) supaya dipakai OK **dan** `checkout()`. **TETAP diulang di
`checkout()`** - bukan pemborosan: isian bisa diubah lagi setelah dialog ditutup, dan
pemeriksaan voucher/DP menembak DB (saldo bisa berubah di sela itu, anti-race).

**Alur mengikuti VB6**: susun keranjang -> `F9` buka dialog -> isi -> OK -> Simpan Transaksi.
- `F9` membuka dialog (`openPayModal()`).
- `Ctrl+Enter` -> `hotkeyCtrlEnter()`: dialog terbuka = OK, tertutup = simpan transaksi.
- `F12` = OK juga, pemicunya DI DALAM `@if ($showPayModal)` jadi hanya hidup selama dialog
  terbuka - tapi **tidak bisa diandalkan**, lihat catatan F12 di bawah.
- `Escape` **tidak** menutup dialog (lihat bagian pintasan).

**`openPayModal()` memeriksa keranjang & pelanggan lebih dulu** supaya kasir tidak membuka
dialog lalu langsung ditolak; validasi sebenarnya tetap di `checkout()` (satu-satunya gerbang).
**`showPayModal` HANYA di-false-kan di blok sukses `checkout()`** - kalau validasi gagal dialog
sengaja dibiarkan terbuka supaya pesannya terbaca di sebelah isiannya.

### Pintasan bayar di dalam dialog - urut sesuai kolom (2026-09-28)

Penomoran LUAR dialog diurutkan ulang user 2026-09-29 (pelanggan ke F1, promo F3, paket F4):

| Tombol | Di DALAM dialog bayar | Di LUAR dialog |
|---|---|---|
| `F1` | Tunai - bayar penuh | **cari pelanggan** |
| `F2` | Kartu Debit - bayar penuh | cari item |
| `F3` | Kartu Kredit - bayar penuh | **cari promo** |
| `F4` | Transfer - bayar penuh | **cari paket** |
| `F5` | **DP - buka pemilih** | cari voucher |
| `F6` | Merchant - bayar penuh | cari DP |
| `F7` | **Voucher - buka pemilih** | tidak melakukan apa pun |

**DUA perilaku berbeda, sengaja**: F1/F2/F3/F4/F6 -> `bayarPenuh($key)` mengosongkan **semua
NILAI bayar** lalu mengisi metode itu sebesar total transaksi, dan kursor pindah ke isian yg
masih perlu dilengkapi (`fokus-bayar` + `x-ref`). **F5 (DP) & F7 (Voucher) hanya MEMBUKA
pemilihnya** - keduanya tidak bisa "diisi penuh" karena nilainya berasal dari saldo yg dipilih
(`pickDp()` mengisi sebesar sisa saldo), dan sisa itu sering tidak menutup seluruh transaksi,
jadi metode lain **TIDAK dikosongkan** karena biasanya masih dibutuhkan menutup sisanya.
`bayarPenuh()` MENOLAK key `dp`/`voucher` (dijaga daftar `PINTASAN_BAYAR`).

Voucher/DP yg sudah dipilih **tidak dilepas** oleh `bayarPenuh()`, hanya nilainya jadi 0
(`checkout()` mengabaikan keduanya saat jumlah 0) - supaya kasir tidak perlu mencarinya ulang.

**Tautan "uang pas" DIHAPUS 2026-09-29** atas permintaan user ("sudah ada shortcut key F"),
beserta `payExact()`, `payExactMethod()` dan `totalBayarExcept()` yg jadi tidak terpakai. F1 di
LUAR dialog ikut dimatikan - dulu mengisi tunai diam-diam padahal tidak ada isian yg terlihat
di layar utama.
**KONSEKUENSI yg perlu diingat**: "uang pas" mengisi SISA yg belum terbayar, `bayarPenuh()`
mengisi TOTAL PENUH. Jadi **tidak ada lagi cara satu-klik mengisi sisa pada PEMBAYARAN
TERBAGI** (mis. debit 20rb dulu, lalu tunai sisanya) - kasir mengetik sendiri sisanya. Kalau
nanti dikeluhkan, rumus lamanya ada di komentar `PosTerminal` (bekas blok `payExact`).

Modal DP/voucher berada SETELAH dialog bayar di urutan blade sehingga tampil di atasnya;
menutupnya mengembalikan ke dialog bayar dgn isian utuh. Tombol "Ganti" DP langsung
`openDpModal()` (dulu `clearDp()`, yg memaksa 2 langkah).

**`Esc` SENGAJA TIDAK menutup dialog bayar** (permintaan user) - isian bayar terlalu mahal utk
hilang gara-gara Esc kepencet. Hanya lewat tombol Batal / (x). Esc TETAP menutup sub-dialog di
atasnya karena pengikatnya ada di badan modal masing-masing dan **bukan `.window`** - kalau
dipasang `.window`, satu Esc akan menutup keduanya sekaligus.

**SELURUH F1-F7 BENTROK dgn pintasan global.** Solusinya **satu pengikat per tombol di `<div>`
root dgn percabangan di PHP** (`hotkeyF1`..`hotkeyF11`): dialog terbuka -> arti bayar, tertutup
-> arti lamanya. **JANGAN memasang `wire:keydown.fN.window` kedua di dalam modal** - keduanya
akan ikut jalan (nilai bayar terisi TAPI modal pencarian ikut terbuka). Pola sama dipakai
`hotkeyCtrlEnter()`.

### Modal TANPA isian wajib `tabindex` + fokus otomatis, kalau tidak panahnya mati (2026-09-30)

Dilaporkan user: panah tidak berfungsi di **"Pilih Baris Promo"**. Penyebabnya bukan
methodnya — `wire:keydown` dipasang di `div.modal-body`, tapi modal itu **tidak punya satu pun
isian**, jadi fokus tetap di `<body>`, event-nya tidak pernah membubble ke div tersebut.
Modal lain (Cari Item/Pelanggan/Paket/Harga Khusus) selamat hanya karena punya `<input>` yang
di-autofokus.

**Pola WAJIB untuk modal berpanah tanpa isian** — persis seperti modal Voucher & DP yang sudah
benar sejak awal:

```blade
<div class="modal-body" wire:key="..." x-data tabindex="-1"
     x-init="$nextTick(() => $el.focus())"
     wire:keydown.arrow-down.prevent="..." ...>
```

Terkena: "Pilih Baris Promo" & "Pilih Baris Kombinasi" (keduanya juga memakai pola lama
`@keydown...="$wire.method()"`, diseragamkan ke `wire:keydown`). Ujinya sekarang **memindai
SEMUA `modal-body`** di blade POS dan gagal kalau ada yang punya `arrow-down` tanpa `<input>`
dan tanpa fokus otomatis — jadi modal baru tidak bisa lolos dengan cacat yang sama.

**Jebakan saat menguji ini**: jangan potong tag HTML sampai `>` pertama — atribut
`x-init="$nextTick(() => $el.focus())"` **mengandung `>`** di dalam `=>`, sehingga pemotongan
naif berhenti di tengah atribut dan menghasilkan 8 kegagalan palsu (sudah kejadian). Ambil
per-baris.

### Pencarian "Daftar Paket" menampilkan Total (2026-09-30)

Kolom Total = **`epaketu.PUTOTALHARGA`**, kolom yang SAMA dipakai VB6 di layar yang sama
(`bFrmCariPaket.frm` baris 331: `select PUID,PUKODE,PUNAMA,PUTOTALHARGA from epaketu ...`).

**BUKAN `SUM(epaketd.PDSUBTOTAL)`** — keduanya TIDAK selalu sama. Dari 1.783 paket aktif:
5 terisi keduanya tapi berbeda, 6 header-nya 0 padahal detailnya berisi, 24 sebaliknya,
58 nol di kedua sisi. Dipakai `PUTOTALHARGA` supaya angkanya sama dengan yang selama ini
dilihat user di VB6. **Paket ber-Total 0 (64 paket) itu masalah DATA MASTER, bukan bug** —
jangan "diperbaiki" dengan diam-diam menghitung dari detail, karena hasilnya akan beda dari
aplikasi lama tanpa user tahu. Nol ditampilkan apa adanya, bukan `—`, sesuai VB6.

### Pencarian "Harga Khusus" menampilkan Minimal Qty (2026-09-30)

Tiga kolom qty di modal, dari `PosTerminal::minimalQtyPerPromo()`. **Qty ada di baris DETAIL
(`emasterpromod`), bukan di master** — jadi harus diringkas per `MPDIDU`.

**Pemetaan kolom — PERLU DIKONFIRMASI USER.** `emasterpromod` punya TIGA kolom qty dan
**tidak ada `...QTY2`** (memang begitu di skema legacy). Label VB6-nya
(`eFrmMasterPromoDiskon2.frm` baris 1509, `eFrmMasterPromoData.frm` 562):

| Di layar kita | Kolom | Label VB6 | Slot item |
|---|---|---|---|
| Min Qty 1 | `MPDMINIMALQTY` | "Minima Item 1" & "Minimal Item 2" | item 1 **dan** 2 (berbagi kolom) |
| Qty 2 | `MPDMINIMALQTY3` | "Min Qty 3" | item 3 |
| Qty 3 | `MPDMINIMALQTY4` | "Min Qty 4" | item 4 |

**Ditampilkan sebagai DAFTAR (mis. `1/3/5`), bukan satu angka** — satu promo rata-rata punya
**7,5 baris** detail (maks 189 di data nyata) dan **111 promo qty-nya BERBEDA antar baris**;
menampilkan `MIN` saja akan menyesatkan kasir. `NULLIF(...,0)` membuang nol karena nol berarti
"tidak dipakai", bukan "minimal 0 buah" (11.251 baris detail nol di ketiganya) → di layar `—`.
Digabung lewat **`leftJoinSub`**, bukan join langsung ke `emasterpromod`, supaya satu promo
tidak jadi banyak baris di hasil pencarian (diuji: hasil tidak terduplikasi).

**Jebakan saat menguji pencarian promo**: di data ini **SELURUH** promo yang aktif+berlaku
hari ini+ber-qty (59 promo) punya batasan `emasterpromokontaktipe`. Memakai sembarang kontak
membuat hasilnya nihil dan ujinya "lulus" tanpa menguji apa pun — pelanggan uji WAJIB dipilih
yang `KTIPE`-nya ada di `emasterpromokontaktipe` promo itu.

### Pencarian Operator & Dokter DIBATASI cabang aktif (2026-09-30)

`PosTerminal::cariPetugas()` (badan bersama `operatorSearchResults()`/`dokterSearchResults()`)
menambah `bkontak.KCABANG = $this->branchId`. **BEDA DISENGAJA dari VB6**: di sana
(`eFrmPOS.frm`/`eFrmPOS2Edit.frm`, `bFrmCariKontak.lblFilterTambahan`) filternya HANYA
`coalesce(KTAMPILDIDOKTER,0)=1` / `...DIPERAWAT...` — **tanpa** batasan cabang, jadi kasir Bali
bisa memilih perawat Petogogan. Ini penambahan atas permintaan user, bukan port.

Aman diterapkan ketat: diperiksa di data nyata, **SELURUH** operator (223) & dokter (59) aktif
punya `KCABANG` terisi dan menunjuk `bgudang.GID` yang ADA — tidak ada yang hilang. Kalau suatu
saat ada yang `KCABANG`-nya kosong, dia tidak akan muncul di cabang mana pun; perbaikannya di
master kontak, **jangan** dengan melonggarkan filter. `branchId` `null` → filter DILEWATI (bukan
mencocokkan `KCABANG = NULL` yang pasti nihil); transaksinya toh tetap tidak bisa disimpan tanpa
cabang. Kedua modal menyebutkan cabangnya terang-terangan supaya kasir tidak mengira nama
rekannya hilang. Keduanya memakai **satu** badan query — jangan dipecah lagi, supaya filternya
mustahil terpasang di salah satu saja (diuji: potongan filternya hanya boleh muncul 1×).

**Jebakan saat menguji pencarian kontak**: nama dokter berawalan `dr.` — kata itu lolos filter
"panjang ≥3" tapi **tidak bisa dicari**: titiknya tidak dibuang `Contact::kandidatId()` dan "dr"
cuma 2 huruf, di bawah `innodb_ft_min_token_size` (3), jadi FULLTEXT mengabaikannya dan hasilnya
nihil. Saat menyusun kata kunci uji, ambil kata **murni huruf ≥4**.

### Pemetaan pintasan BERLAKU (per 2026-09-30, penomoran KEEMPAT) — rujuk ini, jangan komentar lama

| Tombol | Dialog bayar TERTUTUP | Dialog bayar TERBUKA |
|---|---|---|
| F1 | Cari Pelanggan | *(kosong)* |
| F2 | Cari Item | **Merchant** |
| F3 | *(kosong)* | *(kosong)* |
| F4 | *(kosong)* | **Tunai** |
| F5 | Cari Voucher | **Kartu Debit** |
| F6 | Cari DP | **DP** (buka pemilih) |
| F7 | *(kosong)* | *(kosong)* |
| F8 | **Buka dialog Pembayaran** | **Kartu Kredit** |
| F9 | *(kosong)* | **Transfer** |
| F10 | **Harga Khusus** (dulu "Cari Promo") | **Voucher** (buka pemilih) |
| F11 | **Daftar Paket** (dulu "Cari Paket") | *(kosong)* |
| Ctrl+Enter | Simpan Transaksi | OK (isi bayar saja) |

Sumber kebenaran sisi bayar = `PosTerminal::PINTASAN_BAYAR`
(`F4`→tunai, `F5`→debit, `F8`→kredit, `F9`→transfer, `F2`→merchant); DP & Voucher tidak di
situ karena **membuka pemilih**, bukan `bayarPenuh()`.

**DP tidak disebut user** saat penomoran keempat — F5 yg dulu memegangnya jadi Debit, jadi DP
ditaruh di **F6**: satu-satunya tombol tersisa yg tidak bentrok, DAN artinya jadi konsisten
karena di LUAR dialog F6 memang sudah "Cari DP" (F6 juga pemegang DP pada penomoran pertama).

Nama variabel/tabel TETAP `promo`/`paket` — yg berubah hanya **caption & pintasan**, skemanya
tidak. `hotkeyF11` sengaja DIAM selagi dialog bayar terbuka: memilih paket di tengah dialog akan
mengubah total sementara isian bayar sudah terkunci di layar. `hotkeyF8` **tidak** membuka ulang
dialog saat sudah terbuka — `openPayModal()` menyalin ulang `$paySebelumnya`, jadi memanggilnya
lagi menghapus titik pulih tombol Batal.

**AWAS hint tombol yg jadi berbohong.** Urutan pintasan sudah **EMPAT KALI** berubah, dan tiap
kali ada hint `<kbd>` / komentar di tempat lain yg jadi salah:
- perubahan ke-3 menemukan **4 komentar basi** sisa perubahan ke-2 (`Modal Pembayaran (F9)`,
  `Paket (F3)`, `MODAL CARI PELANGGAN (F4)`, `Modal cari Promo (F7)`);
- perubahan ke-4 menemukan **2 label tombol basi** yg terlewat lagi: "Cari DP pelanggan…"
  masih `F5` dan "Cari voucher pelanggan…" masih `F7`.

Tiap mengubah pemetaan, **telusuri SEMUA `<kbd>` DAN semua komentar yg menyebut Fn** di komponen
+ blade-nya (`grep -oE 'F[0-9]+'` pada KEDUA berkas, lalu periksa satu per satu). Uji otomatisnya
mencocokkan pasangan label–tombol sbg **potongan teks PERSIS** (mis. `Transfer <kbd>F9</kbd>`) —
**jangan** pakai pola longgar "kbd pertama setelah label": kata spt "Tunai" juga muncul di kode
JS (`'bayarTunai'`) sehingga pencarian longgar salah sasaran (sudah kejadian).

**`F12` TIDAK BISA DIANDALKAN**: Chrome/Edge membuka DevTools pada F12 dan `preventDefault()`
tidak bisa mencegahnya. Sempat dipilih karena mengikuti tombol "OK [F12]" di VB6. Jalur simpan
sekarang: **`Ctrl+Enter`** (dan tombol OK). **F10 (bilah menu Firefox) & F11 (layar penuh)
BERBEDA — keduanya MASIH bisa dicegah** `preventDefault()`, karena itu dipakai untuk Harga
Khusus & Daftar Paket. Kalau ternyata di peramban tertentu F11 tetap memicu layar penuh,
tombol di layar tetap jadi jalan keluarnya. Hindari F12 untuk pintasan baru apa pun.

**Diuji** (Livewire::test, bagian yg menulis dibungkus `beginTransaction()`/`rollBack()`,
item+pelanggan diambil dari transaksi POS NYATA terakhir): keranjang kosong -> dialog tidak
terbuka; `pay.tunai` tidak ada di HTML saat dialog tertutup; ketujuh jenis bayar ada &
Medika Apps/Piutang Surgery/Cicilan TIDAK ada; debit diisi tanpa no/bank -> dialog TETAP
terbuka + 2 pesan galat + belum tersimpan; tunai pas -> struk terbit, dialog tertutup,
keranjang kosong; rollback bersih. **SEMUA LULUS.**

Jebakan uji: **`json_encode()` meng-escape `/`** jadi pesan `No. kartu/ref` tersimpan sbg
`No. kartu\/ref` - cocokkan dgn `JSON_UNESCAPED_SLASHES`. Dan **blok Voucher/DP belum memuat
`wire:model` sebelum ada yg dipilih** (yg tampil tombol carinya), jadi jangan mencari string
`pay.voucher`/`pay.dp` utk membuktikan bloknya ada. Nama method: `pickCustomer()` &
`pickItemById()` (bukan `pickCust`/`pickItem`); `bitem` TIDAK punya `IAKTIF`/`IHARGAJUAL`.

## POS: Cari Paket dipindah + "Data Transaksi" jadi menu sendiri (2026-09-28)

Dua permintaan user:
1. **Cari Paket dipindah ke BAWAH Cari promo** - dari toolbar header kolom kiri ke kartu
   Promo di kolom kanan, digayakan sama (`w-100 text-start` + `kbd` rata kanan). Pemicu **F3**
   tetap di `<div>` terluar `pos-terminal.blade.php`, jadi tombolnya pindah TANPA menyentuh
   shortcut.
2. **Tombol "Data Transaksi" DIHAPUS dari POS, jadi menu sidebar sendiri**: Penjualan >
   **Data Transaksi POS** (`sales.pos-data` -> `sales/pos-data` -> `sales.pos-data-list`).
   `PosTerminal::openPosDataTab()` dihapus (tidak ada pemanggil lain).

**Path ACL `sales/pos-data` SENGAJA terpisah dari `sales/pos`** - supervisor bisa diberi akses
melihat & cetak ulang transaksi TANPA diberi akses layar kasir. `PosDataList` dapat
`const ACL`, `abort_unless(can_do(self::ACL,'view'), 403)` di `mount()` (defense-in-depth:
`openFromSidebar()` menerima segment_key dari klien) dan tombol Cetak digating
`can_do('sales/pos-data','print')`.

Yang harus diubah bersamaan kalau menambah menu baru: **(1)** `MenuSeeder` (sumber kebenaran),
**(2)** `Workspace::listRegistry()` - tanpa ini klik menu tidak melakukan apa pun karena
`openFromSidebar()` langsung `return`, **(3)** jalankan `db:seed --class=MenuSeeder`,
**(4)** bangkitkan ulang `database/production/2026-09-28_02_menu.sql` (lihat bawah).
Sidebar sendiri sudah otomatis menyaring `view` lewat `Acl::sidebarTree()`.

**`sort_order` bisa bertabrakan** - `sales.order` sudah memakai 32, jadi grup Penjualan
dinomori ulang 31..40 (pos 31, pos-data 32, order 33, sj 34, invoice 35, invoice-mutasi 36,
return 37, alkes 38, promo 39, paket 40). Aman: `sort_order` hanya urutan tampilan.

**`2026-09-28_02_menu.sql` sekarang DIBANGKITKAN**, jangan disunting tangan:
`2026-09-28_02_menu.gen.php` (jalankan SETELAH menyeed). Diuji idempoten: dijalankan 2x di DB
terpisah tetap 59 baris.

### Jebakan uji Livewire yang baru ketemu - hemat waktu nanti
- **`Livewire::test()` MENANGKAP `HttpException` dari `abort()` dan mengubahnya jadi STATUS
  RESPONS.** `try/catch` di sekitar `Livewire::test()` TIDAK akan pernah kena, dan "render
  lolos" BUKAN berarti izinnya bocor - saya sempat salah menyimpulkan ACL bocor karena ini.
  Pakai **`->assertForbidden()`** (jalan di tinker) untuk membuktikannya.
- **`assertOk()` / `assertSee()` TIDAK bisa di tinker** - `assert(self::$instance instanceof
  Configuration)`, butuh PHPUnit (sama spt `assertHasErrors`). Pakai `->html()` + `str_contains`.
- **`app('acl')` singleton MENYIMPAN user saat pertama di-boot.** `auth()->login($userLain)`
  di tengah proses tinker memberi izin BASI (super user terbaca `can_do = false`). Uji tiap
  user di **proses tinker terpisah**.
- **Tombol yang ada di dalam `@forelse` tidak akan muncul kalau hasilnya 0 baris.** Filter
  default `PosDataList` = HARI INI, dan transaksi POS terakhir 2026-09-25, jadi `fa-print`
  tidak ketemu - terlihat spt gating print rusak padahal tidak. Lebarkan rentang tanggalnya
  dulu sebelum menyimpulkan.

## DITUNDA: titik masuk menu = daftar atau form baru? (2026-09-28)

User bertanya apakah menu sebaiknya langsung membuka **form input baru** spt aplikasi lama,
supaya user tidak terlalu jauh berbeda. **Diputuskan DISKIP dulu** - tidak ada perubahan kode.
Simpan temuannya supaya tidak digali ulang:

- **CI3 `dias-online-app` memang berkebalikan**: `Page.php` punya DUA route per modul -
  `bkk` -> `kas-keluar.php` (form kosong) & `bkkData` -> `table-kas-keluar.php` (daftar).
  Menu sidebar menunjuk ke FORM; daftar dicapai dari tombol **"Cari"** di dalam form
  (`location.href = page/bkkData`). Dari 110 loader di `Page.php`, **60 adalah `table-*`** -
  jadi daftarnya ada & dipakai, hanya bukan titik masuk.
- **dias-laravel**: menu -> `*-list`, tombol "Baru" -> `dispatch('open-tab', cmp: '*-form')`
  (tab terpisah). Sudah ada preseden menu yg langsung ke layar entri: `sales.pos`.
- **PENGHALANG UTAMA kalau mau ditukar - ACL**: `view` dan `add` kewenangan TERPISAH, dan 17
  blade daftar menyembunyikan tombol Baru kalau user tak punya `add`. Menu = form baru berarti
  user `view`-saja dan **approver (`view`+`approve`, TANPA `add`)** mendarat di form yg tidak
  boleh dia simpan. Di CI3 ini tidak terasa krn `ausermenu` hanya per-menu, bukan 6 kewenangan.
- Kalau nanti dikerjakan, **jangan sapu rata**: tambah `formRegistry()` di `Workspace` +
  preferensi per user, dan buka form langsung HANYA kalau preferensi ON **dan** user punya
  `add` (kalau tidak, jatuh ke daftar) - itu sekaligus menyelesaikan masalah ACL di atas.
- Dugaan saya sumber "terasa jauh beda" yg sebenarnya: **toolbar**. CI3 punya deretan tombol
  tetap dgn posisi/urutan sama di semua modul (Baru · Simpan · Hapus · Cari · Cetak) + tombol
  hidup bertahap (`btn-step1`/`btn-step2`). Di app ini tombolnya beda-beda ("PB Baru",
  "Penyesuaian Baru") & posisinya tidak konsisten. Menyeragamkan itu lebih terasa & jauh lebih
  kecil risikonya drpd menukar titik masuk.

## Stok per gudang: kolom dipisah + trigger dirapikan (2026-09-28)

**Dikerjakan atas permintaan eksplisit user** ("tolong kamu rapihkan trigger itu", lalu
"pisahkan kolom gudang itu buat menjadi kolom sendiri") - ini **pengecualian** dari aturan
CLAUDE.md "jangan perbaiki trigger/pembukuan legacy, cukup dokumentasikan". Diserahkan sbg
**file SQL yang sudah diuji**, BUKAN dijalankan langsung. Tiga file:
`database/production/2026-09-28_03_pisah_kolom_stok.sql` (yg dijalankan),
`.gen.php` (pembangkitnya), `.uji.sh` (pengujinya). **Belum dijalankan di
`data_pos_nmw_2023`** - menunggu izin user.

### Akar masalahnya: `F_KOLOMGUDANG()` punya `ELSE 'ISTOKPG'`

Fungsi ini yg dipakai **SEMUA** aplikasi (dias-laravel & CI3 dias-online-app) utk menentukan
kolom stok sebuah gudang, dan dia hanya mendaftarkan sebagian gudang lalu menutupnya dgn
`ELSE 'ISTOKPG'`. Jadi **7 gudang yg tidak terdaftar diam-diam membaca kolom Petogogan** -
bukan karena ada yg memutuskan menggabung, tapi karena lupa didaftarkan. `ISTOKMP` juga
dibagi 2 gudang (9 Gogobli, 18 Marketplace) - ini eksplisit di fungsi.

**Perbaikan intinya: `ELSE` sekarang -> `ISTOKXX`, bukan `ISTOKPG`.** Gudang baru yg lupa
dipetakan jadi terlihat salah di satu kolom yg bisa diaudit, tidak lagi menumpuk ke Petogogan.
Trigger ikut punya statement penampung `ISTOKXX` dgn `SDGUDANG NOT IN (...)` supaya sisi TULIS
& BACA sama. `SELECT COUNT(*) FROM bitem WHERE ISTOKXX<>0` harus SELALU 0.

### Tidak ada angka yg perlu dipecah - sudah diperiksa

Dari 8 gudang yg berbagi `ISTOKPG`, **hanya gudang 1 (2.033 mutasi) & 42 (85 mutasi)** yg punya
mutasi, dan mutasi gudang 42 **belum pernah tertulis ke kolom mana pun** (tidak ada statement
trigger untuknya). Jadi `ISTOKPG` sekarang MURNI Petogogan -> **dibiarkan apa adanya**. Idem
`ISTOKMP`: gudang 9 nol mutasi, isinya murni gudang 18. Kolom baru mulai dari 0, TIDAK diisi
dari mutasi lama (netto gudang 42 = **-932,5**, negatif karena keluar tanpa saldo awal yg
tercatat - mengisinya justru menanam angka salah). Penyesuaian 1 Okt yg menetapkan angkanya.

### Kolom: 5 dibuat, 4 dipakai ulang

Baru: `ISTOKDT` (39 Depo Tindakan), `ISTOKKB` (40 Kubis 1), `ISTOKDR` (42 Depo Research),
`ISTOKBS` (49 Busura), `ISTOKXX` (penampung). Dipakai ulang - **sudah ada di skema tapi tidak
pernah dipakai, namanya jelas peruntukannya**: `ISTOKOL` (6 Online), `ISTOKGG` (9 Gogobli),
`ISTOKHC` (14 Home Care), `ISTOKPM` (15 Pameran, `int(11)` -> `double`). Bukti penggabungan ini
regresi belakangan, bukan rancangan awal. Seluruh 49 kolom diberi `COMMENT 'Gudang N Nama'`
supaya petanya terbaca dari `SHOW FULL COLUMNS FROM bitem`.

**`ISTOKOL` berisi 106 item nilai basi (jumlah -3.846) & WAJIB dinolkan** - selama ini gudang 6
membaca `ISTOKPG` jadi isi kolom itu tidak terlihat; setelah dipisah kolomnya mulai dibaca dan
stok hantu akan muncul. Gudang 6 nol mutasi -> stok benarnya memang 0.

### PRASYARAT yg hampir terlewat: `bitem` ROW_FORMAT COMPACT -> DYNAMIC

`bitem` punya **222 kolom + 4 kolom TEXT** dan `ROW_FORMAT=COMPACT`, di mana tiap TEXT menyimpan
awalan **768 byte DI DALAM baris** (4 x 768 = 3.072 byte) - tabel ini sudah mentok batas 8.126
byte/baris. Akibatnya **setiap `MODIFY COLUMN` gagal** dgn `ERROR 1118 Row size too large`,
karena MODIFY memaksa tabel dibangun ulang & batas itu diperiksa lagi. Yg gagal BUKAN
`ADD COLUMN` (itu lolos lewat INSTANT/INPLACE) - sempat menyesatkan saya. `ALTER TABLE bitem
ROW_FORMAT=DYNAMIC` menyelesaikannya (TEXT jadi penunjuk 20 byte); DYNAMIC juga sudah default
server (`innodb_default_row_format=dynamic`), `bitem` COMPACT warisan dump lama. Terukur
**0,3 detik utk 5.580 baris**, semua baris utuh. **Kalau nanti perlu menambah kolom ke tabel
legacy lain, cek ROW_FORMAT-nya dulu.**

### `sql_mode` WAJIB diset di file SQL, 2 alasan berbeda

`SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';`
1. `ALTER TABLE bitem` GAGAL dgn sql_mode default server (`ERROR 1067 Invalid default value for
   'IMODIFD'`) - MariaDB memvalidasi ULANG seluruh definisi tabel saat ALTER, & kolom warisan
   `IMODIFD` punya default `'0000-00-00'` yg ditolak `NO_ZERO_DATE`.
2. **Trigger & rutin MENYIMPAN sql_mode saat dibuat lalu memakainya tiap kali jalan.** Nilai di
   atas persis sama dgn yg tersimpan di `fstokd_*`/`F_KOLOMGUDANG`/`P_RESETSTOK*` sekarang
   (dibaca dari `information_schema.TRIGGERS.SQL_MODE`) supaya yg baru berperilaku identik.
   **Jangan pakai `sql_mode=''`** - itu mengubah perilaku trigger diam-diam.

### Kode aplikasi TIDAK perlu diubah

Seluruh dias-laravel sudah memanggil `F_KOLOMGUDANG()` (`KartuStok`, `PkbForm`, `PrForm`,
`SjForm`, `PosTerminal`) - tidak ada pemetaan gudang->kolom yg ditulis keras. CI3
`dias-online-app` juga (`Datatable_Master.php`, `PB_Permintaan_Barang.php`). Tidak ada VIEW yg
menyebut `ISTOK`. `KartuStok::stokSistem()` mencari "gudang lain yg berbagi kolom" lewat
`whereRaw('F_KOLOMGUDANG(GID) = ?')` -> setelah dipisah hasilnya kosong sendiri & peringatan di
layar hilang tanpa ubah kode. Hanya docblock yg diperbarui.

### `P_RESETSTOK*` ikut diperbaiki (bug destruktif)

`P_RESETSTOKPERBARANG(IDBARANG)` punya **11 `UPDATE` TANPA `WHERE`** -> stok SATU barang
ditimpakan ke SELURUH 5.580 barang. Cabang `IDGUDANG=1` di `P_RESETSTOKPERBARANGGUDANG` juga
tanpa `WHERE`, dan masih menjumlah gudang 1+6 / 9+18. Tidak ada aplikasi yg memanggilnya
(dicek di 3 codebase) jadi belum pernah meledak. Ditulis ulang pakai **SQL dinamis + PREPARE**,
kolom dari `F_KOLOMGUDANG()` -> otomatis ikut kalau ada gudang baru, tidak ada daftar yg bisa
basi. **Tetap BERBAHAYA dijalankan**: menghitung ulang murni dari `fstokd`, padahal hanya
**31 dari 2.872 item** yg `ISTOKPG`-nya sama dgn netto mutasi gudang 1 (stok sebelum
pertengahan 2026 tidak punya jejak mutasi). Diperbaiki supaya aman KALAU dipanggil, bukan
supaya dipakai.

### Trigger: 3 cacat yg diperbaiki, semuanya HANYA pada statement `update bitem set ISTOK...`
1. **Duplikat**: `ISTOKBZ` (gudang 10 Bizpark) & `ISTOKDE` (gudang 20 Depo) masing2 ditulis
   DUA KALI -> tiap mutasi dihitung 2x. Terbukti di data: dari 237 item di gudang 20, **56
   item** nilai `ISTOKDE`-nya tepat 2x netto mutasinya.
2. **Gudang terlewat**: 16 Tokopedia & 38 Rhein Medika Sektor 9 tidak punya statement sama
   sekali -> mutasinya tidak tercatat ke kolom mana pun, padahal aplikasi TETAP menampilkan
   stok utk gudang itu. Idem gudang 15/39/40/42/49 - **gudang 42 "Depo Research" (AKTIF)
   sudah punya 85 baris mutasi yg hilang begitu saja**.
3. **Tulis vs baca beda**: kolom tujuan tiap gudang sekarang diambil dari `F_KOLOMGUDANG()`
   - fungsi yg sama yg dipakai aplikasi MEMBACA stok, jadi tidak bisa lagi menyimpang.

**Dibangkitkan skrip, bukan ditulis tangan**
(`database/production/2026-09-28_03_pisah_kolom_stok.gen.php`, jalankan dari root project via
`artisan tinker --execute="require '...'"`): body trigger
asli dibaca dari `information_schema.TRIGGERS` lalu disunting **bedah** - buang statement
kembar (normalisasi spasi), perluas kondisi `ISTOKPG`, tambah statement gudang baru dgn
**statement `ISTOKCP` (gudang 2) sbg cetakan** karena pemetaannya 1:1 & kondisinya paling
sederhana. Utk `_edit` diambil DUA bentuk sekaligus: pembalik OLD (`-`) dan penerap NEW
(`+ ... WHERE NEW.SDCANCEL=0`).

**Logika NON-STOK di dalam trigger disalin apa adanya** dan itu diverifikasi: `PBDQTYTERIMA`
(PR), `PKBDQTYPAKAI`, `official_nmw.ops_invoice_header`, `esalesorderd.sodmasuk`,
`fproduksid.PDMASUKPAKAI/PDKELUARPAKAI`, blok IF item 5076/5720. Diff potongan non-stok:
**10 vs 10, 16 vs 16, 8 vs 8 - IDENTIK** di ketiga trigger.

**Diuji di DB terpisah** `uji_trigger_dias`
(`database/production/2026-09-28_03_pisah_kolom_stok.uji.sh`, `sh` dari mana saja - jalur di
dalamnya absolut; DB uji dibuat & dibuang sendiri): struktur disalin
tanpa data, trigger LAMA dipasang dulu utk **membuktikan cacatnya ada**, baru diganti yg
baru. Hasil - masuk 50 ke gudang 20,10,42,38,16: LAMA `DE=100 BZ=100 PG=0 TP=0 RK=0`
(dobel + hilang) -> BARU `DE=50 BZ=50 PG=50 TP=50 RK=50`. insert/update/delete diuji per
gudang (1 yg sudah benar, 20 yg tadinya dobel, 16 yg baru, 42 yg tadinya hilang); SDKELUAR
juga (100-40=60).

**Jebakan yg sempat menipu saya - jangan diulang**:
- **Jumlah statement stok BUKAN bukti duplikat hilang**: buang 2 kembar + tambah 2 gudang
  baru = jumlah tetap 41/82/41, sama seperti sebelumnya. Duplikat hanya terlihat dgn
  membandingkan jumlah **TOTAL vs UNIK** (lama 41/39, 82/78, 41/39 -> baru 41/41, 82/82,
  41/41).
- **`information_schema.TRIGGERS` TIDAK terbatas pada DB yg sedang dipakai** - tanpa
  `WHERE TRIGGER_SCHEMA=...` hasilnya bercampur trigger DB proyek lain (sempat terbaca
  "4 baris per nama trigger" & bikin salah kesimpulan). Lihat aturan LINGKUP DATABASE di atas.
- **Menyalin struktur tabel legacy butuh `SET SESSION sql_mode=''`** - default `0000-00-00`
  di `IMODIFD`/`KMODIFD` ditolak mode ketat (`ERROR 1067`).
- **`U_fstokd` unik atas (SDIDSU, SDURUTAN)** - data uji harus beda `SDURUTAN` tiap baris.
- **Urutan bersih-bersih penting**: hapus baris `fstokd` DULU (trigger DELETE ikut jalan),
  BARU nol-kan kolom stok. Kebalikannya menghasilkan stok negatif & terlihat spt bug trigger.
- Saat menguji, pakai `SDPRDID=-1` supaya UPDATE ke **`official_nmw.ops_invoice_header`**
  (database NYATA, dirujuk absolut di dalam trigger) tidak mengenai baris apa pun, dan
  hindari item **5076/5720** supaya blok `IF` + `F_Tanggalsu` tidak ikut jalan.

**Jebakan tambahan dari tahap pisah kolom**:
- **`ADD COLUMN` lolos tapi `MODIFY COLUMN` gagal** di tabel yg mentok ukuran baris - sempat
  bikin saya salah menyimpulkan bahwa penambahan kolomnya yg bermasalah. Baca nomor baris di
  pesan error, jangan tebak.
- **Nolkan kolom stok SEBELUM** menanam nilai basi buatan di data uji, bukan sesudah (fungsi
  `bersih()` menolkan semua kolom `ISTOK%`, jadi nilai uji ikut terhapus).
- Membaca kolom yg **belum dibuat** -> `ERROR 1054`, bukan `NULL`. Periksa keberadaan kolom
  lewat `information_schema.COLUMNS`, jangan `SELECT` kolomnya.
- Di skrip uji, cacat pada objek LAMA itu **yg dibuktikan, bukan kegagalan** - jangan diberi
  label GAGAL (sempat menampilkan 3 "GAGAL LAMA" yg justru bukti perbaikannya benar).

**`CREATE TABLE ... LIKE` TIDAK menyalin FOREIGN KEY** - ini bikin DB uji terlalu longgar &
baru ketahuan saat uji di DB nyata. `fstokd` punya DUA FK yg tidak muncul di DB uji:
`FK_fstokd_u` (`SDIDSU` -> `fstoku.SUID`, ON DELETE CASCADE) dan `FK_fstokd_gudang`
(`SDGUDANG` -> `bgudang.GID`). Konsekuensinya: **gudang asal-asalan tidak bisa masuk `fstokd`**,
jadi `ISTOKXX` hanya bisa kena oleh gudang yg ADA di `bgudang` tapi BELUM di `F_KOLOMGUDANG()` -
yaitu persis regresi yg dulu terjadi. Kalau perlu FK di DB uji, salin skema tabelnya (bukan
`LIKE`) atau uji bagian itu langsung di DB nyata di dalam transaksi.

**JANGAN membangkitkan ulang generator SESUDAH migrasinya terpasang.** Generator ini MEMBACA
keadaan DB lalu menulis langkah "dari keadaan itu ke keadaan benar" - dijalankan dari DB yg
sudah termigrasi, hasilnya file yg TIDAK membuat kolom, TIDAK mengubah ROW_FORMAT & TIDAK
menolkan nilai basi, jadi **tidak bisa dipakai di server production yg belum dimigrasi**. Saya
sempat menimpa file aslinya persis karena ini. Sekarang ada **pengaman**: generator
`throw RuntimeException` kalau kolom `ISTOKXX` sudah ada di DB sumber. Cara membangkitkan ulang
dgn benar - pulihkan cadangan ke DB terpisah lalu `$GEN_DB` diarahkan ke situ:
`sh C:\hafiz\backup-db\bangun_prapisah.sh` lalu
`php artisan tinker --execute="\$GEN_DB='gen_prapisah'; require '<gen.php>';"`.
Skrip uji juga menerima `SRCLAMA=gen_prapisah` (struktur tabel yg ADA di salinan pra-migrasi
diambil dari situ - kalau `bitem` terbawa dari DB yg sudah termigrasi, `ADD COLUMN` bentrok).

**DIJALANKAN di `data_pos_nmw_2023` 2026-09-28** atas izin user. Cadangan di
`C:\hafiz\backup-db\` (`bitem_<ts>.sql` struktur+data, `rutin_trigger_<ts>.sql` semua routine,
`trigger_fstokd_<ts>.sql` ketiga trigger; mysqldump menulis nama trigger TANPA backtick - jangan
grep `` `fstokd_add` ``). Di folder itu juga ada `bangun_prapisah.sh` + `pasang_routine.php` utk
membangun ulang keadaan pra-migrasi dari cadangan. **Catatan saat memulihkan routine dari dump
mysqldump**: dump itu berisi trigger tabel LAIN juga - saring ke `^CREATE (FUNCTION|PROCEDURE)`
saja, kalau tidak `CREATE TRIGGER` utk tabel yg tidak ada memicu fatal & loop berhenti di tengah.
Dan **PHP di Windows tidak mengerti jalur gaya MSYS `/c/...`** - pakai `C:/...` utk bagian PHP
walaupun bagian shell-nya jalan dgn `/c/...`. Hasil sesudah dijalankan: `bitem` ROW_FORMAT **Dynamic**, 227 kolom,
5.580 baris utuh; 49 gudang -> **49 kolom berbeda** (tidak ada lagi yg berbagi); `ISTOKXX`=0;
`ISTOKOL`=0; `ISTOKPG` **tidak berubah** (2.870 item, jumlah 2.284.240,94). Cadangan `bitem`
dipulihkan ke DB terpisah lalu **54 kolom stok dibandingkan satu per satu**: hanya `ISTOKOL`
(106 baris) yg beda - tepat yg disengaja; kolom non-stok 0 beda; 0 baris hilang. Uji mutasi
NYATA dibungkus `DB::beginTransaction()`/`rollBack()`: 13 gudang masing-masing ke kolom sendiri
tepat 50, gudang 20 update/delete/`SDKELUAR` benar, gudang baru 9001 masuk `ISTOKXX` tanpa
mengotori `ISTOKPG`, dan sesudah rollback tidak ada sisa sama sekali. SQL_MODE trigger & rutin
baru **identik** dgn yg lama.

**Hasil uji lengkap** (`.uji.sh`, 12 bagian, DB sementara `uji_pisahstok_dias`): rutin & trigger
LAMA dipasang dulu utk membuktikan cacatnya ada -> masuk 50 ke gudang 20/10/42 memberi
`DE=100 BZ=100` (dobel) & mutasi gudang 42 hilang; setelah file SQL, 12 gudang diuji satu per
satu semuanya menulis ke kolomnya sendiri tepat 50; insert/update/delete di kolom baru
(`ISTOKDR`) & kolom yg tadinya dobel (`ISTOKDE`) benar; `SDKELUAR` benar (100-40=60); penampung
`ISTOKXX` menerima gudang 77 yg tidak terdaftar tanpa mengotori `ISTOKPG`; statement kembar
41/39 82/78 41/39 -> 50/50 100/100 50/50; potongan non-stok 10/16/8 **IDENTIK**;
`P_RESETSTOKPERBARANG` tidak lagi menimpa item lain. **SEMUA LULUS.**

Masih terbuka (SEMUA di dalam `data_pos_nmw_2023`): 9 kolom cadangan `ISTOK*31122016`
(semuanya 0, tidak dirujuk apa pun) dibiarkan; `P_RESETSTOK*` sekarang aman KALAU dipanggil
tapi **tetap jangan dijalankan** (hanya 31 dari 2.872 item yg cocok dgn netto mutasi).

## Salin Hak Akses (2026-09-28)

**Latar yg penting dipahami**: hak akses disetel **per user x per menu x 6 kewenangan**,
sedangkan ada **306 user aktif** dan **58 menu** - sekitar 17.000 keputusan centang kalau
disetel satu per satu. Saat fitur ini dibuat `lv_user_menu` baru berisi **1 baris**, dan
cutover 1 Oktober tinggal 2 hari. Tanpa ini, cutover praktis tidak mungkin.

Ditambahkan ke `Admin\UserAccess` (dua arah):
- **`salinDari($sumberId)`** - tarik hak akses user lain ke GRID. **TIDAK langsung menyimpan**;
  admin periksa dulu lalu klik Simpan. Grid ditimpa penuh, bukan digabung.
- **`terapkanKe()`** - tulis centangan di layar ke BANYAK user sekaligus. Ada
  `pilihSemuaHasil()` utk mencentang seluruh hasil pencarian - ini inti fiturnya.

**MENGGANTI, bukan menggabung**: hak akses user tujuan dihapus dulu (`whereIn(...)->delete()`)
baru diisi. Alasan: "salin" yg menggabung menghasilkan hak akses yg tidak bisa ditebak isinya
DAN tidak bisa dipakai MENCABUT akses. Satu transaksi supaya tidak ada user yg separuh jadi;
insert dipecah `array_chunk(500)`.

Yg ditulis adalah **isi GRID di layar**, bukan yg tersimpan di DB - supaya penyesuaian yg
belum sempat disimpan tidak diam-diam terbawa berbeda. User yg sedang dibuka SELALU
dikecualikan dari daftar tujuan (di query maupun saat menyimpan).

**RISIKO OPERASIONAL yg sudah terlihat saat uji**: "Pilih semua hasil" ikut menyapu user
produksi yg kebetulan cocok kata pencariannya (uji dgn kata "KASIR" ikut menarik
"KASIR PAMERAN" & "usertes"). Daftar centangnya terlihat di layar & jumlahnya ditampilkan di
konfirmasi, tapi tetap perlu diperiksa sebelum diterapkan. Tercatat di `lv_activity_log`
(`user_access_copy`) lengkap dgn daftar UKODE tujuan, jadi bisa ditelusuri kalau salah sapu.

### GOTCHA `x-lw-modal` WAJIB dibungkus `@if` (kena nyata 2026-09-28)

Modal dipasang tanpa `@if` -> **diklik tidak muncul apa-apa**, padahal state-nya sudah `true`
dan server SUDAH merender markup lengkap dgn `show d-block` + backdrop (dicek eksplisit).
Sebabnya: `components/lw-modal.blade.php` memakai **`wire:ignore.self`** pada div modalnya,
jadi Livewire tidak memperbarui **atribut elemen itu sendiri** - kelas `show d-block` tidak
pernah ikut terpasang saat elemennya sudah ada sejak awal.

**Selalu bungkus `@if ($showXxx)`** supaya elemennya DIBUAT BARU (sudah membawa kelasnya) -
pola yg dipakai semua modal lain di app ini (`user-manager`, `coa-manager`, dst). Gejalanya
menyesatkan krn uji server-side LULUS: `$c->html()` memang memuat modalnya.

**INI SOLUSI SEMENTARA.** Rencana sesudah cutover: konsep **Role** (`lv_role` + `lv_role_menu`,
user dikaitkan ke role, `lv_user_menu` tetap jadi pengecualian per user) supaya menu baru cukup
disetel sekali per role, bukan 306 kali. Sengaja TIDAK dibuat sekarang - memperkenalkan konsep
hak akses baru 2 hari sebelum semua aplikasi lama dimatikan menambah risiko di saat terburuk.
Kalau Role jadi dibuat, pengecualian aturan password utk kasir ikut jatuh otomatis dari situ.

Verifikasi (30 asersi LULUS, 3 user uji di transaksi + rollback): salin-dari memuat kewenangan
sumber dgn tepat (POS view+add+print, yg tidak dimiliki tetap kosong), menimpa hak akses lama
di grid, **TAPI DB belum berubah sampai Simpan ditekan**; setelah Simpan hak akses lama
terhapus; terapkan-ke-banyak -> tiap tujuan **TEPAT 2 baris** (tidak ada sisa hak akses lama),
isinya sama persis sumber, dan hak akses SUMBER tidak berubah; diri sendiri tidak pernah ikut
tercentang maupun muncul di daftar; tanpa tujuan tidak melakukan apa pun; tercatat di log
aktivitas. Rollback bersih - `lv_user_menu` kembali 1 baris.

## Password sementara + wajib ganti saat login pertama (2026-09-28)

Permintaan user: tombol **Buat Password** di reset password admin (2 angka + 4 huruf +
2 angka), password itu **sementara**, dan **user diharuskan menggantinya saat login pertama**.

### Yang dibuat
- **`UserManager::buatPassword()`** - generator 8 karakter. `random_int()` (CSPRNG), BUKAN
  `rand()`. **Karakter rancu dibuang**: angka `0`/`1`, huruf `i`/`l`/`o` - password ini
  didikte lewat telepon/WhatsApp. Ruang tebakan 8^2 x 23^4 x 8^2 = ~1,1 miliar; cukup krn
  umurnya hanya sampai login pertama.
- **`must_change = true`** saat admin reset password DAN saat admin mengetik password di form
  tambah/ubah user - dua-duanya password yang isinya diketahui admin.
- **`EnsurePasswordChanged`** (middleware, dipasang ke grup `web` lewat `bootstrap/app.php`) -
  mengalihkan ke `password.change` selama `must_change` menyala.
- **`Auth\ChangePassword`** + `layouts/auth.blade.php` (layout berdiri sendiri, di luar shell
  tab) - syarat password baru: min 8, ada huruf DAN angka, `uncompromised()`, dan wajib beda
  dari password lama.

### Yang HARUS dilewati middleware (kalau tidak user terkunci berputar)
`password.change`, `logout`, dan **`livewire/*`** - halaman ganti password itu sendiri memakai
Livewire, memblokir endpointnya membuat formnya tidak bisa disubmit.

### GOTCHA: `confirmed` vs properti camelCase (kena nyata)
Aturan `confirmed` mencari field `passwordBaru_confirmation` (snake), sedangkan properti
Livewire di sini `passwordBaruConfirmation` - akibatnya validasi **SELALU gagal walau isinya
sama**, dan gejalanya menyesatkan: semua kasus penolakan lulus, justru kasus BERHASIL yang
gagal. Dipakai **`same:passwordBaruConfirmation`**.

### Keputusan: ganti password sendiri TIDAK menyalin MD5 ke `auser.UPASSWORD`
Reset oleh admin punya checkbox utk itu (supaya login CI3 ikut jalan), tapi di layar ganti
password sendiri sengaja TIDAK: MD5 tanpa garam bisa dibongkar cepat, jadi menyalinnya justru
melemahkan password kuat yang baru dibuat. Akibat yang diterima: sampai aplikasi lama
dihentikan, password CI3 user tetap yang lama.

### Aturan password kuat + throttle login (2026-09-28)

Aturan password yang dibuat SENDIRI user ada di **`config/acl.php` -> `password`**
(`min` = 10, `kata_terlarang`). **SENGAJA TIDAK mewajibkan simbol/huruf besar** - aturan
komposisi menghasilkan pola tertebak (`Password1!`, `P@ssw0rd`) yg justru dicoba pertama;
NIST SP 800-63B menyarankan menghindarinya. Panjang jauh lebih menentukan: 10 karakter
huruf+angka ~1.300x lebih luas drpd 8. Ditambah `ChangePassword::tolakKataJelas()` yg menolak
password memuat **username sendiri** atau kata jelas (`nmw`, `petogogan`, `kasir`, ...) -
jauh lebih berguna drpd simbol, krn itu yg biasanya dipilih orang.

**Throttle login** di `LoginController` (5 percobaan, blokir 300 detik). Kuncinya
**`username|IP`**, SENGAJA bukan salah satunya saja: kunci IP-saja membuat satu kantor
ber-IP sama saling mengunci; kunci username-saja membuat penyerang bisa mengunci akun orang
lain dari luar. `RateLimiter::clear()` saat login berhasil.

**JEBAKAN `uncompromised()` - dua-duanya kena & sudah ditangani**:
1. **Gagal DIAM-DIAM (fail-open)**: `NotPwnedVerifier::search()` menelan exception & balik
   body kosong -> password dianggap aman & LOLOS tanpa pesan. Kalau server produksi tidak bisa
   menjangkau `api.pwnedpasswords.com`, aturan ini **praktis mati dan tidak ada yg tahu**.
   Wajib dipastikan saat deploy.
2. **Timeout bawaan 30 DETIK** -> tombol Simpan menggantung selama itu. Dipendekkan jadi 3
   lewat binding di `AppServiceProvider`. **`ValidationServiceProvider` adalah DEFERRED
   provider** - ia register saat kontrak pertama di-resolve dan MENIMPA binding kita, baik
   dari `register()` MAUPUN `boot()` (terbukti: timeout tetap 30). Solusinya
   `$this->app->register(ValidationServiceProvider::class)` dulu, baru `bind()`.

Verifikasi (21 asersi LULUS): 8 karakter ditolak (min 10), tanpa angka/tanpa huruf ditolak,
memuat username sendiri ditolak, memuat `nmw`/`petogogan`/`password`/`KASIR` (tidak peka huruf
besar) ditolak & pesannya menyebut kata yg salah; **password 15 karakter huruf+angka TANPA
simbol DITERIMA**; throttle memblokir tepat di percobaan ke-6, penghitung per username+IP, user
lain dari IP sama tidak ikut terblokir; timeout verifier terbukti 3 detik.

Verifikasi (26 asersi LULUS, user uji dibuat di transaksi + rollback): 300 password hasil
generator semuanya 8 karakter, berpola benar, **tanpa karakter rancu**, 300/300 unik; reset
admin -> hash tersimpan (bukan teks polos), `must_change` TRUE, MD5 tidak ditulis saat checkbox
mati; middleware mengalihkan `/` ke ganti-password TAPI melewatkan halaman itu sendiri, logout,
& `livewire/*`; layar ganti password menolak password lama salah / tanpa angka / tanpa huruf /
< 8 karakter / ulangi tidak sama / sama dgn password lama - dan di setiap penolakan
`must_change` TETAP menyala; password kuat diterima -> `must_change` mati, hash berubah,
**login dgn password baru berhasil & password sementara ditolak**; rollback bersih, 0 user
produksi terpengaruh.

## ATURAN: filter Cabang di SEMUA laporan default ke cabang aktif user (2026-09-28)

Permintaan user, berlaku utk laporan yg sudah ada MAUPUN yg dibuat nanti:
**filter Cabang selalu default ke CABANG AKTIF user**, bukan "semua cabang".

```php
// di mount() tiap komponen filter laporan
$this->cabang = (int) (auth()->user()->UCABANG ?? 0) ?: null;
```

`User::UCABANG` adalah **accessor**, bukan kolom mentah - sudah menghormati fitur "ganti
cabang aktif" lewat session (`active_cabang_{UID}`), jadi cukup dibaca apa adanya.
**JANGAN dikondisikan lagi ke `branchIds()`** seperti pola lama
(`$allowed === [] ? UCABANG : null`) yg justru default ke "semua cabang" untuk user
bercabang banyak - kebalikan dari yg diminta.

Sudah diterapkan: `Reports\IpTindakanProduk`, `Reports\PenjualanPerBarang`.

Verifikasi (14 asersi LULUS): user 1 bercabang tunggal & user 7 bercabang 48 SAMA2 default ke
cabang aktifnya; terbukti tidak lagi "semua cabang"; **mengikuti switch cabang aktif** (user 7
di-switch ke 13 Cibubur -> filter ikut 13); laporan hasilnya benar terfilter (1 blok cabang
"Petogogan", nama cabang ikut di subtitle).

### Penyaringan cabang di laporan (user 2026-09-28)

Aturan user: **"jika user hanya akses 3 cabang, maka jika dia pilih semua, yang tampil 3
cabang itu"**. Penegaknya **`ReportController::cabangLaporan()`** - dipakai SEMUA laporan:

- "Semua cabang" -> seluruh cabang yg BOLEH DILIHAT user, bukan seluruh perusahaan.
- Pilih satu cabang -> **DIIRISKAN** dgn daftar yg boleh. Irisan kosong -> `[0]` (GID 0 tidak
  ada) sehingga hasilnya nihil. **Mengembalikan `[]` di situ justru MEMBUKA semua cabang** -
  jebakan yg sama pernah kena di `KartuStok::gudangIds()`.
- Penyaringan WAJIB di controller, bukan cuma dropdown: parameter `cabang` datang lewat URL.

**Super user DIKECUALIKAN**, lewat `User::visibleBranchIds()` (`[]` = tanpa batas). Konsisten
dgn `Acl` yg melewatkan super user dari semua pemeriksaan. Praktis wajib: UID 1
(Administrator) `UCABANGPILIH`-nya justru cuma `1`, jadi tanpa pengecualian ini admin terkunci
ke Petogogan dan laporan lintas cabang mati. Definisi super user dipindah jadi
**`User::isSuperUser()`** supaya ada satu sumber, tidak lagi hanya di dalam `Acl`.

**BUG YG IKUT KETEMU & DIPERBAIKI**: `dataPenjualanPerBarang()` SUDAH menyaring
`branchIds()` sejak awal TAPI tanpa pengecualian super user - jadi laporan IP Per Barang
selama ini **sudah terkunci ke Petogogan untuk admin**. Sekarang ikut `cabangLaporan()`.

Verifikasi (21 asersi LULUS, user uji 3 cabang dibuat di transaksi + rollback): user biasa
pilih "Semua" -> tepat 3 cabang (Ciputat, Depok, Petogogan), bukan seluruh perusahaan; pilih
cabang sendiri -> tampil; **pilih cabang di luar haknya (Kalimalang) -> hasil KOSONG & nilai 0,
bukan malah semua cabang**; dropdown kedua laporan ikut 3 cabang; laporan IP Per Barang juga
terbatas 3 cabang. Super user: `visibleBranchIds()` `[]`, "Semua cabang" -> 18 cabang (tidak
terkunci), dropdown penuh 31 cabang, boleh pilih cabang lain. Rollback bersih.

## Laporan IP Tindakan/Produk Per Bulan (2026-09-27)

Port dari CI3 - **sumbernya ADA & lengkap**: `dias-online-app/application/views/modul/laporan/
laporan-ip-tindakan-produk-perbulan.php` (+ versi `xls/`) dan
`application/sql/2026-09-02_laporan-ip-tindakan-produk-perbulan.sql` (yg memuat spesifikasinya
di komentar). Baca dua file itu dulu kalau perlu menyentuh laporan ini lagi.

`ReportController::ipTindakanProduk()` / `...Excel()` + `reports/ip-tindakan-produk.blade.php`
& `-xls.blade.php`; tab filter `App\Livewire\Reports\IpTindakanProduk` +
`livewire/reports/ip-tindakan-produk.blade.php`. Route `reports/ip-tindakan-produk[/excel]`,
menu `laporan.ip-tindakan-produk` (sort 83, di bawah `laporan.penjualan`), registry Workspace
sudah didaftarkan. Pola tombol PDF/Excel SAMA `PenjualanPerBarang` (dispatch
`report-pdf-ready` / `report-excel-download`).

**Sumber `SUSUMBER IN ('IP','AL')`** (POS + Alkes Depo), `SUSTATUS <> 9`.
Grup **Cabang > Bulan > Tindakan/Produk**; kolom Tindakan/Produk | Qty Transaksi | Nilai |
Pasien.

### TIGA aturan hitung yg TIDAK boleh disederhanakan (disalin persis dari CI3)
1. **Qty mengecualikan baris kedatangan**:
   `SUM(CASE WHEN IFNULL(SDKEDATANGAN,0)=0 THEN SDKELUAR ELSE 0 END)`. Baris ber-`SDKEDATANGAN`
   TETAP ikut kolom Nilai tapi TIDAK dihitung qty-nya. Nyata: 391 baris di Jun-Sep 2026, dan
   terbukti membuat Qty total 10.522,77 < `SUM(SDKELUAR)` polos 10.990,27.
2. **Kolom Pasien per baris** = `COUNT(DISTINCT kontak)` **hanya pada baris yg ADA HARGANYA**
   (`SDKELUAR*(SDHARGA-SDDISKON) > 0`) - tindakan gratis/bonus tidak menghitung pasien.
3. **Total pasien per bulan dihitung QUERY TERPISAH**, bukan menjumlahkan kolom Pasien:
   `COUNT(DISTINCT CONCAT(kontak,'#',tanggal))` - **1 pasien per hari dihitung 1x**. Karena itu
   total bulan hampir selalu LEBIH KECIL dari jumlah kolom di atasnya, dan **itu MEMANG BENAR**
   (jangan "diperbaiki"). Baris "Total Cabang" & "Grand Total" SENGAJA mengosongkan kolom
   Pasien (CI3 juga) - menjumlahkannya lintas bulan/cabang akan menghitung pasien yg sama 2x.

**BEDA SENGAJA dari CI3 (1)**: label bulan di-Indonesia-kan (`->locale('id')` -> "Juni 2026");
CI3 pakai `DATE_FORMAT(...,'%M %Y')` yg selalu Inggris ("June 2026").

Versi Excel SENGAJA meratakan grup jadi kolom "Cabang" & "Periode" yg diulang tiap baris -
supaya bisa di-pivot, beda dari PDF yg bertingkat.

Verifikasi (31 asersi LULUS, periode Jun-Sep 2026 semua cabang): **hasil dicocokkan
baris-per-baris ke QUERY CI3 ASLI yg dijalankan berdampingan** - **2.059 baris, NOL selisih**
di Qty, Nilai, maupun Pasien per baris; total pasien per cabang-bulan juga **nol selisih** di
36 grup; terbukti ada bulan yg total pasiennya lebih kecil dari jumlah kolom (aturan 3
bekerja); aturan 1 terbukti dari data; 9 potongan teks cetakan; Excel ber-Content-Type &
nama file benar + kolom Cabang/Periode; tanggal terbalik ditolak; default rentang bulan
berjalan; kedua tombol men-dispatch event yg benar; registry & menu terdaftar.

## Pengajuan Dana: cetakan "Petty Cash" (2026-09-27)

`PengajuanDanaPrintController` + `reports/pengajuan-dana-print.blade.php`, route
`finance/pengajuan-dana/{id}/print` (ACL `finance/pengajuan-dana` ability `print`). Dari contoh
user `Pengajuan dana PG-PDN26090001.pdf`. Tabel `ctransaksipu` + `ctransaksipd` (prefix CU/CD).
Tombol Cetak di daftar + form; setelah simpan: toast + `confirm-print`.

**Judul cetakannya "Petty Cash"**, bukan "Pengajuan Dana" - nama modul & nama dokumen beda.

**Cetakan paling kompleks sejauh ini - 5 blok**: (1) kop **BERULANG tiap halaman**
(`<htmlpageheader>`, bukan konten biasa - contoh 2 halaman menampilkannya di KEDUANYA);
(2) tabel berbingkai dgn judul kolom **DWIBAHASA bertumpuk** (Keterangan/Description,
COA/Account No., Jumlah/Amount) + satu kolom tanggal **tanpa judul**; (3) baris Terbilang +
total menyatu di dalam bingkai; (4) kotak ttd 3 sel Dibuat/Diperiksa/Disetujui masing2 dgn
"Tgl/Date"; (5) kotak rekap per COA (KODE|KETERANGAN|DEBIT|KREDIT) + ttd
"Accounting Manager/SPV" & "Accounting".

**Baris urutan 1 = akun sumber dana DIKECUALIKAN** (sesuai `PengajuanDanaWriter::lines()` dan
sesuai contoh: rekapnya hanya 3 COA biaya, tanpa akun sumber). Yg dicetak urutan > 1:
`CDCATATAN` (Keterangan), `CDNOCOA` -> `bcoa.CNOCOA` (COA), `CDDEBIT` (Jumlah).
Rekap DEBIT/KREDIT di-`SUM` atas baris yg sama - bukan dipatok 0 - supaya tetap jujur kalau
suatu saat ada baris kredit di luar akun sumber.

**Perlu konfirmasi user (2)**:
1. **Kolom tanggal per baris** diisi `CUTANGGAL`. Di contoh ke-43 barisnya bernilai SAMA dgn
   tanggal dokumen (10/09/2026) walau uraiannya menyebut tanggal belanja berbeda-beda, jadi
   jelas BUKAN tanggal belanja; kandidat lain (tanggal KK asal) tidak terbedakan dari contoh.
2. **Alamat cabang** dari `bgudang` (`'NMW ' + GNAMA`, lalu `GALAMAT2`). Contoh menulis
   "Jl Petogogan 11 No 29 / Keb Baru - Jak Sel" sedangkan `GALAMAT2` gudang 1 berbunyi
   "Jl. Petogogan 2 No. 29, Kebayoran Baru Jakarta Selatan 12160" - beda nomor jalan & format.
   Dipakai data master supaya cabang lain ikut benar.

**CATATAN**: `ctransaksipu` `CUSUMBER='PDN'` **KOSONG (0 baris)** - modul belum pernah dipakai,
tidak ada dokumen nyata utk dicetak.

Verifikasi (44 asersi LULUS, dokumen uji di transaksi + rollback dgn angka & COA disalin dari
contoh): 24 potongan teks contoh termasuk judul kolom dwibahasa & `htmlpageheader`; **baris
akun sumber terbukti TIDAK tercetak** (hanya 6 baris biaya dirender); Total 2.968.617,00 sama
contoh; **terbilang identik kalimat contoh**; **rekap 3 COA cocok contoh sampai rupiah**
(1.696.323,00 / 888.019,00 / 384.275,00) DAN nama akunnya cocok ("Biaya Kebutuhan kantor",
"Biaya ATK, FC, Materai, Cetakan", "Biaya Transport & Akomodasi") - konfirmasi kuat pemetaan
COA benar; jumlah rekap = Total; banner pembatalan; **rollback bersih, 0 PDN tertinggal**.

**Judul kolom Inggris DI BAWAH yg Indonesia pakai `<br>`, BUKAN `display:block`** (diperbaiki
2026-09-30 atas laporan user). Semula CSS-nya `table.items th small { display: block }` - **mpdf
TIDAK menghormati `display:block` pada elemen inline** spt `<small>`, jadi teks Inggrisnya
menempel sebaris. `display:block` malah DIHAPUS dari CSS supaya jaraknya tidak dobel kalau
suatu hari mpdf mendukungnya. **Berlaku umum utk SEMUA cetakan mpdf**: mau menaruh sesuatu di
baris baru di dalam `<th>`/`<td>`, pakai `<br>` - jangan andalkan `display`.

## Laporan > Persediaan > Daftar Stok Barang (2026-09-30)

Judul MENU "Daftar Stok Barang", judul CETAKAN **"Laporan Real Stok Barang"** (beda, ikut
contoh user `Daftar Stok Barang BIZPARK.pdf`). `DaftarStokBarang` (Livewire filter) +
`ReportController::daftarStokBarang()`/`...Excel()` + `reports/daftar-stok-barang.blade.php`
& `-xls`. Menu `laporan.stok-barang` di bawah grup BARU `laporan.persediaan`.

**Port dari VB6 `C:\code\DIAS_MYSQL_2026\DIAS.vbp` menu 318** — jejaknya, biar tidak perlu
ditelusuri ulang:
- `Module\a\Module\aMod_Menu.bas` **baris 257**: `Case 318, ...` → `zfFrmFilterLaporanStok`,
  dan khusus baris itu `txtTanggal2`/`Cal(1)` disembunyikan → **tanggalnya TUNGGAL (cut-off
  "s/d"), bukan rentang**.
- `Module\f\Form\zfFrmFilterLaporanStok.frm` **baris 1392** (SQL) & **1243** (filter `pFlt`)
  & **798-800** (parameter laporan).

**Rumus stoknya**: `SUM(SDMASUK - IF(SDDARIPAKET<>0 AND SDKEDATANGAN=0, 0, SDKELUAR))`. `IF`
itu BUKAN hiasan — baris dari PAKET yang bukan kedatangan keluarnya **diabaikan** (komponen
paket sudah terhitung lewat baris paketnya; kalau ikut dikurangi, stok dobel). **Jangan
disederhanakan** jadi `SDMASUK - SDKELUAR`. Semua join `INNER` (disengaja, ikut VB6) dan
`GROUP BY`-nya memuat kolom harga — satu item bisa jadi >1 baris kalau harganya pernah beda;
itu perilaku cetakan lama, jangan "dirapikan".

**Aturan gudang 1 & 6**: memilih cabang 1 (Petogogan) ATAU 6 (Online) → `SDGUDANG IN (1,6)`.
Stok Online memang dihitung menyatu dengan Petogogan.

**Filter yang menu 318 TIDAK pakai — SENGAJA tidak dibuat**: Nomor Transaksi, Kontak, Id Item,
Jenis Transaksi, Kelompok 2020, Jumlah Data, COA 2026 (hanya menu 850), **"Hitung Saldo Awal"**
& **"Posting Saja"**. Dua terakhir **terceklis di tangkapan layar user** tapi cabang `Case 318`
tidak pernah membacanya (Hitung Saldo Awal cuma menu 321/322/450/417/448; Posting Saja cuma
baris 1191). Form VB6-nya dipakai bersama belasan menu, jadi banyak isian TAMPIL tapi mati.

**BEDA DISENGAJA dari VB6 (2)**: (1) **COA 2021** — VB6 mengirim `ICOA2021 = ListIndex - 1`
(posisi baris combo), benar HANYA kalau `CTTIPEID` kontigu, dan **tidak**: 26 baris ber-ID
0..99, jadi entri terakhir di VB6 mengirim angka salah. Di sini dipakai `CTTIPEID` ASLI.
(2) **Item** — VB6 `UPPER(IKODE) = '<ketikan>'` sama persis; di sini `<x-search-select>` →
filter `bitem.IID`.

Baris TOTAL menjumlah kolom apa adanya **walau satuannya bercampur** (Pcs, Gr, Kg, Liter) —
itu memang perilaku cetakan lama, bukan kekeliruan.

Verifikasi (LULUS): **query VB6 asli dijalankan BERDAMPINGAN** dan dicocokkan **baris per
baris** (`gudang|kode|nama|stok`), bukan cuma totalnya — identik untuk skenario tangkapan
layar user (Bizpark, 3 ceklis), untuk gudang 1+6 (314 baris), dan untuk filter Jenis Item.
Query ini juga **diuji ke DB PRODUKSI** dan cocok dengan lampiran user sampai satuan:
`NMW PAPAYA CLEANSER 100 GR` = 191,00 dan `BASE RHEIN DAY LIGHT GEL` = −282.073,00; item yang
berbeda hanyalah yang stoknya bergerak setelah PDF-nya dicetak jam 12:08. Plus: 10 potongan
layout contoh; tiap ceklis terbukti mengubah hasil; cut-off tanggal bekerja; Excel sebaris
dengan PDF & kode dipaksa teks; menu/registry/route terdaftar; 9 filter yang sengaja
ditiadakan terbukti tidak ada.

## Kas Masuk & Kas Keluar: cetakan bukti (2026-09-30)

`KasBankPrintController` + `reports/kas-bank-print.blade.php`, route
`finance/kas-{masuk,keluar}/{id}/print` (ACL `finance/kas-masuk`|`finance/kas-keluar` ability
`print`). Tombol Cetak di daftar + form. Tabel `ctransaksiu`/`ctransaksid`, lihat docblock
`KasBankWriter`.

**Layout MENIRU contoh cetakan sistem lama** yg dikirim user (`Bukti Kas Keluar
BZ-KK26090002.pdf`) - **SENGAJA jauh lebih polos drpd `pengajuan-dana-print`**: tanpa kop
nama/alamat cabang, tanpa `htmlpageheader`/`htmlpagefooter`, tanpa bingkai tabel (garis
horizontal saja: atas+bawah judul kolom, atas baris Jumlah), tanpa rekap per COA, tanpa judul
kolom dwibahasa. Kolomnya cuma **Kode Akun | Nama | Debit | Kredit**. **JANGAN "diseragamkan"
dgn cetakan Petty Cash** - user minta persis contohnya.
*(Versi pertama saya justru menyeragamkannya ke rumah gaya Petty Cash lalu harus ditulis ulang -
contoh cetakan lama TIDAK selalu satu gaya antar modul, jangan diasumsikan.)*

**SATU blade & SATU query utk kedua arah** - permintaan user: *"untuk kas masuk di samakan saja
dengan kas keluar, yang beda debit kreditnya dan judul"*. Jadi **TIDAK ADA percabangan layout**:
yg beda cuma `$judul` ("Bukti Kas Masuk"/"Bukti Kas Keluar") dan angkanya jatuh di kolom Debit
atau Kredit - dan itu terjadi **sendiri** karena `CDDEBIT`/`CDKREDIT` dicetak APA ADANYA dari DB
(writer-lah yg menaruh angka di sisi benar: MASUK rekening=debit, KELUAR rekening=kredit).
**JANGAN tambahkan `if ($masuk)` utk menukar kolom** - itu justru merusaknya. Label ttd pun
TETAP "Dikeluarkan/Dicatat/Disetujui" di kedua arah, sesuai contoh.

**Baris rekening kas IKUT DICETAK & ditaruh PALING BAWAH.** Di DB ia `CDURUTAN=1`, tapi di
contoh baris "Kas Kecil" ada di baris TERAKHIR - karena itu `ORDER BY CASE WHEN CDURUTAN=1 THEN
1 ELSE 0 END, CDURUTAN`, bukan sekadar `CDURUTAN`. Beda dari `PengajuanDanaPrintController` yg
justru MENGECUALIKAN baris urutan 1. Nol ditulis `0,00`, **tidak dikosongkan** (ikut contoh).

**Pemetaan field ke contoh**: "Uraian : KAS KELUAR" -> `CUURAIAN`; nama di bawah tanggal
("Dede Suryaman") -> **`CUKONTAK` -> `bkontak.KNAMA`**, BUKAN nama user (diverifikasi: "DEDE
SURYAMAN" ada sbg kontak `KID=151457`, di `auser` nama itu tidak ada sama sekali);
"Jakarta, 25-September-2026" -> `bgudang.GKOTA` + `CUTANGGAL` `isoFormat('DD-MMMM-YYYY')`
locale id. Nilai isian (Uraian, tanggal, nama kontak) pakai font **sans**, sisanya serif -
ditiru apa adanya dari contoh.

**ASUMSI perlu konfirmasi user**: contoh dari cabang **Bizpark** yg `GKOTA`-nya **NULL**, tapi
cetakannya berbunyi "Jakarta" -> sistem lama kemungkinan MEMATOK "Jakarta". Dipakai `GKOTA`
dulu, **fallback "Jakarta"** - sama persis utk cabang di contoh, tapi tidak salah utk
Surabaya/Bali begitu `GKOTA` mereka diisi.

**Bank Masuk/Keluar BELUM** - dokumen Bank py field tambahan (`CUTIPE` Tunai/Giro/Transfer,
`CUBANK`, `CUNOGIRO`, `CUTGLTEMPO`) yg harus tampil, jadi tidak bisa sekadar ikut layout ini.
`KasBank{Form,List}Base::printRoute()` mengembalikan `null` utk keduanya -> tombol Cetak
otomatis disembunyikan.

Verifikasi (LULUS; 2 dokumen NYATA di DB + pasangan uji KK/KM di transaksi lalu rollback): data
view **ditangkap lewat `View::composer`** (bukan query tiruan) jadi yg diuji benar-benar yg
dikirim controller; urutan baris terbukti `2,3,4,1` (rekening kas terakhir); **berimbang
debit=kredit** di kedua arah; **rekening di KREDIT utk KK & DEBIT utk KM**, baris biaya
sebaliknya; 12 potongan layout contoh ada & 6 penanda layout Petty Cash terbukti TIDAK ada;
**terbilang 2.534.719 identik kalimat di contoh user**; **kerangka HTML KK vs KM identik
byte-per-byte** setelah judul & nomor dinormalkan - diff mentahnya menyisakan PERSIS 1 baris
(nomor dokumen), bukti kuat klaim "cuma beda judul & debit/kredit"; contoh PDF direproduksi
dgn 29 baris & total **2.534.719 sama persis** dgn lampiran user; rollback bersih, 0 dokumen
uji tertinggal.

## JOP: cetakan "Job Order Produksi" (2026-09-27)

`JopPrintController` + `reports/jop-print.blade.php`, route `pabrik/jop/{id}/print` (ACL
`pabrik/jop` ability `print`). Dari contoh user `Job Order Produksi RP-JOP26090001.pdf`.
Tombol Cetak di `JopList` + `JopForm`; setelah simpan/ubah: toast + `confirm-print`.

Tabel **`fproduksiu` + `fproduksid` (prefix PU / PD) dgn `PUSUMBER='JOP'`** - BUKAN
`fstoku`/`fstokd` spt Produksi (PRO). JOP = RENCANA, PRO = eksekusinya.

**Tabel 5 kolom: No | Item | Nama Item | Qty Masuk | Qty Keluar** (`PDMASUK`/`PDKELUAR`) + dua
Total Qty. Mirip cetakan Produksi TAPI 3 beda: label kolom "Qty Masuk/Keluar" (bukan "Qty
Produk Jadi/Bahan Baku"), **TIDAK ADA kolom Satuan**, dan tanda tangannya DUA & **BERNAMA**.

**Tanda tangan bernama - satu dari data, satu dari config**:
- "Di Buat Oleh" = `bkontak.KNAMA` dari `PUKONTAK` (di contoh "( Andi Andrian )", sama dgn
  isi "Tujuan :").
- **"Di Setujui Oleh" = `config('dokumen_print.pt.<NPID>.penyetuju_jop')`**. Namanya TIDAK ADA
  di DB: `fproduksiu` **tidak punya kolom approver sama sekali** (`PUKARYAWAN` NULL di kedua
  JOP lokal) dan "Dra. TRI WAHYUNI, Apt." tidak ada persis di `bkontak` (terdekat
  "` TRI WAHYUNI.`" KID 21788, tanpa gelar) - hardcode di report lama, pola SAMA apoteker/SIPA.
  NPID lewat `PUCABANG` -> `bgudang.GPT`; **gudang 35 "RII Produksi" -> NPID 8
  "PT. Royal Igyolini Indonesia"**, jadi entri config ditaruh di NPID 8.
  PT lain -> kurung KOSONG, tidak memakai nama PT lain (diuji eksplisit).

**FLAG**: penyetuju di-scope per PT karena hanya ada SATU contoh (dari RII Produksi). Kalau
ternyata orang yg sama menyetujui JOP semua PT, entri config-nya perlu disalin/dijadikan
global - tanyakan ke user sebelum mengubah.

**CATATAN**: dokumen contoh `RP-JOP26090001` TIDAK ADA di DB lokal - hanya 2 JOP
(`PG-JOP26090001/2`, Petogogan). Sama spt cetakan Produksi yg contohnya (`RP-PRO...`) juga
tidak ada: **dokumen ber-prefix RP (RII Produksi) tampaknya belum ikut terimpor** ke salinan
lokal ini.

Verifikasi (32 asersi LULUS, `PG-JOP26090002`): 15 potongan teks contoh; terbukti TIDAK ada
kolom Satuan & tidak memakai label cetakan Produksi; dua total cocok `SUM(PDMASUK)`/
`SUM(PDKELUAR)` (100,00 / 306,00); "Di Buat Oleh" berisi nama kontak ("Arnis"); rantai config
terbukti (NPID 8 berisi nama sesuai contoh, gudang 35 -> NPID 8) dan **PT tanpa config ->
penyetuju kosong, nama PT lain TIDAK bocor**; SEMUA 2 JOP tercetak; controller JOP menolak id
non-JOP (404); tombol cetak di daftar & form.

## PBC / PKB / Produksi: cetakan (2026-09-27)

Tiga cetakan sekaligus dari contoh user. Tombol Cetak di daftar + form masing2; setelah
simpan: toast + `confirm-print` (menggantikan `session()->flash('status')`).

**JEBAKAN DOCBLOCK - KENA DUA KALI, JANGAN KETIGA**: menulis sepasang prefix kolom bergaya
`(PKBU*/PKBD*)` atau `(CU*/CD*)` di komentar **menutup docblock lebih awal** krn mengandung
`*/` -> `ParseError: Unmatched ')'` yg menunjuk ke BARIS DOCBLOCK, bukan ke kode. Blade-nya
sendiri kompilasi bersih sehingga sempat salah dicurigai dua-duanya.
**Selalu tulis `PKBU / PKBD`, `CU / CD` - jangan pernah `X*/Y*`.**
Cek cepat seluruh proyek: `for f in $(find app config routes database -name '*.php'); do
php -l "$f"; done` - dipakai setelah kejadian kedua, hasilnya 0 file bermasalah.

### PBC - `PbcPrintController` + `reports/pbc-print.blade.php`, route `purchase/pbc/{id}/print`
Judulnya **"Penerimaan Barang"**, SAMA dgn cetakan PB - bukan "...Cabang". Sekeluarga
`pb-print` tapi **TANPA kolom "No PO"** (PBC menerima dari SJ antar cabang), jadi 5 kolom:
No | Item | Nama Item | Qty Masuk | Satuan. Qty dari `SDMASUK`.
**"No Invoice :" SENGAJA DIKOSONGKAN**: di cetakan PB kolom itu diisi `SUNOREF`, tapi utk PBC
`SUNOREF` isinya **nomor Permintaan Barang (RS)** - diverifikasi **1.838 dari 1.838** PBC
berpola `%-RS%`, nol yg kosong. Contoh user pun menampilkannya kosong.

### PKB - `PkbPrintController` + `reports/pkb-print.blade.php`, route `purchase/pkb/{id}/print`
Tabel `fperintahkirimbarangu` + `...d`, BUKAN `fstoku`. Strukturnya beda sendiri: kop
"NMW CLINIC" (`bnamapt.NPNAMACLINIC` via `PKBUGUDANG` -> `bgudang.GPT`), blok kanan No PKB +
Tanggal, lalu sub-judul **"Data Permintaan :"**.
**Empat field diambil dari PR, BUKAN dari PKB**: "No Transaksi"/"Tanggal" =
`PBUNOTRANSAKSI`/`PBUTANGGAL`; "No Ref" = `PBUNOREF`; **"Gudang :" = `PBUGUDANG`** - di contoh
tercetak "Bali" padahal `PKBUGUDANG` = 20 "Depo"; **"Tipe" = `PBUTIPEPERMINTAAN` ->
`blain.LNAMA`** (`PKBUTIPEPERMINTAAN` NULL di dokumen contoh).
**Dua beda SENGAJA**: (1) Tipe dicetak utuh **"Depo Farmasi"**, contoh menyingkat "Farmasi" -
memangkas awalan "Depo " akan merusak nilai lain di `blain` ("Depo Ciledug"->"Ciledug",
"Depo Research"->"Research"); (2) **nilai "Total Qty" DICETAK** - cetakan lama menampilkan
labelnya TANPA angka (contoh: 1 baris qty 30,00, di sebelah "Total Qty" kosong), jelas cacat.
**"Diperintah oleh" dibiarkan KOSONG** ikut contoh - sumbernya tidak ketemu: `PKBUKARYAWAN`
DAN `PKBUKONTAK` dua2nya berisi "Indah Nurhayati" sedangkan cetakan contoh kosong
(`PKBUKONTAK` terisi di 1.103/1.103 PKB). Footer PKB **tanpa awalan "Halaman"**, beda dari
cetakan SJ/PB/KMB/TMB - direplikasi apa adanya.

### Produksi - `ProduksiPrintController` + `reports/produksi-print.blade.php`, route `pabrik/produksi/{id}/print`
Ciri khas: **DUA kolom qty** - `Qty Produk Jadi` (`SDMASUK`) & `Qty Bahan Baku` (`SDKELUAR`) -
dgn **dua Total Qty berdampingan**. Tanda tangan **hanya SATU: "Bag Produksi"** (dokumen
internal, tidak ada serah terima). "Tujuan :" = `bkontak.KNAMA` (nama ORANG, "Andi Andrian").
**Dokumen contoh `RP-PRO26090001` TIDAK ADA di `data_pos_nmw_2023`** (PRO September yg ada cuma
`PG-PRO26090001/2`). Diverifikasi ke PRO nyata berbentuk sama: `RP-PRO26060028` (RII Produksi,
Andi Andrian, 463 produk jadi / 1.985,20 bahan baku).

Verifikasi (61 asersi LULUS): PBC - 16 potongan teks contoh, Total Qty 600,00 & 9 baris sama
contoh, terbukti tidak ada kolom No PO, dan `SUNOREF` (MP-RS26080015) terbukti TIDAK tercetak;
PKB - 19 potongan teks, Gudang terbukti dari PR ("Bali") bukan PKB ("Depo"), Total Qty 30
tercetak, "Diperintah oleh" terbukti tidak menebak nama, footer tanpa "Halaman"; Produksi -
13 potongan teks, dua total cocok `SUM(SDMASUK)`/`SUM(SDKELUAR)`, terbukti hanya satu ttd, ada
baris produk jadi DAN bahan baku. **SEMUA 1.838 PBC + 1.103 PKB + 41 PRO tercetak tanpa
error.** Tombol cetak diuji per modul dgn user yg cabangnya punya datanya (PBC user 1
Petogogan 215 baris; PKB user 137 Depo 388 baris; PRO user 1 Petogogan 2 baris - **tidak ada
user ber-`UCABANG`=35 "RII Produksi"** padahal 39 dari 41 PRO ada di sana).

## SJ: cetakan "Surat Jalan" (2026-09-27)

`SjPrintController` + `reports/sj-print.blade.php`, route `sales/sj/{id}/print` (ACL
`sales/sj` ability `print`). Layout dari contoh cetakan lama user
(`Surat Jalan DE-SJ26090001.pdf` = `fstoku` SUID 1288618). Tombol Cetak di `SjList` +
`SjForm`; setelah simpan: toast + `confirm-print` (menggantikan `session()->flash('status')`).

**KOP APOTEK - beda dari cetakan dokumen stok lain.** PB/KMB/TMB/PY/PL sama sekali tanpa kop;
SJ berkop 3 baris: nama apotek ("APOTEK RHEIN"), "Apoteker : ...", "SIPA : ...". Ketiganya
**TIDAK ADA di database** (dicek: tidak ada kolom IZIN/SIPA/APOTEK; `ainfo`/`bnamapt`/`bgudang`
tidak memuatnya) - hardcode di template report lama. Diambil dari config per `bnamapt.NPID`,
jalur sama cetakan PO: `SUCABANG` -> `bgudang.GPT` -> `NPID`. Dikonfirmasi: SJ dari gudang 20
"Depo" -> NPID 1, dan SIPA di config **cocok persis** dgn contoh. PT tanpa config -> kop
dikosongkan, BUKAN ditebak (diuji dgn SJ ber-GPT=8).

**`config/dokumen_print.php` DIGANTI NAMA jadi `config/dokumen_print.php`** (2026-09-27) krn kini
dipakai >1 cetakan. Ditambah 2 kunci: `apotek` ("APOTEK RHEIN") & `apoteker_nama`.
**`apoteker` vs `apoteker_nama` sengaja dua kunci**: cetakan PO menulis nama dgn gelar
("apt. Leo Arif Prasetyadi, S.Farm"), cetakan SJ pakai label sendiri "Apoteker : " lalu nama
TANPA "apt." - dua2nya disalin persis dari contoh masing2, tidak diturunkan satu dari yg lain.

**GOTCHA: label "No PO" isinya nomor PERMINTAAN BARANG**, bukan Purchase Order. Dirunut:
`SJ.SUNOSO` -> `fperintahkirimbarangu` (PKB `DE-PKB26090003`) -> `PKBUNORS` ->
`fpermintaanbarangu.PBUNOTRANSAKSI` = `BA-RS26080047` (nomor yg tercetak di contoh). Label
menyesatkan DIPERTAHANKAN sesuai contoh - pola sama "Tujuan :" di cetakan PB yg isinya vendor.

**Lebar kolom info (permintaan user 2026-09-27)**: sel isi kiri (Tujuan / Gudang Tujuan /
Gudang Sumber) pakai kelas `.isi-kiri` = **46%** (semula 33%), label kanan dikecilkan
78pt->68pt. **Keterangan diberi `colspan="3"`** = seluruh sisa lebar, karena sisi kanan
barisnya memang kosong. Diuji jg pada SJ berteks terpanjang di seluruh data
(`DE-SJ26080120`, kontak 33 huruf).

Kolom lain: "Tujuan :" = `bkontak.KNAMA`, "Gudang Tujuan :" = `SUGUDANGTUJUAN`,
"Gudang Sumber :" = `SUCABANG` (perhatikan: cetakan KMB menamai kolom yg sama "Gudang Asal"),
"Keterangan :" = `SUURAIAN`. Tabel **5 kolom: No | Nama Item | Keluar | Satuan | Catatan** -
**TIDAK ADA kolom kode item** (beda dari KMB/TMB/PB). Ttd "Bag Apotik"/"Penerima" sebaris
dgn "Total Qty".

**DEFER**: No Batch (`SDSERIAL`) tidak dicetak - contoh tidak memuatnya walau form SJ sudah
mendukung pilih batch. Datanya siap lewat `SerialBatch::unpack()` kalau nanti diminta.

**CATATAN DATA**: **841 dari 1.878 SJ tidak punya baris detail** (header-only hasil impor) -
cetakannya keluar "Tidak ada item.", bukan bug.

Verifikasi (44 asersi LULUS): **regresi cetakan PO masih jalan** setelah config di-rename;
29 potongan teks contoh dicocokkan (kop apotek/apoteker/SIPA, judul, 4 label kiri + isinya,
3 label kanan + isinya termasuk `BA-RS26080047`, 4 header kolom, 2 nama item, 2 ttd,
Total Qty, footer); terbukti TIDAK ada kolom kode "Item"; Total Qty 21,00 = contoh = `SUM
(SDKELUAR)` DB; 5 baris seperti contoh; kop terbukti dari config per NPID (PT lain -> kop
kosong tapi dokumen tetap normal); tombol cetak di form & di daftar (diuji sbg user Depo,
1.058 baris - **user Petogogan tidak punya SJ sama sekali**, SJ dikirim dari Depo/Depo
Farmasi/Bizpark); **SEMUA 1.878 SJ bisa dicetak tanpa error**.

## Invoice Penjualan Mutasi: cetakan (2026-09-27)

Permintaan user: "layout sama, beda penarikan saja, mutasi tarik dari TMB". Karena itu layout,
kop, perhitungan, terbilang & kotak rekap DIPINDAH ke **`InvoicePrintBase`** (abstract),
dipakai bersama IV & IVM; `reports/invoice-print.blade.php` juga SATU file utk keduanya, judul
kolom dokumen sumber jadi variabel (`$kolom1`/`$kolom2`). Tiap turunan cuma menentukan
`sumber()`, `aclPath()`, `labelKolom()`, `isiSumber()`.

`InvoiceMutasiPrintController` + route `sales/invoice-mutasi/{id}/print` (ACL
`sales/invoice-mutasi` ability `print`). Tombol Cetak di `InvoiceMutasiList` +
`InvoiceMutasiForm`; setelah simpan: toast + `confirm-print`.

**Rantai sumber IVM: KMB -> TMB** (sejajar IV yg SJ -> PBC, dokumen KELUAR dulu lalu TERIMA):
- **No TMB** = `IPDSUID` -> `fstoku.SUNOTRANSAKSI` (`SUSUMBER='TMB'`).
- **No KMB** = `TMB.SUPRUID` -> `fstoku.SUID`.
- **Gudang yg ditagih** = **`TMB.SUCABANG`** (konvensi `TmbWriter`: cabang pembuat TMB = cabang
  penerima barang) -> "Kepada Yth" & blok rekap.

**`IPUGUDANGTUJUAN` TIDAK DIPAKAI** walau konsepnya paling tepat & diisi
`InvoicePenjualanMutasiWriter::create()` utk dokumen baru: **NULL di SELURUH 107 IVM impor**.
Ditelusuri dari TMB supaya dokumen lama & baru sama2 benar.

**Rantai TMB->KMB terbukti SANGAT konsisten**: dari 265 baris ber-TMB, `SUPRUID` **tidak pernah
NULL**, 257 ketemu KMB-nya, dan **257 dari 257 punya `KMB.SUCABANG` = `IPUGUDANG` penerbit**.
8 sisanya `SUPRUID` yatim (KMB tidak ikut terimpor) -> kolom No KMB kosong.

**CATATAN DATA IMPOR IVM (lebih parah dari IV)**: dari 525 baris, **265 menunjuk TMB (benar),
258 YATIM, 2 menunjuk dokumen `IP`** (POS, bukan TMB). Akibatnya hanya **58 dari 107 IVM**
dapat gudang tujuan; sisanya "Kepada Yth" jatuh ke nama kontak & kolom dokumen kosong. Baris
yatim TETAP dicetak (nilainya harus masuk Sub Total), tidak dibuang.

Verifikasi (48 asersi LULUS): **regresi IV lengkap** setelah refactor (Sub Total/Pajak/Total/
Kepada Yth/judul kolom/daftar No SJ & No PBC/kotak rekap semua tidak berubah, dan SEMUA 208 IV
masih tercetak); IVM `PG-IVM26060001` -> judul kolom "No KMB"/"No TMB", 31 no TMB & 30 no KMB,
**semua nomor kolom 2 terbukti dokumen TMB dan kolom 1 dokumen KMB**, "Kepada Yth" = 5 gudang
tujuan dari `TMB.SUCABANG`; Sub Total 19.037.786,00 dihitung ulang (kolom DB 0); jumlah kotak
rekap = Sub Total di **SEMUA 107 IVM**; cetakan IVM TIDAK memakai judul kolom IV; tombol cetak
di daftar & form; **controller IV menolak dokumen IVM dan sebaliknya** (404, tidak tertukar).

## Invoice Penjualan: cetakan "INVOICE" (2026-09-26)

`InvoicePrintController` + `reports/invoice-print.blade.php`, route `sales/invoice/{id}/print`
(ACL `sales/invoice` ability `print`). Layout dari contoh cetakan lama user
(`Invoice Penjualan BZ-IV26090001.pdf` = `einvoicepenjualanu` IPUID 6265). Tombol Cetak di
`InvoiceList` + `InvoiceForm`; setelah simpan: toast + `confirm-print` (menggantikan
`session()->flash('status')` yg lama).

**KOP SURAT PER CABANG - BUKAN dari `ainfo`.** Ini beda besar dari cetakan dokumen stok
(PB/KMB/TMB/PY/PL) yg semuanya tanpa kop. Dikonfirmasi ke data: `ainfo.inama` = "NMW Clinic"
dgn SEMUA alamat "-", sedangkan cetakan menampilkan "PT. Royal Igyolini Indonesia" + alamat
Bizpark. Sumbernya `bgudang` dari `IPUGUDANG`:
- `GNAMAPT` = badan hukum cabang (gudang 10 -> "PT. Royal Igyolini Indonesia", gudang 1 ->
  "PT. Igyolini Indonesia" - **beda PT per cabang, JANGAN di-hardcode**).
- `GALAMAT2` = alamat, dan **baris "Telpon : ..." SUDAH ada di dalamnya** (ada newline;
  `GTELP` gudang 10 justru kosong) -> dirender `nl2br()`, jangan digabung manual dgn GTELP.

**SEMUA angka dihitung ulang dari baris.** `IPUSUBTOTAL` = 0 DAN `IPDSUBTOTAL`/`IPDTOTAL` = 0
di SELURUH data impor (invoice 6265: total & pajak terisi, subtotal 0).
`SUM(qty*(harga-diskon))` = 17.821.375 = PERSIS angka contoh. Pola SAMA `SDSUBTOTAL` PO lama.

**Asal kolom tabel (dikonfirmasi ke data, bukan tebakan)**:
- **No SJ** = `IPDSUID` -> `fstoku.SUNOTRANSAKSI`. **BUKAN `IPDSJD`/`IPDSDID`** - keduanya NULL
  di 22/22 baris data impor (walau `InvoicePenjualanWriter::create()` mengisinya utk invoice
  baru). Pakai `IPDSUID` supaya dokumen lama & baru sama2 tampil.
- **No PBC** = `fstoku` `SUSUMBER='PBC'` dgn **`SUNOSJAPOTIK` = id SJ**. PBC TIDAK punya FK SJ
  di kolom yg "kedengaran benar" (`SUPBUID`/`SUSOUID`/`SUNOSO`/`SUNOREFTRANSAKSI` semua NULL).
- **Termin** = `IPUTERMIN` -> `btermin.TTEMPO` (FK, bukan jumlah hari: nilai 4 -> tempo 30).
- **Kemas** = `bsatuan.SKODE`.

`terbilang_rupiah()` BARU di `helpers.php` - `terbilang()` lama membuang desimal, sedangkan
invoice hampir selalu berpecahan krn PPN 11%. Bagian "Sen" hanya ditulis kalau bukan nol,
sesuai contoh ("...Dua Puluh Enam Rupiah Dua Puluh Lima Sen"). `terbilang()` SENGAJA tidak
diubah - cetakan PO memakainya tanpa kata "Rupiah".

**Beda SENGAJA dari cetakan lama (2, keduanya perbaikan)**: (1) kolom Nama di cetakan lama
TERPOTONG kalau panjang - di sini WRAP, krn nama item terpotong di dokumen tagihan kehilangan
informasi; (2) baris "No Surat Jalan :"/"No PBC :" lama diawali koma nyasar (artefak
string-concat) - di sini digabung rapi.

**"Kepada Yth" = `'NMW ' + nama gudang TUJUAN SJ`** (aturan user 2026-09-27), BUKAN
`bkontak.KNAMA` - di contoh `IPUKONTAK` = "PT IGYOLINI INDONESIA" tapi tercetak
"NMW Petogogan". **Satu invoice bisa BANYAK tujuan**: cuma 50 dari 138 bertujuan tunggal,
sisanya 2-6 (mis. `DF-IV26080009` 6 tujuan) - semuanya dirangkai koma. Fallback ke nama kontak
kalau tujuan tidak ketemu (lihat catatan data di bawah).

**Kotak rekap bawah = 2 tingkat: gudang tujuan -> tipe pendapatan** (aturan user 2026-09-27),
lewat rantai `bitem` -> `bitem2` (`I2IDITEM`) -> `bcoatipe_pendapatan`
(`I2COAPENDAPATAN = CTID`), label `CTNAMA`.
**JEBAKAN**: label itu SAMA PERSIS dgn nama akun di `bcoa` (`5-03-01-00-00` = "Skincare &
Retail Revenue"), tapi jalurnya BUKAN lewat kolom COA di `bitem` - sudah diuji
`ICOAPENDAPATAN`/`ICOAPERSEDIAAN`/`ICOAHPP`/`ICOA2021`/`ICOA2026`/`IJENISITEMCOA`, **0 dari 22
baris** menunjuk akun itu; `IKELOMPOK23` pecah 5 grup; `IJENISBARU` labelnya "NMW Clinical
Treatment". Jangan ulangi pencarian itu.
`bcoatipe_pendapatan` di-LEFT JOIN: 3 baris data nyata tanpa tipe -> grup "-" supaya nilainya
TETAP terhitung (kalau dibuang, jumlah rekap tidak sama dgn Sub Total = lebih menyesatkan).
Kolom Satuan = satuan baris PERTAMA tiap grup (contoh: "Botol"; kebetulan sekaligus satuan
terbanyak, jadi dua tafsir itu tidak terbedakan dari contoh).

**CATATAN DATA IMPOR - 32% baris punya `IPDSUID` yatim**: 3.716 dari 11.450 baris menunjuk
`fstoku.SUID` yg TIDAK ADA (`IPDSUID` NULL: 0; tujuan NULL: 0 - murni referensi yatim). Akibatnya
**70 dari 208 invoice** tidak punya gudang tujuan, sehingga kolom **No SJ & No PBC kosong** dan
"Kepada Yth" jatuh ke nama kontak. Ini masalah DATA (SJ sumbernya tidak ikut terimpor / id
di-remap saat impor), BUKAN bug cetakan.

Verifikasi (55 asersi LULUS, invoice nyata `BZ-IV26090001`): Sub Total 17.821.375,00 / Pajak
1.960.351,25 / Total 19.781.726,25 / qty 1.620 / 22 baris **sama persis contoh PDF**; terbukti
`IPDSUBTOTAL` = 0 sehingga angka benar HANYA krn dihitung ulang; 35 potongan teks contoh
dicocokkan (kop, label, 6 header kolom, 2 no SJ, 2 no PBC, syarat pembayaran, ttd, nilai);
terbilang **identik** kalimat contoh + 3 kasus batas (tanpa sen, pembulatan 12,999 -> "Tiga
Belas Rupiah", 0,05 -> "Nol Rupiah Lima Sen"); SEMUA 22 baris dapat No SJ & No PBC (terbukti
`IPDSJD` NULL di 22 baris -> memakainya akan mengosongkan kolom); tombol cetak di daftar &
form; **SEMUA 208 invoice IV bisa dicetak tanpa error, 0 invoice tanpa detail**.

Verifikasi lanjutan "Kepada Yth" + rekap (31 asersi LULUS): contoh -> "NMW Petogogan", rekap
1 blok "Petogogan" x 1 tipe "Skincare & Retail Revenue" qty 1.620,00 satuan Botol jumlah
17.821.375,00 = **persis kotak rekap contoh**, dan jumlahnya = Sub Total dokumen; invoice
6 tujuan (`DF-IV26080009`) -> "Kepada Yth" merangkai 6 nama & rekap 6 blok, jumlah semua blok
34.706.443,75 = Sub Total dan qty 1.689 = qty dokumen; invoice 5 tipe (`DE-IV26080011`) ->
5 baris tipe, jumlah tetap = Sub Total; baris tanpa tipe muncul sbg grup "-" TANPA merusak
kecocokan jumlah; **jumlah kotak rekap = Sub Total di SEMUA 208 invoice (0 selisih)**.

## Kartu Stok: isi menu `inventory.stock` (2026-09-26)

Menunya sudah ada sejak MenuSeeder awal tapi KOSONG (tidak ada komponen). Dibuat
`App\Livewire\Inventory\KartuStok` + `livewire/inventory/kartu-stok.blade.php`, didaftarkan di
registry `Workspace` (`inventory.stock` -> `inventory.kartu-stok`). READ-ONLY total.
Filter sesuai permintaan user: Tanggal s/d Tanggal, Cabang, **Nama Item (wajib)**.

**TIDAK ADA acuan legacy**: tidak ada form VB6 `bFrm*KartuStok*`, tidak ada implementasi di
CI3/CI4, dan tabel `fstoksaldo`/`fstoksaldod` (yg dari namanya jelas dimaksudkan menyimpan
saldo awal/akhir per periode) **KOSONG, 0 baris**. Struktur dirancang dari data.

### Saldo Awal dari mutasi, BUKAN dari `bitem.ISTOK{kode}` - dan kenapa

`Saldo Awal = SUM(SDMASUK - SDKELUAR)` utk `fstokd` item+gudang dgn `SUTANGGAL < dari`.
Alternatif "mundur dari stok sekarang" SENGAJA ditolak, diverifikasi ke DB:
- **`ISTOK{kode}` BUKAN stok per gudang.** `F_KOLOMGUDANG()` memetakan **8 gudang** ke
  `ISTOKPG` (1 Petogogan, 6 Online, 14 Home Care, 15 Pameran, 39 Gudang Depo Tindakan,
  40 Gudang Kubis 1, 42 Depo Research, 49 Busura) dan **2 gudang** ke `ISTOKMP` (9 Gogobli,
  18 Marketplace). Memakainya sbg jangkar bikin kartu 10 gudang itu salah diam-diam.
- `ISTOKDE` punya bug double-count yg sudah didokumentasikan.

**KETERBATASAN yg DITAMPILKAN di layar, bukan disembunyikan**: `fstoku` paling awal
**2026-06-01**, bukan sejak awal usaha. Diuji: gudang 2 (`ISTOKCP`, pemetaan 1:1) hanya
**28 dari 225 item** yg `SUM(masuk-keluar)`-nya sama dgn `ISTOKCP`. Jadi Saldo Awal utk tanggal
<= 2026-06-01 selalu 0 dan Saldo Akhir bisa beda dari Stok Sistem. Kartu ini menampilkan
**Stok Sistem + selisihnya** di kartu ringkasan, plus alert kuning yg menjelaskan sebabnya.
Kalau nanti stok awal di-import ke `fstoksaldod`, rumusnya tidak perlu diubah - cukup tambahkan
saldo awal itu.

### Catatan implementasi
- Kolom: Tanggal | No Transaksi | Jenis (+badge kode) | [Gudang, hanya mode Semua] |
  Keterangan (uraian + kontak + catatan baris) | Masuk | Keluar | Saldo berjalan.
  Baris pembuka "Saldo Awal per dd/mm/yyyy" + footer Jumlah.
- Baris `SDMASUK=0 AND SDKELUAR=0` dibuang - itu sisa dokumen DIBATALKAN (pola cancel semua
  modul: nolkan qty + `SDCANCEL=1`), tidak perlu jadi baris kosong.
- Saldo berjalan dihitung di PHP urut `SUTANGGAL, SUID, SDURUTAN` (pola sama
  `SerialHistori::mutasi()`).
- Label Jenis dari `ajenistransaksistok`. **Tabel itu TIDAK LENGKAP**: `AL`, `PL`, `RC` ada di
  data tapi tidak terdaftar -> ditambal `KartuStok::JENIS_TAMBAHAN`.
- Tanpa pagination (ledger, pagination merusak saldo berjalan). Pengaman `MAKS_BARIS=2000` +
  alert merah kalau kepenuhan; item tersibuk di DB ini cuma 140 baris (item 379 gudang 20).
- Cabang boleh "Semua Gudang" = semua gudang yg BOLEH DILIHAT user (kolom Gudang ikut muncul).

**CELAH AKSES YG DITEMUKAN & DIPERBAIKI saat pengujian**: versi pertama `gudangIds()`
MENGGANTI batasan cabang user dgn pilihan dropdown (`return [(int) $this->cabang]`) - artinya
mengirim `cabang` gudang lain lewat state Livewire menembus batasan `branchIds()`. Terbukti
nyata: user uji (hanya berhak gudang 1) bisa menarik 48 baris gudang 20. Sekarang pilihan
DIIRISKAN dgn `branchIds()`, dan irisan kosong -> `[0]` (GID 0 tidak ada) supaya hasilnya 0
baris - mengembalikan `[]` justru akan MEMBUKA semua gudang. **Pola yg harus diikuti komponen
lain: filter cabang milik user DIIRISKAN, jangan menggantikan scope.**

Verifikasi (62 asersi LULUS, item nyata 379 SPUIT 3 CC gudang 1): tanpa item -> ajakan pilih &
tabel tidak dirender, label bertanda wajib; default rentang bulan ini & cabang user; Saldo Awal
536 / Masuk 353 / Keluar 0 / Saldo Akhir 889 semuanya cocok SQL terpisah; saldo berjalan benar
di SEMUA 7 baris & baris terakhir = Saldo Akhir; urutan tanggal menaik; tidak ada baris 0/0;
7 header kolom ada, kolom Gudang muncul HANYA di mode Semua Gudang; label Jenis benar termasuk
tambalan AL/PL/RC dan tetap pakai `ajenistransaksistok` utk IP; **gudang di luar hak user -> 0
baris & saldo 0**; pemetaan kolom stok benar (gudang 2 -> ISTOKCP tanpa peringatan, gudang 1 ->
ISTOKPG terdeteksi dipakai 7 gudang lain + peringatan tampil menyebut nama gudangnya); Saldo
Awal 01/06/2026 = 0 + alert penjelasan muncul; filter tanggal/item/gudang benar-benar membatasi
(dicek balik per `SDID`); `itemLabel` terisi otomatis (GOTCHA search-select); rentang kosong ->
pesan jelas & Saldo Akhir = Saldo Awal; registry & menu benar.

**BELUM ada cetakan** utk Kartu Stok (user belum minta).

## SJ: konversi tampilan kolom Qty ke box (2026-09-26)

Permintaan user: "Untuk Surat Jalan, ada konversi tampilan di Kolom Qty, yang tampil adalah
Qty Real / Per Box, jumlah box diambil dari iqtyperbox". Dipakai di `SjForm` + bladenya:
kolom **Qty Diminta** & **Qty Dikirim** (dan footer Total) menampilkan
`<qty real> / <jumlah box> box`, dgn **jumlah box = qty real : `bitem.IQTYPERBOX`**.

**MURNI TAMPILAN** - `qtyPerBox` cuma dibawa di array `$lines` utk dirender; `save()` punya
whitelist kolom sendiri jadi nilai ini tidak pernah sampai ke writer/DB. Sudah dipastikan
`fstokd` memang TIDAK punya kolom per-box, jadi konversi WAJIB dihitung dari `bitem` tiap
kali render (bukan disimpan per transaksi - kalau isi per box berubah di master, cetakan/
tampilan dokumen lama ikut berubah. Konsekuensi yg diterima, tidak ada tempat menyimpannya).

`IQTYPERBOX` ditambahkan ke SELECT `PkbWriter::lines()` & `SjWriter::lines()` (dua sumber baris
SJ: tarik-dari-PKB dan buka SJ tersimpan), lalu dipetakan ke `qtyPerBox` di
`SjWriter::fromPkb()` & `SjForm::load()`.

**Aturan kapan konversi DISEMBUNYIKAN** (penting, bukan kosmetik):
- `IQTYPERBOX = 0` -> **408 dari 5.580 item**; bukan pembagi yg sah (division by zero).
- `IQTYPERBOX = 1` -> **5.016 dari 5.580 item** (mayoritas!); 1 box = 1 pcs, jadi "/ 1 box"
  cuma sampah visual di hampir semua baris.
  Sisanya (nilai > 1) yg dikonversi - nilai nyata: 2, 2.5, 5, 10, 15, 20, 50, 60, 100, 250,
  500, 1000, sampai 5000 (item cair/bulk spt GEL, NIACEF 1 KG).
Isi per box aslinya ditaruh di `title` (tooltip "Isi 100 per box").

**Total Box di footer** = jumlah box baris yg PUNYA konversi saja; baris tanpa konversi tidak
ikut (jadi Total Qty Real tetap menjumlah semua, Total Box tidak). Kalau 0, footer box
disembunyikan.

**`wire:model` -> `wire:model.live.debounce.400ms`** di input Qty Dikirim supaya angka box (dan
"Total Qty Dikirim" yg SEBELUMNYA juga baru ikut setelah round-trip) berubah saat qty diketik.
Efek samping yg sudah ada & tidak berubah sifatnya: `updatedLines()` mengosongkan batch terpilih
tiap qty berubah - sekarang terjadi saat mengetik (debounce), bukan saat blur.

**TIDAK disentuh**: kolom Qty di modal "Pilih Serial" (qty per batch = pcs dari stok, konversi
box tidak bermakna di sana), daftar SJ (tidak punya kolom Qty), dan cetakan SJ (belum ada).

Verifikasi (41 asersi LULUS, PKB nyata `PG-PKB26090171` 8 baris): `IQTYPERBOX` terbawa dari
`PkbWriter::lines()` -> `fromPkb()` -> `$lines['qtyPerBox']` dan cocok `bitem` di semua baris;
6 item nyata ber-per-box > 1 tampil konversinya benar (3:2=1,5 box; 10:2=5; 20:15=1,33;
20:5=4; 60:60=1; 60:50=1,2) + tooltip; uji sintetis 5 kasus: per-box 0 & 1 -> TIDAK ada teks
box sama sekali & Total Box 0, per-box 100/2,5/60 -> box benar termasuk pecahan 1,5 dan Qty
Real tetap tampil apa adanya; baris campuran -> Total Box 5 (bukan 12) sedangkan Total Qty
Real 507; `save()` terbukti tidak menyentuh `qtyPerBox`; SJ nyata tersimpan `DE-SJ26090229`
menampilkan konversi di mode read-only. Tidak ada SJ baru terbuat.

## Pengeluaran Lain: modul + cetakan (2026-09-26)

Permintaan user: "menu Pengeluaran lain, inputan sama seperti Penyesuaian Barang, tapi ini
hanya keluar saja, buat juga cetakannya". Dibangun sbg **kembar Penyesuaian (PY)**:
`PengeluaranLainWriter` / `PengeluaranLainList` / `PengeluaranLainForm` /
`PengeluaranLainPrintController` + `reports/pengeluaran-lain-print.blade.php`.
Menu `inventory.pengeluaran-lain` -> path `inventory/pengeluaran-lain`, sort 56 (TEPAT setelah
Penyesuaian 55; `inventory.opname` digeser 56->57, `inventory.serial` 57->58).

**`SUSUMBER='PL'`**, tabel `fstoku`/`fstokd` - persis seperti PY, satu-satunya beda: **tidak
ada kolom Masuk**, `SDMASUK`/`SDMASUKD` selalu 0 dan baris divalidasi `keluar > 0`.

**Struktur direkonstruksi dari SATU dokumen legacy** `RB-PL26060001` (SUID 1221092, satu-satunya
PL hasil impor): header identik PY (`SUKONTAK`, `SUCABANG`=34, `SUURAIAN`='Pengeluaran Lain',
`SUJENISPENYESUAIAN`=6, `SUSTATUS`=0), 2 baris `SDMASUK=0` / `SDKELUAR=3` & `2`, `SDCATATAN`=
'dipakai produksi'. Jadi **PL ikut memakai `bjenispenyesuaian`** - tidak punya tabel jenis
sendiri, dropdown Jenis-nya sama dgn PY.

**GOTCHA legacy**: `PL` TIDAK terdaftar di `aanomor` (PY ada, NID 706) maupun
`ajenistransaksistok` - modul yg ditambahkan belakangan tanpa melengkapi tabel referensi.
Tidak mengganggu: `nextNumber()` menghitung sendiri dari `SUNOTRANSAKSI` (pola semua writer
kita) dan formatnya sudah dicek sama dgn nomor legacy.

**Trigger sudah diperiksa** (`information_schema.TRIGGERS`): `fstoku_add` / `fstokd_add` /
`_edit` / `_DELL` TIDAK punya cabang khusus `SUSUMBER='PL'` - beda dari `'PY'` (menyentuh
`fstokopnameu.SOUDIBUATPY`) dan `'SJ'`/`'IP'`. Stok digerakkan trigger generic `fstokd_add`
(`bitem.ISTOK{kode}` += `SDMASUK - SDKELUAR` sesuai `SDGUDANG`), jadi TIDAK ada bookkeeping
manual. Pembatalan SOFT (`SDCANCEL=1` + nolkan qty + `SUSTATUS=9`) dikembalikan `fstokd_edit`.

Cetakannya kembar `penyesuaian-print`: **satu kolom qty ("Keluar") & satu "Total Keluar"**,
6 kolom (No|Item|Nama Item|Keluar|Satuan|Catatan, lebar 4/20/31/11/9/25). Baris "Jenis :",
kolom Catatan, dan ttd "Dibuat Oleh / Diketahui Oleh" diwarisi dari cetakan PY yg sudah
disetujui user, supaya dua dokumen kembar ini konsisten.

Verifikasi (68 asersi LULUS): cetakan PL legacy -> PDF, 17 potongan teks ada, **terbukti TIDAK
punya kolom Masuk maupun Total Masuk**, Total Keluar 5,00 cocok `SUM()` DB, catatan per baris
tampil; form punya 12 field/kolom spesifikasi & TIDAK punya "Jumlah Masuk"/input masuk, Gudang
terkunci ke cabang user, Uraian default "Pengeluaran Lain", Jenis wajib; simpan -> `SUSUMBER`/
`SDSUMBER`='PL', `SUSTATUS`=0, nomor `PG-PL26090001` (pola sama legacy), `SDKELUAR`=7 &
`SDMASUK`=0, `SDGUDANG`/`SDSATUAN`/`SDCATATAN` benar, read-only setelah simpan, toast +
`confirm-print`; **trigger menurunkan stok `ISTOKPG` 134 -> 127 dan batal mengembalikannya ke
134**, `SDCANCEL`=1, banner "SUDAH DIBATALKAN" di cetakan, batal dua kali ditolak; baris
Keluar=0 ditolak; muncul di daftar (kolom Keluar saja) dgn link cetak benar; registry Workspace
& baris `lv_menu` benar. Semua tulisan DB di transaksi + rollback; dipastikan PL tetap 1 baris
dan stok kembali 134.

## Penyesuaian Barang: cetakan (2026-09-26)

`PenyesuaianPrintController` + `reports/penyesuaian-print.blade.php`, route
`inventory/adjust/{id}/print` (ACL `inventory/adjust` ability `print`). Permintaan user:
"seperti TMB KMB bedanya penyesuaian ada masuk dan keluar".

**TIDAK ADA contoh cetakan legacy** untuk dokumen ini, jadi 4 hal DIPUTUSKAN sendiri (semua
ditandai di docblock controller supaya gampang dikoreksi kalau ada format bakunya):
1. Tabel **7 kolom**: No | Item | Nama Item | **Masuk** (`SDMASUK`) | **Keluar** (`SDKELUAR`)
   | Satuan | **Catatan** (`SDCATATAN`) - lebar 4/20/28/10/10/9/19%. Sel qty dikosongkan
   (bukan "0,00") kalau nilainya 0, supaya arah tiap baris langsung kebaca.
2. Blok bawah punya **DUA total: "Total Masuk" & "Total Keluar"** di dua baris - KMB/TMB cuma
   satu "Total Qty".
3. Blok info kiri dapat baris **"Jenis :"** (`bjenispenyesuaian.JNAMA`) - identitas utama
   dokumen ini (Racikan / Penurunan Barang / Stok Opname / dst).
4. Tanda tangan **"Dibuat Oleh" / "Diketahui Oleh"** - KMB/TMB pakai "Dikirim/Diterima Oleh"
   yg tidak cocok untuk dokumen yg tidak berpindah gudang.

"Tujuan :" = `bkontak.KNAMA`, label legacy yg dipertahankan demi konsistensi sekeluarga
(PB/KMB/TMB semuanya begitu walau isinya nama orang/vendor).

Tombol Cetak di kolom Aksi `PenyesuaianList` + header `PenyesuaianForm` (hanya kalau `$pyId`).
Setelah simpan: toast + `confirm-print` (alur sama PO/PB/KMB/TMB).

**Catatan data**: cuma 28 dari 600 PY hasil impor punya baris detail - kalau cetakan PY lama
keluar "Tidak ada item.", itu memang datanya header-only, bukan bug.

Verifikasi (29 asersi LULUS, PY nyata `KM-PY26060001`): PDF ter-generate (30 KB); 17 potongan
teks ada (judul, 5 label info termasuk "Jenis :", 4 header kolom, 2 total, 2 ttd, footer
halaman+waktu); terbukti TIDAK memakai "Total Qty" tunggal; Total Masuk 435,00 & Total Keluar
405,00 cocok `SUM()` DB; baris ber-Masuk DAN Keluar (NaCl 500 ML 2/2) tampil dua-duanya;
tombol cetak muncul di daftar (link route benar) & form tersimpan, TIDAK muncul di form baru;
simpan PY baru -> `toast` + `confirm-print` ter-dispatch dan PY itu langsung bisa dicetak
(semua tulisan di transaksi + rollback; dipastikan 0 baris PY tertinggal).

## TMB: cetakan "Terima Mutasi Barang" (2026-09-26)

`TmbPrintController` + `reports/tmb-print.blade.php`, route `inventory/tmb/{id}/print`
(ACL `inventory/tmb` ability `print`). Kembaran `kmb-print`, dari contoh cetakan lama user
(`Terima Mutasi Barang PG-TMB26090031.pdf`). Beda dari KMB:
- **TIDAK ada baris "Gudang Tujuan"** - `SUGUDANGTUJUAN` memang NULL di semua TMB nyata
  (dokumen penerimaan; gudang tujuannya ya `SUCABANG` itu sendiri, dilabeli "Gudang :").
- Qty dari **`SDMASUK`** (TMB = dokumen MASUK), sedangkan KMB dari `SDKELUAR`.

**GOTCHA: kolom qty berlabel "Qty Keluar" TAPI isinya `SDMASUK`** - dikonfirmasi dari data
(TMB contoh `SDMASUK=5`, `SDKELUAR=0`, PDF asli menampilkan 5,00 di kolom "Qty Keluar").
Jelas warisan salin-tempel dari laporan KMB; DIREPLIKASI apa adanya sesuai permintaan user
("sama dgn cetakan lama"). Begitu juga tanda tangan "Dikirim Oleh / Diterima Oleh" yg terasa
terbalik untuk dokumen penerimaan.

"Tujuan :" = `bkontak.KNAMA` (nama ORANG) - pola menyesatkan yg SAMA dgn cetakan PB & KMB.

Tombol Cetak di `TmbList` + `TmbForm`; setelah simpan: toast + `confirm-print`.

Verifikasi (21 asersi LULUS, TMB nyata `PG-TMB26080021`): PDF ter-generate; 13 potongan teks
dicocokkan ke contoh; terbukti TIDAK ada baris Gudang Tujuan; header benar (Arnis | Petogogan);
Total Qty 5,00 dari `SDMASUK`; tombol cetak muncul di daftar & form.

## KMB: cetakan "Kirim Mutasi Barang" (2026-09-26)

`KmbPrintController` + `reports/kmb-print.blade.php`, route `inventory/kmb/{id}/print`
(ACL `inventory/kmb` ability `print`). Layout dari contoh cetakan lama user
(`Kirim Mutasi PG-KMB26090042.pdf`). Struktur & CSS SAMA `pb-print` (tanpa kop PT), bedanya:

- Blok info punya **4 baris kiri**: Tujuan / Gudang Asal / **Gudang Tujuan** / Keterangan;
  kanan cuma No Transaksi & Tanggal (**tidak ada No Invoice** spt PB).
- Tabel **5 kolom**: No | Item | Nama Item | **Qty Keluar** (`SDKELUAR`, KMB = dokumen KELUAR)
  | Satuan. **Tidak ada kolom No PO** - mutasi antar gudang tidak berasal dari PO.
- Tanda tangan **"Dikirim Oleh" / "Diterima Oleh"** (PB: "Bag Gudang / Penerima").

**"Tujuan :" = `bkontak.KNAMA` (nama ORANG)**, BUKAN gudang tujuan - gudang tujuan ada di
barisnya sendiri. Label legacy yg menyesatkan, dipertahankan sesuai cetakan lama. Pola SAMA
`PbPrintController` yg "Tujuan"-nya justru nama vendor. Sisanya: Gudang Asal=`SUCABANG`,
Gudang Tujuan=`SUGUDANGTUJUAN`, Keterangan=`SUURAIAN`, Item=`IKODE`, Nama Item=`INAMA`.

Tombol Cetak di kolom Aksi `KmbList` + header `KmbForm` (hanya kalau `$kmbId` ada). Setelah
simpan: toast + `confirm-print` (alur sama PO/PB).

Verifikasi (22 asersi LULUS, KMB nyata `CP-KMB26090010`): PDF ter-generate; 14 potongan teks
cetakan dicocokkan ke contoh; nilai header benar (Endang Yuliyanti | Ciputat -> Tebet);
Total Qty 2,00; Item/Nama Item terisi; terbukti TIDAK ada kolom No PO/Harga; tombol cetak
muncul di daftar & form.

## PB: cetakan "Penerimaan Barang" (2026-09-26)

`PbPrintController` + `reports/pb-print.blade.php`, route `purchase/pb/{id}/print`
(ACL `purchase/receipt` ability `print`). Layout direplikasi dari contoh cetakan lama user
(`Penerimaan BarangRB-PB26080001.pdf` = `fstoku` SUID 1261469). Pola sama `PoPrintController`.

**Label "Tujuan :" ternyata NAMA VENDOR** (`bkontak.KNAMA` - "PT ROI SURYA PRIMA FARMA"),
BUKAN gudang tujuan - label legacy yg menyesatkan, dipertahankan krn user minta sama dgn
cetakan lama. Sisanya lurus: "Gudang :" = `bgudang.GNAMA` (`SUCABANG`), "Keterangan :" =
`SUURAIAN`, "No Transaksi :" = `SUNOTRANSAKSI`, "Tanggal :" = `SUTANGGAL`, "No Invoice :" =
`SUNOREF`. Tabel: "Item" = `bitem.IKODE`, "Nama Item" = `INAMA` (dikonfirmasi lewat item 4843
di contoh), "Qty Masuk" = `SDMASUK`, "Satuan" = `bsatuan.SKODE`, "No PO" ditelusuri
`SDSODID` -> `esalesorderd.SODIDSOU` -> `esalesorderu.SOUNOTRANSAKSI`.

**TIDAK ADA kop PT** (beda dari cetakan PO yg berkop `bnamapt`) - contoh aslinya memang cuma
berjudul "Penerimaan Barang". **Harga TIDAK dicetak**, konsisten dgn kolom Harga yg
disembunyikan di form.

**Catatan data**: PB contoh (`RB-PB26080001`) ternyata **header-only** - baris detailnya
korban import gagal yg sama spt modul lain, jadi cetakannya tampil tanpa item. Diuji juga
dgn PB yg PUNYA detail (Total Qty 561,00) supaya angka & kolomnya terbukti benar.

Tombol Cetak: kolom Aksi `PbList` + header `PbForm` (hanya kalau `$pbId` ada), `<a
target="_blank">`. Setelah simpan: toast + `confirm-print` "Cetak dokumennya sekarang?"
(persis alur PO).

**Catatan revisi layout (2026-09-26)**: sempat dicoba memindahkan blok "Bag Gudang /
Penerima / Total Qty" ke `<tfoot>` tabel item (biar Total Qty sejajar kolom Qty Masuk).
**User minta DIKEMBALIKAN seperti semula** - blok bawah TETAP tabel TERPISAH (26/26/26/22%).
Jangan diulangi kecuali diminta lagi.

Yg TETAP dipakai: **kolom "No PO" dilebarkan 14% -> 22% + `white-space: nowrap`** (nomor spt
`PG-PO26090001` tadinya patah 2 baris). AMAN krn semua `esalesorderu.SOUNOTRANSAKSI` PO
panjangnya PERSIS 13 karakter (dicek 236 PO) - tidak mungkin meluber. Lebar kolom item
sekarang **5/22/34/10/7/22** (tambahan diambil dari Qty Masuk 12->10 & Satuan 10->7 yg
isinya pendek, plus Nama Item 37->34; kolom Item TIDAK dikurangi krn isinya `IKODE` yg di
data ini sering sepanjang namanya).

Verifikasi (33 asersi LULUS, simpan di transaksi + rollback): 20 potongan teks cetakan
dicocokkan ke PDF contoh; Harga terbukti tidak ikut tercetak; PB berisi -> Total Qty & kolom
Item/Nama Item benar; link cetak di daftar & form benar dan TIDAK muncul di form PB baru;
simpan PB baru -> `toast` + `confirm-print` ter-dispatch dan PB itu bisa dicetak.

## PB (daftar): filter Gudang = cabang user, default cabang aktif (2026-09-26)

Permintaan user, melengkapi perubahan "Gudang Tujuan = cabang user login" di `PbForm`.
DULU `PbList` mengunci SEMUANYA ke 3 gudang Depo hardcode: isi dropdown, default filter,
DAN pembatas query. Sekarang:
- **Isi dropdown** = cabang milik user (`auser.UCABANGPILIH` via `User::branchIds()`),
  disaring `Branch::active()` - cabang NONAKTIF sengaja tidak ditawarkan. `UCABANGPILIH`
  kosong = tidak dibatasi -> semua cabang aktif. Pola PERSIS `PrList`/`PosDataList`.
- **Default filter** = cabang AKTIF user (`UCABANG`), selalu terisi (dulu hanya terisi kalau
  cabangnya kebetulan salah satu dari 3 depo, selain itu kosong = tampil semua).
- **Pembatas query** `whereIn('u.SUCABANG', GUDANG_DEPO)` DIGANTI `whereIn(..., $allowed)` -
  ini defense-in-depth yg sebenarnya: dropdown bisa dimanipulasi dari sisi klien, batas di
  query ini yg menjaga user tidak melihat PB cabang lain.

Verifikasi (11 asersi LULUS, 3 profil user nyata): user 1 cabang (Petogogan) -> dropdown 1
opsi & default terisi; user 48 cabang -> dropdown 31 (hanya yg aktif), **tidak ada cabang di
luar izinnya**, default = cabang aktif, dan baris yg tampil semuanya dari cabang berizin
(1, 34, 46, 20); user `UCABANGPILIH` kosong -> 31 cabang aktif, default tetap cabang aktifnya.

## PB: kolom Harga disembunyikan (TAPI tetap disimpan) (2026-09-26)

Permintaan user: kolom **Harga** di grid item PB dihilangkan dari TAMPILAN, tapi nilainya
**TETAP ditarik dari PO dan TETAP disimpan ke `fstokd.SDHARGA`** - akan jadi sumber
perhitungan **HPP** (dibahas terpisah nanti). Perubahan MURNI di blade (kolom itu memang cuma
teks read-only, bukan input), `$lines[]['harga']` & `PbForm::save()` TIDAK disentuh.

**JANGAN hapus `harga` dari state/`save()`/`PbWriter` hanya karena kolomnya tidak kelihatan** -
sudah diberi komentar peringatan di blade tepat di tempat kolom itu dulu berada.

Verifikasi (6 asersi LULUS, simpan di transaksi + rollback): kolom Harga hilang dari HTML;
`harga` tetap ada di state (total 4.500.000); jumlah kolom header == kolom baris (10, colspan
footer ikut disesuaikan); PB tersimpan dan **`SDHARGA` terisi sama persis dgn `SODHARGA` PO**.

## PB: susunan header disamakan dgn VB6 (2026-09-26)

Permintaan user (dgn screenshot VB6 `fFrmPenerimaanBarangPBDepo`):
- **KIRI** (label di samping, lebar label 100px): **Kontak** (= NAMA VENDOR, dulu dilabeli
  "Supplier") dan **Gudang** (dulu "Gudang Tujuan").
- **KANAN** (kelas `.pb-top`, label DI ATAS input): baris 1 = Tanggal | No Transaksi;
  baris 2 = **No PO** | No. Invoice (dulu "No. Invoice / Ref").
- **Keterangan** selebar form di bawah.

**Field "No PO" BARU** di header: kotak read-only berisi daftar nomor PO yg sudah ditarik
(unik, dipisah koma - PB boleh menarik BEBERAPA PO) + tombol kaca pembesar yg membuka picker
yg sama dgn tombol "Tarik dari PO". Tombol lama di atas tabel item TETAP ADA (dua jalan ke
picker yg sama).

**Catatan replikasi**: di VB6 kotak TANGGAL ikut berlabel "No Transaksi" (dua kotak
bersebelahan sama-sama berlabel "No Transaksi" - jelas salah label di form lama). Di sini
dilabeli **"Tanggal"** sesuai isinya; yg direplikasi POSISInya, bukan salah labelnya.

Verifikasi (19 asersi LULUS): ke-7 label ada & urutannya benar (Kontak kiri/Tanggal kanan,
No Transaksi sebelum No PO, Keterangan paling bawah), label lama "Gudang Tujuan"/"Supplier"
sudah hilang, "[otomatis]" utk PB baru; setelah tarik PO -> Kontak terisi nama vendor & No PO
terisi nomornya; simpan PB nyata (transaksi + rollback) -> No. Invoice/Keterangan/Kontak/
Tanggal semua tetap tersimpan benar setelah field dipindah-pindah.

## PB: Gudang Tujuan = cabang user login (2026-09-26)

Permintaan user. Dulu dropdown bebas tapi **dibatasi 3 gudang "Depo"** (`PbWriter::GUDANG_DEPO`
= 20 Depo, 34 RII Bahan Baku, 46 Depo Farmasi - di-hardcode dari data nyata: 420 dokumen PB
legacy 100% cuma di 3 gudang itu). Sekarang field-nya **read-only, isinya cabang user login**
(pola sama PO/SJ/Produksi), sejalan aturan app "kalau ada pilihan cabang, diisi sesuai cabang
user".

**Pembatasan 3-gudang itu DILEPAS** (di `PbForm::save()` DAN `PbWriter::create()`) - kalau
dipertahankan, user yg cabangnya di luar 3 gudang itu (mis. Petogogan, cabang user uji)
TIDAK AKAN PERNAH bisa menyimpan PB krn field-nya terkunci ke cabangnya sendiri. Validasi
tersisa: gudang tujuan tidak boleh kosong. Konstanta `GUDANG_DEPO` SENGAJA DIPERTAHANKAN
sbg dokumentasi sejarah walau tidak lagi memvalidasi.

**Konsekuensi yg SUDAH disampaikan ke user**: PB kini bisa masuk ke gudang mana pun sesuai
cabang user. Kalau ternyata PB memang HARUS depo saja, yg perlu dibatasi adalah SIAPA yg
boleh membuka menu PB (hak akses menu per user), BUKAN dropdown gudangnya.

Efek samping bagus: `openPicker()` tidak lagi bisa kejebak "Pilih gudang tujuan dulu" krn
gudang selalu terisi.

Verifikasi (11 asersi LULUS, simpan di transaksi + rollback): form baru -> gudang = cabang
user (Petogogan) + labelnya tampil + keterangan "tidak bisa diubah" + dropdown hilang; picker
PO langsung terbuka; simpan PB nyata -> `SUCABANG` & `SDGUDANG` semua baris = cabang user,
dan cabang itu MEMANG di luar 3 gudang depo lama (dulu pasti ditolak).

## PB: picker "Tarik dari PO" lintas cabang (2026-09-26)

User melapor tidak bisa menarik PO cabang lain. **Ternyata `pullablePo()` MEMANG sudah tanpa
filter cabang sejak awal** - yg jadi masalah: `limit(30)` + `orderByDesc(SOUID)` memotong PO
cabang lain yg lebih lama, DAN picker tidak menampilkan cabang sama sekali jadi user tidak
bisa tahu/menyaring. Di data nyata ada **62 PO bisa ditarik, tersebar di 5 cabang** (Gudang
Depo Tindakan 33, Depo Farmasi 19, RII Bahan Baku 7, Gudang Bahan Baku 2, Petogogan 1) -
dgn limit 30 praktis cuma cabang ber-SOUID besar yg kelihatan.

Perbaikan: limit 30 -> **50**, query di-extract ke `pullablePoQuery()` (dipakai bersama),
tiap baris membawa **nama cabang** (badge di picker), plus **dropdown filter cabang** yg
isinya dari `cabangPunyaPo()` (cabang yg BENAR2 punya PO bisa ditarik + jumlahnya, mis.
"Depo Farmasi (19)") - default "Semua cabang".

**Kenapa lintas cabang itu benar**: PO dibuat terpusat, sedangkan barangnya diterima di
gudang Depo yg dipilih TERPISAH lewat field "Gudang Tujuan" (`SUCABANG` PB, dibatasi
`GUDANG_DEPO`). Cabang pembuat PO tidak membatasi di mana barang boleh diterima. Pola sama
picker "Tarik dari PR" di PKB yg dulu user konfirmasi juga lintas cabang.

Verifikasi (9 asersi LULUS): picker mengembalikan 50 baris dari 5 cabang; jumlah per cabang
di dropdown (62) == total PO bisa ditarik; filter cabang menyaring tepat (Depo Farmasi -> 19
baris, semuanya cabang itu); komponen me-render dropdown & badge cabang dgn benar.

## PO: cetakan PURCHASE ORDER + tanya-cetak setelah simpan (2026-09-26)

`PoPrintController` + `reports/po-print.blade.php`, route `purchase/po/{id}/print`
(ACL `purchase/po` ability `print`). Layout direplikasi dari contoh cetakan asli user
(`Order Pembelian GB-PO26090001.pdf` = SOUID 7009) - **tiap field dicocokkan lewat query,
bukan tebakan**. Pola sama `PrPrintController` (dokumen 1 transaksi, mpdf A4).

**Sumber field yg mudah salah tebak**:
- "Nama Item" = **`bitem.IPONAMA`** (nama versi supplier, "Peel off mask Calendula 1000 gram"),
  BUKAN `INAMA` ("MASK CALENDULA PO 1 KG"). "Kemasan" = `IPOKEMASAN`.
- "Alamat" supplier = **`bkontak.K1ALAMAT` + `K1KOTA`** (slot 1; K3 null utk vendor contoh).
  `K1KOTA` vendor contoh berisi "27819" dan di PDF asli MEMANG tercetak di baris sendiri.
  `esalesorderu.SOUALAMAT` SENGAJA TIDAK dipakai - di PO contoh isinya cuma baris kosong + "-".
- **"Sub Total" per baris DIHITUNG** (qty x harga-setelah-diskon), TIDAK membaca
  `SODSUBTOTAL`: kolom itu **0 di PO contoh** padahal PDF menampilkan 2.000.000 - sistem lama
  tidak pernah mengisinya. (Catatan: `PoForm::load()` masih membaca `SODSUBTOTAL` utk kolom
  Total di form, jadi **PO legacy yg dibuka di form kita akan menampilkan total baris 0** -
  belum diperbaiki, perlu keputusan tersendiri.)
- "Termin" = `btermin.TKODE`; "Di Kirim Ke" = `bgudang.GNAMA` dari `SOUCABANG`;
  "Diinput Oleh" = `auser.UNAMA` + HP dari `bkontak.K1TELP1` via `auser.UKID`.

**3 baris izin di kop (No Izin / apt. / SIPA) TIDAK ADA DI DATABASE** - dicek
`information_schema`: tidak ada kolom IZIN/SIPA/APOTEK di skema ini & `ainfo` tidak memuatnya;
di sistem lama hardcode di template report. Ditaruh di **`config/dokumen_print.php` per `NPID`**
(badan hukum), BUKAN di blade - SIPA = identitas apoteker yg BEDA per PT, salah cetak = fatal.
PT yg belum terdaftar -> ketiga baris + penanda tangan "Diketahui Oleh" tidak dicetak.
HP apoteker di config terbukti cocok: 0851 5637 9562 = `bkontak` KID 285707 "Leo Arif
Prasetyadi", orang yg sama dgn di kop.

**"Jenis" (`esalesorderu.SOUJENIS`) BELUM JELAS** - PO contoh `SOUJENIS=1` dicetak "Produk
OTC"; data nyata cuma punya 0 (16 PO) & 1 (220 PO) dan form VB6 yg kita punya tidak menyebut
kolom itu sama sekali, jadi label utk 0 TIDAK DIKETAHUI. Dipetakan di `config/dokumen_print.php`,
nilai tak dikenal dicetak "-" (tidak ditebak). **Form PO kita belum punya field Jenis** ->
PO baru akan kosong; perlu konfirmasi user opsi Jenis-nya apa saja.

**Helper baru `terbilang()`** di `app/Support/helpers.php` (angka -> kata Indonesia, desimal
dibuang). Diuji: 6.500.000 -> "Enam Juta Lima Ratus Ribu" (cocok PDF asli), 11 "Sebelas",
19 "Sembilan Belas", 1000 "Seribu", 1500 "Seribu Lima Ratus", 105 "Seratus Lima",
2,5 M "Dua Milyar Lima Ratus Juta", 0 "Nol".

**Alur setelah simpan** (permintaan user): `PoForm::save()` dispatch `toast` ("PO ... berhasil
disimpan") LALU `confirm-print` -> modal "Cetak dokumennya sekarang?" -> kalau Ya, PDF dibuka
di **tab baru** (`window.open`) supaya tab Workspace tidak berpindah halaman. Pertanyaan cetak
HANYA muncul kalau user punya hak `print`. Listener `confirm-print` ada di `dias-helpers.js`,
generik - modul lain tinggal dispatch event yg sama dgn `{message,title,okText,url}`.

**Tombol Cetak permanen** (2026-09-26): di kolom Aksi `PoList` dan di header `PoForm` (hanya
kalau `$poId` sudah ada - PO baru yg belum disimpan tidak punya tombolnya). Keduanya
**`<a target="_blank">` biasa, BUKAN `wire:click`** - cetak tidak butuh round-trip ke server,
dan anchor tab-baru menjamin tab Workspace tidak ikut berpindah halaman. Digate
`can_do('purchase/po','print')`.

Verifikasi (skrip throwaway, 45 asersi LULUS): 10 kasus `terbilang()`; controller menghasilkan
PDF valid (31 KB) utk PO contoh; 27 potongan teks cetakan dicek satu per satu terhadap PDF asli
(kop/izin/supplier/alamat/27819/no transaksi/tanggal/Di Kirim Ke/Jenis/nama item/kemasan/harga/
sub total/terbilang/3 blok tanda tangan/HP/footer); Sub Total hitungan == `SOUSUBTOTAL`
(6.500.000) & Total Qty == 26 persis PDF asli; simpan PO baru men-dispatch `toast` +
`confirm-print` dan PO itu bisa dicetak. **Belum diverifikasi visual** (poppler tidak ada di
environment ini, PDF tidak bisa di-render jadi gambar) - kecocokan ISI sudah dipastikan lewat
render HTML-nya, tapi presisi tata letak (lebar kolom, posisi blok) perlu dilihat user.

## PO: tata letak header disamakan dgn VB6 + default mata uang RP (2026-09-26)

Permintaan user (dgn screenshot VB6 `dFrmOrderPembelian`). Dulu header form kita 3 kolom
seragam dgn label di samping; sekarang:
- **KIRI** (`col-xl-5`): Vendor / **Kontak** (dulu dinamai "Attention") / Alamat - label di
  SAMPING, lebar label dikunci 90px.
- **KANAN** (`col-xl-7`, kelas `.po-top` = label DI ATAS input, spt VB6):
  baris 1 = Pajak | Tanggal | No Transaksi; baris 2 = Kurs | Uang | Nilai Pajak |
  Bag Pembelian | Termin.
- **BAWAH** (sebaris dgn blok total): Catatan | No Ref | Gudang - VB6 jg menaruh Catatan &
  Gudang di bawah, bukan di header.

**Nilai Pajak sekarang SELALU tampil** (dulu `@if ($pajak !== 0)`), cuma di-`disabled` saat
Tanpa Pajak - supaya kolom baris 2 tidak loncat-loncat saat pilihan pajak diganti. Sama spt
VB6 yg comboboxnya memang selalu ada.

**Mata uang default RP** utk PO baru - dicari lewat `buang.UKODE='RP'`, BUKAN hardcode ID,
supaya tetap benar kalau isi tabel berubah. PO tersimpan tetap memakai `SOUUANG` dari DB
(diuji: tidak ditimpa default). Label dropdown tidak lagi "RP - RP": `UNAMA` cuma ditampilkan
kalau BEDA dari `UKODE` (di `buang` keduanya memang sering identik).

Verifikasi (2 skrip throwaway, 24 + 9 asersi LULUS, simpan di transaksi + rollback): ke-17
field ter-render, urutannya benar (Vendor kiri/Pajak kanan, Nilai Pajak -> Bag Pembelian ->
Termin, Catatan turun ke bawah sebaris blok total); setelah tata letak diubah SEMUA field
masih tersimpan benar (Kontak/Catatan/No Ref/Uang/Kurs/Pajak/Nilai Pajak/Termin/Gudang/
diskon %+Rp/2 baris item).

## PO: Diskon header 2 arah (Rp <-> %) (2026-09-26)

Permintaan user + layout VB6 `dFrmOrderPembelian`: **diskon header ada DI BAWAH Sub Total**
(bukan di blok header form spt sebelumnya), dgn DUA input - persen & rupiah - saling menghitung.

**Yg berubah dari sebelumnya**: dulu cuma ada 1 input `Diskon (Rp)` di fieldset header dan
kolom **`SOUDISKONPERSEN` TIDAK PERNAH DITULIS**. Sekarang keduanya diisi.

**LEBIH LENGKAP dari VB6**: VB6 cuma punya arah **Rp -> %** (`txtDiskon_LostFocus` baris 1329:
`txtDiskonPersen = txtDiskon / lblSubTotal * 100`). `txtDiskonPersen_LostFocus` (baris 1325-1327)
HANYA memformat angka - jadi di VB6 **mengetik persen tidak mengubah nilai diskon sama sekali**,
user harus hitung sendiri. Di sini dua arah.

**`$diskonMode` ('rp' | 'persen')** mencatat field mana yg TERAKHIR diketik user. Gunanya saat
**Sub Total berubah** (tambah/hapus baris, ubah qty/harga/disc baris): yg dihitung ulang hanya
field TURUNAN, field yg diketik user tidak pernah bergeser sendiri. Jadi "diskon 10%" tetap 10%
dan rupiahnya menyesuaikan; "diskon Rp 1.000" tetap Rp 1.000 dan persennya yg menyesuaikan.
VB6 tidak menghitung ulang sama sekali saat Sub Total berubah (persennya jadi basi).

Batas: persen di-clamp 0..100, rupiah di-clamp 0..Sub Total. Pajak tetap dihitung dari
**Sub Total SETELAH diskon** - sama VB6 baris 1121 (`zSubTotal = lblSubTotal - txtDiskon`).

Verifikasi (skrip throwaway, 19 asersi LULUS, simpan di transaksi + rollback): ketik 10% ->
Rp = 3.628,43 dari Sub Total 36.284,30; ketik Rp seperempat -> 25%; clamp 100%/Sub Total/negatif;
hapus baris saat mode persen -> % tetap 10, Rp turun 3.628,43 -> 2.264,34; tambah baris saat mode
rp -> Rp tetap 1.000, % jadi 2,756; `SOUDISKON` + `SOUDISKONPERSEN` KEDUANYA tersimpan & termuat
lagi saat PO dibuka ulang; pajak memakai subtotal setelah diskon.

## Referensi CI3

`C:\xampp\htdocs\dias-online-app\application\{controllers,models,views/modul}` — sumber kebenaran untuk field & alur
tiap master/transaksi. Petakan nama field CI3 → kolom legacy persis.

## Referensi spek Excel user

`C:\hafiz\PROMPT DIAS ERP LARAVEL\PROMPT.xlsx` — user kadang menaruh spesifikasi form (field, mapping kolom, pilihan
dropdown) per sheet (mis. sheet "PROMO"). **Cara baca `.xlsx` tanpa PhpSpreadsheet/Python (tidak ada di environment
ini)**: file `.xlsx` = zip - buka via PHP `ZipArchive`, baca `xl/workbook.xml` (nama sheet → rId) + `xl/_rels/workbook.xml.rels`
(rId → `worksheets/sheetN.xml`) + `xl/sharedStrings.xml` (index → teks) + `xl/worksheets/sheetN.xml` (`<c r="C5" t="s"><v>3</v></c>`
= sel C5 isinya `sharedStrings[3]`) via `SimpleXMLElement`. Skrip kecil di scratchpad, hapus setelah selesai baca.
