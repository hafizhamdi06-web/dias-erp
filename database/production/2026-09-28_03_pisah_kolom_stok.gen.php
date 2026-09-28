<?php

/*
 * Membangkitkan database/production/2026-09-28_03_pisah_kolom_stok.sql
 *
 * TIDAK mengubah database apa pun - hanya MEMBACA struktur/rutin yang ada lalu
 * menulis file SQL. Jalankan dari root project:
 *
 *   php artisan tinker --execute="require 'database/production/2026-09-28_03_pisah_kolom_stok.gen.php';"
 *
 * Diuji oleh 2026-09-28_03_pisah_kolom_stok.uji.sh (DB sementara, bukan DB asli).
 */

use Illuminate\Support\Facades\DB;

/** Kutip aman untuk teks di dalam COMMENT. */
if (! function_exists('q')) {
    function q(string $s): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
    }
}

/* Database sumber. Default DB proyek (`data_pos_nmw_2023`); bisa ditimpa dgn menyetel
 | `$GEN_DB` sebelum `require` - dipakai untuk membangkitkan ulang dari salinan
 | PRA-migrasi (lihat pengaman di bawah). */
$DBNAME = $GEN_DB ?? 'data_pos_nmw_2023';

if ($DBNAME !== config('database.connections.mysql.database')) {
    config(['database.connections.mysql.database' => $DBNAME]);
    DB::purge('mysql');
    echo "sumber: {$DBNAME} (bukan DB proyek)\n";
}

/* PENGAMAN - jangan dihapus.
 |
 | Generator ini MEMBACA keadaan DB lalu menulis langkah "dari keadaan itu ke keadaan
 | benar". Kalau dijalankan SETELAH migrasinya terpasang, hasilnya file yang TIDAK lagi
 | membuat kolom, tidak mengubah ROW_FORMAT dan tidak menolkan nilai basi - karena semua
 | itu sudah beres di DB sumber. File seperti itu TIDAK bisa dipakai di server production
 | yang belum dimigrasi. Saya pernah kehilangan file aslinya persis karena ini.
 |
 | `ISTOKXX` hanya ada sesudah migrasi, jadi itu penandanya. */
if (DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', $DBNAME)->where('TABLE_NAME', 'bitem')
    ->where('COLUMN_NAME', 'ISTOKXX')->exists()) {
    throw new RuntimeException(
        "{$DBNAME} SUDAH dimigrasi (kolom ISTOKXX ada). Membangkitkan dari sini akan "
        . "menghasilkan file yang TIDAK membuat kolom/ROW_FORMAT/pembersihan - tidak bisa "
        . "dipakai di server yang belum dimigrasi. Bangkitkan dari salinan PRA-migrasi: "
        . "pulihkan cadangan ke DB terpisah lalu setel \$GEN_DB ke nama DB itu."
    );
}

/* =============================================================================
 | 1. PETA BARU gudang -> kolom stok, 1:1 untuk SEMUA gudang
 |
 | Kolom untuk gudang yang tadinya menumpang `ISTOKPG` / `ISTOKMP`. Empat di
 | antaranya SUDAH ADA di skema tapi tidak pernah dipakai - namanya jelas
 | menunjukkan peruntukannya (OL=Online, GG=Gogobli, HC=Home Care, PM=Pameran),
 | bukti penggabungan ini regresi belakangan, bukan rancangan awal.
 */
$petaBaru = [
    6  => ['ISTOKOL', 'Online'],
    9  => ['ISTOKGG', 'Gogobli'],
    14 => ['ISTOKHC', 'Home Care'],
    15 => ['ISTOKPM', 'Pameran'],
    39 => ['ISTOKDT', 'Gudang Depo Tindakan'],
    40 => ['ISTOKKB', 'Gudang Kubis 1'],
    42 => ['ISTOKDR', 'Depo Research'],
    49 => ['ISTOKBS', 'Busura'],
];

/** Kolom penampung untuk gudang yang BELUM dipetakan (menggantikan ELSE -> ISTOKPG). */
$kolomBelumDipetakan = 'ISTOKXX';

$gudang = DB::table('bgudang')->orderBy('GID')->get(['GID', 'GNAMA', 'GAKTIF']);

// Peta lengkap: ambil dari F_KOLOMGUDANG yang sekarang, lalu timpa yang di atas.
$peta = [];
foreach ($gudang as $g) {
    $gid = (int) $g->GID;
    $peta[$gid] = isset($petaBaru[$gid])
        ? $petaBaru[$gid][0]
        : (string) DB::selectOne('SELECT F_KOLOMGUDANG(?) c', [$gid])->c;
}

// Sanity check: peta HARUS 1:1. Kalau tidak, ada yang salah - berhenti.
$bentrok = array_filter(array_count_values($peta), fn ($n) => $n > 1);
if ($bentrok !== []) {
    throw new RuntimeException('peta masih berbagi kolom: ' . json_encode($bentrok));
}
echo 'peta gudang->kolom: ' . count($peta) . " gudang, semuanya 1:1\n";

/* =============================================================================
 | 2. Kolom mana yang perlu DIBUAT, mana yang sudah ada
 */
$kolomAda = DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', $DBNAME)->where('TABLE_NAME', 'bitem')
    ->where('COLUMN_NAME', 'like', 'ISTOK%')
    ->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all();

$perluDibuat = [];   // kolom => keterangan
$perluDiubah = [];   // kolom => [tipe lama, keterangan]  (tipe bukan double)
$dipakaiUlang = [];  // kolom => keterangan

foreach ($petaBaru as $gid => [$kol, $nama]) {
    $ket = 'Gudang ' . $gid . ' ' . $nama;
    if (! isset($kolomAda[$kol])) {
        $perluDibuat[$kol] = $ket;
    } elseif (strtolower($kolomAda[$kol]) !== 'double') {
        $perluDiubah[$kol] = [$kolomAda[$kol], $ket];
    } else {
        $dipakaiUlang[$kol] = $ket;
    }
}
if (! isset($kolomAda[$kolomBelumDipetakan])) {
    $perluDibuat[$kolomBelumDipetakan] = 'Gudang yang BELUM dipetakan - lihat catatan file';
}

echo 'kolom dibuat baru : ' . (implode(', ', array_keys($perluDibuat)) ?: '-') . "\n";
echo 'kolom diubah tipe : ' . (implode(', ', array_keys($perluDiubah)) ?: '-') . "\n";
echo 'kolom dipakai ulang: ' . (implode(', ', array_keys($dipakaiUlang)) ?: '-') . "\n";

/* =============================================================================
 | 3. Kolom yang dipakai ulang tapi MASIH BERISI nilai basi
 |
 | Selama ini gudangnya membaca `ISTOKPG`, jadi isi kolom ini tidak terlihat di
 | mana pun. Setelah dipisah kolomnya mulai dibaca - kalau tidak dinolkan, stok
 | hantu tiba-tiba muncul di gudang itu.
 */
$perluDinolkan = [];
foreach (array_merge(array_keys($dipakaiUlang), array_keys($perluDiubah)) as $kol) {
    $n = (int) DB::table('bitem')->whereRaw("`$kol` <> 0")->count();
    if ($n > 0) {
        $jml = (float) DB::table('bitem')->whereRaw("`$kol` <> 0")->sum($kol);
        $perluDinolkan[$kol] = [$n, $jml];
    }
}
foreach ($perluDinolkan as $kol => [$n, $jml]) {
    echo "kolom {$kol} berisi {$n} item nilai basi (jumlah {$jml}) -> dinolkan\n";
}

/* =============================================================================
 | 4. Mutasi yang selama ini TIDAK tercatat ke mana pun
 */
$mutasiHilang = [];
foreach ($petaBaru as $gid => [$kol, $nama]) {
    $n = (int) DB::table('fstokd')->where('SDGUDANG', $gid)->count();
    if ($n > 0) {
        $netto = (float) DB::table('fstokd')->where('SDGUDANG', $gid)
            ->selectRaw('SUM(SDMASUK - IF(SDDARIPAKET<>0 AND SDKEDATANGAN=0,0,SDKELUAR)) n')->value('n');
        $item = (int) DB::table('fstokd')->where('SDGUDANG', $gid)->distinct()->count('SDITEM');
        $mutasiHilang[$gid] = [$nama, $kol, $n, $item, $netto];
    }
}

/* =============================================================================
 | 5. Susun file SQL
 */
$tgl = '2026-09-28';
$sql = "-- =============================================================================\n"
    . "--  dias-laravel - PISAH KOLOM STOK PER GUDANG\n"
    . "--  Dibuat {$tgl}. Jalankan SATU KALI. WAJIB backup dulu.\n"
    . "--  Perbaikan trigger `fstokd` (statement kembar + gudang yang terlewat) SUDAH\n"
    . "--  TERMASUK di sini - tidak ada file terpisah untuk itu.\n"
    . "-- =============================================================================\n"
    . "--\n"
    . "--  MASALAHNYA\n"
    . "--  `F_KOLOMGUDANG()` - fungsi yang dipakai SEMUA aplikasi (dias-laravel maupun\n"
    . "--  CI3 dias-online-app) untuk menentukan kolom stok sebuah gudang - hanya\n"
    . "--  mendaftarkan sebagian gudang dan menutupnya dengan `ELSE 'ISTOKPG'`. Akibatnya\n"
    . "--  8 gudang MEMBACA kolom `ISTOKPG` yang sama (1 Petogogan, 6 Online, 14 Home\n"
    . "--  Care, 15 Pameran, 39 Gudang Depo Tindakan, 40 Gudang Kubis 1, 42 Depo Research,\n"
    . "--  49 Busura) dan 2 gudang membaca `ISTOKMP` (9 Gogobli, 18 Marketplace).\n"
    . "--\n"
    . "--  Trigger `fstokd` juga tidak konsisten dengan fungsi itu: sebagian gudang tidak\n"
    . "--  punya statement sama sekali (mutasinya hilang), dan `ISTOKBZ` (gudang 10) serta\n"
    . "--  `ISTOKDE` (gudang 20) punya statement KEMBAR sehingga tiap mutasi dihitung 2x\n"
    . "--  (terbukti di data: dari 237 item di gudang 20, 56 item nilainya tepat 2x netto).\n"
    . "--\n"
    . "--  YANG DIKERJAKAN FILE INI\n"
    . "--  1. Kolom stok sendiri untuk tiap gudang yang tadinya menumpang.\n"
    . "--  2. `F_KOLOMGUDANG()` ditulis ulang: 1:1 untuk SEMUA " . count($peta) . " gudang, dan `ELSE`\n"
    . "--     mengarah ke `{$kolomBelumDipetakan}` - bukan lagi ke `ISTOKPG`. Jadi gudang baru yang\n"
    . "--     lupa dipetakan akan terlihat SALAH DI SATU TEMPAT, tidak lagi diam-diam\n"
    . "--     menumpuk ke Petogogan. INI inti perbaikannya.\n"
    . "--  3. Ketiga trigger `fstokd` dibangkitkan ulang 1:1 dari peta yang sama, sehingga\n"
    . "--     TULIS (trigger) dan BACA (fungsi) tidak bisa lagi berbeda.\n"
    . "--  4. `P_RESETSTOK*` diperbaiki (lihat BAGIAN 5 - ada bug destruktif di sana).\n"
    . "--\n"
    . "--  TIDAK ADA ANGKA YANG PERLU DIPECAH - ini sudah diperiksa\n"
    . "--  Dari 8 gudang yang berbagi `ISTOKPG`, hanya gudang 1 (2.033 mutasi) dan 42\n"
    . "--  (85 mutasi) yang punya mutasi. Mutasi gudang 42 BELUM PERNAH tertulis ke kolom\n"
    . "--  mana pun (tidak ada statement trigger untuknya), jadi `ISTOKPG` sekarang MURNI\n"
    . "--  Petogogan dan dibiarkan apa adanya. Idem `ISTOKMP`: gudang 9 Gogobli 0 mutasi,\n"
    . "--  jadi isinya murni gudang 18 Marketplace.\n"
    . "--\n";

if ($mutasiHilang !== []) {
    $sql .= "--  Mutasi yang selama ini hilang dan mulai tercatat setelah file ini:\n";
    foreach ($mutasiHilang as $gid => [$nama, $kol, $n, $item, $netto]) {
        $sql .= sprintf("--     gudang %-2d %-22s -> %-9s %d mutasi, %d item, netto %s\n",
            $gid, $nama, $kol, $n, $item, rtrim(rtrim(number_format($netto, 2, '.', ''), '0'), '.'));
    }
    $sql .= "--\n"
        . "--  Kolom barunya SENGAJA dimulai dari 0, TIDAK diisi dari mutasi lama. Netto\n"
        . "--  mutasi itu negatif karena barangnya keluar tanpa pernah ada saldo awal yang\n"
        . "--  tercatat - mengisinya justru menanam angka yang salah. Penyesuaian saldo awal\n"
        . "--  1 Oktober yang akan menetapkan angka sebenarnya.\n"
        . "--\n";
}

$sql .= "--  YANG TIDAK DIUBAH\n"
    . "--  * `ISTOKPG` (Petogogan) dan `ISTOKMP` (Marketplace) isinya tidak disentuh.\n"
    . "--  * `F_STOKPERGUDANG()` tidak disentuh.\n"
    . "--  * 9 kolom cadangan bertanggal (`ISTOK*31122016`) dibiarkan.\n"
    . "--  * Angka stok yang terlanjur salah TIDAK dikoreksi di sini - Penyesuaian saldo\n"
    . "--    awal 1 Oktober yang akan menimpanya.\n"
    . "-- =============================================================================\n\n\n"

    /* sql_mode WAJIB diset dan bukan sekadar kehati-hatian:
     | 1. `ALTER TABLE bitem` GAGAL dgn sql_mode default server ini
     |    (`ERROR 1067 Invalid default value for 'IMODIFD'`) karena MariaDB memvalidasi
     |    ULANG seluruh definisi tabel saat ALTER, dan kolom warisan `IMODIFD` punya
     |    default `'0000-00-00'` yg ditolak `NO_ZERO_DATE`.
     | 2. Trigger & rutin MENYIMPAN sql_mode yg berlaku saat dibuat, dan dipakai ulang
     |    setiap kali dijalankan. Nilai di bawah PERSIS sama dgn yg tersimpan di trigger
     |    `fstokd_*`, `F_KOLOMGUDANG` dan `P_RESETSTOK*` yg sekarang - supaya yang baru
     |    berperilaku identik, bukan berubah diam-diam. */
    . "-- =============================================================================\n"
    . "--  WAJIB - jangan dihapus\n"
    . "-- =============================================================================\n"
    . "--  (1) `ALTER TABLE bitem` GAGAL dgn sql_mode default server ini:\n"
    . "--      `ERROR 1067 Invalid default value for 'IMODIFD'`. MariaDB memvalidasi ULANG\n"
    . "--      seluruh definisi tabel saat ALTER, dan kolom warisan `IMODIFD` punya default\n"
    . "--      `'0000-00-00'` yang ditolak oleh `NO_ZERO_DATE`.\n"
    . "--  (2) Trigger & rutin MENYIMPAN sql_mode yang berlaku saat dibuat, lalu memakainya\n"
    . "--      setiap kali dijalankan. Nilai di bawah PERSIS SAMA dengan yang tersimpan di\n"
    . "--      trigger `fstokd_*`, `F_KOLOMGUDANG` dan `P_RESETSTOK*` yang ada sekarang,\n"
    . "--      supaya yang baru berperilaku identik - bukan berubah diam-diam.\n"
    . "--  Hanya berlaku utk sesi ini, tidak mengubah setelan server.\n"
    . "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';\n\n\n";

/* ---- BAGIAN 0: row format ---- */
$rf = (string) DB::table('information_schema.TABLES')
    ->where('TABLE_SCHEMA', $DBNAME)->where('TABLE_NAME', 'bitem')->value('ROW_FORMAT');
$nKolom = (int) DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', $DBNAME)->where('TABLE_NAME', 'bitem')->count();
echo "bitem: {$nKolom} kolom, ROW_FORMAT sekarang {$rf}\n";

if (strtolower($rf) !== 'dynamic') {
    $sql .= "-- =============================================================================\n"
        . "--  BAGIAN 0 - `bitem` ROW_FORMAT: {$rf} -> DYNAMIC   (PRASYARAT, bukan pilihan)\n"
        . "-- =============================================================================\n"
        . "--  Tanpa langkah ini BAGIAN 1 GAGAL dengan:\n"
        . "--    ERROR 1118 Row size too large (> 8126)\n"
        . "--\n"
        . "--  `bitem` punya {$nKolom} kolom dan 4 kolom TEXT. Di ROW_FORMAT={$rf} setiap TEXT\n"
        . "--  menyimpan awalan 768 byte DI DALAM baris (4 x 768 = 3.072 byte hanya untuk itu),\n"
        . "--  sehingga tabel ini sudah mentok batas 8.126 byte per baris. Setiap\n"
        . "--  `MODIFY COLUMN` memaksa tabel DIBANGUN ULANG dan batas itu diperiksa lagi -\n"
        . "--  jadi bukan cuma menambah kolom yang tertolak, memberi COMMENT pun tertolak.\n"
        . "--\n"
        . "--  Di ROW_FORMAT=DYNAMIC kolom TEXT hanya menyimpan penunjuk 20 byte, jadi ruang\n"
        . "--  barisnya lega kembali. DYNAMIC juga sudah menjadi default server ini\n"
        . "--  (`innodb_default_row_format=dynamic`) - `bitem` COMPACT adalah warisan dump lama.\n"
        . "--\n"
        . "--  Ini murni perubahan CARA PENYIMPANAN: data, tipe kolom, index dan hasil query\n"
        . "--  tidak berubah sama sekali. Tabel dibangun ulang sekali (5.580 baris / ~8 MB,\n"
        . "--  terukur 0,3 detik di mesin uji) dan tabel TERKUNCI selama itu.\n"
        . "ALTER TABLE `bitem` ROW_FORMAT=DYNAMIC;\n\n\n";
}

/* ---- BAGIAN 1: kolom ---- */
$sql .= "-- =============================================================================\n"
    . "--  BAGIAN 1 - KOLOM STOK\n"
    . "-- =============================================================================\n\n";

if ($perluDibuat !== []) {
    $baris = [];
    foreach ($perluDibuat as $kol => $ket) {
        $baris[] = "  ADD COLUMN `{$kol}` double DEFAULT 0 COMMENT " . q($ket);
    }
    $sql .= "-- Kolom yang benar-benar baru. `DEFAULT 0` penting: trigger melakukan\n"
        . "-- `KOLOM = KOLOM + (...)`, kalau NULL hasilnya NULL bukan angka.\n"
        . "ALTER TABLE `bitem`\n" . implode(",\n", $baris) . ";\n\n";
}

foreach ($perluDiubah as $kol => [$tipeLama, $ket]) {
    $sql .= "-- `{$kol}` sudah ada tapi bertipe {$tipeLama} - disamakan jadi double spt kolom stok lain.\n"
        . "ALTER TABLE `bitem` MODIFY COLUMN `{$kol}` double DEFAULT 0 COMMENT " . q($ket) . ";\n\n";
}

if ($dipakaiUlang !== []) {
    $sql .= "-- Kolom yang SUDAH ADA di skema tapi tidak pernah dipakai - sekarang dipakai\n"
        . "-- sesuai peruntukan yang sudah tersirat di namanya. Hanya diberi COMMENT.\n";
    foreach ($dipakaiUlang as $kol => $ket) {
        $sql .= "ALTER TABLE `bitem` MODIFY COLUMN `{$kol}` double DEFAULT 0 COMMENT " . q($ket) . ";\n";
    }
    $sql .= "\n";
}

// COMMENT untuk seluruh kolom stok lain supaya pemetaannya terbaca dari skema.
$sql .= "-- Sisanya hanya diberi COMMENT supaya pemetaan gudang->kolom bisa dibaca\n"
    . "-- langsung dari `SHOW FULL COLUMNS FROM bitem` tanpa membuka fungsi.\n";
foreach ($peta as $gid => $kol) {
    // yang sudah diberi COMMENT di blok-blok di atas dilewati
    if (isset($perluDibuat[$kol]) || isset($perluDiubah[$kol]) || isset($dipakaiUlang[$kol])) {
        continue;
    }
    $nama = (string) $gudang->firstWhere('GID', $gid)->GNAMA;
    $sql .= "ALTER TABLE `bitem` MODIFY COLUMN `{$kol}` double DEFAULT 0 COMMENT "
        . q('Gudang ' . $gid . ' ' . trim($nama)) . ";\n";
}
$sql .= "\n\n";

/* ---- BAGIAN 2: nilai basi ---- */
$sql .= "-- =============================================================================\n"
    . "--  BAGIAN 2 - NOLKAN NILAI BASI DI KOLOM YANG DIPAKAI ULANG\n"
    . "-- =============================================================================\n";
if ($perluDinolkan === []) {
    $sql .= "-- Tidak ada: semua kolom yang dipakai ulang isinya sudah 0.\n\n\n";
} else {
    $sql .= "--  Gudangnya selama ini MEMBACA `ISTOKPG`, jadi isi kolom ini tidak terlihat di\n"
        . "--  mana pun. Setelah dipisah kolomnya mulai dibaca - tanpa langkah ini stok hantu\n"
        . "--  tiba-tiba muncul di gudang tersebut. Gudangnya juga NOL mutasi di `fstokd`,\n"
        . "--  jadi stok yang benar memang 0.\n\n";
    foreach ($perluDinolkan as $kol => [$n, $jml]) {
        $sql .= "-- `{$kol}`: {$n} item bernilai (jumlah {$jml}) - sisa data lama.\n"
            . "UPDATE `bitem` SET `{$kol}` = 0 WHERE `{$kol}` <> 0;\n\n";
    }
    $sql .= "\n";
}

/* ---- BAGIAN 3: F_KOLOMGUDANG ---- */
$sql .= "-- =============================================================================\n"
    . "--  BAGIAN 3 - F_KOLOMGUDANG() : 1:1 untuk semua gudang\n"
    . "-- =============================================================================\n"
    . "--  Karakteristik dipertahankan sama dgn yang sekarang (NOT DETERMINISTIC, NO SQL)\n"
    . "--  supaya tidak perlu `log_bin_trust_function_creators` di production.\n"
    . "--  Sengaja TIDAK membaca tabel: fungsi ini dipanggil per baris di beberapa query.\n\n"
    . "DELIMITER \$\$\n\n"
    . "DROP FUNCTION IF EXISTS `F_KOLOMGUDANG`\$\$\n"
    . "CREATE FUNCTION `F_KOLOMGUDANG`(IDCABANG INT) RETURNS VARCHAR(20)\n"
    . "    NOT DETERMINISTIC\n    NO SQL\n"
    . "BEGIN\n"
    . "  RETURN CASE IDCABANG\n";
foreach ($peta as $gid => $kol) {
    $nama = trim((string) $gudang->firstWhere('GID', $gid)->GNAMA);
    $aktif = (int) $gudang->firstWhere('GID', $gid)->GAKTIF ? '' : ' (non-aktif)';
    $sql .= sprintf("    WHEN %2d THEN %-11s -- %s%s\n", $gid, "'{$kol}'", $nama, $aktif);
}
$sql .= "    -- Gudang yang BELUM dipetakan. Dulu `ELSE 'ISTOKPG'` - itulah sebabnya 7\n"
    . "    -- gudang diam-diam menumpuk ke Petogogan tanpa ada yang sadar. Sekarang\n"
    . "    -- salahnya berkumpul di satu kolom yang jelas & bisa diaudit.\n"
    . "    ELSE '{$kolomBelumDipetakan}'\n"
    . "  END;\n"
    . "END\$\$\n\n"
    . "DELIMITER ;\n\n\n";

/* ---- BAGIAN 4: trigger ---- */
$sql .= "-- =============================================================================\n"
    . "--  BAGIAN 4 - TRIGGER `fstokd` : dibangkitkan 1:1 dari peta yang sama\n"
    . "-- =============================================================================\n"
    . "--  Seluruh logika NON-STOK di dalam trigger DISALIN APA ADANYA - hanya statement\n"
    . "--  `update bitem set ISTOK...` yang diganti: qty terima PR (`PBDQTYTERIMA`),\n"
    . "--  pemakaian PKB (`PKBDQTYPAKAI`), `official_nmw.ops_invoice_header`,\n"
    . "--  `esalesorderd.sodmasuk`, `fproduksid.PDMASUKPAKAI/PDKELUARPAKAI`, blok IF\n"
    . "--  item 5076/5720. Semua itu diverifikasi identik oleh skrip uji.\n\n"
    . "DELIMITER \$\$\n\n";

$ringkas = [];
foreach (['fstokd_add' => 'AFTER INSERT', 'fstokd_edit' => 'AFTER UPDATE', 'fstokd_DELL' => 'AFTER DELETE'] as $nama => $waktu) {
    $body = (string) DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', $DBNAME)->where('TRIGGER_NAME', $nama)->value('ACTION_STATEMENT');

    // Semua statement stok yang ada sekarang.
    preg_match_all('/update\s+bitem\s+set\s+ISTOK[A-Z0-9]+\s*=.*?;/is', $body, $m0, PREG_OFFSET_CAPTURE);
    $lama = $m0[0];
    if ($lama === []) {
        throw new RuntimeException("tidak ada statement stok di {$nama}");
    }

    /* Cetakan: statement milik `ISTOKCP` (gudang 2). Dipilih karena pemetaannya sudah
     | 1:1 & kondisinya paling sederhana (`SDGUDANG=2` tunggal), jadi substitusinya
     | bersih. Utk `fstokd_edit` ada DUA cetakan: pembalik OLD (`-`) lalu penerap NEW
     | (`+ ... WHERE NEW.SDCANCEL=0`) - keduanya diambil, dan urutan dua BLOK itu
     | dipertahankan spt aslinya (semua pembalik dulu, baru semua penerap). */
    $cetakan = [];
    foreach ($lama as [$s]) {
        if (preg_match('/ISTOKCP/i', $s)) {
            $cetakan[] = trim(preg_replace('/\s+/', ' ', $s));
        }
    }
    if ($cetakan === []) {
        throw new RuntimeException("cetakan ISTOKCP tidak ketemu di {$nama}");
    }

    // Bangkitkan blok baru: per cetakan, satu statement per gudang.
    $blok = '';
    foreach ($cetakan as $i => $c) {
        $blok .= "\n  -- " . ($i === 0 && count($cetakan) > 1 ? 'kembalikan nilai LAMA' : (count($cetakan) > 1 ? 'terapkan nilai BARU' : 'terapkan mutasi')) . "\n";
        foreach ($peta as $gid => $kol) {
            $s = str_ireplace('ISTOKCP', $kol, $c);
            $s = preg_replace('/((?:OLD|NEW)\.SDGUDANG)\s*=\s*2\b/i', '$1=' . $gid, $s);
            $blok .= '  ' . $s . "\n";
        }

        /* Penampung gudang yang BELUM dipetakan - pasangan tulis dari `ELSE ISTOKXX`
         | di F_KOLOMGUDANG. Tanpa ini sisi BACA punya penampung sedangkan sisi TULIS
         | tidak, yaitu ketidakcocokan yang sedang diberantas file ini. */
        $s = str_ireplace('ISTOKCP', $kolomBelumDipetakan, $c);
        $s = preg_replace('/((?:OLD|NEW)\.SDGUDANG)\s*=\s*2\b/i',
            '$1 NOT IN (' . implode(',', array_keys($peta)) . ')', $s);
        $blok .= '  -- gudang yg belum dipetakan (harus tidak pernah kena - lihat catatan file)' . "\n"
            . '  ' . $s . "\n";
    }

    // Statement stok pertama diganti seluruh blok; sisanya dibuang. Dikerjakan dari
    // BELAKANG supaya offset statement sebelumnya tidak bergeser.
    $baru = $body;
    for ($i = count($lama) - 1; $i >= 0; $i--) {
        [$teks, $pos] = $lama[$i];
        $ganti = $i === 0 ? ltrim($blok, "\n") : '';
        $baru = substr($baru, 0, $pos) . $ganti . substr($baru, $pos + strlen($teks));
    }

    $ringkas[$nama] = [count($lama), count($cetakan) * (count($peta) + 1)];
    $sql .= "-- ----- {$nama} ({$waktu}) -----\n"
        . "DROP TRIGGER IF EXISTS `{$nama}`\$\$\n"
        . "CREATE TRIGGER `{$nama}` {$waktu} ON `fstokd` FOR EACH ROW\n"
        . $baru . "\$\$\n\n\n";
}
$sql .= "DELIMITER ;\n\n\n";

foreach ($ringkas as $n => [$a, $b]) {
    printf("%-14s : %d statement stok lama -> %d baru\n", $n, $a, $b);
}

/* ---- BAGIAN 5: prosedur reset ---- */
$sql .= <<<'PROC'
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


PROC;

/* ---- penutup ---- */
$sql .= <<<'FOOT'
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
FOOT;

$path = 'database/production/2026-09-28_03_pisah_kolom_stok.sql';
file_put_contents($path, $sql);
echo 'ditulis: ' . $path . ' (' . number_format(strlen($sql)) . " byte)\n";
