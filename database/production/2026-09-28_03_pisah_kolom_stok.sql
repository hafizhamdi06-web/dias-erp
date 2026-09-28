-- =============================================================================
--  dias-laravel - PISAH KOLOM STOK PER GUDANG
--  Dibuat 2026-09-28. Jalankan SATU KALI. WAJIB backup dulu.
--  Perbaikan trigger `fstokd` (statement kembar + gudang yang terlewat) SUDAH
--  TERMASUK di sini - tidak ada file terpisah untuk itu.
-- =============================================================================
--
--  MASALAHNYA
--  `F_KOLOMGUDANG()` - fungsi yang dipakai SEMUA aplikasi (dias-laravel maupun
--  CI3 dias-online-app) untuk menentukan kolom stok sebuah gudang - hanya
--  mendaftarkan sebagian gudang dan menutupnya dengan `ELSE 'ISTOKPG'`. Akibatnya
--  8 gudang MEMBACA kolom `ISTOKPG` yang sama (1 Petogogan, 6 Online, 14 Home
--  Care, 15 Pameran, 39 Gudang Depo Tindakan, 40 Gudang Kubis 1, 42 Depo Research,
--  49 Busura) dan 2 gudang membaca `ISTOKMP` (9 Gogobli, 18 Marketplace).
--
--  Trigger `fstokd` juga tidak konsisten dengan fungsi itu: sebagian gudang tidak
--  punya statement sama sekali (mutasinya hilang), dan `ISTOKBZ` (gudang 10) serta
--  `ISTOKDE` (gudang 20) punya statement KEMBAR sehingga tiap mutasi dihitung 2x
--  (terbukti di data: dari 237 item di gudang 20, 56 item nilainya tepat 2x netto).
--
--  YANG DIKERJAKAN FILE INI
--  1. Kolom stok sendiri untuk tiap gudang yang tadinya menumpang.
--  2. `F_KOLOMGUDANG()` ditulis ulang: 1:1 untuk SEMUA 49 gudang, dan `ELSE`
--     mengarah ke `ISTOKXX` - bukan lagi ke `ISTOKPG`. Jadi gudang baru yang
--     lupa dipetakan akan terlihat SALAH DI SATU TEMPAT, tidak lagi diam-diam
--     menumpuk ke Petogogan. INI inti perbaikannya.
--  3. Ketiga trigger `fstokd` dibangkitkan ulang 1:1 dari peta yang sama, sehingga
--     TULIS (trigger) dan BACA (fungsi) tidak bisa lagi berbeda.
--  4. `P_RESETSTOK*` diperbaiki (lihat BAGIAN 5 - ada bug destruktif di sana).
--
--  TIDAK ADA ANGKA YANG PERLU DIPECAH - ini sudah diperiksa
--  Dari 8 gudang yang berbagi `ISTOKPG`, hanya gudang 1 (2.033 mutasi) dan 42
--  (85 mutasi) yang punya mutasi. Mutasi gudang 42 BELUM PERNAH tertulis ke kolom
--  mana pun (tidak ada statement trigger untuknya), jadi `ISTOKPG` sekarang MURNI
--  Petogogan dan dibiarkan apa adanya. Idem `ISTOKMP`: gudang 9 Gogobli 0 mutasi,
--  jadi isinya murni gudang 18 Marketplace.
--
--  Mutasi yang selama ini hilang dan mulai tercatat setelah file ini:
--     gudang 42 Depo Research          -> ISTOKDR   85 mutasi, 11 item, netto -932.5
--
--  Kolom barunya SENGAJA dimulai dari 0, TIDAK diisi dari mutasi lama. Netto
--  mutasi itu negatif karena barangnya keluar tanpa pernah ada saldo awal yang
--  tercatat - mengisinya justru menanam angka yang salah. Penyesuaian saldo awal
--  1 Oktober yang akan menetapkan angka sebenarnya.
--
--  YANG TIDAK DIUBAH
--  * `ISTOKPG` (Petogogan) dan `ISTOKMP` (Marketplace) isinya tidak disentuh.
--  * `F_STOKPERGUDANG()` tidak disentuh.
--  * 9 kolom cadangan bertanggal (`ISTOK*31122016`) dibiarkan.
--  * Angka stok yang terlanjur salah TIDAK dikoreksi di sini - Penyesuaian saldo
--    awal 1 Oktober yang akan menimpanya.
-- =============================================================================


-- =============================================================================
--  WAJIB - jangan dihapus
-- =============================================================================
--  (1) `ALTER TABLE bitem` GAGAL dgn sql_mode default server ini:
--      `ERROR 1067 Invalid default value for 'IMODIFD'`. MariaDB memvalidasi ULANG
--      seluruh definisi tabel saat ALTER, dan kolom warisan `IMODIFD` punya default
--      `'0000-00-00'` yang ditolak oleh `NO_ZERO_DATE`.
--  (2) Trigger & rutin MENYIMPAN sql_mode yang berlaku saat dibuat, lalu memakainya
--      setiap kali dijalankan. Nilai di bawah PERSIS SAMA dengan yang tersimpan di
--      trigger `fstokd_*`, `F_KOLOMGUDANG` dan `P_RESETSTOK*` yang ada sekarang,
--      supaya yang baru berperilaku identik - bukan berubah diam-diam.
--  Hanya berlaku utk sesi ini, tidak mengubah setelan server.
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';


-- =============================================================================
--  BAGIAN 0 - `bitem` ROW_FORMAT: Compact -> DYNAMIC   (PRASYARAT, bukan pilihan)
-- =============================================================================
--  Tanpa langkah ini BAGIAN 1 GAGAL dengan:
--    ERROR 1118 Row size too large (> 8126)
--
--  `bitem` punya 222 kolom dan 4 kolom TEXT. Di ROW_FORMAT=Compact setiap TEXT
--  menyimpan awalan 768 byte DI DALAM baris (4 x 768 = 3.072 byte hanya untuk itu),
--  sehingga tabel ini sudah mentok batas 8.126 byte per baris. Setiap
--  `MODIFY COLUMN` memaksa tabel DIBANGUN ULANG dan batas itu diperiksa lagi -
--  jadi bukan cuma menambah kolom yang tertolak, memberi COMMENT pun tertolak.
--
--  Di ROW_FORMAT=DYNAMIC kolom TEXT hanya menyimpan penunjuk 20 byte, jadi ruang
--  barisnya lega kembali. DYNAMIC juga sudah menjadi default server ini
--  (`innodb_default_row_format=dynamic`) - `bitem` COMPACT adalah warisan dump lama.
--
--  Ini murni perubahan CARA PENYIMPANAN: data, tipe kolom, index dan hasil query
--  tidak berubah sama sekali. Tabel dibangun ulang sekali (5.580 baris / ~8 MB,
--  terukur 0,3 detik di mesin uji) dan tabel TERKUNCI selama itu.
ALTER TABLE `bitem` ROW_FORMAT=DYNAMIC;


-- =============================================================================
--  BAGIAN 1 - KOLOM STOK
-- =============================================================================

-- Kolom yang benar-benar baru. `DEFAULT 0` penting: trigger melakukan
-- `KOLOM = KOLOM + (...)`, kalau NULL hasilnya NULL bukan angka.
ALTER TABLE `bitem`
  ADD COLUMN `ISTOKDT` double DEFAULT 0 COMMENT 'Gudang 39 Gudang Depo Tindakan',
  ADD COLUMN `ISTOKKB` double DEFAULT 0 COMMENT 'Gudang 40 Gudang Kubis 1',
  ADD COLUMN `ISTOKDR` double DEFAULT 0 COMMENT 'Gudang 42 Depo Research',
  ADD COLUMN `ISTOKBS` double DEFAULT 0 COMMENT 'Gudang 49 Busura',
  ADD COLUMN `ISTOKXX` double DEFAULT 0 COMMENT 'Gudang yang BELUM dipetakan - lihat catatan file';

-- `ISTOKPM` sudah ada tapi bertipe int(11) - disamakan jadi double spt kolom stok lain.
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKPM` double DEFAULT 0 COMMENT 'Gudang 15 Pameran';

-- Kolom yang SUDAH ADA di skema tapi tidak pernah dipakai - sekarang dipakai
-- sesuai peruntukan yang sudah tersirat di namanya. Hanya diberi COMMENT.
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKOL` double DEFAULT 0 COMMENT 'Gudang 6 Online';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKGG` double DEFAULT 0 COMMENT 'Gudang 9 Gogobli';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKHC` double DEFAULT 0 COMMENT 'Gudang 14 Home Care';

-- Sisanya hanya diberi COMMENT supaya pemetaan gudang->kolom bisa dibaca
-- langsung dari `SHOW FULL COLUMNS FROM bitem` tanpa membuka fungsi.
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKPG` double DEFAULT 0 COMMENT 'Gudang 1 Petogogan';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCP` double DEFAULT 0 COMMENT 'Gudang 2 Ciputat';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKDP` double DEFAULT 0 COMMENT 'Gudang 3 Depok';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKKM` double DEFAULT 0 COMMENT 'Gudang 4 Kalimalang';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKJG` double DEFAULT 0 COMMENT 'Gudang 5 Yogyakarta';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBA` double DEFAULT 0 COMMENT 'Gudang 7 Bintaro';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBB` double DEFAULT 0 COMMENT 'Gudang 8 Bintaro Lt 2';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBZ` double DEFAULT 0 COMMENT 'Gudang 10 Bizpark';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCL` double DEFAULT 0 COMMENT 'Gudang 11 Ciledug';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKKG` double DEFAULT 0 COMMENT 'Gudang 12 Kelapa Gading';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCB` double DEFAULT 0 COMMENT 'Gudang 13 Cibubur';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKTP` double DEFAULT 0 COMMENT 'Gudang 16 Tokopedia';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCN` double DEFAULT 0 COMMENT 'Gudang 17 Cinere';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKMP` double DEFAULT 0 COMMENT 'Gudang 18 Marketplace';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKLW` double DEFAULT 0 COMMENT 'Gudang 19 Living World';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKDE` double DEFAULT 0 COMMENT 'Gudang 20 Depo';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKPP` double DEFAULT 0 COMMENT 'Gudang 21 Pos Pengumben';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKTB` double DEFAULT 0 COMMENT 'Gudang 22 Tebet';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCK` double DEFAULT 0 COMMENT 'Gudang 23 Cikarang';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBD` double DEFAULT 0 COMMENT 'Gudang 24 Bandung KP';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKGB` double DEFAULT 0 COMMENT 'Gudang 25 Gudang Bahan Baku';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKSB` double DEFAULT 0 COMMENT 'Gudang 26 Surabaya';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKLB` double DEFAULT 0 COMMENT 'Gudang 27 Lab Rhein Medika';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBL` double DEFAULT 0 COMMENT 'Gudang 28 Bali';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKKR` double DEFAULT 0 COMMENT 'Gudang 29 Cimone';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKCG` double DEFAULT 0 COMMENT 'Gudang 30 Cengkareng';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRM` double DEFAULT 0 COMMENT 'Gudang 31 Rawamangun';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKNH` double DEFAULT 0 COMMENT 'Gudang 32 National Hospital';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKWN` double DEFAULT 0 COMMENT 'Gudang 33 Web NMW';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRBB` double DEFAULT 0 COMMENT 'Gudang 34 RII Bahan Baku';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRPR` double DEFAULT 0 COMMENT 'Gudang 35 RII Produksi';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRSJ` double DEFAULT 0 COMMENT 'Gudang 36 RII Sample Bahan Jadi';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRSB` double DEFAULT 0 COMMENT 'Gudang 37 RII Sample Bahan Baku';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKRK` double DEFAULT 0 COMMENT 'Gudang 38 Rhein Medika Sektor 9';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKNL` double DEFAULT 0 COMMENT 'Gudang 41 NL Kemang';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBR` double DEFAULT 0 COMMENT 'Gudang 43 Bandung BR';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBG` double DEFAULT 0 COMMENT 'Gudang 44 Bogor';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKOF` double DEFAULT 0 COMMENT 'Gudang 45 OFL';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKDF` double DEFAULT 0 COMMENT 'Gudang 46 Depo Farmasi';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKBW` double DEFAULT 0 COMMENT 'Gudang 47 Brawijaya';
ALTER TABLE `bitem` MODIFY COLUMN `ISTOKDG` double DEFAULT 0 COMMENT 'Gudang 48 DAPS Petogogan';


-- =============================================================================
--  BAGIAN 2 - NOLKAN NILAI BASI DI KOLOM YANG DIPAKAI ULANG
-- =============================================================================
--  Gudangnya selama ini MEMBACA `ISTOKPG`, jadi isi kolom ini tidak terlihat di
--  mana pun. Setelah dipisah kolomnya mulai dibaca - tanpa langkah ini stok hantu
--  tiba-tiba muncul di gudang tersebut. Gudangnya juga NOL mutasi di `fstokd`,
--  jadi stok yang benar memang 0.

-- `ISTOKOL`: 106 item bernilai (jumlah -3846) - sisa data lama.
UPDATE `bitem` SET `ISTOKOL` = 0 WHERE `ISTOKOL` <> 0;


-- =============================================================================
--  BAGIAN 3 - F_KOLOMGUDANG() : 1:1 untuk semua gudang
-- =============================================================================
--  Karakteristik dipertahankan sama dgn yang sekarang (NOT DETERMINISTIC, NO SQL)
--  supaya tidak perlu `log_bin_trust_function_creators` di production.
--  Sengaja TIDAK membaca tabel: fungsi ini dipanggil per baris di beberapa query.

DELIMITER $$

DROP FUNCTION IF EXISTS `F_KOLOMGUDANG`$$
CREATE FUNCTION `F_KOLOMGUDANG`(IDCABANG INT) RETURNS VARCHAR(20)
    NOT DETERMINISTIC
    NO SQL
BEGIN
  RETURN CASE IDCABANG
    WHEN  1 THEN 'ISTOKPG'   -- Petogogan
    WHEN  2 THEN 'ISTOKCP'   -- Ciputat
    WHEN  3 THEN 'ISTOKDP'   -- Depok
    WHEN  4 THEN 'ISTOKKM'   -- Kalimalang
    WHEN  5 THEN 'ISTOKJG'   -- Yogyakarta
    WHEN  6 THEN 'ISTOKOL'   -- Online (non-aktif)
    WHEN  7 THEN 'ISTOKBA'   -- Bintaro
    WHEN  8 THEN 'ISTOKBB'   -- Bintaro Lt 2 (non-aktif)
    WHEN  9 THEN 'ISTOKGG'   -- Gogobli (non-aktif)
    WHEN 10 THEN 'ISTOKBZ'   -- Bizpark
    WHEN 11 THEN 'ISTOKCL'   -- Ciledug
    WHEN 12 THEN 'ISTOKKG'   -- Kelapa Gading
    WHEN 13 THEN 'ISTOKCB'   -- Cibubur
    WHEN 14 THEN 'ISTOKHC'   -- Home Care (non-aktif)
    WHEN 15 THEN 'ISTOKPM'   -- Pameran (non-aktif)
    WHEN 16 THEN 'ISTOKTP'   -- Tokopedia (non-aktif)
    WHEN 17 THEN 'ISTOKCN'   -- Cinere
    WHEN 18 THEN 'ISTOKMP'   -- Marketplace
    WHEN 19 THEN 'ISTOKLW'   -- Living World (non-aktif)
    WHEN 20 THEN 'ISTOKDE'   -- Depo
    WHEN 21 THEN 'ISTOKPP'   -- Pos Pengumben
    WHEN 22 THEN 'ISTOKTB'   -- Tebet
    WHEN 23 THEN 'ISTOKCK'   -- Cikarang (non-aktif)
    WHEN 24 THEN 'ISTOKBD'   -- Bandung KP (non-aktif)
    WHEN 25 THEN 'ISTOKGB'   -- Gudang Bahan Baku (non-aktif)
    WHEN 26 THEN 'ISTOKSB'   -- Surabaya
    WHEN 27 THEN 'ISTOKLB'   -- Lab Rhein Medika (non-aktif)
    WHEN 28 THEN 'ISTOKBL'   -- Bali
    WHEN 29 THEN 'ISTOKKR'   -- Cimone
    WHEN 30 THEN 'ISTOKCG'   -- Cengkareng
    WHEN 31 THEN 'ISTOKRM'   -- Rawamangun
    WHEN 32 THEN 'ISTOKNH'   -- National Hospital
    WHEN 33 THEN 'ISTOKWN'   -- Web NMW (non-aktif)
    WHEN 34 THEN 'ISTOKRBB'  -- RII Bahan Baku
    WHEN 35 THEN 'ISTOKRPR'  -- RII Produksi
    WHEN 36 THEN 'ISTOKRSJ'  -- RII Sample Bahan Jadi
    WHEN 37 THEN 'ISTOKRSB'  -- RII Sample Bahan Baku
    WHEN 38 THEN 'ISTOKRK'   -- Rhein Medika Sektor 9
    WHEN 39 THEN 'ISTOKDT'   -- Gudang Depo Tindakan
    WHEN 40 THEN 'ISTOKKB'   -- Gudang Kubis 1
    WHEN 41 THEN 'ISTOKNL'   -- NL Kemang (non-aktif)
    WHEN 42 THEN 'ISTOKDR'   -- Depo Research
    WHEN 43 THEN 'ISTOKBR'   -- Bandung BR (non-aktif)
    WHEN 44 THEN 'ISTOKBG'   -- Bogor (non-aktif)
    WHEN 45 THEN 'ISTOKOF'   -- OFL (non-aktif)
    WHEN 46 THEN 'ISTOKDF'   -- Depo Farmasi
    WHEN 47 THEN 'ISTOKBW'   -- Brawijaya
    WHEN 48 THEN 'ISTOKDG'   -- DAPS Petogogan (non-aktif)
    WHEN 49 THEN 'ISTOKBS'   -- Busura (non-aktif)
    -- Gudang yang BELUM dipetakan. Dulu `ELSE 'ISTOKPG'` - itulah sebabnya 7
    -- gudang diam-diam menumpuk ke Petogogan tanpa ada yang sadar. Sekarang
    -- salahnya berkumpul di satu kolom yang jelas & bisa diaudit.
    ELSE 'ISTOKXX'
  END;
END$$

DELIMITER ;


-- =============================================================================
--  BAGIAN 4 - TRIGGER `fstokd` : dibangkitkan 1:1 dari peta yang sama
-- =============================================================================
--  Seluruh logika NON-STOK di dalam trigger DISALIN APA ADANYA - hanya statement
--  `update bitem set ISTOK...` yang diganti: qty terima PR (`PBDQTYTERIMA`),
--  pemakaian PKB (`PKBDQTYPAKAI`), `official_nmw.ops_invoice_header`,
--  `esalesorderd.sodmasuk`, `fproduksid.PDMASUKPAKAI/PDKELUARPAKAI`, blok IF
--  item 5076/5720. Semua itu diverifikasi identik oleh skrip uji.

DELIMITER $$

-- ----- fstokd_add (AFTER INSERT) -----
DROP TRIGGER IF EXISTS `fstokd_add`$$
CREATE TRIGGER `fstokd_add` AFTER INSERT ON `fstokd` FOR EACH ROW
begin

  DECLARE tmpVar INTEGER; 
  
       -- terapkan mutasi
  update bitem set ISTOKPG=ISTOKPG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=1;
  update bitem set ISTOKCP=ISTOKCP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=2;
  update bitem set ISTOKDP=ISTOKDP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=3;
  update bitem set ISTOKKM=ISTOKKM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=4;
  update bitem set ISTOKJG=ISTOKJG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=5;
  update bitem set ISTOKOL=ISTOKOL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=6;
  update bitem set ISTOKBA=ISTOKBA+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=7;
  update bitem set ISTOKBB=ISTOKBB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=8;
  update bitem set ISTOKGG=ISTOKGG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=9;
  update bitem set ISTOKBZ=ISTOKBZ+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=10;
  update bitem set ISTOKCL=ISTOKCL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=11;
  update bitem set ISTOKKG=ISTOKKG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=12;
  update bitem set ISTOKCB=ISTOKCB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=13;
  update bitem set ISTOKHC=ISTOKHC+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=14;
  update bitem set ISTOKPM=ISTOKPM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=15;
  update bitem set ISTOKTP=ISTOKTP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=16;
  update bitem set ISTOKCN=ISTOKCN+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=17;
  update bitem set ISTOKMP=ISTOKMP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=18;
  update bitem set ISTOKLW=ISTOKLW+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=19;
  update bitem set ISTOKDE=ISTOKDE+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=20;
  update bitem set ISTOKPP=ISTOKPP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=21;
  update bitem set ISTOKTB=ISTOKTB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=22;
  update bitem set ISTOKCK=ISTOKCK+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=23;
  update bitem set ISTOKBD=ISTOKBD+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=24;
  update bitem set ISTOKGB=ISTOKGB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=25;
  update bitem set ISTOKSB=ISTOKSB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=26;
  update bitem set ISTOKLB=ISTOKLB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=27;
  update bitem set ISTOKBL=ISTOKBL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=28;
  update bitem set ISTOKKR=ISTOKKR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=29;
  update bitem set ISTOKCG=ISTOKCG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=30;
  update bitem set ISTOKRM=ISTOKRM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=31;
  update bitem set ISTOKNH=ISTOKNH+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=32;
  update bitem set ISTOKWN=ISTOKWN+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=33;
  update bitem set ISTOKRBB=ISTOKRBB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=34;
  update bitem set ISTOKRPR=ISTOKRPR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=35;
  update bitem set ISTOKRSJ=ISTOKRSJ+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=36;
  update bitem set ISTOKRSB=ISTOKRSB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=37;
  update bitem set ISTOKRK=ISTOKRK+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=38;
  update bitem set ISTOKDT=ISTOKDT+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=39;
  update bitem set ISTOKKB=ISTOKKB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=40;
  update bitem set ISTOKNL=ISTOKNL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=41;
  update bitem set ISTOKDR=ISTOKDR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=42;
  update bitem set ISTOKBR=ISTOKBR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=43;
  update bitem set ISTOKBG=ISTOKBG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=44;
  update bitem set ISTOKOF=ISTOKOF+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=45;
  update bitem set ISTOKDF=ISTOKDF+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=46;
  update bitem set ISTOKBW=ISTOKBW+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=47;
  update bitem set ISTOKDG=ISTOKDG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=48;
  update bitem set ISTOKBS=ISTOKBS+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG=49;
  -- gudang yg belum dipetakan (harus tidak pernah kena - lihat catatan file)
  update bitem set ISTOKXX=ISTOKXX+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where IID=NEW.SDITEM and NEW.SDGUDANG NOT IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49);

  
  
   
    
  
    
   
  
  
    
     
   
  
    
  
  
  
    
  
  
  
  
  
 
  
      
        
   
         
   
   
   
        
        
        
       
        
         
          
    
 



   
  update fpermintaanbarangd set PBDQTYTERIMA=PBDQTYTERIMA+ (NEW.SDMASUK+new.sdkeluar) where PBDID = NEW.SDPBDID ;
  
  update fperintahkirimbarangd set PKBDQTYPAKAI=PKBDQTYPAKAI+ NEW.SDKELUAR where PKBDID = NEW.SDSODID ;  
   
  update official_nmw.ops_invoice_header set ivhStatusDIAS=1 where ivhId=NEW.SDPRDID ; 
  
   UPDATE esalesorderd set sodmasuk = sodmasuk + new.sdmasukpb where sodid = new.sdsodid ; 
   
     
   
       if ( NEW.SDITEM = 5076  or  NEW.SDITEM = 5720)  THEN               
   update bkontak set    kaktif = 1, KTGLTRANSAKHIR=F_Tanggalsu(  NEW.SDIDSU), ktipe=12 
     where kid= F_IDPASIEN(NEW.SDIDSU)   ; 
     END IF; 
  
   
    
     
     UPDATE fproduksid set PDMASUKPAKAI = PDMASUKPAKAI + NEW.SDMASUK WHERE PDID=NEW.SDIDJOP ;
      UPDATE fproduksid set PDKELUARPAKAI = PDKELUARPAKAI + NEW.SDKELUAR WHERE PDID=NEW.SDIDJOP ;
      
      
       
end$$


-- ----- fstokd_edit (AFTER UPDATE) -----
DROP TRIGGER IF EXISTS `fstokd_edit`$$
CREATE TRIGGER `fstokd_edit` AFTER UPDATE ON `fstokd` FOR EACH ROW
begin

  DECLARE tmpVar INTEGER;
  
    -- kembalikan nilai LAMA
  update bitem set ISTOKPG=ISTOKPG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=1;
  update bitem set ISTOKCP=ISTOKCP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=2;
  update bitem set ISTOKDP=ISTOKDP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=3;
  update bitem set ISTOKKM=ISTOKKM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=4;
  update bitem set ISTOKJG=ISTOKJG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=5;
  update bitem set ISTOKOL=ISTOKOL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=6;
  update bitem set ISTOKBA=ISTOKBA-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=7;
  update bitem set ISTOKBB=ISTOKBB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=8;
  update bitem set ISTOKGG=ISTOKGG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=9;
  update bitem set ISTOKBZ=ISTOKBZ-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=10;
  update bitem set ISTOKCL=ISTOKCL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=11;
  update bitem set ISTOKKG=ISTOKKG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=12;
  update bitem set ISTOKCB=ISTOKCB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=13;
  update bitem set ISTOKHC=ISTOKHC-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=14;
  update bitem set ISTOKPM=ISTOKPM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=15;
  update bitem set ISTOKTP=ISTOKTP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=16;
  update bitem set ISTOKCN=ISTOKCN-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=17;
  update bitem set ISTOKMP=ISTOKMP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=18;
  update bitem set ISTOKLW=ISTOKLW-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=19;
  update bitem set ISTOKDE=ISTOKDE-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=20;
  update bitem set ISTOKPP=ISTOKPP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=21;
  update bitem set ISTOKTB=ISTOKTB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=22;
  update bitem set ISTOKCK=ISTOKCK-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=23;
  update bitem set ISTOKBD=ISTOKBD-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=24;
  update bitem set ISTOKGB=ISTOKGB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=25;
  update bitem set ISTOKSB=ISTOKSB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=26;
  update bitem set ISTOKLB=ISTOKLB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=27;
  update bitem set ISTOKBL=ISTOKBL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=28;
  update bitem set ISTOKKR=ISTOKKR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=29;
  update bitem set ISTOKCG=ISTOKCG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=30;
  update bitem set ISTOKRM=ISTOKRM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=31;
  update bitem set ISTOKNH=ISTOKNH-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=32;
  update bitem set ISTOKWN=ISTOKWN-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=33;
  update bitem set ISTOKRBB=ISTOKRBB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=34;
  update bitem set ISTOKRPR=ISTOKRPR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=35;
  update bitem set ISTOKRSJ=ISTOKRSJ-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=36;
  update bitem set ISTOKRSB=ISTOKRSB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=37;
  update bitem set ISTOKRK=ISTOKRK-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=38;
  update bitem set ISTOKDT=ISTOKDT-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=39;
  update bitem set ISTOKKB=ISTOKKB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=40;
  update bitem set ISTOKNL=ISTOKNL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=41;
  update bitem set ISTOKDR=ISTOKDR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=42;
  update bitem set ISTOKBR=ISTOKBR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=43;
  update bitem set ISTOKBG=ISTOKBG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=44;
  update bitem set ISTOKOF=ISTOKOF-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=45;
  update bitem set ISTOKDF=ISTOKDF-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=46;
  update bitem set ISTOKBW=ISTOKBW-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=47;
  update bitem set ISTOKDG=ISTOKDG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=48;
  update bitem set ISTOKBS=ISTOKBS-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=49;
  -- gudang yg belum dipetakan (harus tidak pernah kena - lihat catatan file)
  update bitem set ISTOKXX=ISTOKXX-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG NOT IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49);

  -- terapkan nilai BARU
  update bitem set ISTOKPG=ISTOKPG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=1;
  update bitem set ISTOKCP=ISTOKCP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=2;
  update bitem set ISTOKDP=ISTOKDP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=3;
  update bitem set ISTOKKM=ISTOKKM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=4;
  update bitem set ISTOKJG=ISTOKJG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=5;
  update bitem set ISTOKOL=ISTOKOL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=6;
  update bitem set ISTOKBA=ISTOKBA+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=7;
  update bitem set ISTOKBB=ISTOKBB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=8;
  update bitem set ISTOKGG=ISTOKGG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=9;
  update bitem set ISTOKBZ=ISTOKBZ+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=10;
  update bitem set ISTOKCL=ISTOKCL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=11;
  update bitem set ISTOKKG=ISTOKKG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=12;
  update bitem set ISTOKCB=ISTOKCB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=13;
  update bitem set ISTOKHC=ISTOKHC+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=14;
  update bitem set ISTOKPM=ISTOKPM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=15;
  update bitem set ISTOKTP=ISTOKTP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=16;
  update bitem set ISTOKCN=ISTOKCN+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=17;
  update bitem set ISTOKMP=ISTOKMP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=18;
  update bitem set ISTOKLW=ISTOKLW+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=19;
  update bitem set ISTOKDE=ISTOKDE+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=20;
  update bitem set ISTOKPP=ISTOKPP+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=21;
  update bitem set ISTOKTB=ISTOKTB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=22;
  update bitem set ISTOKCK=ISTOKCK+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=23;
  update bitem set ISTOKBD=ISTOKBD+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=24;
  update bitem set ISTOKGB=ISTOKGB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=25;
  update bitem set ISTOKSB=ISTOKSB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=26;
  update bitem set ISTOKLB=ISTOKLB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=27;
  update bitem set ISTOKBL=ISTOKBL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=28;
  update bitem set ISTOKKR=ISTOKKR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=29;
  update bitem set ISTOKCG=ISTOKCG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=30;
  update bitem set ISTOKRM=ISTOKRM+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=31;
  update bitem set ISTOKNH=ISTOKNH+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=32;
  update bitem set ISTOKWN=ISTOKWN+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=33;
  update bitem set ISTOKRBB=ISTOKRBB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=34;
  update bitem set ISTOKRPR=ISTOKRPR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=35;
  update bitem set ISTOKRSJ=ISTOKRSJ+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=36;
  update bitem set ISTOKRSB=ISTOKRSB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=37;
  update bitem set ISTOKRK=ISTOKRK+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=38;
  update bitem set ISTOKDT=ISTOKDT+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=39;
  update bitem set ISTOKKB=ISTOKKB+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=40;
  update bitem set ISTOKNL=ISTOKNL+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=41;
  update bitem set ISTOKDR=ISTOKDR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=42;
  update bitem set ISTOKBR=ISTOKBR+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=43;
  update bitem set ISTOKBG=ISTOKBG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=44;
  update bitem set ISTOKOF=ISTOKOF+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=45;
  update bitem set ISTOKDF=ISTOKDF+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=46;
  update bitem set ISTOKBW=ISTOKBW+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=47;
  update bitem set ISTOKDG=ISTOKDG+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=48;
  update bitem set ISTOKBS=ISTOKBS+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG=49;
  -- gudang yg belum dipetakan (harus tidak pernah kena - lihat catatan file)
  update bitem set ISTOKXX=ISTOKXX+(new.SDMASUK-( IF(new.SDDARIPAKET<> 0 AND new.SDKEDATANGAN = 0,0,new.sdkeluar) )) where NEW.SDCANCEL=0 AND IID=NEW.SDITEM and NEW.SDGUDANG NOT IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49);

  
  
   
  
   
    
   
   
    
   
     
    
     
  
  
  
  
  
  
  
   
  
   
 
  
  
   
  
         
   
   
   
  
  
  
  
    
 
 
 
     
  
  
  
   
  
  
    
   
   
   
      
   
    
      
    
  
  
   
  
  
  
  
  
  

 
        
        
    
     
         
   
   
   
  
         
       
       
          
         
          
      
   
  update fpermintaanbarangd set PBDQTYTERIMA=PBDQTYTERIMA- (OLD.SDMASUK+old.sdkeluar) where PBDID = OLD.SDPBDID ; 
  update fpermintaanbarangd set PBDQTYTERIMA=PBDQTYTERIMA+ (NEW.SDMASUK+new.sdkeluar) where PBDID = NEW.SDPBDID ; 
  
   update official_nmw.ops_invoice_header set ivhStatusDIAS=0 where ivhId=OLD.SDPRDID ;
   update official_nmw.ops_invoice_header set ivhStatusDIAS=1 where ivhId=NEW.SDPRDID ;  
   
   
  update fperintahkirimbarangd set PKBDQTYPAKAI=PKBDQTYPAKAI- OLD.SDKELUAR where PKBDID = OLD.SDSODID ;  
  update fperintahkirimbarangd set PKBDQTYPAKAI=PKBDQTYPAKAI+ NEW.SDKELUAR where PKBDID = NEW.SDSODID ;  
   
    
     
   
     
     
    UPDATE fproduksid set PDMASUKPAKAI = PDMASUKPAKAI - OLD.SDMASUK WHERE PDID=OLD.SDIDJOP ;
    UPDATE fproduksid set PDMASUKPAKAI = PDMASUKPAKAI + NEW.SDMASUK WHERE PDID=NEW.SDIDJOP ;
   
   UPDATE fproduksid set PDKELUARPAKAI = PDKELUARPAKAI - OLD.SDKELUAR WHERE PDID=OLD.SDIDJOP ;
    UPDATE fproduksid set PDKELUARPAKAI = PDKELUARPAKAI + NEW.SDKELUAR WHERE PDID=NEW.SDIDJOP ;
 
      
                                                                                          
   UPDATE esalesorderd set sodmasuk = sodmasuk - old.sdmasukpb where sodid = old.sdsodid ; 
   UPDATE esalesorderd set sodmasuk = sodmasuk + new.sdmasukpb where sodid = new.sdsodid ; 
    
   
     
   
       if ( NEW.SDITEM = 5076  or  NEW.SDITEM = 5720)  THEN               
   update bkontak set    kaktif = 1, KTGLTRANSAKHIR=F_Tanggalsu(  NEW.SDIDSU), ktipe=12 
     where kid= F_IDPASIEN(NEW.SDIDSU)   ; 
     END IF;  
         
   
              
   
end$$


-- ----- fstokd_DELL (AFTER DELETE) -----
DROP TRIGGER IF EXISTS `fstokd_DELL`$$
CREATE TRIGGER `fstokd_DELL` AFTER DELETE ON `fstokd` FOR EACH ROW
begin

  DECLARE tmpVar INTEGER; 
  
     -- terapkan mutasi
  update bitem set ISTOKPG=ISTOKPG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=1;
  update bitem set ISTOKCP=ISTOKCP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=2;
  update bitem set ISTOKDP=ISTOKDP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=3;
  update bitem set ISTOKKM=ISTOKKM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=4;
  update bitem set ISTOKJG=ISTOKJG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=5;
  update bitem set ISTOKOL=ISTOKOL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=6;
  update bitem set ISTOKBA=ISTOKBA-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=7;
  update bitem set ISTOKBB=ISTOKBB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=8;
  update bitem set ISTOKGG=ISTOKGG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=9;
  update bitem set ISTOKBZ=ISTOKBZ-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=10;
  update bitem set ISTOKCL=ISTOKCL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=11;
  update bitem set ISTOKKG=ISTOKKG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=12;
  update bitem set ISTOKCB=ISTOKCB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=13;
  update bitem set ISTOKHC=ISTOKHC-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=14;
  update bitem set ISTOKPM=ISTOKPM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=15;
  update bitem set ISTOKTP=ISTOKTP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=16;
  update bitem set ISTOKCN=ISTOKCN-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=17;
  update bitem set ISTOKMP=ISTOKMP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=18;
  update bitem set ISTOKLW=ISTOKLW-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=19;
  update bitem set ISTOKDE=ISTOKDE-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=20;
  update bitem set ISTOKPP=ISTOKPP-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=21;
  update bitem set ISTOKTB=ISTOKTB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=22;
  update bitem set ISTOKCK=ISTOKCK-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=23;
  update bitem set ISTOKBD=ISTOKBD-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=24;
  update bitem set ISTOKGB=ISTOKGB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=25;
  update bitem set ISTOKSB=ISTOKSB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=26;
  update bitem set ISTOKLB=ISTOKLB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=27;
  update bitem set ISTOKBL=ISTOKBL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=28;
  update bitem set ISTOKKR=ISTOKKR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=29;
  update bitem set ISTOKCG=ISTOKCG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=30;
  update bitem set ISTOKRM=ISTOKRM-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=31;
  update bitem set ISTOKNH=ISTOKNH-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=32;
  update bitem set ISTOKWN=ISTOKWN-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=33;
  update bitem set ISTOKRBB=ISTOKRBB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=34;
  update bitem set ISTOKRPR=ISTOKRPR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=35;
  update bitem set ISTOKRSJ=ISTOKRSJ-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=36;
  update bitem set ISTOKRSB=ISTOKRSB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=37;
  update bitem set ISTOKRK=ISTOKRK-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=38;
  update bitem set ISTOKDT=ISTOKDT-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=39;
  update bitem set ISTOKKB=ISTOKKB-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=40;
  update bitem set ISTOKNL=ISTOKNL-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=41;
  update bitem set ISTOKDR=ISTOKDR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=42;
  update bitem set ISTOKBR=ISTOKBR-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=43;
  update bitem set ISTOKBG=ISTOKBG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=44;
  update bitem set ISTOKOF=ISTOKOF-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=45;
  update bitem set ISTOKDF=ISTOKDF-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=46;
  update bitem set ISTOKBW=ISTOKBW-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=47;
  update bitem set ISTOKDG=ISTOKDG-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=48;
  update bitem set ISTOKBS=ISTOKBS-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG=49;
  -- gudang yg belum dipetakan (harus tidak pernah kena - lihat catatan file)
  update bitem set ISTOKXX=ISTOKXX-(old.SDMASUK-( IF(old.SDDARIPAKET<> 0 AND old.SDKEDATANGAN = 0,0,old.sdkeluar) )) where IID=old.SDITEM and old.SDGUDANG NOT IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49);

  
  
   
    
   
    
   
  
   
   
    
    
     
  
  
  
  
  
  
  
   
  
  
 
  
 
  
    
         
   
   
   
  
  
 
 
    
 
 
 
              
         update fpermintaanbarangd set PBDQTYTERIMA=PBDQTYTERIMA- (OLD.SDMASUK+old.sdkeluar) where PBDID = OLD.SDPBDID ;       
                                                                                                           
  update fperintahkirimbarangd set PKBDQTYPAKAI=PKBDQTYPAKAI- OLd.SDKELUAR where PKBDID = OLD.SDSODID ;  
  update official_nmw.ops_invoice_header set ivhStatusDIAS=0 where ivhId=OLD.SDPRDID ;
 
  UPDATE fproduksid set PDMASUKPAKAI = PDMASUKPAKAI - OLD.SDMASUK WHERE PDID=OLD.SDIDJOP ;
  UPDATE fproduksid set PDKELUARPAKAI = PDKELUARPAKAI - OLD.SDKELUAR WHERE PDID=OLD.SDIDJOP ;
   
   UPDATE esalesorderd set sodmasuk = sodmasuk - old.sdmasukpb where sodid = old.sdsodid ; 
   

end$$


DELIMITER ;


-- =============================================================================
--  BAGIAN 5 - P_RESETSTOK* : bug destruktif + ikut peta baru
-- =============================================================================
--  KENAPA HARUS DISENTUH
--  Ketiga prosedur ini MENULIS ke kolom stok dengan pemetaan gudang->kolom yang
--  ditulis keras di dalamnya. Setelah kolom dipisah, pemetaan itu jadi salah.
--
--  Selain itu ada bug yang memang sudah ada sekarang:
--    * `P_RESETSTOKPERBARANG(IDBARANG)` punya 11 `UPDATE bitem SET ... =
--      F_STOKPERGUDANG(n, IDBARANG)` TANPA `WHERE`. Artinya stok SATU barang yang
--      dikirim sebagai parameter ditimpakan ke SELURUH 5.580 barang.
--    * Cabang `IDGUDANG=1` di `P_RESETSTOKPERBARANGGUDANG` juga tanpa `WHERE`,
--      dan masih menjumlahkan gudang 1+6 (dan 9+18) - penggabungan yang justru
--      sedang kita bongkar.
--  Tidak ada aplikasi yang memanggilnya (sudah dicek: dias-laravel, dias-erp,
--  dias-online-app) - jadi bug ini belum pernah meledak. Tetap diperbaiki supaya
--  tidak ada yang memanggilnya nanti dan merusak seluruh tabel.
--
--  CARA BARU: kolom tujuan diambil dari `F_KOLOMGUDANG()` lewat SQL dinamis, jadi
--  prosedurnya OTOMATIS ikut kalau ada gudang baru - tidak ada daftar kolom yang
--  bisa basi lagi.
--
--  !! PERINGATAN - JANGAN dijalankan sembarangan !!
--  Prosedur ini MENGHITUNG ULANG stok murni dari `fstokd`. Di database ini itu
--  BUKAN angka yang benar: stok sebelum pertengahan 2026 tidak punya jejak mutasi
--  (hanya 31 dari 2.872 item yang `ISTOKPG`-nya sama dgn netto mutasi gudang 1).
--  Menjalankannya akan MENGUBAH stok besar-besaran. Diperbaiki agar aman kalau
--  dipanggil, bukan supaya dipakai.

DELIMITER $$

-- Satu barang, satu gudang. Kolom dari F_KOLOMGUDANG + `WHERE IID=` yang tadinya hilang.
DROP PROCEDURE IF EXISTS `P_RESETSTOKPERBARANGGUDANG`$$
CREATE PROCEDURE `P_RESETSTOKPERBARANGGUDANG`(IN IDBARANG INT, IN IDGUDANG INT)
BEGIN
  DECLARE KOL VARCHAR(20);
  SET KOL = F_KOLOMGUDANG(IDGUDANG);
  SET @sqlreset = CONCAT('UPDATE bitem SET `', KOL, '` = F_STOKPERGUDANG(',
                         IDGUDANG, ',', IDBARANG, ') WHERE IID = ', IDBARANG);
  PREPARE st FROM @sqlreset;
  EXECUTE st;
  DEALLOCATE PREPARE st;
END$$

-- Satu barang, SEMUA gudang (dulu hanya 11 gudang, dan tanpa WHERE).
DROP PROCEDURE IF EXISTS `P_RESETSTOKPERBARANG`$$
CREATE PROCEDURE `P_RESETSTOKPERBARANG`(IN IDBARANG INT)
BEGIN
  DECLARE SELESAI INT DEFAULT 0;
  DECLARE GD INT;
  DECLARE CR CURSOR FOR SELECT GID FROM bgudang ORDER BY GID;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET SELESAI = 1;
  OPEN CR;
  ULANG: LOOP
    FETCH CR INTO GD;
    IF SELESAI = 1 THEN LEAVE ULANG; END IF;
    CALL P_RESETSTOKPERBARANGGUDANG(IDBARANG, GD);
  END LOOP;
  CLOSE CR;
END$$

-- SEMUA barang, SEMUA gudang (dulu hanya 5 gudang).
-- LAMBAT: F_STOKPERGUDANG() satu query per baris -> ~5.580 x jumlah gudang.
DROP PROCEDURE IF EXISTS `P_RESETSTOK`$$
CREATE PROCEDURE `P_RESETSTOK`()
BEGIN
  DECLARE SELESAI INT DEFAULT 0;
  DECLARE GD INT;
  DECLARE KOL VARCHAR(20);
  DECLARE CR CURSOR FOR SELECT GID FROM bgudang ORDER BY GID;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET SELESAI = 1;
  OPEN CR;
  ULANG: LOOP
    FETCH CR INTO GD;
    IF SELESAI = 1 THEN LEAVE ULANG; END IF;
    SET KOL = F_KOLOMGUDANG(GD);
    SET @sqlreset = CONCAT('UPDATE bitem SET `', KOL, '` = F_STOKPERGUDANG(', GD, ', IID)');
    PREPARE st FROM @sqlreset;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE CR;
END$$

DELIMITER ;

-- =============================================================================
--  SESUDAH MENJALANKAN
-- =============================================================================
--  1. Pastikan pemetaannya 1:1 - query ini HARUS kosong:
--
--     SELECT F_KOLOMGUDANG(GID) kolom, GROUP_CONCAT(GID) gudang
--       FROM bgudang GROUP BY 1 HAVING COUNT(*) > 1;
--
--  2. Pastikan tiap gudang punya tepat SATU statement di tiap trigger. Cara yang
--     benar adalah membandingkan jumlah statement TOTAL vs UNIK - JANGAN pakai
--     jumlah saja (membuang statement kembar lalu menambah gudang baru bisa
--     menghasilkan jumlah yang sama persis dgn sebelumnya).
--
--  3. Uji satu mutasi kecil di item percobaan pada gudang 20 Depo: stok harus
--     bertambah SEKALI, bukan dua kali.
--
--  4. Kolom `ISTOKXX` harus tetap 0 selamanya. Kalau isinya berubah, berarti ada
--     gudang baru yang belum dimasukkan ke `F_KOLOMGUDANG()`:
--
--     SELECT COUNT(*) FROM bitem WHERE ISTOKXX <> 0;   -- harus 0
--
--  LINGKUP
--     File ini HANYA untuk `data_pos_nmw_2023`. Database lain di server ini milik
--     PROYEK LAIN dan sengaja tidak disentuh, walaupun sebagian punya salinan trigger
--     `fstokd` dengan cacat yang persis sama - itu bukan urusan proyek ini.
--
--     Catatan: trigger di file ini MENULIS ke `official_nmw.ops_invoice_header`
--     (dirujuk absolut, lewat `SDPRDID`) - itu perilaku ASLI yang disalin apa adanya,
--     bukan tambahan. Kalau menguji, pakai `SDPRDID=-1` supaya UPDATE itu tidak
--     mengenai baris apa pun di database proyek lain.
-- =============================================================================