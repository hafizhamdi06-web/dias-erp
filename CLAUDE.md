# DIAS ERP (Laravel) — panduan proyek

Rebuild ERP klinik kecantikan NMW dengan **Laravel 12 + Livewire (class-based) + Blade + AdminLTE 4**.
Jalan **langsung di DB produksi `data_pos_nmw_2023`** (MariaDB). CI3 (`C:\xampp\htdocs\dias-online-app`)
dan CI4 (`C:\xampp\htdocs\dias-online-nmw`) jalan paralel di DB yang sama.

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
di sistem lama hardcode di template report. Ditaruh di **`config/po_print.php` per `NPID`**
(badan hukum), BUKAN di blade - SIPA = identitas apoteker yg BEDA per PT, salah cetak = fatal.
PT yg belum terdaftar -> ketiga baris + penanda tangan "Diketahui Oleh" tidak dicetak.
HP apoteker di config terbukti cocok: 0851 5637 9562 = `bkontak` KID 285707 "Leo Arif
Prasetyadi", orang yg sama dgn di kop.

**"Jenis" (`esalesorderu.SOUJENIS`) BELUM JELAS** - PO contoh `SOUJENIS=1` dicetak "Produk
OTC"; data nyata cuma punya 0 (16 PO) & 1 (220 PO) dan form VB6 yg kita punya tidak menyebut
kolom itu sama sekali, jadi label utk 0 TIDAK DIKETAHUI. Dipetakan di `config/po_print.php`,
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
